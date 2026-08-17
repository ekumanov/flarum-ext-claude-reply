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
