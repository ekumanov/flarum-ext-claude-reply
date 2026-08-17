<?php

namespace Ekumanov\ClaudeReply\Access;

use Carbon\Carbon;
use Ekumanov\ClaudeReply\ReplyLog;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\User\User;

/**
 * Rolling 24-hour usage counting, forum-wide and per member.
 *
 * Both caps are enforced by counting audit rows in the window rather than by
 * keeping a counter: a worker restart, a deploy or a queue drain must not hand
 * anyone a fresh allowance. `skipped` rows are excluded because nothing was
 * billed for them — a trigger post that was deleted before the worker got to
 * it should not cost the member one of their replies.
 *
 * The per-user figure is also what the forum payload exposes so the composer
 * can warn *before* a post is submitted. That makes this the one query on the
 * page-load path, which is why it is a single indexed COUNT and why it is only
 * ever asked for on behalf of a member who has already passed the user gate.
 */
final class ReplyQuota
{
    /**
     * Lets a group past the per-user cap. Granted to nobody by default; Flarum
     * admins are exempt implicitly, as they are from every permission.
     */
    public const BYPASS_PERMISSION = 'ekumanov-claude-reply.bypass-user-limit';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** Forum-wide replies used in the last 24 hours. */
    public function forumUsed(): int
    {
        return $this->countSince(null);
    }

    /** Replies this member has summoned in the last 24 hours. */
    public function userUsed(int $userId): int
    {
        return $this->countSince($userId);
    }

    /**
     * How many more replies this member may summon, or null when no per-user
     * limit applies to them — either because there is no limit configured, or
     * because they may bypass it.
     */
    public function userRemaining(User $actor): ?int
    {
        $limit = $this->settings->perUserDailyLimit();

        if ($limit <= 0 || $this->mayBypass($actor)) {
            return null;
        }

        return max(0, $limit - $this->userUsed((int) $actor->id));
    }

    /**
     * Whether this member is exempt from the per-user cap.
     *
     * Scoped to the per-user cap on purpose. That cap is a fairness device —
     * it stops one member spending the forum's whole day of replies before
     * anyone else gets a turn — and a moderator answering support questions has
     * a fair claim to be outside it. The forum-wide cap is a different animal:
     * it is the only absolute ceiling on what this extension can spend, and a
     * permission that could lift it would mean no ceiling at all. So it applies
     * to everyone, bypass or not, admin or not.
     *
     * Flarum admins pass `hasPermission()` unconditionally, so they are exempt
     * without being granted anything. Every other group starts with no bypass:
     * there is no migration seeding a default, which keeps this consistent with
     * the rest of the extension's fail-closed posture.
     */
    public function mayBypass(User $actor): bool
    {
        return $actor->hasPermission(self::BYPASS_PERMISSION);
    }

    /**
     * Deny with the reason that applies, or allow. Checks the member's own cap
     * before the forum's so the message they can be shown is the accurate one.
     */
    public function check(User $actor): Decision
    {
        $perUser = $this->settings->perUserDailyLimit();

        if ($perUser > 0 && ! $this->mayBypass($actor) && $this->userUsed((int) $actor->id) >= $perUser) {
            return Decision::deny(Reason::UserQuota);
        }

        $forum = $this->settings->dailyReplyLimit();

        // 0 blocks everything forum-wide — the documented meaning of the
        // setting, and unlike the per-user limit it is not "unlimited".
        if ($forum <= 0 || $this->forumUsed() >= $forum) {
            return Decision::deny(Reason::ForumQuota);
        }

        return Decision::allow(Reason::Ok);
    }

    /**
     * Count what was actually spent, not what was attempted.
     *
     * `skipped` never counted — nothing reached the API. Failures need the same
     * treatment, but only some of them: a job that died before the API answered
     * (a network error, a timeout) cost nothing and must not consume a member's
     * daily allowance, whereas one that failed *after* a response — a refusal,
     * an empty reply — was billed and must. Recorded token usage is what tells
     * the two apart.
     *
     * Not academic: two transport failures in a row took a member from 0 to
     * their 2-a-day limit without a single token being billed, and left them
     * unable to try again.
     */
    private function countSince(?int $userId): int
    {
        $query = ReplyLog::query()
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->where('status', '!=', ReplyLog::STATUS_SKIPPED)
            ->where(function ($q) {
                $q->where('status', '!=', ReplyLog::STATUS_FAILED)
                    ->orWhereNotNull('input_tokens');
            });

        if ($userId !== null) {
            $query->where('trigger_user_id', $userId);
        }

        return $query->count();
    }
}
