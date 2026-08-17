<?php

namespace Ekumanov\ClaudeReply\Settings;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Resolves the Anthropic API key.
 *
 * Precedence, highest first:
 *
 *   1. config.php  →  'claude_reply' => ['api_key' => 'sk-ant-...']
 *   2. ANTHROPIC_API_KEY in the environment
 *   3. the settings table (ekumanov-claude-reply.api_key)
 *
 * config.php stays on top because it is the safest of the three and because an
 * install already configured that way must not change behaviour when a key is
 * later typed into the admin UI.
 *
 * The settings-table option exists for portability: not everyone installing
 * from Packagist has shell access to edit config.php, and refusing to work
 * without it is a poor trade for a self-hosted forum. It is genuinely the
 * weaker option though, and worth being precise about why:
 *
 *   - Flarum's AdminPayload dumps the ENTIRE settings table into the admin
 *     page's HTML, so a key stored there is readable in the DOM by any admin
 *     and by any other extension's admin JavaScript. {@see ApiKeyPrivacy}
 *     closes that specific hole by masking the value before it is serialised.
 *   - It is still cached in Redis (fof/redis caches the whole settings table)
 *     and still present in every database dump, which config.php values are
 *     not. That part cannot be mitigated from inside the extension, so the
 *     admin help text says so plainly.
 */
final class ApiKey
{
    public const SETTING = SettingsRepository::PREFIX.'api_key';

    public function __construct(
        private readonly Config $config,
        private readonly SettingsRepositoryInterface $settings,
    ) {}

    public function get(): ?string
    {
        $section = $this->config['claude_reply'] ?? null;

        if (is_array($section)) {
            $key = trim((string) ($section['api_key'] ?? ''));

            if ($key !== '') {
                return $key;
            }
        }

        $env = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));

        if ($env !== '') {
            return $env;
        }

        $stored = trim((string) ($this->settings->get(self::SETTING) ?? ''));

        return $stored === '' ? null : $stored;
    }

    public function isConfigured(): bool
    {
        return $this->get() !== null;
    }

    /**
     * Where the active key came from — for `claude-reply:test` diagnostics and
     * the admin UI, which tells the admin that a config.php key is overriding
     * whatever is in the settings field.
     */
    public function source(): ?string
    {
        $section = $this->config['claude_reply'] ?? null;

        if (is_array($section) && trim((string) ($section['api_key'] ?? '')) !== '') {
            return 'config.php';
        }

        if (trim((string) (getenv('ANTHROPIC_API_KEY') ?: '')) !== '') {
            return 'environment';
        }

        if (trim((string) ($this->settings->get(self::SETTING) ?? '')) !== '') {
            return 'settings';
        }

        return null;
    }
}
