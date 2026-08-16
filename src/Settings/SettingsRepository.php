<?php

namespace Ekumanov\ClaudeReply\Settings;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Typed accessor for ekumanov-claude-reply.* settings.
 *
 * Defaults live here, not in a migration — no DB rows are written unless the
 * admin actively changes a value.
 *
 * The API key is deliberately NOT a setting. It is read from config.php
 * (see {@see ApiKey}), same pattern as the edge-cache Cloudflare token: the
 * settings table is Redis-cached, dumped into every backup, and rendered in
 * the admin payload for anyone with admin access.
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

    /** Username of the account Claude posts as. Mentioning it is the trigger. */
    public function botUsername(): string
    {
        $v = trim((string) ($this->settings->get(self::PREFIX.'bot_username') ?? ''));
        return $v === '' ? 'claude_user' : $v;
    }

    /**
     * User IDs allowed to trigger a reply. Empty means NOBODY — this is a
     * deliberate fail-closed default so an accidental enable can't open the
     * bot to the whole forum.
     *
     * @return list<int>
     */
    public function allowedUserIds(): array
    {
        return array_map('intval', $this->csvSetting('allowed_user_ids'));
    }

    /**
     * Tag IDs the bot will answer in. Empty means NO tag is allowed — again
     * fail-closed, so enabling the extension does not silently expose every
     * discussion on the forum to a third-party API.
     *
     * @return list<int>
     */
    public function allowedTagIds(): array
    {
        return array_map('intval', $this->csvSetting('allowed_tag_ids'));
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
     * @return list<string>
     */
    private function csvSetting(string $key): array
    {
        $raw = (string) ($this->settings->get(self::PREFIX.$key) ?? '');
        if ($raw === '') {
            return [];
        }
        $items = preg_split('/[\s,;]+/', $raw) ?: [];
        $items = array_map(fn ($s) => trim($s), $items);
        $items = array_filter($items, fn ($s) => $s !== '');
        return array_values(array_unique($items));
    }
}
