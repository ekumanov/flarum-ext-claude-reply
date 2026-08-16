<?php

namespace Ekumanov\ClaudeReply\Settings;

use Flarum\Foundation\Config;

/**
 * Resolves the Anthropic API key.
 *
 * Deliberately NOT a row in the settings table. Precedence:
 *
 *   1. config.php  →  'claude_reply' => ['api_key' => 'sk-ant-...']
 *   2. ANTHROPIC_API_KEY in the environment
 *
 * config.php is the established place for third-party secrets on this stack
 * (the edge-cache Cloudflare token lives there too): it is not Redis-cached,
 * not returned in the admin payload, and not carried in a settings-table dump.
 */
final class ApiKey
{
    public function __construct(private readonly Config $config) {}

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

        return $env === '' ? null : $env;
    }

    public function isConfigured(): bool
    {
        return $this->get() !== null;
    }
}
