<?php

namespace Ekumanov\ClaudeReply\Access;

use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\User\User;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $log,
    ) {}

    /** May this member summon a reply at all? */
    public function user(User $actor): Decision
    {
        if ($actor->isAdmin() && $this->settings->adminBypassAccess()) {
            return Decision::allow(Reason::AdminBypass);
        }

        $decision = AccessResolver::atUserLevel((int) $actor->id, $this->settings->users());

        // Settled by the member's own entry — their groups are irrelevant, so
        // do not pay a query to load them.
        if ($decision !== null) {
            return $decision;
        }

        $groups = $this->settings->groups();
        $blocklist = $this->settings->usersBlocklistMode();

        // No group lists configured: nothing for the group step to match, so
        // the answer is whichever default the mode calls for and the member's
        // groups need not be loaded at all.
        if ($groups->isEmpty()) {
            return AccessResolver::atGroupLevel([], $groups, $blocklist);
        }

        return AccessResolver::atGroupLevel($this->groupIds($actor), $groups, $blocklist);
    }

    /**
     * May the bot answer in this discussion?
     *
     * The tag lists are the only per-discussion consent boundary the extension
     * has, so in allow-list mode a discussion the lists do not cover is
     * refused — including every discussion on a forum with tags disabled.
     */
    public function discussion(object $discussion, ?User $actor = null): Decision
    {
        if ($actor?->isAdmin() && $this->settings->adminBypassTags()) {
            return Decision::allow(Reason::AdminBypass);
        }

        $blocklist = $this->settings->tagsBlocklistMode();

        if (! $this->extensions->isEnabled('flarum-tags')) {
            // Without tags there is no allow-list to satisfy — but equally
            // nothing to exclude, so a blocklist has no objection either.
            return $blocklist
                ? Decision::allow(Reason::NotDenied)
                : Decision::deny(Reason::TagsDisabled);
        }

        try {
            $tagIds = $discussion->tags->pluck('id')->map('intval')->all();
        } catch (Throwable) {
            return Decision::deny(Reason::NoTags);
        }

        return AccessResolver::forTags($tagIds, $this->settings->tags(), $blocklist);
    }

    /**
     * Exposed for `claude-reply:test`, which prints the resolved set so an
     * empty one is visible rather than merely implied by a DENY.
     *
     * @return list<int>
     */
    public function groupIdsFor(User $actor): array
    {
        return $this->groupIds($actor);
    }

    /**
     * The groups the member's permissions are drawn from.
     *
     * Not simply `$actor->groups`, and the difference is not cosmetic: Flarum's
     * Member group is implicit — confirmed users have no `group_user` row for it
     * — so reading the relation alone would silently never match an admin who
     * picked "Members" as an allowed group.
     *
     * Two ways to get the full set, because core moved it. 2.0.0-rc.6 exposes
     * `permissionGroupIds()`; rc.5 has the identical logic inlined in
     * `permissions()` with no accessor to call. Preferring the method where it
     * exists means group processors — extensions that grant groups dynamically —
     * are honoured on the versions that can do so, and the fallback reproduces
     * core's own rc.5 body for the rest.
     *
     * This is a bug fix, and worth being blunt about how it failed: calling the
     * rc.6 method on rc.5 raises BadMethodCallException, which a blanket
     * `catch (Throwable)` here turned into "this member is in no groups". The
     * result was fail-closed, so nothing leaked — but every group allow/deny
     * rule silently matched nobody, with no error anywhere to explain why.
     * Hence the narrow catch and the log line below: a gate that cannot resolve
     * its inputs must say so, not quietly deny.
     *
     * It costs nothing extra in a real request: any permission check on the
     * actor has already resolved and cached the relation.
     *
     * @return list<int>
     */
    private function groupIds(User $actor): array
    {
        try {
            if (method_exists($actor, 'permissionGroupIds')) {
                return $this->normalise($actor->permissionGroupIds());
            }

            $ids = [Group::GUEST_ID];

            if ($actor->is_email_confirmed) {
                $ids = array_merge($ids, [Group::MEMBER_ID], $actor->groups->pluck('id')->all());
            }

            return $this->normalise($ids);
        } catch (Throwable $e) {
            $this->log->error('claude-reply: could not resolve group membership — group rules will not match', [
                'user_id' => $actor->id,
                'err' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  iterable<mixed> $ids
     * @return list<int>
     */
    private function normalise(iterable $ids): array
    {
        $out = [];

        foreach ($ids as $id) {
            $out[] = (int) $id;
        }

        return array_values(array_unique($out));
    }
}
