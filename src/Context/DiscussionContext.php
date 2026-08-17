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
    public function __construct(
        public string $title,
        public array $tags,
        public array $posts,
        public int $omitted,
        public int $totalComments,
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
     * The gap marker matters: without it a model handed posts #1 then #180
     * will happily treat them as consecutive and reason about a conversation
     * that never happened.
     */
    public function render(): string
    {
        $out = "# Discussion: {$this->title}\n";

        if ($this->tags !== []) {
            $out .= 'Tags: '.implode(', ', $this->tags)."\n";
        }

        $out .= "Total replies in this discussion: {$this->totalComments}\n\n";

        $previousNumber = null;

        foreach ($this->posts as $post) {
            if ($previousNumber !== null && $post->number > $previousNumber + 1) {
                $gap = $post->number - $previousNumber - 1;
                $out .= "\n[… {$gap} earlier post(s) omitted …]\n";
            }

            $out .= "\n".$post->render()."\n";
            $previousNumber = $post->number;
        }

        return $out;
    }
}
