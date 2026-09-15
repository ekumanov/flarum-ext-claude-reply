<?php

// Records how many forum lookups one reply performed.
//
// Sits beside `web_search_requests`, which counts the server-side kind, and is
// kept separate rather than summed into it because the two have different
// costs and different consequences. A web search is billed per search and
// reads the public internet; a forum lookup is free to perform but each one
// adds a round trip that resends the whole conversation, and it reads this
// forum's own content — which is what makes it worth being able to audit.
//
// Between this and `api_calls` a row explains its own token count: n lookups
// implies n extra calls, and a reply whose input tokens look wrong can be read
// rather than guessed at.

use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_claude_replies')
            || $schema->hasColumn('ekumanov_claude_replies', 'forum_searches')) {
            return;
        }

        $schema->table('ekumanov_claude_replies', function (Blueprint $table) {
            $table->unsignedSmallInteger('forum_searches')->nullable();
        });
    },

    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_claude_replies')
            || ! $schema->hasColumn('ekumanov_claude_replies', 'forum_searches')) {
            return;
        }

        $schema->table('ekumanov_claude_replies', function (Blueprint $table) {
            $table->dropColumn('forum_searches');
        });
    },
];
