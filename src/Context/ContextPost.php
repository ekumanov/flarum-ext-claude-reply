<?php

namespace Ekumanov\ClaudeReply\Context;

/**
 * One discussion post, flattened for the prompt.
 *
 * `text` is the *unparsed source* — the markdown/BBCode as the author typed
 * it, recovered from the stored TextFormatter XML. That is both cheaper than
 * rendered HTML and a more faithful representation of intent (a table stays a
 * table, a quote stays a quote).
 *
 * The ids are carried alongside the prose for two reasons: the header line
 * hands the model the exact token it needs to quote or reply to this post the
 * way Flarum does, and {@see \Ekumanov\ClaudeReply\Reply\MentionSanitizer}
 * uses the same ids to verify that whatever the model emitted points at a post
 * and a person it was actually shown.
 */
final readonly class ContextPost
{
    public function __construct(
        public int $id,
        public int $number,
        public string $author,
        public ?int $authorId,
        public ?string $authorUsername,
        public string $createdAt,
        public string $text,
        public bool $isOpeningPost = false,
        public bool $isTrigger = false,
    ) {}

    /**
     * Rough token count, deliberately an over-estimate so the greedy walk
     * stops early rather than overshooting the budget.
     *
     * Counts BYTES, not characters, at 2.8 bytes/token. Both choices are
     * calibrated against a real 20-post thread (4291 context tokens): the
     * textbook ~4 chars/token badly under-counts real forum prose, which is
     * full of quote markers, URLs, nicknames and emoji, and byte length is
     * what makes multi-byte content cost proportionally more (an emoji is one
     * character but several tokens) while leaving pure ASCII unchanged.
     *
     * One sample is not a calibration curve, so treat this as a guide rail
     * rather than a measurement — the authoritative figure comes from the
     * single count_tokens call the job makes before spending anything, and
     * that is what enforces the budget.
     */
    public function estimatedTokens(): int
    {
        // +40 covers the header line (now carrying the mention token) and the
        // separators the renderer adds.
        return (int) ceil(strlen($this->text) / 2.8) + 40;
    }

    /**
     * The token Flarum itself would insert to quote or reply to this post.
     *
     * Handing the model a ready-made token rather than a syntax rule to apply
     * is the difference between a reply that renders as a real Flarum quote and
     * one that shows raw `@"..."#p12` text: the id is the only part that has to
     * be right, and copying beats composing. flarum/mentions rewrites the
     * display name from the id when parsing, so a stale nickname self-heals.
     */
    public function mentionToken(): string
    {
        return '@"'.$this->author.'"#p'.$this->id;
    }

    public function render(): string
    {
        $flags = [];

        if ($this->isOpeningPost) {
            $flags[] = 'opening post';
        }

        if ($this->isTrigger) {
            $flags[] = 'THIS IS THE POST THAT MENTIONED YOU — reply to this';
        }

        $suffix = $flags === [] ? '' : ' ['.implode(' | ', $flags).']';

        return sprintf(
            "--- post #%d by %s (%s) — to quote or reply to this post use %s%s ---\n%s",
            $this->number,
            $this->author,
            $this->createdAt,
            $this->mentionToken(),
            $suffix,
            $this->text,
        );
    }
}
