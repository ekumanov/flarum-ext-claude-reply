<?php

namespace Ekumanov\ClaudeReply\Anthropic;

/**
 * What came back from one generated reply, flattened to what we persist.
 *
 * Usually one Messages API call, but a paused turn takes more than one (see
 * {@see ClaudeClient::reply()}). Where that happens the token counts are sums
 * over the whole turn — billing is per call — while `stopReason` and `model`
 * come from the last call, which is the one that ended it.
 */
final readonly class ReplyResult
{
    public function __construct(
        public string $text,
        public string $model,
        public ?string $stopReason,
        /** API calls this reply cost. More than one means the turn paused. */
        public int $apiCalls,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadInputTokens,
        public int $cacheCreationInputTokens,
        public int $webSearchRequests,
    ) {}

    public function isRefusal(): bool
    {
        return $this->stopReason === 'refusal';
    }

    /** The answer was cut off by max_tokens — worth flagging in the log. */
    public function isTruncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }

    /**
     * The model never finished its turn, so `text` is not a reply.
     *
     * `pause_turn` means the API suspended a long-running turn and we ran out
     * of continuation budget before it resumed; `model_context_window_exceeded`
     * means it ran out of room to finish. Either way what we hold is whatever
     * had been written by the time it stopped — for a paused turn, typically a
     * line of preamble before the first web search. Unlike a `max_tokens`
     * truncation, which at least cuts off a real answer, this must not be
     * published.
     */
    public function isIncomplete(): bool
    {
        return in_array($this->stopReason, ['pause_turn', 'model_context_window_exceeded'], true);
    }
}
