import app from 'flarum/forum/app';

const ATTRIBUTE = 'ekumanov-claude-reply.quota';

/**
 * The quota block from the forum payload, or null.
 *
 * Null for guests, for members who cannot summon replies, and when the
 * extension is off or has no API key — see Api\ForumAttributes. Everything in
 * the forum bundle is gated on this being non-null, which is what keeps the
 * feature's cost to non-participants at one property read.
 */
export function quotaState() {
  const raw = app.forum.attribute(ATTRIBUTE);

  if (!raw || typeof raw !== 'object' || !raw.botId) return null;

  return {
    botId: Number(raw.botId),
    botUsername: String(raw.botUsername || ''),
    allowedTagIds: (raw.allowedTagIds || []).map(Number),
    deniedTagIds: (raw.deniedTagIds || []).map(Number),
    perUserLimit: Number(raw.perUserLimit || 0),
    // null means no per-user limit at all, which is not the same as 0 left.
    remaining: raw.remaining === null || raw.remaining === undefined ? null : Number(raw.remaining),
  };
}

/**
 * Note one reply as spent, in this tab.
 *
 * The payload figure is only as fresh as the last page load. Without this, a
 * member with two replies left could summon both and be warned about neither,
 * because nothing told the client the first one had been used.
 */
export function spendOne() {
  const raw = app.forum.attribute(ATTRIBUTE);

  if (!raw || raw.remaining === null || raw.remaining === undefined) return;

  raw.remaining = Math.max(0, Number(raw.remaining) - 1);
}

/**
 * Does this composer text mention the bot?
 *
 * Matches the two forms flarum/mentions accepts, against the source text rather
 * than the parsed XML — the post has not been parsed yet, which is the whole
 * point of warning before it is submitted:
 *
 *   @"Display Name"#<botId>   what the autocomplete inserts
 *   @username                 typed by hand, or from an older client
 *
 * This is intentionally the *loose* side of the server's check: the server reads
 * the parsed XML, so it will not be fooled by an `@claude_user` inside a code
 * block, whereas this will. Over-warning costs a dismissable dialog;
 * under-warning costs the member the reply they expected, so the asymmetry runs
 * the right way.
 */
export function mentionsBot(content, state) {
  if (!content) return false;

  if (new RegExp('@["“][^"”]*["”]#' + state.botId + '\\b').test(content)) return true;

  if (!state.botUsername) return false;

  // \B before @, and no `#` after the name — mirrors mentions' own pattern, so
  // an email address does not read as a mention.
  return new RegExp('\\B@' + escapeRegExp(state.botUsername) + '(?!#)', 'i').test(content);
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\-]/g, '\\$&');
}
