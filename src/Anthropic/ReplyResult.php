<?php

namespace Ekumanov\ClaudeReply\Anthropic;

/**
 * What came back from one Messages API call, flattened to what we persist.
 */
final readonly class ReplyResult
{
    public function __construct(
        public string $text,
        public string $model,
        public ?string $stopReason,
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
}
