<?php

namespace Ekumanov\ClaudeReply\Context;

use Flarum\Post\Post;
use Throwable;

/**
 * The author's original source text for one post.
 *
 * `$post->content` already IS the unparsed source: Flarum's
 * HasFormattedContent accessor runs the formatter's unparse chain on read
 * (which is also what restores `@"Nickname"#42` mention syntax from the stored
 * XML). Calling `Formatter::unparse()` on it would unparse twice. The raw XML,
 * when we want it, is `$post->parsed_content`.
 *
 * Falls back to a stripped rendering of the XML if the accessor throws — one
 * malformed post must not sink a whole reply.
 *
 * Extracted so the context builder and the forum search cannot drift apart on
 * it. The content/parsed_content inversion is the easiest thing in this
 * codebase to get backwards, and it fails silently: the wrong one finds
 * nothing at all rather than erroring.
 */
final class PostText
{
    public static function of(Post $post): string
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
}
