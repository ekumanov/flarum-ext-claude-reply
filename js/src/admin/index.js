import app from 'flarum/admin/app';
import ApiKeyField from './components/ApiKeyField';
import EntityPicker from './components/EntityPicker';
import ModelField from './components/ModelField';
import { userSource, tagSource, groupSource } from './sources';

const PREFIX = 'ekumanov-claude-reply.';

app.initializers.add('ekumanov/claude-reply', () => {
  const reg = app.registry.for('ekumanov-claude-reply');

  const t = (key, params) => app.translator.trans(`ekumanov-claude-reply.admin.settings.${key}`, params);

  /*
   * Settings are grouped under headings. There are eighteen of them now, and a
   * flat column of eighteen fields — half of them about access control, half
   * about spend — is a page nobody reads carefully, which is the wrong property
   * for a page that decides what gets sent to a third-party API.
   *
   * Priorities descend so the sections stay in this order; ItemList sorts high
   * to low. Callback entries take an explicit key because they have no
   * `setting` of their own to be keyed by.
   */
  let priority = 1000;
  const next = () => (priority -= 10);

  const heading = (key) => {
    reg.registerSetting(() => m('h3.ClaudeReply-section', t(`section_${key}`)), next(), `section-${key}`);
  };

  const note = (key, className) => {
    reg.registerSetting(() => m(`.ClaudeReply-note.ClaudeReply-note--${className}`, t(key)), next(), `note-${key}`);
  };

  /**
   * A picker-backed setting. `this` is the ExtensionPage, so `this.setting()`
   * both reads the current value and registers the stream with the page's Save
   * button — the same contract the built-in setting types use.
   */
  const picker = (key, source, opts = {}) => {
    reg.registerSetting(
      function () {
        return m('.Form-group', [
          m('label', t(key)),
          m('.helpText', t(`${key}_help`)),
          m(EntityPicker, {
            stream: this.setting(PREFIX + (opts.setting || key), '', t(key)),
            source,
            single: opts.single || false,
          }),
        ]);
      },
      next(),
      opts.setting || key
    );
  };

  /*
   * Exemption from the per-member cap, as a permission rather than another id
   * list: "which groups are trusted here" is what the permission grid is for,
   * and moderators are usually the answer. Admins pass it implicitly, as they
   * pass every permission. The forum-wide cap is deliberately not bypassable —
   * see Access\ReplyQuota::mayBypass.
   */
  reg.registerPermission(
    {
      icon: 'fas fa-robot',
      label: app.translator.trans('ekumanov-claude-reply.admin.permissions.bypass_user_limit'),
      permission: 'ekumanov-claude-reply.bypass-user-limit',
    },
    'reply',
    95
  );

  // --- Connection ----------------------------------------------------------
  heading('general');

  reg.registerSetting(
    {
      setting: PREFIX + 'enabled',
      type: 'boolean',
      label: t('enabled'),
      help: t('enabled_help'),
    },
    next()
  );

  reg.registerSetting(
    function () {
      return m(ApiKeyField, { stream: this.setting(PREFIX + 'api_key', '', t('api_key')) });
    },
    next(),
    'api_key'
  );

  reg.registerSetting(
    function () {
      const stream = this.setting(PREFIX + 'bot_user_id', '', t('bot_account'));
      const legacy = app.data.settings[PREFIX + 'bot_username'];

      return m('.Form-group', [
        m('label', t('bot_account')),
        m('.helpText', t('bot_account_help')),
        m(EntityPicker, { stream, source: userSource, single: true }),
        // Installs configured before this was a picker have only a username.
        // Say so rather than showing an empty control that looks unconfigured.
        !stream() && legacy ? m('.ClaudeReply-note.ClaudeReply-note--info', t('bot_account_unset', { username: legacy })) : null,
      ]);
    },
    next(),
    'bot_user_id'
  );

  // --- Who may summon a reply ---------------------------------------------
  heading('access');
  note('access_intro', 'info');

  reg.registerSetting(
    {
      setting: PREFIX + 'admin_bypass_access',
      type: 'boolean',
      label: t('admin_bypass_access'),
      help: t('admin_bypass_access_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'users_blocklist_mode',
      type: 'boolean',
      label: t('users_blocklist_mode'),
      help: t('users_blocklist_mode_help'),
    },
    next()
  );

  picker('allowed_users', userSource, { setting: 'allowed_user_ids' });
  picker('denied_users', userSource, { setting: 'denied_user_ids' });
  picker('allowed_groups', groupSource, { setting: 'allowed_group_ids' });
  picker('denied_groups', groupSource, { setting: 'denied_group_ids' });

  // --- Where it may reply --------------------------------------------------
  heading('where');

  reg.registerSetting(
    {
      setting: PREFIX + 'tags_blocklist_mode',
      type: 'boolean',
      label: t('tags_blocklist_mode'),
      help: t('tags_blocklist_mode_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'admin_bypass_tags',
      type: 'boolean',
      label: t('admin_bypass_tags'),
      help: t('admin_bypass_tags_help'),
    },
    next()
  );

  picker('allowed_tags', tagSource, { setting: 'allowed_tag_ids' });
  picker('denied_tags', tagSource, { setting: 'denied_tag_ids' });

  // --- Spend limits --------------------------------------------------------
  heading('limits');

  reg.registerSetting(
    {
      setting: PREFIX + 'per_user_daily_limit',
      type: 'number',
      min: 0,
      default: 2,
      label: t('per_user_daily_limit'),
      help: t('per_user_daily_limit_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'daily_reply_limit',
      type: 'number',
      min: 0,
      default: 25,
      label: t('daily_reply_limit'),
      help: t('daily_reply_limit_help'),
    },
    next()
  );

  // --- Model and generation ------------------------------------------------
  heading('model');

  reg.registerSetting(
    function () {
      return m(ModelField, { stream: this.setting(PREFIX + 'model', '', t('model')) });
    },
    next(),
    'model'
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'effort',
      type: 'select',
      options: {
        low: 'low',
        medium: 'medium',
        high: 'high',
        xhigh: 'xhigh',
        max: 'max',
      },
      default: 'medium',
      label: t('effort'),
      help: t('effort_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'max_tokens',
      type: 'number',
      min: 1024,
      default: 8000,
      label: t('max_tokens'),
      help: t('max_tokens_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'context_token_budget',
      type: 'number',
      min: 1000,
      default: 20000,
      label: t('context_token_budget'),
      help: t('context_token_budget_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'max_context_posts',
      type: 'number',
      min: 1,
      default: 25,
      label: t('max_context_posts'),
      help: t('max_context_posts_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'trigger_on_quote',
      type: 'boolean',
      label: t('trigger_on_quote'),
      help: t('trigger_on_quote_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'web_search',
      type: 'boolean',
      label: t('web_search'),
      help: t('web_search_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'web_search_max_uses',
      type: 'number',
      min: 1,
      default: 5,
      label: t('web_search_max_uses'),
      help: t('web_search_max_uses_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'forum_search',
      type: 'boolean',
      label: t('forum_search'),
      help: t('forum_search_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'forum_search_max_uses',
      type: 'number',
      min: 1,
      default: 4,
      label: t('forum_search_max_uses'),
      help: t('forum_search_max_uses_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'forum_search_results',
      type: 'number',
      min: 1,
      max: 20,
      default: 12,
      label: t('forum_search_results'),
      help: t('forum_search_results_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'forum_search_posts_read',
      type: 'number',
      min: 1,
      max: 50,
      default: 15,
      label: t('forum_search_posts_read'),
      help: t('forum_search_posts_read_help'),
    },
    next()
  );

  // --- Voice ---------------------------------------------------------------
  heading('voice');

  reg.registerSetting(
    {
      setting: PREFIX + 'persona_prompt',
      type: 'textarea',
      label: t('persona_prompt'),
      help: t('persona_prompt_help'),
    },
    next()
  );

  reg.registerSetting(
    {
      setting: PREFIX + 'footer',
      type: 'textarea',
      label: t('footer'),
      help: t('footer_help'),
      placeholder: '*This reply was generated by Claude.*',
    },
    next()
  );
});
