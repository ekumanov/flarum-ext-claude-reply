<?php

namespace Ekumanov\ClaudeReply\Access;

/**
 * The allow/deny precedence rules. Pure functions over plain id arrays — no
 * settings, no models, no database — so the matrix below can be exercised
 * directly.
 *
 * ## Who may summon a reply
 *
 * Four lists are in play: allowed users, denied users, allowed groups, denied
 * groups. They are resolved strictly in that order and the first list that
 * matches decides:
 *
 *   1. denied users   → NO
 *   2. allowed users  → YES
 *   3. denied groups  → NO
 *   4. allowed groups → YES
 *   5. nothing matched → NO
 *
 * Two principles fall out of that ordering, and they are the ones worth
 * remembering:
 *
 * **Deny beats allow within a category.** Listing the same id in both lists is
 * a deny — step 1 runs before step 2. This is what makes a deny list safe to
 * reach for in a hurry: adding someone can never be undone by an allow entry
 * you forgot about.
 *
 * **The user level beats the group level.** A user entry — either kind —
 * settles the question before groups are consulted at all. So a member of an
 * allowed group who is individually denied is refused, and a member of a
 * denied group who is individually allowed is permitted. That is the useful
 * direction of precedence: the broad stroke is the group, the exception is the
 * person.
 *
 * **Empty allow lists mean nobody.** Step 5 is a deny, so a configuration with
 * nothing allowed refuses everyone no matter what the deny lists say — which
 * also means a deny-only configuration is still a closed door, not an open one.
 * That is deliberate and it is the same fail-closed default the extension has
 * always had: enabling it cannot, by itself, start sending posts to a
 * third-party API. A deny list is a subtractive override on top of an
 * allow-list, never a standalone policy.
 *
 * ## Which discussions
 *
 * Tags have no user/group-style hierarchy, so there are two lists and the same
 * deny-first ordering:
 *
 *   1. any of the discussion's tags is denied → NO
 *   2. any of the discussion's tags is allowed → YES
 *   3. otherwise → NO
 *
 * Deny is evaluated across the whole tag set rather than per tag, so a
 * discussion tagged both `General` (allowed) and `Politics` (denied) is
 * refused. With mixed tags the cautious reading is the only defensible one:
 * the content of that discussion is what would be sent to the API, and one
 * denied tag is enough to say it should not be.
 *
 * Parent tags need no special handling. flarum/tags attaches the parent
 * whenever a child is selected, so a discussion in a child tag genuinely
 * carries the parent in its tag set — denying a parent denies its children,
 * and allowing a parent allows them, through the data rather than through a
 * rule here.
 */
final class AccessResolver
{
    /**
     * @param int       $userId
     * @param list<int> $groupIds groups the user belongs to
     */
    public static function forUser(int $userId, array $groupIds, IdList $users, IdList $groups): Decision
    {
        return self::atUserLevel($userId, $users)
            ?? self::atGroupLevel($groupIds, $groups);
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
     * Steps 3 to 5 — the group entries, and the fail-closed default.
     *
     * @param list<int> $groupIds
     */
    public static function atGroupLevel(array $groupIds, IdList $groups): Decision
    {
        if (array_intersect($groupIds, $groups->denied) !== []) {
            return Decision::deny(Reason::GroupDenied);
        }

        if (array_intersect($groupIds, $groups->allowed) !== []) {
            return Decision::allow(Reason::GroupAllowed);
        }

        return Decision::deny(Reason::NotListed);
    }

    /**
     * @param list<int> $tagIds the discussion's tags
     */
    public static function forTags(array $tagIds, IdList $tags): Decision
    {
        if ($tagIds === []) {
            return Decision::deny(Reason::NoTags);
        }

        if (array_intersect($tagIds, $tags->denied) !== []) {
            return Decision::deny(Reason::TagDenied);
        }

        if (array_intersect($tagIds, $tags->allowed) !== []) {
            return Decision::allow(Reason::TagAllowed);
        }

        return Decision::deny(Reason::TagNotListed);
    }
}
