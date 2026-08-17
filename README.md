# Claude Reply

Mention a designated bot account in a Flarum 2.0 discussion and Claude replies in-thread, as that account.

Built to be kept on a short leash: only explicitly allowed members can trigger a reply, only in explicitly allowed tags, never in private discussions, under both a per-member and a forum-wide daily cap.

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

**2. API key.** Either paste it into the admin field, or — better — put it in `config.php`:

```php
'claude_reply' => [
    'api_key' => 'sk-ant-...',
],
```

Resolution order is `config.php` → `ANTHROPIC_API_KEY` in the environment → the settings table, and the first one found wins. The admin field exists because not every install has shell access, and it is handled carefully: the value is masked out of the admin page payload, so it is never sent to a browser, and saving the page without touching the field cannot overwrite it. It is still stored in the settings table though, which means it is in your database backups and your settings cache — which `config.php` values are not. Prefer `config.php` where you can.

**3. Settings** (Admin → Extensions → Claude Reply). The gate is fail-closed and does nothing until you configure it:

| Setting | Default | Notes |
|---|---|---|
| Enable Claude replies | **off** | Master switch |
| Allowed members / groups | **empty = nobody** | Pick from a search box |
| Allowed tags | **empty = no tag** | Pick from your tag list |

Enabling the extension alone cannot send a single post to Anthropic; you have to name who and where.

Others worth tuning: `model` (a dropdown of the models your key can actually call), `effort` (default `medium`), `context_token_budget` (20000), `per_user_daily_limit` (2), `daily_reply_limit` (25), `persona_prompt`, `footer`.

## Who may summon a reply

Six lists, in three pairs — members, groups, tags — each with an allow list and a deny list. They resolve in a fixed order, and the first match decides:

1. denied members → **no**
2. allowed members → **yes**
3. denied groups → **no**
4. allowed groups → **yes**
5. nothing matched → **no**

Two rules fall out of that, and they are the ones to remember. **Deny beats allow within a category**, so listing someone in both is a deny — which makes a deny list safe to reach for in a hurry. And **a person beats their groups**, so a member of an allowed group can be denied individually, and a member of a denied group can be allowed individually.

**An empty allow list means nobody**, whatever the deny lists say. A deny list only subtracts from what an allow list has granted; it is never a policy on its own. That is deliberate — it is what makes "enable the extension" a safe thing to do by accident.

Tags work the same way, minus the hierarchy: one denied tag on a discussion refuses it even if another of its tags is allowed. Parent tags need no special rule — Flarum attaches the parent whenever a child is selected, so allowing or denying a parent covers its children through the data.

## Limits

Two caps, both over a rolling 24 hours, both counted from the audit table so a restart or a deploy cannot hand out a fresh allowance:

- **Per member** (default 2). A fairness device, so one person cannot spend the day's replies alone. Groups granted **Bypass the per-member Claude reply limit** in Permissions are exempt; admins are exempt implicitly, as they are from every permission. Set to 0 to disable it entirely.
- **Forum-wide** (default 25). The actual spend ceiling. This one is not bypassable by anybody, which is the point of it. Set to 0 to block all replies.

When a member is out of quota, the composer tells them **before** they post — "mentioning @claude_user in this post will not produce an answer" — with the choice to post anyway or go back and edit. Nothing is generated and nothing is billed either way; the alternative designs both cost something for no benefit, since having the bot post "you are out of replies" spends an API call to say that no more API calls are available.

The warning is advisory. The server enforces the cap regardless, and records a `skipped` audit row so a refusal is never invisible.

## Quoting and replying

Claude is given each post's real Flarum mention token in the context it reads (`@"Name"#p123`), and uses them to quote and reply the way the Reply and Quote buttons do — a real blockquote, a real reply pointer, not a plain-text paraphrase.

Anything it emits that points at a post or a person it was **not** shown is stripped before publishing, leaving the bare name behind. A wrong post id would only render as visible junk, but a wrong *user* id notifies a real member who had nothing to do with the discussion, and a group mention notifies everyone in the group. Only the ids that were in the context survive; flarum/mentions rewrites the display name from the id on parse, so a stale nickname corrects itself.

## Tuning without spending money

```bash
php flarum claude-reply:test <postId>
```

Prints a gate report — every check, its verdict and the reason, for the post's author against current settings — then the exact context that would be sent, the post count, the heuristic estimate and the real `count_tokens` figure. Add `--send` to also call the API and print the reply, still without posting it, or `--gate` to stop after the gate report.

This is the first thing to reach for when the answer to "why didn't the bot reply to that?" is not obvious.

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
| Nothing happens, nothing logged | Run `claude-reply:test <postId> --gate` — it names the gate that refused. |
| A member stopped getting replies | Their per-member cap. `skipped` rows in the audit table record it, with `per_user_daily_limit_reached` in `error`. |
| `no API key configured` | `config.php` key missing or the file wasn't reloaded. |
| Audit row stuck `pending` | The worker never picked the job up. |
| Audit row `failed` | Read its `error` column — it holds the API error verbatim (truncated to 255 chars). |
| Reply cut off mid-sentence | `max_tokens` too low. It caps thinking **and** visible text together. |

## Implementation notes

Non-obvious Flarum 2.0 details this depends on, all verified against rc.5:

- **`$post->content` is not the XML.** The `HasFormattedContent` accessor unparses on read; the stored XML is **`$post->parsed_content`**. Mention extraction has to use the latter.
- **`Flarum\Post\Command\PostReply` no longer exists.** Internal post creation goes through `JsonApi::process()` against `PostResource` with the `create` endpoint.
- **`Extend\Event::listen()` will not take `[Class::class, 'method']`** for a non-static method, whatever its docblock says — PHP's `callable` type rejects it. A class handling two events has to be registered with `->subscribe()`.
- **The admin page dumps the whole settings table into the page HTML** (`AdminPayload`). A secret stored as a setting is readable in the DOM by any admin and any admin-side extension JS, unless it is masked in the `Deserializing` event first.
- **Initializers run before `app.forum` exists.** Core's `boot()` calls initializers, *then* assigns `app.forum` from the store, so reading the payload at the top of an initializer throws on every page load.
- **`state` is a reserved attr name for modals.** `ModalManager` passes its own `state` down to the modal component, silently clobbering an attr of that name.
- **Don't name a job method `fail()`.** `AbstractJob` inherits a public `fail()` from Laravel's `InteractsWithQueue`; redeclaring it privately is a class-load fatal, which no `try/catch` can see — the symptom is a worker that dies silently mid-listener.

## Licence

MIT
