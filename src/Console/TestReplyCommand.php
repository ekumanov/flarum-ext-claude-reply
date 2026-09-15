<?php

namespace Ekumanov\ClaudeReply\Console;

use Ekumanov\ClaudeReply\Access\ReplyQuota;
use Ekumanov\ClaudeReply\Access\TriggerGate;
use Ekumanov\ClaudeReply\Anthropic\ClaudeClient;
use Ekumanov\ClaudeReply\BotAccount;
use Ekumanov\ClaudeReply\Context\ContextBuilder;
use Ekumanov\ClaudeReply\Settings\ApiKey;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Console\Command;
use s9e\TextFormatter\Utils;

/**
 * Dry-run harness: `php flarum claude-reply:test <postId>`.
 *
 * Builds the context exactly as the job would and prints it, so the context
 * window can be inspected — and the prompt tuned — without spending a token.
 * `--send` additionally calls the API and prints the reply, still WITHOUT
 * posting it to the forum.
 *
 * This is the tool for tuning `persona_prompt` and `context_token_budget`:
 * iterate on the printed context first, spend money second.
 */
class TestReplyCommand extends Command
{
    protected $signature = 'claude-reply:test
                            {post : ID of a post to treat as the trigger}
                            {--send : Actually call the API and print the reply (still does not post it)}
                            {--gate : Only report the gate decision, do not build the context}';

    protected $description = 'Explain the gate decision for a post, preview the discussion context, and optionally the generated reply — without posting.';

    public function handle(
        ContextBuilder $contextBuilder,
        ClaudeClient $claude,
        BotAccount $bot,
        ApiKey $apiKey,
        TriggerGate $gate,
        ReplyQuota $quota,
        SettingsRepository $settings,
        SettingsRepositoryInterface $rawSettings,
    ): int {
        $postId = (int) $this->argument('post');

        /** @var CommentPost|null $post */
        $post = CommentPost::find($postId);

        if ($post === null) {
            $this->error("No comment post with id {$postId}.");
            return 1;
        }

        $this->explainGate($post, $bot, $apiKey, $gate, $quota, $settings);

        if ($this->option('gate')) {
            return 0;
        }

        $botUser = $bot->get();
        $botName = $botUser?->display_name ?? $settings->botUsername();

        if ($botUser === null) {
            $this->warn("Bot account '{$settings->botUsername()}' not found — using the raw username as display name.");
        }

        $forumTitle = (string) ($rawSettings->get('forum_title') ?: 'this forum');

        $context = $contextBuilder->build($post);
        $rendered = $context->render();

        $this->info('=== CONTEXT ===');
        $this->line($rendered);
        $this->newLine();

        $this->info('=== SUMMARY ===');
        $this->line('Posts included : '.count($context->posts).' of '.$context->totalComments);
        $this->line('Omitted        : '.$context->omitted);
        $this->line('Window ended on: '.$context->stoppedOn.' (budget '.$settings->contextTokenBudget().', cap '.$settings->maxContextPosts().')');

        if ($context->posts !== []) {
            $this->line('Oldest included: post #'.$context->posts[0]->number.' of #'.$post->number);
        }
        $this->line('Estimated tok  : '.$context->estimatedTokens().' (heuristic)');
        $this->line('Model          : '.$settings->model());
        $this->line('Effort         : '.$settings->effort());

        if (! $apiKey->isConfigured()) {
            $this->newLine();
            $this->warn('No API key configured — set claude_reply.api_key in config.php to use --send.');
            return 0;
        }

        // countTokens prices the whole request, system prompt included, so
        // report the breakdown — comparing the context heuristic against the
        // combined figure makes the estimator look far worse than it is.
        $actual   = $claude->countTokens($forumTitle, $botName, $rendered);
        $overhead = $claude->countTokens($forumTitle, $botName, 'x');

        $this->line('System prompt  : ~'.$overhead.' tokens');
        $this->line('Context actual : ~'.max(0, $actual - $overhead).' tokens (vs '.$context->estimatedTokens().' estimated)');
        $this->line('Billed input   : '.$actual.' tokens');

        if (! $this->option('send')) {
            return 0;
        }

        $this->newLine();
        $this->info('=== CALLING API ===');

        $result = $claude->reply($forumTitle, $botName, $rendered);

        $this->line('stop_reason    : '.($result->stopReason ?? 'null'));
        $this->line('tokens in/out  : '.$result->inputTokens.' / '.$result->outputTokens);
        if ($result->apiCalls > 1) {
            $this->line('api calls      : '.$result->apiCalls.' (the turn paused and was continued)');
        }
        if ($result->webSearchRequests > 0) {
            $this->line('web searches   : '.$result->webSearchRequests);
        }
        $this->newLine();

        if ($result->isRefusal()) {
            $this->error('Model declined the request (stop_reason: refusal).');
            return 1;
        }

        if ($result->isIncomplete()) {
            $this->error('Model never finished its turn (stop_reason: '.($result->stopReason ?? 'null').').');
            $this->line('What it had written is below, but it is a fragment, not a reply — the job would refuse to post it.');
            $this->newLine();
            $this->line($result->text);
            return 1;
        }

        $this->info('=== REPLY (not posted) ===');
        $this->line($result->text);

        if ($result->isTruncated()) {
            $this->newLine();
            $this->warn('Reply hit max_tokens and was truncated — raise ekumanov-claude-reply.max_tokens.');
        }

        return 0;
    }

    /**
     * Walk the same gates the listener walks and print the verdict for each.
     *
     * The question this answers is "why did the bot not reply to that post?",
     * which used to be answerable only by reading the listener with the settings
     * table open alongside it. With allow and deny lists across three
     * categories, plus two quotas, that stopped being reasonable.
     *
     * Note this reports on the post's *author* as the would-be trigger, and
     * that it evaluates current settings — not the state at the time the post
     * was made.
     */
    private function explainGate(
        CommentPost $post,
        BotAccount $bot,
        ApiKey $apiKey,
        TriggerGate $gate,
        ReplyQuota $quota,
        SettingsRepository $settings,
    ): void {
        $this->info('=== GATE ===');

        $author = $post->user;
        $botId = $bot->id();
        $rows = [];

        $rows[] = ['enabled', $settings->enabled() ? 'yes' : 'NO — nothing is queued'];
        $rows[] = ['api key', $apiKey->isConfigured() ? 'yes (from '.$apiKey->source().')' : 'NO'];
        $rows[] = ['bot account', $botId === null
            ? 'NOT FOUND ('.($settings->botUserId() ?? $settings->botUsername()).')'
            : $bot->username().' (id '.$botId.')'];

        if ($author === null) {
            $rows[] = ['post author', 'MISSING — cannot evaluate the user gate'];
        } else {
            $decision = $gate->user($author);
            $rows[] = ['post author', $author->username.' (id '.$author->id.')'];
            // Printed because a gate that resolves no groups looks identical to
            // a member who is in none — which is how a version-incompatible
            // lookup went unnoticed until an admin wondered why an allowed
            // group matched nobody.
            $rows[] = ['author groups', implode(', ', $gate->groupIdsFor($author)) ?: '(none resolved)'];
            $rows[] = ['user gate', ($decision->allowed ? 'ALLOW' : 'DENY').' — '.$decision->reason->value];
        }

        $mentionsBot = $botId !== null && str_contains((string) $post->parsed_content, '<USERMENTION')
            && in_array(
                (string) $botId,
                array_map('strval', Utils::getAttributeValues((string) $post->parsed_content, 'USERMENTION', 'id')),
                true
            );

        $rows[] = ['mentions the bot', $mentionsBot ? 'yes' : 'no — this post would not be a trigger'];

        $discussion = $post->discussion;

        if ($discussion === null) {
            $rows[] = ['discussion', 'MISSING'];
        } else {
            $tagDecision = $gate->discussion($discussion, $author);
            $rows[] = ['private discussion', $discussion->is_private ? 'YES — always refused' : 'no'];
            $rows[] = ['tag gate', ($tagDecision->allowed ? 'ALLOW' : 'DENY').' — '.$tagDecision->reason->value];
        }

        if ($author !== null) {
            $perUser = $settings->perUserDailyLimit();
            $used = $quota->userUsed((int) $author->id);

            $rows[] = ['author usage (24h)', match (true) {
                $quota->mayBypass($author) => $used.' (exempt from the per-user limit)',
                $perUser > 0 => $used.' of '.$perUser,
                default => $used.' (no per-user limit)',
            }];
            $rows[] = ['forum usage (24h)', $quota->forumUsed().' of '.$settings->dailyReplyLimit()];

            $quotaDecision = $quota->check($author);
            $rows[] = ['quota', ($quotaDecision->allowed ? 'ALLOW' : 'DENY').' — '.$quotaDecision->reason->value];
        }

        $this->table(['check', 'result'], $rows);
        $this->newLine();
    }
}
