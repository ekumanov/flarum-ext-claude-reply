<?php

namespace Ekumanov\ClaudeReply;

use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\User\User;

/**
 * Resolves the forum account Claude speaks as.
 *
 * The account is an ordinary user — it needs `reply` permission in the tags it
 * answers in, and it must not be in a group whose posts land in the approval
 * queue, or replies will silently never appear.
 *
 * Resolution is memoised per process: the listener asks on every post, and a
 * queue worker handling a burst would otherwise re-query for each one.
 */
final class BotAccount
{
    private ?User $cached = null;
    private bool $resolved = false;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function get(): ?User
    {
        if ($this->resolved) {
            return $this->cached;
        }

        $this->resolved = true;
        $this->cached = User::query()
            ->where('username', $this->settings->botUsername())
            ->first();

        return $this->cached;
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }
}
