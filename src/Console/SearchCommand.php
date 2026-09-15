<?php

namespace Ekumanov\ClaudeReply\Console;

use Ekumanov\ClaudeReply\Search\ForumSearch;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Illuminate\Console\Command;

/**
 * Dry-run harness for retrieval: `php flarum claude-reply:search <query>`.
 *
 * Prints exactly what {@see ForumSearch} would hand the model for a query, and
 * with `--read` what one discussion looks like when opened. No API call, no
 * token spent — the same "tune first, spend second" loop `claude-reply:test`
 * gives the context builder.
 *
 * It is also the way to answer the question an admin will actually have, which
 * is not "does search work" but **"what can the bot see?"**. Run it as a
 * sanity check after changing the tag rules: anything printed here can end up
 * quoted into a public reply, and anything missing is something the bot cannot
 * cite no matter how relevant it is.
 */
class SearchCommand extends Command
{
    protected $signature = 'claude-reply:search
                            {query* : Search terms}
                            {--read= : Instead of searching, open this discussion id as the model would}
                            {--exclude=0 : Pretend the bot is replying in this discussion id, which is always excluded}';

    protected $description = 'Preview what the forum-search tools would return for a query — no API call, nothing billed.';

    public function handle(ForumSearch $search, SettingsRepository $settings): int
    {
        $exclude = (int) $this->option('exclude');

        if (! $settings->forumSearchEnabled()) {
            $this->warn('Forum search is disabled in settings — this preview still runs, but the bot would not.');
            $this->newLine();
        }

        $read = $this->option('read');

        if ($read !== null && $read !== '') {
            $this->info('=== forum_read_discussion('.(int) $read.') ===');
            $this->line($search->readDiscussion((int) $read, $exclude));

            return 0;
        }

        $query = implode(' ', (array) $this->argument('query'));

        $this->info('=== forum_search("'.$query.'") ===');
        $this->line($search->search($query, $exclude));
        $this->newLine();
        $this->line('Visible as: a logged-out guest, intersected with the tag rules.');
        $this->line('Anything absent here is something the bot cannot cite.');

        return 0;
    }
}
