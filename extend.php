<?php

use Ekumanov\ClaudeReply\Console\TestReplyCommand;
use Ekumanov\ClaudeReply\Listener\HandleMention;
use Flarum\Extend;
use Flarum\Post\Event\Posted;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    /*
     * Only `Posted`. Hooking `Revised` as well would let a single post bill
     * repeatedly by editing the mention in and out, and an edit-triggered
     * reply lands out of order in the thread.
     */
    (new Extend\Event())
        ->listen(Posted::class, HandleMention::class),

    /*
     * Defaults live in Ekumanov\ClaudeReply\Settings\SettingsRepository, not
     * in a migration — nothing is written to the settings table until an admin
     * changes a value.
     *
     * The two that matter are fail-closed and stay that way until explicitly
     * configured: `enabled` is false, and both `allowed_user_ids` and
     * `allowed_tag_ids` are empty, which means *nobody* and *no tag*. Enabling
     * the extension therefore cannot on its own send a single post to a
     * third-party API.
     *
     * The API key is NOT a setting — see Settings\ApiKey. It comes from
     * config.php ('claude_reply' => ['api_key' => '...']).
     */

    (new Extend\Console())
        ->command(TestReplyCommand::class),
];
