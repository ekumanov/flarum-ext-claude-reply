<?php

namespace Ekumanov\ClaudeReply\Reply;

use Ekumanov\ClaudeReply\Context\DiscussionContext;
use Flarum\User\User;

/**
 * Neutralises mention tokens the model was not entitled to emit, before the
 * reply is published.
 *
 * Teaching the model to use Flarum's real quote and reply syntax means teaching
 * it to write tokens that *do something* on parse: a post mention becomes a
 * link and a reply pointer, and a user mention sends that member a
 * notification. Those are exactly the things a confabulated id must not be able
 * to do. A wrong post id is merely ugly — flarum/mentions invalidates the tag
 * and the raw token shows up as text — but a wrong *user* id is a real member,
 * uninvolved in the discussion, getting notified that the bot mentioned them.
 * A group mention is the same failure multiplied by the size of the group.
 *
 * So the rule is allow-list, not validation: a token survives only if it points
 * at a post or a person that was actually in the context the model was shown.
 * Everything else is reduced to the plain name it was wearing, which keeps the
 * sentence readable.
 *
 * The three forms, all of them from flarum/mentions' own parser config:
 *
 *   @"Name"#p123   post mention — the quote/reply pointer
 *   @"Name"#123    user mention — notifies
 *   @"Group"#g4    group mention — notifies every member of the group
 *   @username      bare user mention — notifies, if the name resolves
 *
 * Display names are deliberately NOT checked. flarum/mentions rewrites the name
 * from the id when parsing (`ConfigureMentions::addPostId`), so a stale or
 * mangled name is harmless and self-correcting; only the id carries meaning.
 */
final class MentionSanitizer
{
    /**
     * Quoted form: @"Name"#<prefix><id> — prefix empty for users, `p` for
     * posts, `g` for groups. Mirrors the parser's own patterns, including the
     * `\B` anchor and the negative lookahead that ends the name at the first
     * `"#<id>` sequence.
     */
    private const QUOTED = '/\B@["“](?<name>((?!"#[a-z]{0,3}[0-9]+).)+)["”]#(?<prefix>[a-z]{0,3})(?<id>[0-9]+)\b/';

    /** Bare form: @username, not followed by `#`. */
    private const BARE = '/\B@(?<username>[a-z0-9_-]+)(?!#)/i';

    public function sanitize(string $text, DiscussionContext $context): SanitizedReply
    {
        $allowedPosts   = $context->postIds();
        $allowedUsers   = $context->authorIds();
        $allowedHandles = $context->authorUsernames();

        $stripped = [];

        $result = preg_replace_callback(
            self::QUOTED,
            function (array $m) use ($allowedPosts, $allowedUsers, &$stripped): string {
                $id     = (int) $m['id'];
                $prefix = strtolower($m['prefix']);
                $name   = $m['name'];

                $keep = match ($prefix) {
                    'p'     => in_array($id, $allowedPosts, true),
                    ''      => in_array($id, $allowedUsers, true),
                    default => false, // groups (#g) and anything unrecognised
                };

                if ($keep) {
                    return $m[0];
                }

                $stripped[] = $prefix === 'p' ? 'post' : ($prefix === 'g' ? 'group' : 'user');

                return $name;
            },
            $text
        );

        // A null return means the subject broke the regex engine (backtrack
        // limit on a pathological reply, say). Publishing the original is the
        // safe failure here: unvalidated tokens are a nuisance, a lost reply is
        // worse, and an invalid id still cannot resolve to anything on parse.
        if ($result === null) {
            return new SanitizedReply($text, []);
        }

        $result = $this->sanitizeBare($result, $allowedHandles, $stripped);

        return new SanitizedReply($result, $stripped);
    }

    /**
     * The bare `@username` form.
     *
     * Handled differently from the quoted forms on purpose. An unresolvable
     * bare name is already inert — flarum/mentions leaves it as literal text —
     * so rewriting it would only damage prose that was never a mention (an
     * `@` in a code sample, a handle from another site). The only case worth
     * intervening in is the dangerous one: a name that DOES resolve to a real
     * member who was not part of the discussion. That costs one query, and
     * only when the reply contains bare candidates at all.
     *
     * @param list<string> $allowedHandles
     * @param list<string> $stripped
     */
    private function sanitizeBare(string $text, array $allowedHandles, array &$stripped): string
    {
        if (! preg_match_all(self::BARE, $text, $matches)) {
            return $text;
        }

        $candidates = [];

        foreach ($matches['username'] as $handle) {
            if (! in_array(strtolower($handle), $allowedHandles, true)) {
                $candidates[strtolower($handle)] = true;
            }
        }

        if ($candidates === []) {
            return $text;
        }

        $existing = User::query()
            ->whereIn('username', array_keys($candidates))
            ->pluck('username')
            ->map(fn ($u) => strtolower((string) $u))
            ->all();

        if ($existing === []) {
            return $text;
        }

        $result = preg_replace_callback(
            self::BARE,
            function (array $m) use ($existing, &$stripped): string {
                if (! in_array(strtolower($m['username']), $existing, true)) {
                    return $m[0];
                }

                $stripped[] = 'user';

                // Drop only the `@`. The name stays, so the sentence still
                // reads as written — it simply no longer notifies anyone.
                return $m['username'];
            },
            $text
        );

        return $result ?? $text;
    }
}
