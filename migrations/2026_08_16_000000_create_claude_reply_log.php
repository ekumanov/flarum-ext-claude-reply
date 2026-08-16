<?php

// Creates the single table this extension owns:
//
//   ekumanov_claude_replies — one row per attempted reply. Doubles as the
//                             audit trail (who triggered what, in which
//                             discussion) and the spend ledger (per-call
//                             token counts, so cost can be reconciled against
//                             the Anthropic console without guesswork).
//
// It is also the enforcement surface for the rolling 24h reply cap: the
// listener counts rows in the window rather than trusting an in-memory
// counter that a worker restart would reset.
//
// Rows are written in `pending` state by the listener BEFORE the job is
// queued, so a job that dies mid-flight still leaves a trace. The job
// transitions the row to `posted` or `failed`.
//
// Idempotent create; `down` is a real drop — unlike cached previews, losing
// this table costs only history, and leaving an orphan table behind on
// uninstall would be untidy.

use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        if ($schema->hasTable('ekumanov_claude_replies')) {
            return;
        }

        $schema->create('ekumanov_claude_replies', function (Blueprint $table) {
            $table->increments('id');

            // What triggered this.
            $table->unsignedInteger('discussion_id');
            $table->unsignedInteger('trigger_post_id');
            $table->unsignedInteger('trigger_user_id');

            // What came out. Null until the job succeeds.
            $table->unsignedInteger('reply_post_id')->nullable();

            // pending | posted | failed | skipped
            $table->string('status', 16)->default('pending');
            $table->string('error', 255)->nullable();

            // Spend ledger. Populated from the API response `usage` block.
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_input_tokens')->nullable();
            $table->unsignedInteger('cache_creation_input_tokens')->nullable();
            $table->unsignedInteger('web_search_requests')->nullable();

            // How much of the thread we actually sent.
            $table->unsignedSmallInteger('context_posts')->nullable();

            $table->timestamp('created_at');
            $table->timestamp('completed_at')->nullable();

            // The daily-cap query is `where created_at >= ? and status <> 'skipped'`.
            $table->index('created_at');
            $table->index(['discussion_id', 'created_at']);
        });
    },

    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        $schema->dropIfExists('ekumanov_claude_replies');
    },
];
