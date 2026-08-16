<?php

namespace Ekumanov\ClaudeReply\Listener;

use Carbon\Carbon;
use Ekumanov\ClaudeReply\BotAccount;
use Ekumanov\ClaudeReply\Job\GenerateReplyJob;
use Ekumanov\ClaudeReply\ReplyLog;
use Ekumanov\ClaudeReply\Settings\ApiKey;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Queue\RoutingQueue;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;
use Psr\Log\LoggerInterface;
use s9e\TextFormatter\Utils;
use Throwable;

/**
 * Decides whether a newly posted comment should get a Claude reply, and if so
 * queues one.
 *
 * This runs INSIDE the post-save HTTP request, so it does no network I/O: it
 * reads settings, runs a handful of cheap checks, writes one audit row and
 * pushes a job. Everything expensive is the worker's problem.
 *
 * Gate order is cheapest-first and fail-closed at every step. Two independent
 * guards stop a reply loop: the bot's own user id is never in the allow-list,
 * and it is additionally rejected by name below. Note also that the *trigger*
 * is a mention of the bot, so even a reply that @-mentions a human can't
 * bounce back.
 *
 * Deliberately NOT hooked to `Revised`. Editing a post to add the mention
 * would otherwise let one post bill repeatedly, and an edit-triggered reply
 * appears out of order in the thread.
 */
final class HandleMention
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ApiKey $apiKey,
        private readonly BotAccount $bot,
        private readonly ExtensionManager $extensions,
        private readonly Queue $queue,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(Posted $event): void
    {
        try {
            $this->maybeQueue($event->post, $event->actor?->id);
        } catch (Throwable $e) {
            // A failure here must never break posting. The user's post is
            // already saved; the reply is best-effort.
            $this->log->error('claude-reply: listener failed', [
                'post_id' => $event->post->id ?? null,
                'err' => $e->getMessage(),
            ]);
        }
    }

    private function maybeQueue(CommentPost $post, ?int $actorId): void
    {
        if (! $this->settings->enabled()) {
            return;
        }

        if ($actorId === null) {
            return;
        }

        // Guard 1: the bot must never trigger itself.
        $botId = $this->bot->id();
        if ($botId === null) {
            $this->log->warning('claude-reply: bot account not found', [
                'username' => $this->settings->botUsername(),
            ]);
            return;
        }
        if ($actorId === $botId) {
            return;
        }

        // Guard 2: explicit trigger allow-list. Empty = nobody.
        $allowed = $this->settings->allowedUserIds();
        if ($allowed === [] || ! in_array($actorId, $allowed, true)) {
            return;
        }

        // Mention check before the API-key check: otherwise every ordinary
        // post by an allow-listed user logs a key warning.
        if (! $this->mentionsBot($post, $botId)) {
            return;
        }

        if (! $this->apiKey->isConfigured()) {
            $this->log->warning('claude-reply: no API key configured (config.php claude_reply.api_key)');
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

        if (! $this->tagAllowed($discussion)) {
            return;
        }

        if ($this->dailyLimitReached()) {
            $this->log->warning('claude-reply: daily reply limit reached', [
                'limit' => $this->settings->dailyReplyLimit(),
            ]);
            return;
        }

        // A sync queue executes push() inline, which would run a multi-minute
        // API call inside this post-save request — the member would watch the
        // composer hang, and PHP would likely hit max_execution_time first.
        // Refuse rather than degrade. Unlike link-preview there is no
        // scheduled sweep to fall back on, and inventing one for a feature
        // only an allow-listed admin can trigger is not worth the cron
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
     * True when the post's TextFormatter XML carries a USERMENTION pointing at
     * the bot. Reading the parsed attribute rather than string-matching the
     * raw body means a code block containing `@claude_user`, or a nickname
     * change, can't produce a false positive.
     *
     * NB `$post->content` is NOT the XML — the HasFormattedContent accessor
     * unparses it back to source on read. `parsed_content` is the stored XML.
     */
    private function mentionsBot(CommentPost $post, int $botId): bool
    {
        $content = (string) $post->parsed_content;
        if ($content === '') {
            return false;
        }

        try {
            $ids = Utils::getAttributeValues($content, 'USERMENTION', 'id');
        } catch (Throwable) {
            return false;
        }

        return in_array((string) $botId, array_map('strval', $ids), true);
    }

    /**
     * Tag allow-list. Empty = no tag permitted, so enabling the extension
     * without configuring tags cannot silently expose the whole forum.
     *
     * With flarum-tags disabled there are no tags to check against and the
     * allow-list can never be satisfied — that is intentional.
     */
    private function tagAllowed(object $discussion): bool
    {
        $allowedTags = $this->settings->allowedTagIds();
        if ($allowedTags === []) {
            return false;
        }

        if (! $this->extensions->isEnabled('flarum-tags')) {
            return false;
        }

        try {
            $tagIds = $discussion->tags->pluck('id')->map('intval')->all();
        } catch (Throwable) {
            return false;
        }

        return array_intersect($tagIds, $allowedTags) !== [];
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

    private function dailyLimitReached(): bool
    {
        $limit = $this->settings->dailyReplyLimit();
        if ($limit <= 0) {
            return true;
        }

        $used = ReplyLog::query()
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->where('status', '!=', ReplyLog::STATUS_SKIPPED)
            ->count();

        return $used >= $limit;
    }
}
