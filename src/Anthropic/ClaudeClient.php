<?php

namespace Ekumanov\ClaudeReply\Anthropic;

use Anthropic\Client;
use Anthropic\Messages\Message;
use Anthropic\Messages\WebSearchTool20260209;
use Ekumanov\ClaudeReply\Settings\ApiKey;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use RuntimeException;

/**
 * Thin wrapper over the Anthropic Messages API for the one call this
 * extension makes.
 *
 * Deliberately non-streaming: this runs in a queue worker with nobody
 * watching, so there is no partial output to display and a single blocking
 * call is simpler to reason about. `max_tokens` stays well under the level
 * where the SDK would need streaming to dodge HTTP timeouts, and the request
 * timeout is raised to cover a slow thinking turn.
 *
 * No prompt caching. The system prompt is stable and would be cacheable, but
 * forum mentions arrive minutes or hours apart while the cache TTL is five
 * minutes — writes (1.25x) would almost never be read back (0.1x), so caching
 * here would cost more than it saves.
 */
final class ClaudeClient
{
    /** Wall-clock ceiling for one API call. Thinking turns can be slow. */
    private const TIMEOUT_SECONDS = 300.0;

    public function __construct(
        private readonly ApiKey $apiKey,
        private readonly SettingsRepository $settings,
        private readonly SystemPrompt $systemPrompt,
    ) {}

    /**
     * Ask the API how large the assembled prompt actually is.
     *
     * The context builder walks the thread on a deliberately pessimistic
     * character heuristic; this is the authoritative number, used to refuse
     * an over-budget request before it is billed.
     */
    public function countTokens(string $forumTitle, string $botName, string $context): int
    {
        return $this->client()->messages->countTokens(
            messages: [['role' => 'user', 'content' => $context]],
            model: $this->settings->model(),
            system: $this->systemPrompt->build($forumTitle, $botName),
            thinking: ['type' => 'adaptive'],
            requestOptions: ['timeout' => 30.0],
        )->inputTokens;
    }

    public function reply(string $forumTitle, string $botName, string $context): ReplyResult
    {
        $tools = null;

        if ($this->settings->webSearchEnabled()) {
            $tools = [
                WebSearchTool20260209::with(
                    maxUses: $this->settings->webSearchMaxUses(),
                ),
            ];
        }

        $message = $this->client()->messages->create(
            maxTokens: $this->settings->maxTokens(),
            messages: [['role' => 'user', 'content' => $context]],
            model: $this->settings->model(),
            outputConfig: ['effort' => $this->settings->effort()],
            system: $this->systemPrompt->build($forumTitle, $botName),
            thinking: ['type' => 'adaptive'],
            tools: $tools,
            requestOptions: ['timeout' => self::TIMEOUT_SECONDS],
        );

        return $this->toResult($message);
    }

    private function toResult(Message $message): ReplyResult
    {
        $usage = $message->usage;

        return new ReplyResult(
            text: $this->extractText($message),
            model: $message->model,
            stopReason: $message->stopReason,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheReadInputTokens: $usage->cacheReadInputTokens ?? 0,
            cacheCreationInputTokens: $usage->cacheCreationInputTokens ?? 0,
            webSearchRequests: $usage->serverToolUse?->webSearchRequests ?? 0,
        );
    }

    /**
     * Concatenate the text blocks.
     *
     * `content` is a heterogeneous list — with thinking on it also carries
     * thinking blocks (empty-texted by default), and with web search enabled
     * it carries server-tool-use and search-result blocks. Only `text` blocks
     * are the reply.
     */
    private function extractText(Message $message): string
    {
        $parts = [];

        foreach ($message->content as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : ($block->type ?? null);

            if ($type !== 'text') {
                continue;
            }

            $text = is_array($block) ? ($block['text'] ?? '') : ($block->text ?? '');

            if (is_string($text) && $text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode("\n", $parts));
    }

    private function client(): Client
    {
        $key = $this->apiKey->get();

        if ($key === null) {
            throw new RuntimeException('claude-reply: no Anthropic API key configured');
        }

        return new Client(apiKey: $key);
    }
}
