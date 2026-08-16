# Claude Reply

Mention a designated bot account in a Flarum 2.0 discussion and Claude replies in-thread, as that account.

Built for a single-operator setup: only explicitly allow-listed users can trigger a reply, only in explicitly allow-listed tags, never in private discussions, under a hard daily cap.

## How it works

1. An allow-listed member mentions the bot account (`@claude_user` by default) in a post.
2. A listener runs inside the post-save request, checks the gates, writes an audit row, and queues a job. No network I/O happens in the request.
3. The worker assembles a token-budgeted excerpt of the discussion, makes **one** Messages API call, and posts the reply through Flarum's own API layer — so mentions resolve, notifications fire, realtime pushes it, and any purge-on-write hooks run exactly as for a human post.

There is no session state. Claude's own replies are ordinary posts, so the next time it is mentioned in the same thread it reads its earlier answers back as context.

## Requirements

- Flarum **2.0**, `flarum/mentions` and `flarum/tags` enabled
- **A real queue driver** (`redis`, `database`, …) and a running worker. The extension refuses to run on the `sync` queue rather than block a member's post-save request for minutes — see Troubleshooting.
- An Anthropic API key ([console.anthropic.com](https://console.anthropic.com)). This is billed per token, separately from any Claude Pro/Max subscription.

## Setup

**1. Bot account.** Create a normal user for Claude to post as. It needs `reply` permission in the allowed tags, and must **not** be in a group whose posts require approval — otherwise replies land in the approval queue and never appear.

**2. API key** — in `config.php`, *not* in the settings table (which is cached, dumped into backups, and readable by any admin):

```php
'claude_reply' => [
    'api_key' => 'sk-ant-...',
],
```

`ANTHROPIC_API_KEY` in the environment also works as a fallback.

**3. Settings** (Admin → Extensions → Claude Reply). Three are fail-closed and do nothing until you set them:

| Setting | Default | Notes |
|---|---|---|
| Enable Claude replies | **off** | Master switch |
| Allowed trigger user IDs | **empty = nobody** | Comma-separated |
| Allowed tag IDs | **empty = no tag** | Comma-separated |

Enabling the extension alone cannot send a single post to Anthropic; you have to name the users and the tags.

Others worth tuning: `model` (default `claude-opus-5`), `effort` (default `medium`), `context_token_budget` (20000), `daily_reply_limit` (25), `persona_prompt`, `footer`.

## Tuning without spending money

```bash
php flarum claude-reply:test <postId>
```

Prints the exact context that would be sent, the post count, the heuristic estimate and the real `count_tokens` figure. Add `--send` to also call the API and print the reply — still without posting it.

Use this to iterate on `persona_prompt` and `context_token_budget` before letting it near a real thread.

## Context strategy

Whole-thread-always is wrong: expensive on long threads, and the middle is mostly noise. Instead:

- The **opening post is always included** — it frames the thread.
- Posts the trigger **quotes or post-mentions** are force-included wherever they sit.
- Everything else is a greedy walk backwards from the mention until the token budget or post cap is hit.
- Holes are marked `[… N earlier post(s) omitted …]`, which is what stops the model inventing continuity across the gap.

Posts are sent as their **unparsed source** (the markdown the author typed), not rendered HTML — cheaper and more faithful.

## Privacy

Mentioning the bot sends that discussion's posts — other members' words, under their real display names — to Anthropic. That is the deal; the allow-lists exist so you opt into it deliberately.

- Private discussions (fof/byobu) are **always refused**, hard-coded, not a setting.
- Hidden and pending-approval posts are excluded (the builder uses Flarum's own `comments()` scope).
- Check your Anthropic org's data-retention setting before going live.

An AI-disclosure `footer` is configurable and recommended.

## Cost

One reply ≈ one Messages API call. On `claude-opus-5` ($5/$25 per MTok), a short thread costs roughly $0.05 and a full 20k-token context roughly $0.14. Every call's token counts are written to `ekumanov_claude_replies`, so spend can be reconciled against the console:

```sql
SELECT DATE(created_at) d, COUNT(*) replies,
       SUM(input_tokens) tok_in, SUM(output_tokens) tok_out
FROM ekumanov_claude_replies WHERE status='posted' GROUP BY d ORDER BY d DESC;
```

Prompt caching is deliberately **not** used: mentions arrive minutes or hours apart while the cache TTL is five minutes, so cache writes (1.25×) would almost never be read back (0.1×).

## Troubleshooting

| Symptom | Cause |
|---|---|
| `refusing to run on the sync queue` in the log | Set a real queue driver and run a worker. Running a multi-minute API call inline would hang the composer. |
| Nothing happens, nothing logged | The mention didn't parse, the user isn't allow-listed, or the tag isn't. Check the post's `parsed_content` for a `<USERMENTION>` tag. |
| `no API key configured` | `config.php` key missing or the file wasn't reloaded. |
| Audit row stuck `pending` | The worker never picked the job up. |
| Audit row `failed` | Read its `error` column — it holds the API error verbatim (truncated to 255 chars). |
| Reply cut off mid-sentence | `max_tokens` too low. It caps thinking **and** visible text together. |

## Implementation notes

Non-obvious Flarum 2.0 details this depends on, all verified against rc.5:

- **`$post->content` is not the XML.** The `HasFormattedContent` accessor unparses on read; the stored XML is **`$post->parsed_content`**. Mention extraction has to use the latter.
- **`Flarum\Post\Command\PostReply` no longer exists.** Internal post creation goes through `JsonApi::process()` against `PostResource` with the `create` endpoint.
- **Don't name a job method `fail()`.** `AbstractJob` inherits a public `fail()` from Laravel's `InteractsWithQueue`; redeclaring it privately is a class-load fatal, which no `try/catch` can see — the symptom is a worker that dies silently mid-listener.

## Licence

MIT
