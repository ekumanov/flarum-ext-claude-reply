<?php

namespace Ekumanov\ClaudeReply\Context;

/**
 * One discussion post, flattened for the prompt.
 *
 * `text` is the *unparsed source* — the markdown/BBCode as the author typed
 * it, recovered from the stored TextFormatter XML. That is both cheaper than
 * rendered HTML and a more faithful representation of intent (a table stays a
 * table, a quote stays a quote).
 */
final readonly class ContextPost
{
    public function __construct(
        public int $number,
        public string $author,
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
        // +24 covers the header line and separators the renderer adds.
        return (int) ceil(strlen($this->text) / 2.8) + 24;
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
            "--- post #%d by %s (%s)%s ---\n%s",
            $this->number,
            $this->author,
            $this->createdAt,
            $suffix,
            $this->text,
        );
    }
}
