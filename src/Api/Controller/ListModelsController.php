<?php

namespace Ekumanov\ClaudeReply\Api\Controller;

use Ekumanov\ClaudeReply\Anthropic\ClaudeClient;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * GET /api/claude-reply/models
 *
 * The live model list from the Anthropic Models API, so the admin picks a model
 * from a dropdown of what the account can actually call instead of typing an id
 * from memory — the single most annoying way to misconfigure this extension,
 * because a typo only surfaces later as a failed job.
 *
 * Admin-only: it spends an (unbilled) API request and reveals which models the
 * key has access to.
 *
 * Cached for an hour. The admin settings page would otherwise call the API on
 * every visit, and the list changes a few times a year. `?refresh=1` skips the
 * cache for the case where it just changed.
 *
 * Failure is not an error here. No key configured, a network problem, an
 * expired key — all of them return 200 with an empty list and a message, and
 * the admin UI falls back to a free-text field. A settings page that cannot
 * render because a third-party API is down would be a poor trade for a
 * convenience feature.
 */
class ListModelsController implements RequestHandlerInterface
{
    private const CACHE_KEY = 'ekumanov-claude-reply.models';
    private const CACHE_SECONDS = 3600;

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly Cache $cache,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $refresh = (string) ($request->getQueryParams()['refresh'] ?? '') !== '';

        if ($refresh) {
            $this->cache->forget(self::CACHE_KEY);
        }

        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return new JsonResponse($cached);
        }

        try {
            $models = $this->claude->listModels();
        } catch (Throwable $e) {
            // Deliberately not cached — a transient failure should not lock the
            // dropdown out for an hour.
            return new JsonResponse([
                'models' => [],
                'error' => $e->getMessage(),
            ]);
        }

        $payload = ['models' => $models, 'error' => null];

        $this->cache->put(self::CACHE_KEY, $payload, self::CACHE_SECONDS);

        return new JsonResponse($payload);
    }
}
