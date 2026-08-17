<?php

namespace Ekumanov\ClaudeReply\Api;

use Ekumanov\ClaudeReply\Access\ReplyQuota;
use Ekumanov\ClaudeReply\Access\TriggerGate;
use Ekumanov\ClaudeReply\BotAccount;
use Ekumanov\ClaudeReply\Settings\ApiKey;
use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Flarum\Api\Context;
use Flarum\Api\Schema\Arr;

/**
 * What the forum frontend is told about Claude replies.
 *
 * One attribute, and it is empty for almost everybody. It is populated only for
 * a signed-in member who has passed the user/group gate — which is what lets
 * the composer warn them before they spend a post on a reply that will not
 * come. Everyone else gets `[]`.
 *
 * Three reasons that gate is on the payload and not just on the warning:
 *
 *   - **Privacy.** The remaining fields describe the forum's configuration:
 *     which account is the bot, which tags it answers in. A member who is
 *     entitled to summon replies is entitled to know how; nobody else is, and
 *     guests least of all.
 *   - **Cost.** `remaining` is a COUNT over the audit table. Restricting it to
 *     allow-listed members means the query runs for the handful of people who
 *     can actually use the feature, not on every page load of the forum.
 *   - **Edge caching.** A forum that serves guest HTML from a CDN needs the
 *     guest payload not to vary. An empty array for every guest keeps it
 *     byte-identical from one request to the next.
 */
final class ForumAttributes
{
    public const ATTRIBUTE = 'ekumanov-claude-reply.quota';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly TriggerGate $gate,
        private readonly ReplyQuota $quota,
        private readonly BotAccount $bot,
        private readonly ApiKey $apiKey,
    ) {}

    public function __invoke(): array
    {
        return [
            Arr::make(self::ATTRIBUTE)
                ->get(fn ($model, Context $context) => $this->payload($context)),
        ];
    }

    private function payload(Context $context): array
    {
        $actor = $context->getActor();

        if ($actor->isGuest() || ! $this->settings->enabled() || ! $this->apiKey->isConfigured()) {
            return [];
        }

        if (! $this->gate->user($actor)->allowed) {
            return [];
        }

        $botId = $this->bot->id();

        if ($botId === null) {
            return [];
        }

        $tags = $this->settings->tags();

        return [
            // Both halves of the tag rule, because the client re-applies the
            // same deny-then-allow precedence to decide whether the discussion
            // being replied to is one the bot would answer in.
            'allowedTagIds' => $tags->allowed,
            'deniedTagIds'  => $tags->denied,

            // Needed to spot the mention in the composer's raw text, in both
            // the `@"Name"#id` and bare `@username` forms.
            'botId'       => $botId,
            'botUsername' => $this->bot->username(),

            // null remaining means "never warn me": no limit configured, or
            // this member may bypass it.
            'perUserLimit' => $this->settings->perUserDailyLimit(),
            'remaining'    => $this->quota->userRemaining($actor),
        ];
    }
}
