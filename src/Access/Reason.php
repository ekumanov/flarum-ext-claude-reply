<?php

namespace Ekumanov\ClaudeReply\Access;

/**
 * Why a trigger was allowed or refused.
 *
 * Exists so the decision can be *explained* rather than merely taken: the same
 * reason code drives the log line, the `claude-reply:test` diagnostics table
 * and (for the two cases a member is allowed to know about) the pre-post
 * warning in the composer. Debugging "why did the bot not answer me?" without
 * this meant reading the gate source and re-deriving the settings by hand.
 */
enum Reason: string
{
    // User / group gate.
    case UserDenied   = 'user_denied';
    case UserAllowed  = 'user_allowed';
    case GroupDenied  = 'group_denied';
    case GroupAllowed = 'group_allowed';
    case NotListed    = 'not_listed';

    // Tag gate.
    case TagDenied    = 'tag_denied';
    case TagAllowed   = 'tag_allowed';
    case TagNotListed = 'tag_not_listed';
    case NoTags       = 'discussion_has_no_tags';
    case TagsDisabled = 'tags_extension_disabled';

    // Everything else the gate checks.
    case Disabled       = 'extension_disabled';
    case BotMissing     = 'bot_account_not_found';
    case BotItself      = 'actor_is_the_bot';
    case NoMention      = 'post_does_not_mention_the_bot';
    case NoApiKey       = 'no_api_key_configured';
    case PrivateDiscussion = 'private_discussion';
    case UserQuota      = 'per_user_daily_limit_reached';
    case ForumQuota     = 'forum_daily_limit_reached';
    case SyncQueue      = 'sync_queue';
    case Ok             = 'ok';
}
