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
 * Resolution prefers the id picked in the admin UI and falls back to the
 * username setting, which is both how installs configured before the picker
 * existed keep working and how a fresh install with neither set still finds a
 * conventionally-named `claude_user`. An id is the better key because renaming
 * the account no longer breaks the trigger.
 *
 * Memoised per process: the listener asks on every candidate post, and a queue
 * worker handling a burst would otherwise re-query for each one.
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

        $id = $this->settings->botUserId();

        if ($id !== null) {
            $this->cached = User::query()->find($id);

            if ($this->cached !== null) {
                return $this->cached;
            }
        }

        $this->cached = User::query()
            ->where('username', $this->settings->botUsername())
            ->first();

        return $this->cached;
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    /** The account's actual username, for the client-side mention check. */
    public function username(): string
    {
        return (string) ($this->get()?->username ?? $this->settings->botUsername());
    }
}
