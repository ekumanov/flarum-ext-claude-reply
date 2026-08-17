<?php

namespace Ekumanov\ClaudeReply\Access;

/**
 * The allow/deny precedence rules. Pure functions over plain id arrays — no
 * settings, no models, no database — so the matrix below can be exercised
 * directly.
 *
 * There are two independent questions, and each has its own mode:
 *
 *   WHO may summon a reply  — members and groups, resolved together
 *   WHERE it may reply      — tags
 *
 * ## Allow-list mode (the default)
 *
 * Four lists are in play for WHO: allowed members, denied members, allowed
 * groups, denied groups. They are resolved strictly in that order and the first
 * list that matches decides:
 *
 *   1. denied members  → NO
 *   2. allowed members → YES
 *   3. denied groups   → NO
 *   4. allowed groups  → YES
 *   5. nothing matched → NO
 *
 * Two principles fall out of that ordering, and they are the ones worth
 * remembering:
 *
 * **Deny beats allow within a level.** Listing the same id in both lists is a
 * deny — step 1 runs before step 2. This is what makes a deny list safe to
 * reach for in a hurry: adding someone can never be undone by an allow entry
 * you forgot about.
 *
 * **The member level beats the group level.** A member entry — either kind —
 * settles the question before groups are consulted at all. So a member of an
 * allowed group who is individually denied is refused, and a member of a denied
 * group who is individually allowed is permitted. That is the useful direction
 * of precedence: the broad stroke is the group, the exception is the person.
 *
 * **An empty *members* list does not mean nobody.** It means that level had
 * nothing to say, and the question passes down to groups — which is why
 * allowing a group works perfectly well with both member lists empty. Only step
 * 5, where nothing at either level matched, is a refusal.
 *
 * ## Blocklist mode
 *
 * Flipping a category to blocklist mode changes exactly one thing: step 5
 * becomes YES. Every precedence rule above still applies, so the deny lists keep
 * working the same way and individual entries still beat group ones — the
 * difference is only what happens to somebody no list mentions.
 *
 * It is a separate, explicit setting rather than something inferred from a list
 * being empty. Inferring it was considered and rejected: it would make the same
 * empty field mean "nobody" or "everybody" depending on a neighbouring field,
 * and it would fail *open* on a config edit — adding one denied tag would
 * silently expose every other tag on the forum.
 *
 * ## Tags
 *
 * Same two modes, minus the hierarchy:
 *
 *   1. any of the discussion's tags is denied → NO
 *   2. any of the discussion's tags is allowed → YES
 *   3. otherwise → NO in allow-list mode, YES in blocklist mode
 *
 * Deny is evaluated across the whole tag set rather than per tag, so a
 * discussion tagged both `General` (allowed) and `Politics` (denied) is refused.
 * With mixed tags the cautious reading is the only defensible one: the content
 * of that discussion is what would be sent to the API, and one denied tag is
 * enough to say it should not be.
 *
 * Parent tags need no special handling. flarum/tags attaches the parent whenever
 * a child is selected, so a discussion in a child tag genuinely carries the
 * parent in its tag set — denying a parent denies its children, and allowing a
 * parent allows them, through the data rather than through a rule here.
 */
final class AccessResolver
{
    /**
     * @param int       $userId
     * @param list<int> $groupIds groups the user belongs to
     * @param bool      $blocklistMode allow anyone no list mentions
     */
    public static function forUser(
        int $userId,
        array $groupIds,
        IdList $users,
        IdList $groups,
        bool $blocklistMode = false,
    ): Decision {
        return self::atUserLevel($userId, $users)
            ?? self::atGroupLevel($groupIds, $groups, $blocklistMode);
    }

    /**
     * Steps 1 and 2 — the member's own entries. Null when neither list mentions
     * them and the question passes down to their groups.
     *
     * Split out so the caller can avoid loading the member's groups at all when
     * this settles it: resolving groups costs a query, and a denied member
     * should not pay for one to be turned away.
     */
    public static function atUserLevel(int $userId, IdList $users): ?Decision
    {
        if (in_array($userId, $users->denied, true)) {
            return Decision::deny(Reason::UserDenied);
        }

        if (in_array($userId, $users->allowed, true)) {
            return Decision::allow(Reason::UserAllowed);
        }

        return null;
    }

    /**
     * Steps 3 to 5 — the group entries, and whichever default the mode calls for.
     *
     * @param list<int> $groupIds
     */
    public static function atGroupLevel(array $groupIds, IdList $groups, bool $blocklistMode = false): Decision
    {
        if (array_intersect($groupIds, $groups->denied) !== []) {
            return Decision::deny(Reason::GroupDenied);
        }

        if (array_intersect($groupIds, $groups->allowed) !== []) {
            return Decision::allow(Reason::GroupAllowed);
        }

        return $blocklistMode
            ? Decision::allow(Reason::NotDenied)
            : Decision::deny(Reason::NotListed);
    }

    /**
     * @param list<int> $tagIds the discussion's tags
     */
    public static function forTags(array $tagIds, IdList $tags, bool $blocklistMode = false): Decision
    {
        if ($tagIds === []) {
            // A discussion with no tags at all cannot satisfy an allow-list, but
            // in blocklist mode there is nothing to exclude it either.
            return $blocklistMode
                ? Decision::allow(Reason::NotDenied)
                : Decision::deny(Reason::NoTags);
        }

        if (array_intersect($tagIds, $tags->denied) !== []) {
            return Decision::deny(Reason::TagDenied);
        }

        if (array_intersect($tagIds, $tags->allowed) !== []) {
            return Decision::allow(Reason::TagAllowed);
        }

        return $blocklistMode
            ? Decision::allow(Reason::NotDenied)
            : Decision::deny(Reason::TagNotListed);
    }
}
