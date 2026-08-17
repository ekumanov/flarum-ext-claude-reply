<?php

namespace Ekumanov\ClaudeReply\Access;

/**
 * One allow/deny pair for a single category (users, groups or tags).
 *
 * Kept as a plain value object with no settings dependency so the precedence
 * rules in {@see AccessResolver} stay pure and testable in isolation — the
 * whole point of splitting them out is that the interesting logic can be
 * exercised without a database, a container or a settings table.
 */
final readonly class IdList
{
    /**
     * @param list<int> $allowed
     * @param list<int> $denied
     */
    public function __construct(
        public array $allowed = [],
        public array $denied = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->allowed === [] && $this->denied === [];
    }
}
