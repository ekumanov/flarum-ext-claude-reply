<?php

namespace Ekumanov\ClaudeReply\Access;

use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Throwable;

/**
 * Applies {@see AccessResolver} to real Flarum models.
 *
 * Kept separate from the resolver so the precedence rules stay free of models
 * and settings, and separate from the listener so the same decisions can be
 * reached from three places without drifting: the post listener, the forum
 * payload that feeds the composer warning, and `claude-reply:test`.
 */
final class TriggerGate
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ExtensionManager $extensions,
    ) {}

    /** May this member summon a reply at all? */
    public function user(User $actor): Decision
    {
        $decision = AccessResolver::atUserLevel((int) $actor->id, $this->settings->users());

        // Settled by the member's own entry — their groups are irrelevant, so
        // do not pay a query to load them.
        if ($decision !== null) {
            return $decision;
        }

        $groups = $this->settings->groups();

        // No group lists configured: nothing for the group step to match, and
        // the answer is the fail-closed default either way. Most forums (prod
        // among them) run member lists only, so this keeps the common path
        // query-free too.
        if ($groups->isEmpty()) {
            return AccessResolver::atGroupLevel([], $groups);
        }

        return AccessResolver::atGroupLevel($this->groupIds($actor), $groups);
    }

    /**
     * May the bot answer in this discussion?
     *
     * With flarum-tags disabled there are no tags to satisfy an allow-list
     * with, so nothing is answerable. That is intentional: the tag lists are
     * the only per-discussion consent boundary the extension has.
     */
    public function discussion(object $discussion): Decision
    {
        if (! $this->extensions->isEnabled('flarum-tags')) {
            return Decision::deny(Reason::TagsDisabled);
        }

        try {
            $tagIds = $discussion->tags->pluck('id')->map('intval')->all();
        } catch (Throwable) {
            return Decision::deny(Reason::NoTags);
        }

        return AccessResolver::forTags($tagIds, $this->settings->tags());
    }

    /**
     * The groups the member's permissions are drawn from.
     *
     * `permissionGroupIds()`, not `$actor->groups`, and the difference is not
     * cosmetic: Flarum's Member group is implicit — confirmed users are not
     * rows in `group_user` — so reading the relation alone would silently never
     * match an admin who picked "Members" as an allowed group. This is also the
     * set that extensions' group processors contribute to, so a group granted
     * dynamically behaves the same here as it does for permissions.
     *
     * It costs nothing extra in a real request: any permission check on the
     * actor has already resolved and cached it.
     *
     * @return list<int>
     */
    private function groupIds(User $actor): array
    {
        try {
            return array_values(array_unique(array_map('intval', $actor->permissionGroupIds())));
        } catch (Throwable) {
            return [];
        }
    }
}
