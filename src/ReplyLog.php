<?php

namespace Ekumanov\ClaudeReply;

use Flarum\Database\AbstractModel;

/**
 * One row per attempted reply — audit trail and spend ledger.
 *
 * @property int $id
 * @property int $discussion_id
 * @property int $trigger_post_id
 * @property int $trigger_user_id
 * @property int|null $reply_post_id
 * @property string $status
 * @property string|null $error
 * @property string|null $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $cache_read_input_tokens
 * @property int|null $cache_creation_input_tokens
 * @property int|null $web_search_requests
 * @property int|null $api_calls
 * @property int|null $context_posts
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $completed_at
 */
class ReplyLog extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_POSTED  = 'posted';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'ekumanov_claude_replies';

    public $timestamps = false;

    protected $casts = [
        'created_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];
}
