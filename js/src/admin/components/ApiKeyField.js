import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';

/** Must match Settings\ApiKeyPrivacy::MASK. */
const MASK = '••••••••';

/**
 * The API key field.
 *
 * The value this renders is never the real key. `Settings\ApiKeyPrivacy`
 * replaces a stored key with {@link MASK} before the settings table is
 * serialised into the admin page, so the secret is not in the DOM to begin
 * with. What makes the round trip safe is Flarum's own save behaviour: the page
 * posts only settings whose stream differs from `app.data.settings`, so a field
 * left alone is never submitted, and the mask cannot be written back over the
 * key. (The server drops a submitted mask anyway.)
 *
 * That leaves clearing, which falls out for free: empty the box and an empty
 * string is submitted, which `Extend\Settings::resetWhen` turns into a deleted
 * row. Hence the hint text — "clear the field to remove it" is a real
 * instruction, not a figure of speech.
 *
 * The status line matters more than it looks. Three sources feed the key and
 * config.php wins, so an admin can type a perfectly good key here and see no
 * change in behaviour. Rather than let that be a mystery, the server sends which
 * source is actually in use and this says so.
 */
export default class ApiKeyField extends Component {
  view() {
    const stream = this.attrs.stream;
    const source = app.data.settings['ekumanov-claude-reply.api_key_source'] || '';
    const stored = source === 'settings' || stream() === MASK;

    return m('.Form-group', [
      m('label', app.translator.trans('ekumanov-claude-reply.admin.settings.api_key')),
      m('.helpText', app.translator.trans('ekumanov-claude-reply.admin.settings.api_key_help')),
      m('input.FormControl', {
        type: 'password',
        autocomplete: 'off',
        spellcheck: false,
        value: stream(),
        placeholder: extractText(app.translator.trans('ekumanov-claude-reply.admin.settings.api_key_placeholder')),
        oninput: (e) => stream(e.target.value),
      }),
      this.status(source, stored),
    ]);
  }

  status(source, stored) {
    if (source === 'config.php' || source === 'environment') {
      return m(
        '.ClaudeReply-note.ClaudeReply-note--info',
        app.translator.trans('ekumanov-claude-reply.admin.settings.api_key_overridden', { source })
      );
    }

    if (stored) {
      return m('.ClaudeReply-note.ClaudeReply-note--ok', app.translator.trans('ekumanov-claude-reply.admin.settings.api_key_stored'));
    }

    return m('.ClaudeReply-note.ClaudeReply-note--warn', app.translator.trans('ekumanov-claude-reply.admin.settings.api_key_missing'));
  }
}
