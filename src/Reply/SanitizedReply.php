<?php

namespace Ekumanov\ClaudeReply\Reply;

/**
 * A reply body after {@see MentionSanitizer} has been over it, plus what it
 * had to take out — logged so a persona prompt that keeps pushing the model
 * into inventing mentions is visible rather than silently patched over.
 */
final readonly class SanitizedReply
{
    /**
     * @param list<string> $stripped one entry per neutralised token: 'post',
     *                               'user' or 'group'
     */
    public function __construct(
        public string $text,
        public array $stripped,
    ) {}

    public function changed(): bool
    {
        return $this->stripped !== [];
    }

    /**
     * Counts by kind, for the log line.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        return array_count_values($this->stripped);
    }
}
