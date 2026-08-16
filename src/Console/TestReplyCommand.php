<?php

namespace Ekumanov\ClaudeReply\Console;

use Ekumanov\ClaudeReply\Anthropic\ClaudeClient;
use Ekumanov\ClaudeReply\BotAccount;
use Ekumanov\ClaudeReply\Context\ContextBuilder;
use Ekumanov\ClaudeReply\Settings\ApiKey;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Console\Command;

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
                            {--send : Actually call the API and print the reply (still does not post it)}';

    protected $description = 'Preview the discussion context (and optionally the generated reply) for a given trigger post, without posting.';

    public function handle(
        ContextBuilder $contextBuilder,
        ClaudeClient $claude,
        BotAccount $bot,
        ApiKey $apiKey,
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
        if ($result->webSearchRequests > 0) {
            $this->line('web searches   : '.$result->webSearchRequests);
        }
        $this->newLine();

        if ($result->isRefusal()) {
            $this->error('Model declined the request (stop_reason: refusal).');
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
}
