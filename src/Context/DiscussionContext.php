<?php

namespace Ekumanov\ClaudeReply\Context;

/**
 * The assembled prompt payload for one discussion: what Claude gets to see.
 */
final readonly class DiscussionContext
{
    /**
     * @param list<string>      $tags
     * @param list<ContextPost> $posts   ascending by post number
     * @param int               $omitted posts skipped between the opening post
     *                                   and the tail window
     */
    /**
     * @param list<int> $allNumbers every visible comment number in the
     *                              discussion, ascending — the yardstick the
     *                              gap markers are measured against
     */
    public function __construct(
        public string $title,
        public array $tags,
        public array $posts,
        public int $omitted,
        public int $totalComments,
        public array $allNumbers = [],
        /**
         * Which limit ended the backward walk: 'token budget', 'post cap' or
         * 'whole discussion'. Reported by claude-reply:test, because raising the
         * wrong one is otherwise a silent no-op — the post cap was raised from
         * 25 to 100 on a thread whose limit had always been the budget.
         */
        public string $stoppedOn = 'whole discussion',
    ) {}

    public function estimatedTokens(): int
    {
        $sum = 64; // header block
        foreach ($this->posts as $post) {
            $sum += $post->estimatedTokens();
        }
        return $sum;
    }

    /**
     * How many real comments sit strictly between two selected posts.
     *
     * Falls back to the old numeric difference only when the yardstick is
     * missing entirely, which happens for contexts built by older callers.
     *
     * @param array<int, int> $positions
     */
    private function postsBetween(array $positions, int $from, int $to): int
    {
        if (isset($positions[$from], $positions[$to])) {
            return max(0, $positions[$to] - $positions[$from] - 1);
        }

        return max(0, $to - $from - 1);
    }

    /**
     * Ids of the posts the model was actually shown — the only posts it may
     * legitimately quote or link to.
     *
     * @return list<int>
     */
    public function postIds(): array
    {
        return array_values(array_map(fn (ContextPost $p) => $p->id, $this->posts));
    }

    /**
     * Ids of the people the model was shown — the only members it may
     * legitimately @-mention. Anyone else it names would be a member who never
     * took part in this discussion receiving a notification about it.
     *
     * @return list<int>
     */
    public function authorIds(): array
    {
        $ids = [];

        foreach ($this->posts as $post) {
            if ($post->authorId !== null) {
                $ids[] = $post->authorId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Usernames of those same people, lowercased, for validating the bare
     * `@username` mention form.
     *
     * @return list<string>
     */
    public function authorUsernames(): array
    {
        $names = [];

        foreach ($this->posts as $post) {
            if ($post->authorUsername !== null && $post->authorUsername !== '') {
                $names[] = strtolower($post->authorUsername);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Flatten to the single user-turn string.
     *
     * The gap marker matters: without it a model handed posts #1 then #180 will
     * happily treat them as consecutive and reason about a conversation that
     * never happened. It matters just as much that the marker is TRUE — a model
     * told it is missing posts hedges, and hedges about posts it was in fact
     * shown.
     *
     * Which is why gaps are counted against the discussion's actual comment
     * numbers rather than by subtracting one number from another. Numbering is
     * not contiguous in the real world: moving posts between discussions leaves
     * holes, and so does deleting one. Arithmetic on numbers invented 14
     * non-existent posts in a 66-post thread on the forum this was built for,
     * and reported gaps in a thread the model had been handed in full.
     */
    public function render(): string
    {
        $out = "# Discussion: {$this->title}\n";

        if ($this->tags !== []) {
            $out .= 'Tags: '.implode(', ', $this->tags)."\n";
        }

        $out .= "Total replies in this discussion: {$this->totalComments}\n\n";

        $previousNumber = null;

        // number => position among the discussion's real comments
        $positions = array_flip($this->allNumbers);

        foreach ($this->posts as $post) {
            if ($previousNumber !== null) {
                $gap = $this->postsBetween($positions, $previousNumber, $post->number);

                if ($gap > 0) {
                    $out .= "\n[… {$gap} earlier post(s) omitted …]\n";
                }
            }

            $out .= "\n".$post->render()."\n";
            $previousNumber = $post->number;
        }

        return $out;
    }
}
