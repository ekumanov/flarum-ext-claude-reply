<?php

namespace Ekumanov\ClaudeReply\Listener;

use Carbon\Carbon;
use Ekumanov\ClaudeReply\Access\Reason;
use Ekumanov\ClaudeReply\Access\ReplyQuota;
use Ekumanov\ClaudeReply\Access\TriggerGate;
use Ekumanov\ClaudeReply\BotAccount;
use Ekumanov\ClaudeReply\Job\GenerateReplyJob;
use Ekumanov\ClaudeReply\ReplyLog;
use Ekumanov\ClaudeReply\Settings\ApiKey;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Queue\RoutingQueue;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;
use Psr\Log\LoggerInterface;
use s9e\TextFormatter\Utils;
use Throwable;

/**
 * Decides whether a newly posted comment should get a Claude reply, and if so
 * queues one.
 *
 * This runs INSIDE the post-save HTTP request, on every post the forum
 * receives, so what it costs matters more than what it does. It performs no
 * network I/O, and the gate order below is arranged so that an ordinary post —
 * the overwhelming majority — is rejected without touching the database at
 * all:
 *
 *   1. `enabled` (memoised settings read)
 *   2. an actor exists
 *   3. the post's XML contains a user mention AT ALL — a substring test on a
 *      string already in memory. This is the short-circuit that matters: a
 *      post with no `@` in it never gets further, so the steady-state cost of
 *      having this extension installed is one settings lookup and one
 *      `str_contains`.
 *   4. the user/group gate (array work; one query only if group lists are in
 *      use)
 *   5. the bot account (first query — memoised per process), self-trigger
 *      guard, and whether the mention is actually OF the bot
 *   6. API key, private-discussion refusal, tag gate
 *   7. quotas (per member, then forum-wide)
 *   8. queue driver sanity, then one audit row and one job push
 *
 * Every step is fail-closed. Two independent guards stop a reply loop: the bot
 * is not normally on any allow-list, and it is additionally rejected by id in
 * step 5. Note also that the *trigger* is a mention of the bot, so even a reply
 * that @-mentions a human cannot bounce back.
 *
 * Deliberately NOT hooked to `Revised`. Editing a post to add the mention would
 * otherwise let one post bill repeatedly, and an edit-triggered reply appears
 * out of order in the thread.
 */
final class HandleMention
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ApiKey $apiKey,
        private readonly BotAccount $bot,
        private readonly TriggerGate $gate,
        private readonly ReplyQuota $quota,
        private readonly Queue $queue,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(Posted $event): void
    {
        try {
            $this->maybeQueue($event->post, $event->actor);
        } catch (Throwable $e) {
            // A failure here must never break posting. The user's post is
            // already saved; the reply is best-effort.
            $this->log->error('claude-reply: listener failed', [
                'post_id' => $event->post->id ?? null,
                'err' => $e->getMessage(),
            ]);
        }
    }

    private function maybeQueue(CommentPost $post, ?User $actor): void
    {
        if (! $this->settings->enabled()) {
            return;
        }

        if ($actor === null || $actor->id === null) {
            return;
        }

        $actorId = (int) $actor->id;

        // Cheapest possible rejection, and the one that runs for nearly every
        // post on the forum: no user mention in the stored XML means this post
        // cannot be a trigger, whoever wrote it. No query, no parsing.
        $xml = (string) $post->parsed_content;
        $alsoQuotes = $this->settings->replyToQuotes();

        if (! str_contains($xml, '<USERMENTION') && ! ($alsoQuotes && str_contains($xml, '<POSTMENTION'))) {
            return;
        }

        $decision = $this->gate->user($actor);

        if (! $decision->allowed) {
            return;
        }

        $botId = $this->bot->id();

        if ($botId === null) {
            $this->log->warning('claude-reply: bot account not found', [
                'bot_user_id' => $this->settings->botUserId(),
                'username' => $this->settings->botUsername(),
            ]);

            return;
        }

        // The bot must never trigger itself, even if someone allow-lists it.
        if ($actorId === $botId) {
            return;
        }

        if (! $this->mentionsBot($xml, $botId) && ! ($alsoQuotes && $this->quotesBot($xml, $botId))) {
            return;
        }

        if (! $this->apiKey->isConfigured()) {
            $this->log->warning('claude-reply: no API key configured (config.php claude_reply.api_key, ANTHROPIC_API_KEY, or the admin setting)');

            return;
        }

        $discussion = $post->discussion;

        if ($discussion === null) {
            return;
        }

        // Never in private discussions (fof/byobu). Hard-coded, not a setting:
        // a PM is the one place where "this content goes to a third party" is
        // categorically not what the participants agreed to.
        if ((bool) $discussion->is_private) {
            $this->log->info('claude-reply: refused in private discussion', [
                'discussion_id' => $discussion->id,
            ]);

            return;
        }

        $tagDecision = $this->gate->discussion($discussion, $actor);

        if (! $tagDecision->allowed) {
            return;
        }

        $quotaDecision = $this->quota->check($actor);

        if (! $quotaDecision->allowed) {
            // Worth a log line at warning level: unlike the gates above, this
            // is a member who was entitled to a reply and did not get one. The
            // composer warns them before they post, but the warning is
            // advisory and this is the authoritative refusal.
            $this->log->warning('claude-reply: refused, quota reached', [
                'reason' => $quotaDecision->reason->value,
                'user_id' => $actorId,
                'post_id' => $post->id,
                'per_user_limit' => $this->settings->perUserDailyLimit(),
                'forum_limit' => $this->settings->dailyReplyLimit(),
            ]);

            $this->recordSkip($post, $actorId, $quotaDecision->reason);

            return;
        }

        // A sync queue executes push() inline, which would run a multi-minute
        // API call inside this post-save request — the member would watch the
        // composer hang, and PHP would likely hit max_execution_time first.
        // Refuse rather than degrade. Unlike link-preview there is no
        // scheduled sweep to fall back on, and inventing one for a feature
        // only an allow-listed member can trigger is not worth the cron
        // dependency: configure a real queue driver instead.
        if ($this->isSyncQueue()) {
            $this->log->warning(
                'claude-reply: refusing to run on the sync queue — configure a queue driver (redis/database) and run a worker'
            );

            return;
        }

        // Audit row first, job second: a worker that dies mid-flight still
        // leaves evidence that the trigger fired.
        $logRow = new ReplyLog();
        $logRow->discussion_id   = $discussion->id;
        $logRow->trigger_post_id = $post->id;
        $logRow->trigger_user_id = $actorId;
        $logRow->status          = ReplyLog::STATUS_PENDING;
        $logRow->created_at      = Carbon::now();
        $logRow->save();

        $this->queue->push(new GenerateReplyJob($logRow->id));
    }

    /**
     * Leave a trace of a quota refusal.
     *
     * Written as `skipped`, which by definition does not count against either
     * cap — nothing was billed. Without this the ledger would show a member's
     * replies simply stopping, with no record of the attempts that were turned
     * away, and "why did the bot ignore me at 3pm" would be unanswerable.
     */
    private function recordSkip(CommentPost $post, int $actorId, Reason $reason): void
    {
        $row = new ReplyLog();
        $row->discussion_id   = $post->discussion_id;
        $row->trigger_post_id = $post->id;
        $row->trigger_user_id = $actorId;
        $row->status          = ReplyLog::STATUS_SKIPPED;
        $row->error           = $reason->value;
        $row->created_at      = Carbon::now();
        $row->completed_at    = Carbon::now();
        $row->save();
    }

    /**
     * True when the post's TextFormatter XML carries a USERMENTION pointing at
     * the bot. Reading the parsed attribute rather than string-matching the raw
     * body means a code block containing `@claude_user`, or a nickname change,
     * can't produce a false positive.
     *
     * NB the XML comes from `parsed_content`, not `content` — the
     * HasFormattedContent accessor unparses the latter back to source on read.
     */
    private function mentionsBot(string $xml, int $botId): bool
    {
        try {
            $ids = Utils::getAttributeValues($xml, 'USERMENTION', 'id');
        } catch (Throwable) {
            return false;
        }

        return in_array((string) $botId, array_map('strval', $ids), true);
    }

    /**
     * True when the post replies to or quotes one of the bot's own posts.
     *
     * Both affordances produce a POSTMENTION carrying the quoted post's id, so
     * this is a lookup of those ids' authors — one query, and only for posts
     * that already carry a post mention, on a forum that has opted in.
     */
    private function quotesBot(string $xml, int $botId): bool
    {
        try {
            $ids = Utils::getAttributeValues($xml, 'POSTMENTION', 'id');
        } catch (Throwable) {
            return false;
        }

        if ($ids === []) {
            return false;
        }

        return CommentPost::query()
            ->whereIn('id', array_map('intval', $ids))
            ->where('user_id', $botId)
            ->exists();
    }

    /**
     * Whether jobs would execute inline.
     *
     * Flarum may hand us the raw driver or a {@see RoutingQueue} wrapping it
     * (core's own check unwraps the same way, but its helper is protected), so
     * test both rather than relying on which one the container happens to
     * resolve for the configured driver.
     */
    private function isSyncQueue(): bool
    {
        $queue = $this->queue;

        if ($queue instanceof RoutingQueue) {
            $queue = $queue->getDriver();
        }

        return $queue instanceof SyncQueue;
    }
}
