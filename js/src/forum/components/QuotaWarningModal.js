import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';

/**
 * "You are out of replies for today — post anyway?"
 *
 * Statically imported, which is safe here because core registers
 * `common/components/Modal` in the main forum bundle rather than in a lazy
 * chunk. (The trap this extension has to respect is importing components that
 * ARE lazy — those compile to a registry lookup that is undefined at evaluation
 * time, which is why ReplyComposer is patched through `flarum.reg.onLoad`.)
 *
 * Resolves the promise the submit handler is waiting on: true to post anyway,
 * false to go back to editing. Dismissing the modal any other way — Escape, the
 * close button, clicking outside — counts as "back to editing", because the one
 * outcome a member cannot want from an accidental dismissal is the irreversible
 * one.
 */
export default class QuotaWarningModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);

    this.answered = false;
  }

  className() {
    return 'ClaudeReply-quotaModal Modal--small';
  }

  title() {
    return app.translator.trans('ekumanov-claude-reply.forum.quota.title');
  }

  content() {
    // `quota`, not `state`: ModalManager passes its OWN `state` attr (the
    // manager's state object) down to the modal component, so an attr of that
    // name is silently overwritten and every interpolation renders as
    // "undefined".
    const quota = this.attrs.quota;
    const bot = '@' + quota.botUsername;

    return m('.Modal-body', [
      m(
        'p',
        quota.perUserLimit === 1
          ? app.translator.trans('ekumanov-claude-reply.forum.quota.exhausted_one', { bot })
          : app.translator.trans('ekumanov-claude-reply.forum.quota.exhausted_many', { bot, limit: quota.perUserLimit })
      ),
      m('.ClaudeReply-quotaActions', [
        m(
          Button,
          {
            className: 'Button Button--primary',
            onclick: () => this.answer(false),
          },
          app.translator.trans('ekumanov-claude-reply.forum.quota.cancel')
        ),
        m(
          Button,
          {
            className: 'Button',
            onclick: () => this.answer(true),
          },
          app.translator.trans('ekumanov-claude-reply.forum.quota.post_anyway')
        ),
      ]),
    ]);
  }

  answer(proceed) {
    this.answered = true;
    this.attrs.resolve(proceed);
    this.hide();
  }

  onremove(vnode) {
    super.onremove(vnode);

    // Dismissed without choosing — treat as "back to editing" so the promise
    // never dangles and the post is not silently submitted.
    if (!this.answered) {
      this.attrs.resolve(false);
    }
  }
}
