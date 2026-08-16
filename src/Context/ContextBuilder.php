<?php

namespace Ekumanov\ClaudeReply\Context;

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
    ) {}

    public function build(CommentPost $trigger): DiscussionContext
    {
        $discussion = $trigger->discussion;
        $budget     = $this->settings->contextTokenBudget();
        $maxPosts   = $this->settings->maxContextPosts();

        $totalComments = (int) $discussion->comments()->count();

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

        // Force-include whatever the trigger explicitly points at.
        foreach ($this->quotedPostIds($trigger) as $postId) {
            $quoted = $discussion->comments()->with(['user', 'mentionsUsers'])->find($postId);
            if ($quoted === null) {
                continue;
            }
            $ctx = $this->toContextPost($quoted, $discussion->first_post_id, $trigger->id);
            if (! isset($selected[$ctx->number])) {
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
                    $selected[$ctx->number] = $ctx;
                    $tokens += $ctx->estimatedTokens();
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
        );
    }

    private function toContextPost(Post $post, ?int $firstPostId, int $triggerId): ContextPost
    {
        return new ContextPost(
            number: (int) $post->number,
            author: $post->user?->display_name ?? '[deleted user]',
            createdAt: $post->created_at?->toDateString() ?? '',
            text: $this->unparse($post),
            isOpeningPost: $firstPostId !== null && $post->id === $firstPostId,
            isTrigger: $post->id === $triggerId,
        );
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
