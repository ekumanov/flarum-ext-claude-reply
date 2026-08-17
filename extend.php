<?php

use Ekumanov\ClaudeReply\Api\Controller\ListModelsController;
use Ekumanov\ClaudeReply\Api\ForumAttributes;
use Ekumanov\ClaudeReply\Console\TestReplyCommand;
use Ekumanov\ClaudeReply\Listener\HandleMention;
use Ekumanov\ClaudeReply\Settings\ApiKey;
use Ekumanov\ClaudeReply\Settings\ApiKeyPrivacy;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
use Flarum\Post\Event\Posted;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    /*
     * The forum bundle exists for one thing: warning a member, before they
     * submit, that their post will not get a reply because they are out of
     * quota. It bails on the first line unless the payload carries the quota
     * block, which it only does for a signed-in member who has passed the
     * user/group gate — so for guests and ordinary members it costs a property
     * read. See ForumAttributes.
     */
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    new Extend\Locales(__DIR__.'/locale'),

    /*
     * Only `Posted`. Hooking `Revised` as well would let a single post bill
     * repeatedly by editing the mention in and out, and an edit-triggered
     * reply lands out of order in the thread.
     */
    (new Extend\Event())
        ->listen(Posted::class, HandleMention::class)
        ->subscribe(ApiKeyPrivacy::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(ForumAttributes::class),

    (new Extend\Routes('api'))
        ->get('/claude-reply/models', 'claude-reply.models', ListModelsController::class),

    /*
     * Emptying the API key field deletes the row rather than storing an empty
     * string, so "remove the key" is expressible through the same field that
     * sets it. (Not reaching this at all is the normal case: an untouched
     * masked field is never submitted, because Flarum posts only modified
     * settings.)
     */
    (new Extend\Settings())
        ->resetWhen(ApiKey::SETTING, fn ($value) => trim((string) $value) === ''),

    /*
     * Defaults live in Ekumanov\ClaudeReply\Settings\SettingsRepository, not
     * in a migration — nothing is written to the settings table until an admin
     * changes a value.
     *
     * The gate is fail-closed and stays that way until explicitly configured:
     * `enabled` is false, and every allow-list is empty, which means *nobody*
     * and *no tag*. Deny lists are subtractive overrides on top of those
     * allow-lists, never a policy of their own — see Access\AccessResolver for
     * the full precedence rules. Enabling the extension therefore cannot on its
     * own send a single post to a third-party API.
     *
     * The API key may come from config.php, the environment, or the settings
     * table, in that order — see Settings\ApiKey for why that order, and
     * Settings\ApiKeyPrivacy for how the settings copy is kept out of the
     * admin page's HTML.
     */

    (new Extend\Console())
        ->command(TestReplyCommand::class),
];
