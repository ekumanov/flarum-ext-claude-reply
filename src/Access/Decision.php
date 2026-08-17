<?php

namespace Ekumanov\ClaudeReply\Access;

/**
 * The outcome of one gate check: a verdict plus the reason behind it.
 */
final readonly class Decision
{
    private function __construct(
        public bool $allowed,
        public Reason $reason,
    ) {}

    public static function allow(Reason $reason): self
    {
        return new self(true, $reason);
    }

    public static function deny(Reason $reason): self
    {
        return new self(false, $reason);
    }
}
