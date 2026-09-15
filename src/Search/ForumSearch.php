<?php

namespace Ekumanov\ClaudeReply\Search;

use Ekumanov\ClaudeReply\Access\AccessResolver;
use Ekumanov\ClaudeReply\Context\PostText;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Search\SearchCriteria;
use Flarum\Search\SearchManager;
use Flarum\User\Guest;
use Throwable;

/**
 * Searches the rest of the forum on Claude's behalf.
 *
 * ## Who this runs as, and why it is not the person asking
 *
 * Everything reachable from here can be quoted into a reply that is published
 * publicly in the thread — read by members, by logged-out visitors, and served
 * from Cloudflare's edge cache for up to a day. So the right visibility scope
 * is not "what the member who asked can see". It is **what the least
 * privileged reader of the answer can see**, which is a guest.
 *
 * The failure that rules out the obvious alternative: a moderator asks a
 * question in a public thread, the search runs as them, finds a staff-only
 * discussion about a moderation decision, and the bot summarises it into a
 * reply anyone can read. Nobody did anything wrong at any step, and the leak
 * is public and cached.
 *
 * Guest scoping is applied two different ways, because the two entry points
 * reach discussions differently:
 *
 *   - {@see search()} passes a {@see Guest} as the search criteria's actor,
 *     which is what the searcher scopes its query by.
 *   - {@see readDiscussion()} is a lookup by id, so the searcher never sees
 *     it. It must scope explicitly with `whereVisibleTo`, or the model could
 *     read any discussion by guessing — or by using an id it saw quoted in a
 *     member's post.
 *
 * ## The tag list on top of that
 *
 * Guest visibility alone is not sufficient, and the reason is visible in the
 * live configuration: of the tags this forum denies the bot, four are
 * restricted (so a guest is already excluded) but one is merely *hidden*,
 * which is a listing property, not a permission. So both halves are load
 * bearing. The tag decision reuses {@see AccessResolver::forTags()} — the same
 * consent boundary that decides whether the bot may post in a tag decides
 * whether it may draw on one. Blocklist mode and deny-list precedence come
 * along for free rather than being reimplemented here and drifting.
 *
 * ## Output
 *
 * Both methods return plain text destined for a `tool_result` block, and both
 * report their own failures as text rather than throwing: a search that falls
 * over should cost the model a retry or a hedge, not kill a reply that was
 * otherwise fine.
 *
 * Results carry **links, never mention tokens**. A `@"Name"#p123` pointing at
 * another discussion would notify somebody who is not in this conversation,
 * which the system prompt forbids, and would then be stripped by
 * {@see \Ekumanov\ClaudeReply\Reply\MentionSanitizer} anyway — it validates
 * tokens against the assembled context, which search results are not part of.
 */
final class ForumSearch
{
    /**
     * Ask the searcher for more hits than we will return.
     *
     * The tag filter runs after the search, so without headroom a query whose
     * top hits all sit in denied tags comes back empty while relevant public
     * threads sat at rank nine.
     */
    private const OVERFETCH = 4;

    private const SNIPPET_CHARS = 260;

    public function __construct(
        private readonly SearchManager $search,
        private readonly SettingsRepository $settings,
        private readonly ExtensionManager $extensions,
        private readonly UrlGenerator $url,
    ) {}

    /**
     * Headlines for discussions matching a query.
     *
     * Deliberately not full posts: the model picks from these and then asks
     * for the one it wants via {@see readDiscussion()}. Returning bodies here
     * would spend the whole context on the first search.
     */
    public function search(string $query, int $excludeDiscussionId): string
    {
        $query = trim($query);

        if ($query === '') {
            return 'Empty query, nothing searched. Pass some search terms.';
        }

        $limit = $this->settings->forumSearchResults();

        try {
            $results = $this->search->query(
                Discussion::class,
                new SearchCriteria(
                    actor: new Guest(),
                    filters: ['q' => $query],
                    limit: $limit * self::OVERFETCH,
                    // Relevance ordering is not the default — it has to be
                    // asked for. FulltextFilter registers its scoring as the
                    // state's *default sort*, and AbstractSearcher::applySort()
                    // only reaches for that when `sortIsDefault` is true, which
                    // is core's way of saying "the caller expressed no
                    // preference, so rank these". Leave it false, as the
                    // parameter default does, and the ranking is silently
                    // discarded: results come back in table order, which looks
                    // like working search right up until you compare it with
                    // the forum's own.
                    sortIsDefault: true,
                ),
            )->getResults();

            // One query per relation instead of one per result. The tag filter
            // and the snippet both reach through a relation on every hit, and
            // with a dozen results over-fetched fourfold that is a hundred
            // stray queries on a page the member is waiting for.
            $results->load(['user', 'firstPost', 'tags']);
        } catch (Throwable $e) {
            return 'The forum search failed: '.$e->getMessage();
        }

        $lines = [];

        foreach ($results as $discussion) {
            if (count($lines) >= $limit) {
                break;
            }

            if ((int) $discussion->id === $excludeDiscussionId || ! $this->tagsAllow($discussion)) {
                continue;
            }

            $lines[] = $this->headline($discussion);
        }

        if ($lines === []) {
            return "No other discussions on this forum matched \"{$query}\". "
                .'Try different words, or answer without a forum citation.';
        }

        return "Forum search results for \"{$query}\":\n\n".implode("\n\n", $lines);
    }

    /**
     * The beginning AND the end of one discussion.
     *
     * Reading the first N posts is the obvious implementation and it is wrong
     * for this forum. Measured over the public discussions: 45% run to ten
     * posts or fewer, but 23% pass thirty, and those are the owners' clubs and
     * the long-running debates — exactly the threads somebody means when they
     * ask what the community concluded. On a 207-post thread started in 2021,
     * the first ten posts are 2021, and so are the first twenty; the answer to
     * "what did people settle on" is at the far end. A bigger N buys more of
     * the opening and never reaches it.
     *
     * So the budget is split: half the head, half the tail, and a marker
     * between them saying how much was skipped. The head frames what the
     * thread is about and the tail is where it got to. The marker matters as
     * much as the posts — without it the model reads a 2021 opening followed
     * by a 2026 reply as one continuous conversation, which is how you get
     * confident nonsense about who said what to whom.
     *
     * Short discussions are returned whole and carry no marker, which is the
     * common case.
     */
    public function readDiscussion(int $discussionId, int $excludeDiscussionId): string
    {
        if ($discussionId === $excludeDiscussionId) {
            return 'That is the discussion you are already replying in. Its posts are in your context above.';
        }

        try {
            /** @var Discussion|null $discussion */
            $discussion = Discussion::whereVisibleTo(new Guest())->with('tags')->find($discussionId);
        } catch (Throwable $e) {
            return 'Could not open that discussion: '.$e->getMessage();
        }

        if ($discussion === null) {
            return "There is no publicly readable discussion with id {$discussionId}.";
        }

        if (! $this->tagsAllow($discussion)) {
            return "Discussion {$discussionId} is in a tag this bot is not permitted to draw on.";
        }

        $max = $this->settings->forumSearchPostsRead();

        // Counted through the same guest scope as the posts themselves, not
        // from `comment_count`: that column counts everything, so a thread with
        // hidden posts would report a gap that is not there.
        $readable = fn () => $discussion->posts()
            ->whereVisibleTo(new Guest())
            ->where('type', 'comment')
            ->whereNull('hidden_at');

        try {
            $total = (int) $readable()->count();

            if ($total <= $max) {
                $head = $readable()->orderBy('number')->with('user')->get();
                $tail = null;
                $omitted = 0;
            } else {
                $headSize = (int) ceil($max / 2);
                $tailSize = $max - $headSize;

                $head = $readable()->orderBy('number')->with('user')->limit($headSize)->get();

                $tail = $tailSize > 0
                    ? $readable()->orderByDesc('number')->with('user')->limit($tailSize)->get()->reverse()->values()
                    : null;

                $omitted = $total - $head->count() - ($tail?->count() ?? 0);
            }
        } catch (Throwable $e) {
            return 'Could not read that discussion: '.$e->getMessage();
        }

        if ($head->isEmpty()) {
            return "Discussion {$discussionId} has no publicly readable posts.";
        }

        $out = [
            '# '.$discussion->title,
            $this->link($discussion).' — '.$total.' posts'
                .($discussion->created_at !== null ? ', started '.$discussion->created_at->toDateString() : ''),
            '',
        ];

        foreach ($head as $post) {
            $out[] = $this->renderPost($post);
        }

        if ($omitted > 0) {
            // "further down the thread", not "later in time": posts are
            // ordered by number, which is thread order, and a moved or
            // merged post can carry a date out of step with its neighbours.
            $out[] = "[… {$omitted} post(s) omitted — the discussion continues further down the thread …]";
        }

        foreach ($tail ?? [] as $post) {
            $out[] = $this->renderPost($post);
        }

        return implode("\n\n", $out);
    }

    private function renderPost(object $post): string
    {
        $author = $post->user?->display_name ?? '[deleted user]';
        $date   = $post->created_at?->toDateString() ?? '';
        $body   = $this->clamp(PostText::of($post), self::SNIPPET_CHARS * 4);

        return "## Post #{$post->number} — {$author}, {$date}\n{$body}";
    }

    /**
     * One search result.
     *
     * The starter's name earns its place: without it the model can only infer
     * whose thread this is from whose argument it finds inside, and it will —
     * a live reply described a thread as "PASHKULI's light-action thread" when
     * peterws had started it and PASHKULI had merely replied. The substance was
     * right and the possessive was wrong, which is exactly the error a named
     * member notices. The reader labels every post with its author; a citation
     * made from a headline alone had nothing to go on.
     */
    private function headline(Discussion $discussion): string
    {
        $bits = [
            'id '.$discussion->id,
            ($discussion->comment_count ?? 0).' posts',
        ];

        if ($discussion->created_at !== null) {
            $starter = $discussion->user?->display_name;

            $bits[] = 'started '.$discussion->created_at->toDateString()
                .($starter !== null ? ' by '.$starter : '');
        }

        if ($discussion->last_posted_at !== null) {
            $bits[] = 'last reply '.$discussion->last_posted_at->toDateString();
        }

        $line = '**'.$discussion->title.'** ('.implode(', ', $bits).")\n".$this->link($discussion);

        $snippet = $this->snippet($discussion);

        return $snippet === '' ? $line : $line."\n".$snippet;
    }

    private function snippet(Discussion $discussion): string
    {
        try {
            $first = $discussion->firstPost;

            if ($first === null) {
                return '';
            }

            return $this->clamp(PostText::of($first), self::SNIPPET_CHARS);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Collapse to a single line and cut at a word boundary.
     *
     * Newlines matter here: a snippet that keeps them would let a quoted post
     * inside a search result open what looks like a new section of the tool
     * output, which is a cheap way for forum content to impersonate structure
     * the model is meant to trust.
     */
    private function clamp(string $text, int $chars): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= $chars) {
            return $text;
        }

        $cut   = mb_substr($text, 0, $chars);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $chars * 0.6 ? mb_substr($cut, 0, $space) : $cut).'…';
    }

    private function link(Discussion $discussion): string
    {
        try {
            return $this->url->to('forum')->route('discussion', ['id' => $discussion->id]);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The same tag consent boundary the trigger gate applies, on a result.
     *
     * With tags disabled there is no allow-list to satisfy and no deny-list to
     * violate, so this mirrors the gate: allowed only in blocklist mode.
     */
    private function tagsAllow(Discussion $discussion): bool
    {
        $blocklist = $this->settings->tagsBlocklistMode();

        if (! $this->extensions->isEnabled('flarum-tags')) {
            return $blocklist;
        }

        try {
            $tagIds = $discussion->tags->pluck('id')->map('intval')->all();
        } catch (Throwable) {
            return false;
        }

        return AccessResolver::forTags($tagIds, $this->settings->tags(), $blocklist)->allowed;
    }
}
