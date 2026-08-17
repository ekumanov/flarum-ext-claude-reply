<?php

namespace Ekumanov\ClaudeReply\Anthropic;

use Anthropic\Client;
use Anthropic\Messages\Message;
use Anthropic\Messages\WebSearchTool20260209;
use Ekumanov\ClaudeReply\Settings\ApiKey;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;

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
    /**
     * Wall-clock ceiling for one API call. Thinking turns are slow, and a turn
     * that runs a couple of web searches is slower still. Enforced by the
     * transport built in {@see transporter()} — passing it to the SDK as a
     * request option does nothing.
     */
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
            // Advisory only (see transporter()); the real ceiling is the
            // transport's. Kept so the intent is visible at the call site.
            requestOptions: ['timeout' => 30.0],
        )->inputTokens;
    }

    /**
     * The models this key can call, newest first.
     *
     * Returned as plain arrays rather than SDK objects because the only caller
     * serialises straight to JSON for the admin dropdown. `maxTokens` comes
     * along so the UI can tell an admin that their `max_tokens` exceeds what
     * the chosen model accepts, and the effort flag because not every model
     * supports the `effort` parameter this extension sends.
     *
     * @return list<array{id: string, name: string, maxTokens: int|null, maxInputTokens: int|null, effort: bool}>
     */
    public function listModels(): array
    {
        $page = $this->client()->models->list(
            limit: 100,
            requestOptions: ['timeout' => 20.0],
        );

        $models = [];

        // getItems(), not foreach: iterating a Page yields successive *pages*
        // and walks the whole cursor, which would turn one request into as many
        // as the account has models.
        foreach ($page->getItems() as $model) {
            $models[] = [
                'id' => $model->id,
                'name' => $model->displayName,
                'maxTokens' => $model->maxTokens,
                'maxInputTokens' => $model->maxInputTokens,
                'effort' => $model->capabilities?->effort->supported ?? false,
            ];
        }

        return $models;
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
     *
     * Joined with NOTHING, not a newline. A plain answer arrives as a single
     * text block, but a cited one — which is what web search produces — is
     * split at every citation boundary into contiguous prose fragments that
     * already carry their own spacing:
     *
     *     "…rated power output of " / "11 W x 2" / ", for a total of 22 watts."
     *
     * Joining those with "\n" injects line breaks mid-sentence, which Markdown
     * then renders as visibly broken text in the posted reply.
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

        return trim(implode('', $parts));
    }

    private function client(): Client
    {
        $key = $this->apiKey->get();

        if ($key === null) {
            throw new RuntimeException('claude-reply: no Anthropic API key configured');
        }

        return new Client(
            apiKey: $key,
            requestOptions: ['transporter' => $this->transporter()],
        );
    }

    /**
     * An HTTP client that will actually wait for a slow reply.
     *
     * The SDK's `timeout` request option is advisory and nothing in the SDK
     * reads it — its own docblock says so: "the timeout is enforced by the
     * caller-supplied transport". Supply no transport and PSR-18 discovery
     * picks one whose default comes from PHP's `default_socket_timeout`, which
     * ships as 60 seconds. A reply that takes longer than that dies with a
     * connection error, and this extension routinely takes longer: thinking
     * plus a couple of server-side web searches on a technical question runs
     * well past a minute. It failed at exactly 61s, twice, which is what sent
     * us looking here.
     *
     * So the transport is constructed explicitly rather than discovered. It
     * costs nothing when the request is quick, and it is the difference between
     * "web search is enabled" and "web search works".
     *
     * Falls back to raising `default_socket_timeout` for the call when neither
     * supported client is installed — cruder, and global for the process, but a
     * queue worker doing one thing at a time can live with it, and it beats
     * inheriting a minute.
     */
    private function transporter(): ?ClientInterface
    {
        $seconds = (int) ceil(self::TIMEOUT_SECONDS);

        if (class_exists(SymfonyPsr18Client::class) && class_exists(SymfonyHttpClient::class)) {
            return new SymfonyPsr18Client(SymfonyHttpClient::create([
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
            ]));
        }

        if (class_exists(GuzzleClient::class)) {
            return new GuzzleClient([
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => 10.0,
            ]);
        }

        if ((int) ini_get('default_socket_timeout') < $seconds) {
            ini_set('default_socket_timeout', (string) $seconds);
        }

        return null;
    }
}
