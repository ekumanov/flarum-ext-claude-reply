<?php

// Adds the index behind the per-user reply cap.
//
// The cap is enforced — and, more importantly, *displayed* — by counting a
// member's rows in the last 24 hours:
//
//   where trigger_user_id = ? and created_at >= ? and status <> 'skipped'
//
// The existing `created_at` index alone makes that a range scan over every reply
// the forum has generated in the window, filtered afterwards by user. That is
// nothing on a table with a few hundred rows, but this query also runs on the
// forum payload for allow-listed members — once per page load — so it is worth
// the composite index rather than relying on the table staying small forever.
//
// Column order matters: the equality predicate first, the range second, which
// lets MySQL seek straight to one member's window instead of scanning the whole
// window and discarding other people's rows.

use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_claude_replies')
            || $schema->hasIndex('ekumanov_claude_replies', 'claude_replies_user_window')) {
            return;
        }

        $schema->table('ekumanov_claude_replies', function (Blueprint $table) {
            $table->index(['trigger_user_id', 'created_at'], 'claude_replies_user_window');
        });
    },

    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_claude_replies')
            || ! $schema->hasIndex('ekumanov_claude_replies', 'claude_replies_user_window')) {
            return;
        }

        $schema->table('ekumanov_claude_replies', function (Blueprint $table) {
            $table->dropIndex('claude_replies_user_window');
        });
    },
];
