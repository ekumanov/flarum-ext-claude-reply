<?php

namespace Ekumanov\ClaudeReply\Context;

use Ekumanov\ClaudeReply\BotAccount;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Collection;
use s9e\TextFormatter\Utils;
use Throwable;

/**
 * Assembles the discussion context for one reply.
 *
 * Strategy — "pinned head, greedy tail":
 *
 *   1. The opening post is ALWAYS included. It frames what the thread is
 *      about, and a late reply usually still needs it.
 *   2. Any post the trigger explicitly quotes or post-mentions is force-
 *      included regardless of position — that is the strongest available
 *      signal about what the question is actually referring to.
 *   3. Everything else is a greedy walk backwards from the trigger, stopping
 *      at whichever of the token budget / post cap is hit first.
 *
 * Whole-thread-always is the wrong default: on a 300-post thread it is
 * expensive and the middle is mostly noise. The gap marker in
 * {@see DiscussionContext::render()} tells the model it is not seeing
 * everything, which is what stops it inventing continuity across the hole.
 */
final class ContextBuilder
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ExtensionManager $extensions,
        private readonly BotAccount $bot,
    ) {}

    public function build(CommentPost $trigger): DiscussionContext
    {
        $discussion = $trigger->discussion;
        $budget     = $this->settings->contextTokenBudget();
        $maxPosts   = $this->settings->maxContextPosts();

        // The discussion's real comment numbers, ascending. Doubles as the
        // total and as the yardstick the gap markers are measured against —
        // subtracting one post number from another is not the same thing once
        // posts have been moved or deleted.
        $allNumbers = $discussion->comments()
            ->orderBy('number')
            ->pluck('number')
            ->map('intval')
            ->all();

        $totalComments = count($allNumbers);

        /** @var Collection<int, Post> $candidates newest first */
        $candidates = $discussion->comments()
            ->where('number', '<=', $trigger->number)
            ->orderByDesc('number')
            ->with(['user', 'mentionsUsers'])
            ->limit($maxPosts)
            ->get();

        /** @var array<int, ContextPost> keyed by post number */
        $selected = [];
        $tokens   = 64; // header block

        foreach ($candidates as $post) {
            $ctx = $this->toContextPost($post, $discussion->first_post_id, $trigger->id);

            // The trigger itself is non-negotiable even if it alone blows the
            // budget — a reply without the question is worthless.
            $isTrigger = $post->id === $trigger->id;

            if (! $isTrigger && $tokens + $ctx->estimatedTokens() > $budget) {
                break;
            }

            $selected[$ctx->number] = $ctx;
            $tokens += $ctx->estimatedTokens();
        }

        $stoppedOn = count($selected) >= $maxPosts ? 'post cap' : ($tokens >= $budget ? 'token budget' : 'whole discussion');

        // Force-include whatever the trigger explicitly points at. These and the
        // opening post below are additions the walk did not budget for, so they
        // are capped too — previously they were appended unchecked, which is how
        // a configured budget of 20000 produced a 23237-token context. The
        // trigger itself is exempt and was already taken above.
        foreach ($this->quotedPostIds($trigger) as $postId) {
            $quoted = $discussion->comments()->with(['user', 'mentionsUsers'])->find($postId);
            if ($quoted === null) {
                continue;
            }
            $ctx = $this->toContextPost($quoted, $discussion->first_post_id, $trigger->id);
            if (! isset($selected[$ctx->number]) && $tokens + $ctx->estimatedTokens() <= $budget) {
                $selected[$ctx->number] = $ctx;
                $tokens += $ctx->estimatedTokens();
            }
        }

        // Pin the opening post.
        $firstPostId = $discussion->first_post_id;
        if ($firstPostId !== null) {
            $alreadyHave = false;
            foreach ($selected as $ctx) {
                if ($ctx->isOpeningPost) {
                    $alreadyHave = true;
                    break;
                }
            }

            if (! $alreadyHave) {
                $first = $discussion->comments()->with(['user', 'mentionsUsers'])->find($firstPostId);
                if ($first !== null) {
                    $ctx = $this->toContextPost($first, $firstPostId, $trigger->id);
                    if ($tokens + $ctx->estimatedTokens() <= $budget) {
                        $selected[$ctx->number] = $ctx;
                        $tokens += $ctx->estimatedTokens();
                    }
                }
            }
        }

        ksort($selected);
        $posts = array_values($selected);

        $omitted = max(0, $totalComments - count($posts));

        return new DiscussionContext(
            title: (string) $discussion->title,
            tags: $this->tagNames($discussion),
            posts: $posts,
            omitted: $omitted,
            totalComments: $totalComments,
            allNumbers: $allNumbers,
            stoppedOn: $stoppedOn,
        );
    }

    private function toContextPost(Post $post, ?int $firstPostId, int $triggerId): ContextPost
    {
        $author = $post->user;
        $botId = $this->bot->id();
        $text = $this->unparse($post);

        // The bot's own replies come back as ordinary posts, footer and all.
        // Left in, the model reads the disclosure line as house style and
        // writes its own — and then the job appends the real one, so the reply
        // ends with two. Strip it here, at the point where its own output is
        // fed back to it.
        if ($botId !== null && $author?->id !== null && (int) $author->id === $botId) {
            $text = $this->stripFooter($text);
        }

        return new ContextPost(
            id: (int) $post->id,
            number: (int) $post->number,
            author: $this->mentionSafeName($author?->display_name ?? '[deleted user]'),
            authorId: $author?->id !== null ? (int) $author->id : null,
            authorUsername: $author?->username,
            createdAt: $post->created_at?->toDateString() ?? '',
            text: $text,
            isOpeningPost: $firstPostId !== null && $post->id === $firstPostId,
            isTrigger: $post->id === $triggerId,
        );
    }

    /**
     * Remove the configured footer from the end of one of the bot's own posts.
     *
     * Loops, because posts already published with the duplicate bug carry it
     * twice and both copies have to go.
     */
    private function stripFooter(string $text): string
    {
        $footer = $this->settings->footer();

        if ($footer === '') {
            return $text;
        }

        $text = rtrim($text);

        while (str_ends_with($text, $footer)) {
            $text = rtrim(substr($text, 0, -strlen($footer)));
        }

        return $text;
    }

    /**
     * Make a display name safe to embed in a mention token.
     *
     * flarum/mentions matches `@"name"#p123` with a name pattern that
     * explicitly cannot contain `"#` followed by an id (it is how the parser
     * finds the end of the name), and core's own unparser rewrites such names
     * before emitting a token. A nickname containing `"#12` would otherwise
     * produce a token that parses at the wrong boundary, so apply the same
     * substitution core does.
     */
    private function mentionSafeName(string $name): string
    {
        if (! str_contains($name, '"#')) {
            return $name;
        }

        return (string) preg_replace('/"#[a-z]{0,3}[0-9]+/', '_', $name);
    }

    /**
     * The author's original source text.
     *
     * `$post->content` already IS the unparsed source: Flarum's
     * HasFormattedContent accessor runs the formatter's unparse chain on read
     * (which is also what restores `@"Nickname"#42` mention syntax from the
     * stored XML). Calling `Formatter::unparse()` on it would unparse twice.
     * The raw XML, when we want it, is `$post->parsed_content`.
     *
     * Falls back to a stripped rendering of the XML if the accessor throws —
     * one malformed post must not sink the whole reply.
     */
    private function unparse(Post $post): string
    {
        try {
            $text = $post->content;
            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        } catch (Throwable) {
            // fall through
        }

        return trim(strip_tags((string) $post->parsed_content));
    }

    /**
     * Post IDs the trigger quotes or post-mentions.
     *
     * @return list<int>
     */
    private function quotedPostIds(CommentPost $trigger): array
    {
        try {
            // parsed_content, not content — see unparse() above.
            $ids = Utils::getAttributeValues((string) $trigger->parsed_content, 'POSTMENTION', 'id');
        } catch (Throwable) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @return list<string>
     */
    private function tagNames(object $discussion): array
    {
        if (! $this->extensions->isEnabled('flarum-tags')) {
            return [];
        }

        try {
            return $discussion->tags->pluck('name')->filter()->values()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
