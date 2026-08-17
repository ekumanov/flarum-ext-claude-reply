import app from 'flarum/forum/app';
import { override } from 'flarum/common/extend';
import QuotaWarningModal from './components/QuotaWarningModal';
import { mentionsBot, quotaState, spendOne } from './quota';

/**
 * Warns a member, before their post is submitted, that it will not get a Claude
 * reply because they are out of quota for the day.
 *
 * Why warn at all: the alternative is silence. A member mentions the bot,
 * nothing happens, and nothing distinguishes "you have used today's replies"
 * from "the bot is broken". The other alternative — having the bot post "you are
 * out of replies" — spends an API call to say that no more API calls are
 * available, and leaves a throwaway post in the thread forever.
 *
 * This is advisory only. The server decides, in HandleMention, and it decides
 * again at submit time on the authoritative count; nothing here can grant
 * anything, only inform. If the member posts anyway the post goes through
 * untouched — they simply do not get an answer, which is what the warning said.
 *
 * ## Cost
 *
 * This bundle is on every forum page load, and all it does at boot is register
 * one lazy-load callback. The payload is not read until someone actually
 * submits a reply, and the first thing the handler does is bail unless the
 * quota block is present — which it is not for guests, nor for any member who
 * cannot summon replies (see Api\ForumAttributes).
 *
 * The payload deliberately is NOT read at initializer time, tempting as the
 * early return is: core's `boot()` runs initializers BEFORE it assigns
 * `app.forum` from the store, so `app.forum.attribute(...)` up here throws on
 * every single page load — for guests too — and core turns that into a visible
 * "extension failed to initialize" error.
 */
app.initializers.add('ekumanov/claude-reply', () => {
  // ReplyComposer lives in a lazy chunk in Flarum 2.0, so it cannot be imported
  // at the top level — that compiles to a registry lookup which is undefined at
  // evaluation time. Patch it when the chunk arrives instead. Guests never load
  // that chunk, so for them this callback is never even invoked.
  flarum.reg.onLoad('core', 'forum/components/ReplyComposer', (module) => {
    const ReplyComposer = module?.default ?? module;

    override(ReplyComposer.prototype, 'onsubmit', async function (original) {
      const state = quotaState();

      if (!state) return original();

      // Only posts that would actually have summoned a reply are of interest:
      // the bot has to be mentioned, and the discussion has to be one it answers
      // in. Anything else is an ordinary post and must submit untouched.
      const wouldTrigger = mentionsBot(this.composer.fields.content(), state) && answerable(this.attrs.discussion, state);

      if (!wouldTrigger) return original();

      if (state.remaining !== null && state.remaining <= 0) {
        const proceed = await confirmPostWithoutReply(state);

        if (!proceed) {
          // Leave the composer as it was — the member went back to editing,
          // most likely to take the mention out.
          return;
        }

        // Posting anyway: no reply is generated, so nothing is spent.
        return original();
      }

      const result = await original();

      // Count it only once the post actually landed. Keeps the tally honest
      // across several replies without a page reload; a failed submit does not
      // cost the member a reply.
      spendOne();

      return result;
    });
  });
});

/**
 * Whether the bot would answer in this discussion, by the same deny-then-allow
 * rule the server applies (Access\AccessResolver::forTags). Replicated rather
 * than asked about, because the answer is needed synchronously inside a submit
 * handler and a round trip there would stall the post.
 */
function answerable(discussion, state) {
  if (!discussion) return false;

  const tags = discussion.tags && discussion.tags();

  // Tags not loaded, or flarum/tags not installed: no basis for claiming the
  // post would have been answered, so stay quiet rather than warn wrongly.
  if (!tags || tags.length === 0) return false;

  const ids = tags.map((tag) => Number(tag.id()));

  if (ids.some((id) => state.deniedTagIds.includes(id))) return false;

  return ids.some((id) => state.allowedTagIds.includes(id));
}

function confirmPostWithoutReply(state) {
  return new Promise((resolve) => {
    app.modal.show(QuotaWarningModal, { quota: state, resolve });
  });
}
