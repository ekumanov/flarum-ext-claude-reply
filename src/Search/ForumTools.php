<?php

namespace Ekumanov\ClaudeReply\Search;

use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The client-executed tools Claude may call while composing a reply.
 *
 * Two of them, deliberately. `forum_search` returns headlines only and
 * `forum_read_discussion` opens exactly one of them, because the alternatives
 * are both bad: headlines alone rarely let the model answer, so it hedges or
 * fills the gap itself, while search results carrying full post bodies spend
 * the entire context on the first query. Splitting them makes the model the
 * reranker — it reads the headlines, picks, and opens one thing — which is the
 * part of a retrieval pipeline that would otherwise have to be built.
 *
 * Unlike web search, these run *here*: the API stops the turn with
 * `stop_reason: "tool_use"`, this class answers, and the turn resumes. That
 * loop, its bounds and its accounting live in
 * {@see \Ekumanov\ClaudeReply\Anthropic\ClaudeClient::reply()}.
 *
 * Everything returned by {@see run()} is forum content written by members and
 * is therefore untrusted — the system prompt's boundary names tool results
 * explicitly for that reason. The wrapper this class puts around each result
 * gives that boundary something concrete to refer to, so the model can always
 * tell where the quoted-from-the-forum part starts and stops.
 */
final class ForumTools
{
    public const SEARCH = 'forum_search';
    public const READ   = 'forum_read_discussion';

    public function __construct(
        private readonly ForumSearch $search,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $log,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->forumSearchEnabled();
    }

    /**
     * Tool definitions for the `tools` request parameter.
     *
     * Plain arrays with camelCase keys — the SDK maps `inputSchema` to the
     * wire's `input_schema` itself. `strict` is not set: these take one
     * free-text field each, so there is no schema for it to buy anything on,
     * and it is incompatible with enough else that turning it on here would be
     * a constraint for no gain.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => self::SEARCH,
                'description' =>
                    'Search THIS forum for other discussions relevant to the question, and get back a '
                    .'list of matching discussions with titles, links, dates and short excerpts. Use it when '
                    .'the question might already have been discussed elsewhere on the forum, when somebody asks '
                    .'what the community thinks of something, or when a previous thread would be a better answer '
                    .'than a summary. It searches discussion text, so plain keywords work best — model numbers, '
                    .'brand names and distinctive terms beat whole sentences. Results are limited to discussions '
                    .'any visitor could read, so some of the forum is deliberately invisible to this tool. '
                    .'It returns headlines only; to read one, call '.self::READ.'.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search keywords. Not a question — the words you would expect to appear in the thread.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => self::READ,
                'description' =>
                    'Read one discussion on this forum, by the numeric id given in a '.self::SEARCH
                    .' result. Use it when a search result looks like it actually answers the question and you '
                    .'want to say what it concluded rather than just link it. Long discussions come back as the '
                    .'beginning and the end with a marker showing how much was skipped between them — so the '
                    .'posts either side of that marker may be years apart, and you must not read them as one '
                    .'continuous exchange. Only discussions any visitor could read are returned.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'discussion_id' => [
                            'type' => 'integer',
                            'description' => 'The numeric discussion id, as printed in a '.self::SEARCH.' result.',
                        ],
                    ],
                    'required' => ['discussion_id'],
                ],
            ],
        ];
    }

    /**
     * Execute one tool call and return the text for its `tool_result`.
     *
     * Never throws. A tool that raises would abort a reply the model could
     * still have written without it, so every failure comes back as text the
     * model can read and route around — which is also why the caller does not
     * need to mark these results `is_error`.
     *
     * @param array<string, mixed> $input
     */
    public function run(string $name, array $input, int $currentDiscussionId): string
    {
        try {
            $body = match ($name) {
                self::SEARCH => $this->search->search(
                    (string) ($input['query'] ?? ''),
                    $currentDiscussionId,
                ),
                self::READ => $this->search->readDiscussion(
                    (int) ($input['discussion_id'] ?? 0),
                    $currentDiscussionId,
                ),
                default => 'Unknown tool: '.$name,
            };
        } catch (Throwable $e) {
            $this->log->warning('claude-reply: forum tool failed', [
                'tool' => $name,
                'err' => $e->getMessage(),
            ]);

            $body = 'That tool failed: '.$e->getMessage().' Answer without it.';
        }

        return "<forum_content>\n".$body."\n</forum_content>";
    }
}
