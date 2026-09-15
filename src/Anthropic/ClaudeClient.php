<?php

namespace Ekumanov\ClaudeReply\Anthropic;

use Anthropic\Client;
use Anthropic\Messages\Message;
use Anthropic\Messages\ToolUseBlock;
use Anthropic\Messages\WebSearchTool20260209;
use Ekumanov\ClaudeReply\Search\ForumTools;
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
 * One reply is often more than one call. Two things extend a turn, and both
 * are continuations of the same turn rather than new ones: `pause_turn`, where
 * the API suspended a long-running turn and waits to be handed it back, and
 * `tool_use`, where it is waiting on a forum lookup this process has to
 * perform. {@see reply()} owns that loop and its bounds.
 *
 * Caching is scoped to a single turn, never across replies. Across replies it
 * would lose money: forum mentions arrive minutes or hours apart, far outside
 * the five-minute TTL, so the 1.25x write would almost never be read back at
 * 0.1x. Within a turn the arithmetic inverts — the calls are seconds apart and
 * the prefix is identical because we only append — so the context carries a
 * breakpoint whenever a tool could extend the turn. {@see firstMessage()}.
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

    /**
     * Most API calls one reply may cost, continuations included.
     *
     * A backstop, not the working limit. The budget that should actually
     * govern a thorough reply is `forum_search_max_uses`, and at 4 this bound
     * silently overruled it: a search, a read and a search is three lookups
     * and four calls, so the fourth request tripped this before the setting
     * ever applied, and raising the setting above three did nothing at all.
     *
     * Six leaves room for the configured number of lookups plus the call that
     * writes the answer, which puts the setting back in charge and leaves
     * TOTAL_BUDGET_SECONDS as the bound that really protects the queue — a
     * wall clock is a truer measure of a runaway turn than a call count.
     */
    private const MAX_CALLS = 6;

    public function __construct(
        private readonly ApiKey $apiKey,
        private readonly SettingsRepository $settings,
        private readonly SystemPrompt $systemPrompt,
        private readonly ForumTools $forumTools,
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
     * Generate one reply, continuing the turn for as long as it needs.
     *
     * Neither `pause_turn` nor `tool_use` is a finished response. The first
     * means the API suspended a long-running turn and will resume it if handed
     * the assistant content straight back; the second means it is waiting on a
     * forum lookup only this process can do. Treating either as final
     * publishes whatever preamble had been written first, which is typically a
     * sentence of throat-clearing rather than an answer.
     *
     * Continuations are bounded three ways — call count, total wall clock, and
     * a floor under what is left — because either can repeat. The two stop
     * reasons part company at the bound: a paused turn can simply be abandoned
     * and reported unfinished, but a turn waiting on tools cannot, because the
     * API requires a `tool_result` for every `tool_use` block. Those calls are
     * declined and the turn gets one final call to answer without them.
     *
     * Where the turn is still unfinished at the end, `stopReason` says so and
     * the caller refuses to publish it — see {@see ReplyResult::isIncomplete()}.
     */
    public function reply(string $forumTitle, string $botName, string $context, int $discussionId): ReplyResult
    {
        $tools      = [];
        $forumTools = $this->forumTools->enabled();

        if ($this->settings->webSearchEnabled()) {
            $tools[] = WebSearchTool20260209::with(
                maxUses: $this->settings->webSearchMaxUses(),
            );
        }

        if ($forumTools) {
            foreach ($this->forumTools->definitions() as $definition) {
                $tools[] = $definition;
            }
        }

        $system   = $this->systemPrompt->build($forumTitle, $botName, $forumTools);
        $messages = [['role' => 'user', 'content' => $this->firstMessage($context, $tools !== [])]];
        $deadline = microtime(true) + self::TOTAL_BUDGET_SECONDS;

        /** @var list<string> Every text fragment of the turn, in order. */
        $parts = [];

        $calls         = 0;
        $inputTokens   = 0;
        $outputTokens  = 0;
        $cacheRead     = 0;
        $cacheCreation = 0;
        $webSearches   = 0;
        $forumSearches = 0;

        while (true) {
            $timeout = min(self::TIMEOUT_SECONDS, max(1.0, $deadline - microtime(true)));

            $message = $this->client($timeout)->messages->create(
                maxTokens: $this->settings->maxTokens(),
                messages: $messages,
                model: $this->settings->model(),
                outputConfig: ['effort' => $this->settings->effort()],
                system: $system,
                thinking: ['type' => 'adaptive'],
                tools: $tools === [] ? null : $tools,
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

            $continuing = $message->stopReason === 'pause_turn' || $message->stopReason === 'tool_use';

            if (! $continuing) {
                break;
            }

            if ($calls >= self::MAX_CALLS
                || $deadline - microtime(true) < self::MIN_CONTINUATION_SECONDS) {
                // Out of budget. A paused turn is simply unfinished, but a
                // turn waiting on tools cannot be left that way: the API
                // requires a result for every tool_use block, so the calls are
                // answered with a refusal and the turn is given one last
                // chance to write an answer without them.
                if ($message->stopReason !== 'tool_use') {
                    break;
                }

                $refusals = $this->refuseCalls($message);

                if ($refusals === []) {
                    break;
                }

                $messages[] = ['role' => 'assistant', 'content' => $message->content];
                $messages[] = ['role' => 'user', 'content' => $refusals];

                $lastChance = min(self::TIMEOUT_SECONDS, max(30.0, $deadline - microtime(true)));

                $message = $this->client($lastChance)->messages->create(
                    maxTokens: $this->settings->maxTokens(),
                    messages: $messages,
                    model: $this->settings->model(),
                    outputConfig: ['effort' => $this->settings->effort()],
                    system: $system,
                    thinking: ['type' => 'adaptive'],
                    tools: $tools === [] ? null : $tools,
                    requestOptions: ['timeout' => $lastChance],
                );

                $calls++;
                $inputTokens   += $message->usage->inputTokens;
                $outputTokens  += $message->usage->outputTokens;
                $cacheRead     += $message->usage->cacheReadInputTokens ?? 0;
                $cacheCreation += $message->usage->cacheCreationInputTokens ?? 0;
                $webSearches   += $message->usage->serverToolUse?->webSearchRequests ?? 0;

                foreach ($this->textParts($message) as $part) {
                    $parts[] = $part;
                }

                break;
            }

            // Hand the turn back. For a pause that is the assistant content
            // verbatim — anything dropped or reordered reads to the model as
            // an edit of its own output. For tool use it is that same content
            // followed by one user message carrying a result for EVERY
            // tool_use block; splitting them across messages, or omitting one,
            // is a protocol error.
            $messages[] = ['role' => 'assistant', 'content' => $message->content];

            if ($message->stopReason === 'tool_use') {
                $results = [];

                foreach ($this->toolCalls($message) as $block) {
                    // Counted only when actually performed: a refused call
                    // costs a round trip but reads nothing, and the ledger
                    // column means "lookups done", not "lookups asked for".
                    if ($forumSearches >= $this->settings->forumSearchMaxUses()) {
                        $results[] = [
                            'type' => 'tool_result',
                            'toolUseID' => $block->id,
                            'content' => 'You have used the forum tools as many times as this reply '
                                .'allows. Answer with what you already have.',
                        ];

                        continue;
                    }

                    $results[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $block->id,
                        'content' => $this->forumTools->run($block->name, $block->input, $discussionId),
                    ];

                    $forumSearches++;
                }

                if ($results === []) {
                    // stop_reason said tool_use but nothing in the content was
                    // a call we own. Continuing would send a user message with
                    // no results, which the API rejects — stop instead and let
                    // the caller see the unfinished stop reason.
                    array_pop($messages);
                    break;
                }

                $messages[] = ['role' => 'user', 'content' => $results];
            }
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
            forumSearches: $forumSearches,
        );
    }

    /**
     * The opening user message, with a cache breakpoint when tools are on.
     *
     * The class docblock explains why nothing is cached *between* replies. A
     * single reply is a different matter once tools exist: a turn that
     * searches, reads and then answers is three calls seconds apart, each
     * resending the whole context, and the prefix is identical every time
     * because we only ever append. Writing the breakpoint costs 1.25x once and
     * every later call in the turn reads it at 0.1x, so it pays for itself
     * from the second call onward and loses about a quarter of the context's
     * input cost on a reply that turns out to need only one.
     *
     * Hence the condition: breakpoint only when some tool could extend the
     * turn. With no tools at all a reply is always one call and the write
     * would be pure loss. `api_calls` in the ledger is what says whether that
     * bet is paying off in practice.
     *
     * @return string|list<array<string, mixed>>
     */
    private function firstMessage(string $context, bool $cacheable): string|array
    {
        if (! $cacheable) {
            return $context;
        }

        return [[
            'type' => 'text',
            'text' => $context,
            'cacheControl' => ['type' => 'ephemeral'],
        ]];
    }

    /**
     * The tool calls in a message that this extension is responsible for.
     *
     * Server-side tools arrive as `server_tool_use` blocks and are Anthropic's
     * to run, not ours; answering one would be wrong. Matching on the block
     * type and then on our own tool names keeps the two apart even if a future
     * server tool starts sharing the block type.
     *
     * @return list<ToolUseBlock>
     */
    private function toolCalls(Message $message): array
    {
        $names = [ForumTools::SEARCH, ForumTools::READ];
        $calls = [];

        foreach ($message->content as $block) {
            if ($block instanceof ToolUseBlock && in_array($block->name, $names, true)) {
                $calls[] = $block;
            }
        }

        return $calls;
    }

    /**
     * Results that decline every pending call, for the out-of-budget path.
     *
     * @return list<array<string, mixed>>
     */
    private function refuseCalls(Message $message): array
    {
        $results = [];

        foreach ($this->toolCalls($message) as $block) {
            $results[] = [
                'type' => 'tool_result',
                'toolUseID' => $block->id,
                'content' => 'This reply has run out of time for forum lookups. '
                    .'Write your answer now with what you already know.',
            ];
        }

        return $results;
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
