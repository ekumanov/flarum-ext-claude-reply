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
 * Thin wrapper over the Anthropic Messages API for the one reply this
 * extension generates.
 *
 * Deliberately non-streaming: this runs in a queue worker with nobody
 * watching, so there is no partial output to display and blocking calls are
 * simpler to reason about. `max_tokens` stays well under the level where the
 * SDK would need streaming to dodge HTTP timeouts, and the request timeout is
 * raised to cover a slow thinking turn.
 *
 * One reply is usually one call, but not always: with web search enabled the
 * API may end a response with `pause_turn`, meaning it suspended a
 * long-running turn partway through and is waiting to be handed it back. That
 * is a continuation of the same turn, not a new one — see {@see reply()}.
 *
 * No prompt caching. The system prompt is stable and would be cacheable, but
 * forum mentions arrive minutes or hours apart while the cache TTL is five
 * minutes — writes (1.25x) would almost never be read back (0.1x), so caching
 * here would cost more than it saves. (A paused turn resumes within seconds,
 * so continuations *would* read a cache back; it is only worth the breakpoint
 * if they ever become common, which so far they are not.)
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

    /**
     * Wall-clock ceiling for the whole exchange, continuations included.
     *
     * Has to leave room inside GenerateReplyJob's own 420s timeout for the
     * token count, the context assembly and the publish. Without this a turn
     * that paused twice could spend 3x TIMEOUT_SECONDS and be killed by the
     * queue mid-publish, which is the one failure mode that can double-post.
     */
    private const TOTAL_BUDGET_SECONDS = 340.0;

    /**
     * Never start a continuation with less than this much budget left — an
     * API call given ten seconds is a wasted API call.
     */
    private const MIN_CONTINUATION_SECONDS = 45.0;

    /** Most API calls one reply may cost, continuations included. */
    private const MAX_CALLS = 4;

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
        // The SDK's request option is advisory (see transporter()), so the
        // same figure goes to the transport, which is what actually enforces
        // it. This one has to stay short: it is spent before the reply budget
        // starts, and both together must fit inside the queue job's timeout.
        $timeout = 30.0;

        return $this->client($timeout)->messages->countTokens(
            messages: [['role' => 'user', 'content' => $context]],
            model: $this->settings->model(),
            system: $this->systemPrompt->build($forumTitle, $botName),
            thinking: ['type' => 'adaptive'],
            requestOptions: ['timeout' => $timeout],
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
        $timeout = 20.0;

        $page = $this->client($timeout)->models->list(
            limit: 100,
            requestOptions: ['timeout' => $timeout],
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

    /**
     * Generate one reply, continuing the turn if the API pauses it.
     *
     * A `pause_turn` stop reason is not a finished response. It means the API
     * suspended a long-running turn — in practice, one that is working through
     * server-side web searches — and the turn resumes by sending the assistant
     * content straight back. Treating it as final publishes whatever preamble
     * had been written before the pause, which is typically a sentence of
     * throat-clearing rather than an answer.
     *
     * Continuations are bounded three ways (call count, total wall clock, and
     * a floor under what is left) because the pause can in principle repeat.
     * When a bound is hit we stop and return with `stopReason` still set to
     * `pause_turn`, which is how the caller tells an unfinished turn apart
     * from a finished one — see {@see ReplyResult::isIncomplete()}.
     */
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

        $system   = $this->systemPrompt->build($forumTitle, $botName);
        $messages = [['role' => 'user', 'content' => $context]];
        $deadline = microtime(true) + self::TOTAL_BUDGET_SECONDS;

        /** @var list<string> Every text fragment of the turn, in order. */
        $parts = [];

        $calls         = 0;
        $inputTokens   = 0;
        $outputTokens  = 0;
        $cacheRead     = 0;
        $cacheCreation = 0;
        $webSearches   = 0;

        while (true) {
            $timeout = min(self::TIMEOUT_SECONDS, max(1.0, $deadline - microtime(true)));

            $message = $this->client($timeout)->messages->create(
                maxTokens: $this->settings->maxTokens(),
                messages: $messages,
                model: $this->settings->model(),
                outputConfig: ['effort' => $this->settings->effort()],
                system: $system,
                thinking: ['type' => 'adaptive'],
                tools: $tools,
                requestOptions: ['timeout' => $timeout],
            );

            $calls++;

            // Usage is per call, so every counter is a running total. The
            // spend ledger has to bill the whole turn, not just its last leg.
            $usage = $message->usage;

            $inputTokens   += $usage->inputTokens;
            $outputTokens  += $usage->outputTokens;
            $cacheRead     += $usage->cacheReadInputTokens ?? 0;
            $cacheCreation += $usage->cacheCreationInputTokens ?? 0;
            $webSearches   += $usage->serverToolUse?->webSearchRequests ?? 0;

            foreach ($this->textParts($message) as $part) {
                $parts[] = $part;
            }

            if ($message->stopReason !== 'pause_turn') {
                break;
            }

            if ($calls >= self::MAX_CALLS
                || $deadline - microtime(true) < self::MIN_CONTINUATION_SECONDS) {
                break;
            }

            // Hand the paused turn back verbatim. Anything dropped or
            // reordered here reads to the model as an edit of its own output.
            $messages[] = ['role' => 'assistant', 'content' => $message->content];
        }

        return new ReplyResult(
            text: trim(implode('', $parts)),
            model: $message->model,
            stopReason: $message->stopReason,
            apiCalls: $calls,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheReadInputTokens: $cacheRead,
            cacheCreationInputTokens: $cacheCreation,
            webSearchRequests: $webSearches,
        );
    }

    /**
     * The text blocks of one message, in order, neither trimmed nor joined.
     *
     * `content` is a heterogeneous list — with thinking on it also carries
     * thinking blocks (empty-texted by default), and with web search enabled
     * it carries server-tool-use and search-result blocks. Only `text` blocks
     * are the reply.
     *
     * The caller joins these with NOTHING, not a newline. A plain answer
     * arrives as a single text block, but a cited one — which is what web
     * search produces — is split at every citation boundary into contiguous
     * prose fragments that already carry their own spacing:
     *
     *     "…rated power output of " / "11 W x 2" / ", for a total of 22 watts."
     *
     * Joining those with "\n" injects line breaks mid-sentence, which Markdown
     * then renders as visibly broken text in the posted reply.
     *
     * Which is also why the parts are returned raw and the trim happens once,
     * after the whole turn is assembled: a paused turn splits the reply across
     * several messages, and that seam lands mid-sentence exactly as a citation
     * boundary does. Trimming per message would eat the space either side of
     * it and fuse two words together.
     *
     * @return list<string>
     */
    private function textParts(Message $message): array
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

        return $parts;
    }

    private function client(float $timeoutSeconds): Client
    {
        $key = $this->apiKey->get();

        if ($key === null) {
            throw new RuntimeException('claude-reply: no Anthropic API key configured');
        }

        return new Client(
            apiKey: $key,
            requestOptions: ['transporter' => $this->transporter($timeoutSeconds)],
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
     *
     * Built per call rather than once, because the ceiling is not the same for
     * every request: a token count wants seconds, a reply wants minutes, and a
     * continuation wants only what is left of the turn's budget.
     */
    private function transporter(float $timeoutSeconds): ?ClientInterface
    {
        $seconds = (int) ceil($timeoutSeconds);

        if (class_exists(SymfonyPsr18Client::class) && class_exists(SymfonyHttpClient::class)) {
            return new SymfonyPsr18Client(SymfonyHttpClient::create([
                'timeout' => $timeoutSeconds,
                'max_duration' => $timeoutSeconds,
            ]));
        }

        if (class_exists(GuzzleClient::class)) {
            return new GuzzleClient([
                'timeout' => $timeoutSeconds,
                'connect_timeout' => 10.0,
            ]);
        }

        if ((int) ini_get('default_socket_timeout') < $seconds) {
            ini_set('default_socket_timeout', (string) $seconds);
        }

        return null;
    }
}
