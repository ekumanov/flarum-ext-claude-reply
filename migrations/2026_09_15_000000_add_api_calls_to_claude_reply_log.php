<?php

// Records how many Messages API calls one reply actually cost.
//
// It was one, always, until pause_turn handling landed: with web search
// enabled the API can suspend a long-running turn and wait to be handed it
// back, and resuming it is a second billed call carrying the whole
// conversation again. The token columns beside this one are sums over the
// turn, so without a call count there is nothing in the ledger to explain why
// a reply's input tokens are three times its context — the row looks like a
// mispriced single call rather than a turn that paused twice.
//
// Nullable with no default rather than `default(1)`: rows written before this
// column existed were not counted, and claiming they were one call would be a
// guess. It happens to be true for all of them, but the ledger should not
// invent history it did not record.

use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_claude_replies')
            || $schema->hasColumn('ekumanov_claude_replies', 'api_calls')) {
            return;
        }

        $schema->table('ekumanov_claude_replies', function (Blueprint $table) {
            $table->unsignedSmallInteger('api_calls')->nullable();
        });
    },

    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_claude_replies')
            || ! $schema->hasColumn('ekumanov_claude_replies', 'api_calls')) {
            return;
        }

        $schema->table('ekumanov_claude_replies', function (Blueprint $table) {
            $table->dropColumn('api_calls');
        });
    },
];
