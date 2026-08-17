import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';

/**
 * Model picker, populated from the Anthropic Models API.
 *
 * Typing a model id from memory is the easiest way to misconfigure this
 * extension and the slowest to notice: a typo is accepted by the settings page
 * without complaint and only surfaces later as a failed job in the log. The
 * dropdown lists what the configured key can actually call, so the id is either
 * right or not offered.
 *
 * It degrades on purpose. No key, an expired key, a network problem — anything
 * that stops the list loading leaves a plain text field with the current value
 * in it, because a settings page that will not render because a third-party API
 * is unreachable would be a bad trade for a convenience. The manual field is
 * also always reachable through the toggle: a brand-new model may exist before
 * this list, or the account, catches up with it.
 *
 * The endpoint caches for an hour server-side; "Refresh list" bypasses that.
 */
export default class ModelField extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.models = null;
    this.error = null;
    this.loading = true;

    // Manual mode is chosen by the data until the admin says otherwise: the
    // list decides while `userToggled` is false, and stops deciding the moment
    // they pick a mode for themselves. Without that flag a refresh either
    // strands them in the text field after they have fixed their key, or yanks
    // them out of it while they are typing a model id the list does not know
    // about yet.
    this.manual = false;
    this.userToggled = false;

    this.load();
  }

  load(refresh = false) {
    this.loading = true;
    this.error = null;

    return app
      .request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/claude-reply/models' + (refresh ? '?refresh=1' : ''),
      })
      .then((response) => {
        this.models = response.models || [];
        this.error = response.error || null;

        if (!this.userToggled) {
          this.manual = this.models.length === 0 || !this.models.some((model) => model.id === this.attrs.stream());
        }
      })
      .catch((e) => {
        this.models = [];
        this.error = (e && e.message) || 'request failed';

        // No list to choose from, whatever the admin last picked.
        this.manual = true;
      })
      .then(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return m('.Form-group', [
      m('label', app.translator.trans('ekumanov-claude-reply.admin.settings.model')),
      m('.helpText', app.translator.trans('ekumanov-claude-reply.admin.settings.model_help')),
      this.loading ? m('.ClaudeReply-note', [m(LoadingIndicator, { display: 'inline', size: 'small' }), ' ', app.translator.trans('ekumanov-claude-reply.admin.settings.model_loading')]) : this.field(),
    ]);
  }

  field() {
    const stream = this.attrs.stream;
    const hasList = this.models && this.models.length > 0;

    return [
      this.manual
        ? m('input.FormControl', {
            type: 'text',
            value: stream(),
            placeholder: 'claude-opus-5',
            oninput: (e) => stream(e.target.value),
          })
        : m(Select, {
            value: stream(),
            options: this.options(),
            onchange: (value) => stream(value),
          }),
      m('.ClaudeReply-fieldActions', [
        hasList
          ? m(
              Button,
              {
                className: 'Button Button--link',
                onclick: () => {
                  this.manual = !this.manual;
                  this.userToggled = true;
                },
              },
              app.translator.trans(
                this.manual ? 'ekumanov-claude-reply.admin.settings.model_choose' : 'ekumanov-claude-reply.admin.settings.model_custom'
              )
            )
          : null,
        m(
          Button,
          {
            className: 'Button Button--link',
            icon: 'fas fa-sync',
            loading: this.loading,
            onclick: () => this.load(true),
          },
          app.translator.trans('ekumanov-claude-reply.admin.settings.model_refresh')
        ),
      ]),
      this.notes(),
    ];
  }

  options() {
    const options = {};

    this.models.forEach((model) => {
      // maxTokens is the ceiling on the reply, which is the number an admin has
      // to reason about when setting "Max response tokens" — worth showing where
      // the choice is made rather than making them look it up.
      options[model.id] = model.name && model.name !== model.id ? model.name + ' — ' + model.id : model.id;
    });

    return options;
  }

  notes() {
    const notes = [];

    if (this.error) {
      notes.push(
        m(
          '.ClaudeReply-note.ClaudeReply-note--warn',
          app.translator.trans(
            this.models && this.models.length === 0 && /api key/i.test(String(this.error))
              ? 'ekumanov-claude-reply.admin.settings.model_no_key'
              : 'ekumanov-claude-reply.admin.settings.model_unavailable',
            { error: this.error }
          )
        )
      );
    }

    const current = this.models && this.models.find((model) => model.id === this.attrs.stream());

    if (current && current.effort === false) {
      notes.push(m('.ClaudeReply-note.ClaudeReply-note--warn', app.translator.trans('ekumanov-claude-reply.admin.settings.model_effort_unsupported')));
    }

    return notes;
  }
}
