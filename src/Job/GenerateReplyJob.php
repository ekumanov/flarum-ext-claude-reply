<?php

namespace Ekumanov\ClaudeReply\Job;

use Carbon\Carbon;
use Ekumanov\ClaudeReply\Anthropic\ClaudeClient;
use Ekumanov\ClaudeReply\BotAccount;
use Ekumanov\ClaudeReply\Context\ContextBuilder;
use Ekumanov\ClaudeReply\PostPublisher;
use Ekumanov\ClaudeReply\Reply\MentionSanitizer;
use Ekumanov\ClaudeReply\ReplyLog;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\Post\CommentPost;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background worker — build the context, call the API once, post the reply.
 *
 * Single-attempt by design ($tries = 1). A retry would re-bill a full request
 * and, worse, could double-post if the failure happened after publish. On any
 * failure the audit row records why and the job stops; the human can just
 * mention the bot again.
 *
 * The job carries only the ReplyLog id. Everything else is re-read at run
 * time, so a post edited or deleted between mention and pickup is handled on
 * current state rather than a stale serialized model.
 */
class GenerateReplyJob extends AbstractJob
{
    /** @var int No retries — see class docblock. */
    public int $tries = 1;

    /**
     * @var int Wall-clock cap. Must exceed ClaudeClient's 300s request
     *          timeout with room for context assembly and the publish.
     */
    public int $timeout = 420;

    public function __construct(public readonly int $logId) {}

    public function handle(
        ContextBuilder $contextBuilder,
        ClaudeClient $claude,
        PostPublisher $publisher,
        BotAccount $bot,
        MentionSanitizer $sanitizer,
        SettingsRepository $settings,
        SettingsRepositoryInterface $rawSettings,
        LoggerInterface $log,
    ): void {
        $row = ReplyLog::find($this->logId);

        if ($row === null || $row->status !== ReplyLog::STATUS_PENDING) {
            return; // deleted, or already handled
        }

        try {
            $this->run($row, $contextBuilder, $claude, $publisher, $bot, $sanitizer, $settings, $rawSettings, $log);
        } catch (Throwable $e) {
            $this->markFailed($row, substr($e->getMessage(), 0, 250));

            $log->error('claude-reply: generation failed', [
                'log_id' => $row->id,
                'discussion_id' => $row->discussion_id,
                'err' => $e->getMessage(),
            ]);
        }
    }

    private function run(
        ReplyLog $row,
        ContextBuilder $contextBuilder,
        ClaudeClient $claude,
        PostPublisher $publisher,
        BotAccount $bot,
        MentionSanitizer $sanitizer,
        SettingsRepository $settings,
        SettingsRepositoryInterface $rawSettings,
        LoggerInterface $log,
    ): void {
        $botUser = $bot->get();
        if ($botUser === null) {
            $this->markFailed($row, 'bot account not found');
            return;
        }

        /** @var CommentPost|null $trigger */
        $trigger = CommentPost::find($row->trigger_post_id);
        if ($trigger === null || $trigger->hidden_at !== null) {
            // Deleted or hidden between mention and pickup — nothing to answer.
            $this->markSkipped($row, 'trigger post gone');
            return;
        }

        $forumTitle = (string) ($rawSettings->get('forum_title') ?: 'this forum');
        $botName    = $botUser->display_name ?? $settings->botUsername();

        $context = $contextBuilder->build($trigger);
        $rendered = $context->render();

        $row->context_posts = count($context->posts);
        $row->model = $settings->model();
        $row->save();

        // Authoritative size check before anything is billed. The builder's
        // character heuristic is intentionally pessimistic, but a thread full
        // of CJK text or base64 blobs can still surprise it.
        $actualTokens = $claude->countTokens($forumTitle, $botName, $rendered);
        $budget = $settings->contextTokenBudget();

        if ($actualTokens > $budget * 2) {
            $this->markFailed($row, "context {$actualTokens} tokens exceeds twice the budget ({$budget})");
            $log->warning('claude-reply: context over budget, refusing', [
                'log_id' => $row->id,
                'tokens' => $actualTokens,
                'budget' => $budget,
            ]);
            return;
        }

        $result = $claude->reply($forumTitle, $botName, $rendered);

        $row->input_tokens                = $result->inputTokens;
        $row->output_tokens               = $result->outputTokens;
        $row->cache_read_input_tokens     = $result->cacheReadInputTokens;
        $row->cache_creation_input_tokens = $result->cacheCreationInputTokens;
        $row->web_search_requests         = $result->webSearchRequests;
        $row->model                       = $result->model;
        $row->save();

        // Check stop_reason before trusting content: a refusal returns HTTP
        // 200 with empty or partial content.
        if ($result->isRefusal()) {
            $this->markFailed($row, 'model declined (stop_reason: refusal)');
            $log->warning('claude-reply: model refused', ['log_id' => $row->id]);
            return;
        }

        $text = trim($result->text);

        if ($text === '') {
            $this->markFailed($row, 'empty response');
            return;
        }

        if ($result->isTruncated()) {
            $log->warning('claude-reply: reply hit max_tokens and was truncated', [
                'log_id' => $row->id,
                'max_tokens' => $settings->maxTokens(),
            ]);
        }

        // Validate mention tokens against what the model was actually shown,
        // BEFORE the footer is appended — the footer is the admin's own text
        // and is not the model's to be held to.
        $sanitized = $sanitizer->sanitize($text, $context);

        if ($sanitized->changed()) {
            $log->warning('claude-reply: stripped mention tokens the model was not shown', [
                'log_id' => $row->id,
                'stripped' => $sanitized->summary(),
            ]);
        }

        $text = $sanitized->text;

        // Append the disclosure footer, unless the model has already written
        // one. The prompt tells it not to, and its own past posts no longer
        // arrive carrying one, but a paraphrase is still cheap to guard against
        // and a duplicate is visible to every reader.
        $footer = $settings->footer();

        if ($footer !== '' && ! str_ends_with(rtrim($text), $footer)) {
            $text .= "\n\n".$footer;
        }

        $post = $publisher->publish($row->discussion_id, $botUser, $text);

        $row->reply_post_id = $post->id;
        $row->status        = ReplyLog::STATUS_POSTED;
        $row->completed_at  = Carbon::now();
        $row->save();

        $log->info('claude-reply: posted', [
            'log_id' => $row->id,
            'discussion_id' => $row->discussion_id,
            'post_id' => $post->id,
            'in' => $result->inputTokens,
            'out' => $result->outputTokens,
        ]);
    }

    /**
     * NB not `fail()` — AbstractJob inherits a public `fail()` from
     * Laravel's InteractsWithQueue, and redeclaring it private is a fatal
     * error at class-load time (which PHP raises before any try/catch can
     * see it, so the symptom is a silently dead worker).
     */
    private function markFailed(ReplyLog $row, string $error): void
    {
        $row->status       = ReplyLog::STATUS_FAILED;
        $row->error        = $error;
        $row->completed_at = Carbon::now();
        $row->save();
    }

    /**
     * Skipped rows do not count against the daily cap — nothing was billed.
     */
    private function markSkipped(ReplyLog $row, string $reason): void
    {
        $row->status       = ReplyLog::STATUS_SKIPPED;
        $row->error        = $reason;
        $row->completed_at = Carbon::now();
        $row->save();
    }
}
