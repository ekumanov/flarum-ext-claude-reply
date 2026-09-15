<?php

namespace Ekumanov\ClaudeReply\Settings;

use Ekumanov\ClaudeReply\Access\IdList;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Typed accessor for ekumanov-claude-reply.* settings.
 *
 * Defaults live here, not in a migration — no DB rows are written unless the
 * admin actively changes a value.
 *
 * The API key is a special case: config.php takes precedence over the settings
 * table, and the settings copy is masked out of the admin payload. See
 * {@see ApiKey} and {@see ApiKeyPrivacy}.
 */
final class SettingsRepository
{
    public const PREFIX = 'ekumanov-claude-reply.';

    public function __construct(private readonly SettingsRepositoryInterface $settings) {}

    /**
     * Master kill switch. When false the listener returns immediately —
     * nothing is queued, nothing is billed.
     */
    public function enabled(): bool
    {
        return $this->boolSetting('enabled', false);
    }

    /**
     * Id of the account Claude posts as, when one has been picked in the admin
     * UI. Preferred over {@see botUsername()} because it survives a rename.
     */
    public function botUserId(): ?int
    {
        $v = (int) ($this->settings->get(self::PREFIX.'bot_user_id') ?? 0);

        return $v > 0 ? $v : null;
    }

    /**
     * Username of the account Claude posts as.
     *
     * Retained as the fallback for installs configured before the admin UI
     * switched to a user picker (prod among them), and as the value shown when
     * the picked account can no longer be resolved.
     */
    public function botUsername(): string
    {
        $v = trim((string) ($this->settings->get(self::PREFIX.'bot_username') ?? ''));

        return $v === '' ? 'claude_user' : $v;
    }

    /**
     * Whether administrators skip the member/group lists and the per-member cap.
     *
     * On by default. Gating an admin achieves nothing a determined admin cannot
     * undo in the settings page thirty seconds later, and being silently
     * excluded from a feature you administer is a confusing way to find that
     * out — which is exactly how this setting came to exist.
     */
    public function adminBypassAccess(): bool
    {
        return $this->boolSetting('admin_bypass_access', true);
    }

    /**
     * Whether administrators may also summon a reply in tags the lists refuse.
     *
     * OFF by default, unlike the access bypass, because the tag lists are not
     * an access control — they are the boundary that decides which of the
     * forum's content is allowed to leave it. Denying a tag is usually a
     * statement about members' privacy rather than about who is trusted, and an
     * admin absent-mindedly mentioning the bot in a private-ish tag is precisely
     * the accident the list is there to prevent. Turn it on if you would rather
     * have the reach; it is your forum.
     */
    public function adminBypassTags(): bool
    {
        return $this->boolSetting('admin_bypass_tags', false);
    }

    /**
     * Whether the member/group lists are a blocklist rather than an allow-list.
     *
     * Off means "only those listed"; on means "everyone except those denied".
     * An explicit setting rather than something inferred from a list being
     * empty — see {@see \Ekumanov\ClaudeReply\Access\AccessResolver} for why
     * inferring it would be the dangerous choice.
     */
    public function usersBlocklistMode(): bool
    {
        return $this->boolSetting('users_blocklist_mode', false);
    }

    /** Same, for tags: off = only these tags, on = all tags except these. */
    public function tagsBlocklistMode(): bool
    {
        return $this->boolSetting('tags_blocklist_mode', false);
    }

    /**
     * Users who may (or may never) summon a reply.
     *
     * `allowed_user_ids` keeps its historical name and comma-separated format,
     * so an install configured before the picker existed keeps working
     * untouched.
     */
    public function users(): IdList
    {
        return new IdList(
            allowed: $this->idListSetting('allowed_user_ids'),
            denied: $this->idListSetting('denied_user_ids'),
        );
    }

    /** Groups whose members may (or may never) summon a reply. */
    public function groups(): IdList
    {
        return new IdList(
            allowed: $this->idListSetting('allowed_group_ids'),
            denied: $this->idListSetting('denied_group_ids'),
        );
    }

    /**
     * Tags the bot will (or will never) answer in.
     *
     * Every post in an answered discussion may be sent to Anthropic as
     * context, which is why this is opt-in per tag rather than forum-wide.
     */
    public function tags(): IdList
    {
        return new IdList(
            allowed: $this->idListSetting('allowed_tag_ids'),
            denied: $this->idListSetting('denied_tag_ids'),
        );
    }

    /**
     * Also treat a reply to, or a quote of, one of the bot's posts as a trigger.
     *
     * Off by default. Flarum's Reply arrow and Quote button insert a POST
     * mention, not a user mention, so they do not summon the bot — which
     * surprises people, hence this switch. It stays off because the surprise
     * cuts both ways: in a thread ABOUT the bot, members quote its posts to
     * discuss them, and it would barge into every one of those.
     */
    public function replyToQuotes(): bool
    {
        return $this->boolSetting('trigger_on_quote', false);
    }

    /** Model id passed to the Messages API. */
    public function model(): string
    {
        $v = trim((string) ($this->settings->get(self::PREFIX.'model') ?? ''));

        return $v === '' ? 'claude-opus-5' : $v;
    }

    /**
     * output_config.effort. Lower is cheaper and faster; forum replies rarely
     * need deep reasoning, so the default is one step below the API default.
     */
    public function effort(): string
    {
        $v = strtolower(trim((string) ($this->settings->get(self::PREFIX.'effort') ?? '')));

        return in_array($v, ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $v : 'medium';
    }

    /**
     * Hard ceiling on the response, thinking included. Claude Opus 5 thinks by
     * default, so this must leave room for both — a value sized to the visible
     * answer alone truncates mid-reply.
     */
    public function maxTokens(): int
    {
        return max(1024, $this->intSetting('max_tokens', 8000));
    }

    /**
     * Input-token budget for the assembled discussion context. The builder
     * stops walking backwards once it would exceed this.
     */
    public function contextTokenBudget(): int
    {
        return max(1000, $this->intSetting('context_token_budget', 20000));
    }

    /** Hard cap on posts pulled into context, regardless of the token budget. */
    public function maxContextPosts(): int
    {
        return max(1, $this->intSetting('max_context_posts', 25));
    }

    /** Replies per rolling 24h across the whole forum. Spend ceiling. */
    public function dailyReplyLimit(): int
    {
        return max(0, $this->intSetting('daily_reply_limit', 25));
    }

    /**
     * Replies one member may summon per rolling 24h.
     *
     * Note the asymmetry with {@see dailyReplyLimit()}, where 0 blocks
     * everything: here 0 means "no per-user limit", because this setting
     * refines an already fail-closed gate rather than forming it. Access is
     * decided by the allow-lists; this only stops one member from spending the
     * forum's whole daily budget by themselves. "Block everybody" is already
     * expressible — turn the extension off, or set the forum limit to 0.
     */
    public function perUserDailyLimit(): int
    {
        return max(0, $this->intSetting('per_user_daily_limit', 2));
    }

    /**
     * Extra guidance appended to the built-in system prompt — forum-specific
     * persona, house style, language. Admin-editable so the voice can be
     * tuned without a release.
     */
    public function personaPrompt(): string
    {
        return trim((string) ($this->settings->get(self::PREFIX.'persona_prompt') ?? ''));
    }

    /**
     * Markdown appended to every reply, e.g. an AI-disclosure line. Empty
     * disables the footer.
     */
    public function footer(): string
    {
        return trim((string) ($this->settings->get(self::PREFIX.'footer') ?? ''));
    }

    /** Let Claude run server-side web searches while composing the reply. */
    public function webSearchEnabled(): bool
    {
        return $this->boolSetting('web_search', false);
    }

    /** Max server-side searches per reply. Billed at $10/1000 searches. */
    public function webSearchMaxUses(): int
    {
        return max(1, $this->intSetting('web_search_max_uses', 5));
    }

    /**
     * Let Claude search this forum's other discussions while composing.
     *
     * Off by default, and deliberately a separate switch from web search: the
     * two have different risk shapes. Web search reads the public internet;
     * this reads the forum's own content and can quote it into a public reply,
     * so it is the one an admin should have to turn on knowingly.
     */
    public function forumSearchEnabled(): bool
    {
        return $this->boolSetting('forum_search', false);
    }

    /**
     * Max forum tool calls per reply.
     *
     * Not the same ceiling as the loop's own call bound — that one exists to
     * stop a runaway turn, this one to stop a thorough one from costing four
     * times what it should. Each call resends the whole conversation, so the
     * marginal search is more expensive than the one before it.
     */
    public function forumSearchMaxUses(): int
    {
        return max(1, $this->intSetting('forum_search_max_uses', 4));
    }

    /**
     * Discussions listed per forum search.
     *
     * Twelve rather than eight because relevance decay turns out to depend on
     * the topic, not on the rank: a narrow query is noise by rank five, but a
     * subject the forum has worked over for years stays on-topic past rank
     * fifteen, and eight cut those off mid-seam. Each result costs ~90 tokens,
     * so the four extra are cheap insurance. Going much higher is not free in
     * quality terms — on a narrow query the tail is padding, and padding is
     * what the model has to rerank through.
     */
    public function forumSearchResults(): int
    {
        return max(1, min(20, $this->intSetting('forum_search_results', 12)));
    }

    /**
     * Posts returned when the model opens one discussion.
     *
     * Split between the start and the end of the discussion rather than spent
     * on the start alone — on a long thread the conclusion is at the far end,
     * and no amount of this budget spent at the opening will reach it. See
     * {@see \Ekumanov\ClaudeReply\Search\ForumSearch::readDiscussion()}.
     */
    public function forumSearchPostsRead(): int
    {
        return max(1, min(50, $this->intSetting('forum_search_posts_read', 15)));
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $v = $this->settings->get(self::PREFIX.$key);

        if ($v === null || $v === '') {
            return $default;
        }

        return (bool) $v && $v !== '0';
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get(self::PREFIX.$key);

        return $v === null || $v === '' ? $default : (int) $v;
    }

    /**
     * Parse a comma-separated id list.
     *
     * Tolerant of whatever separators an admin (or an older hand-edited value)
     * left behind, and drops anything that is not a positive integer so a
     * stray word can never widen or narrow a list by accident.
     *
     * @return list<int>
     */
    private function idListSetting(string $key): array
    {
        $raw = (string) ($this->settings->get(self::PREFIX.$key) ?? '');

        if (trim($raw) === '') {
            return [];
        }

        $items = preg_split('/[\s,;]+/', $raw) ?: [];

        $ids = [];

        foreach ($items as $item) {
            $item = trim($item);

            if ($item === '' || ! ctype_digit($item)) {
                continue;
            }

            $id = (int) $item;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
