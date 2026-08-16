<?php

namespace Ekumanov\ClaudeReply;

use Flarum\Api\JsonApi;
use Flarum\Api\Resource\PostResource;
use Flarum\Post\Post;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequestFactory;

/**
 * Publishes the generated reply as a real forum post.
 *
 * Goes through the API resource layer rather than inserting a row, so the
 * post takes the same path as one typed into the composer: content gets
 * parsed by TextFormatter, mentions are resolved and notifications sent,
 * `Posted` fires (which is what drives realtime push, search indexing, the
 * discussion's last-post pointers and the edge-cache purge), and the actor's
 * `reply` permission is actually checked.
 *
 * Note Flarum 2.0 removed `Flarum\Post\Command\PostReply`; `JsonApi::process()`
 * is the supported internal entry point in its place.
 *
 * The synthetic request matters: `JsonApi::process()` falls back to
 * `ServerRequestFactory::fromGlobals()`, which in a queue worker means
 * inventing a request out of CLI globals. We hand it an explicit one instead.
 * `ipAddress` is deliberately left unset — this post did not come from an IP,
 * and `posts.ip_address` is nullable.
 */
final class PostPublisher
{
    public function __construct(private readonly JsonApi $api) {}

    public function publish(int $discussionId, User $bot, string $content): Post
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/posts');

        /** @var Post $post */
        $post = $this->api
            ->withRequest($request)
            ->forResource(PostResource::class)
            ->forEndpoint('create')
            ->process(
                body: [
                    'data' => [
                        'attributes' => [
                            'content' => $content,
                        ],
                        'relationships' => [
                            'discussion' => [
                                'data' => [
                                    'type' => 'discussions',
                                    'id' => (string) $discussionId,
                                ],
                            ],
                        ],
                    ],
                ],
                options: ['actor' => $bot],
            );

        return $post;
    }
}
