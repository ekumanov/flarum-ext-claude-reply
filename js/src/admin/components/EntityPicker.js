import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import { parseIds, formatIds } from '../util/idList';

/**
 * Type-to-search, click-to-add, click-to-remove list of members, tags or groups.
 *
 * This replaces four text fields that took raw ids. Those fields worked, but
 * every use of them started with a trip to the database or the admin user list
 * to look up a number, and a typo in one silently changed who could spend money
 * — a wrong id is still a *valid* id.
 *
 * Two deliberate choices:
 *
 * - **Results render inline, below the input, not in a floating dropdown.** No
 *   positioning, no z-index, no clipping inside the settings page's scroll
 *   container. On a settings form nobody minds the page growing by three rows.
 * - **The stored value stays a comma-separated id list.** The picker is a new
 *   editor for the existing setting, not a new setting, so nothing needs
 *   migrating and a hand-configured install is untouched.
 *
 * @property {Stream<string>} attrs.stream  the setting, as a CSV of ids
 * @property {object}         attrs.source  see sources.js
 */
export default class EntityPicker extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    /** id → model, or null for an id that no longer resolves */
    this.models = {};
    this.query = '';
    this.results = [];
    this.loading = true;
    this.searching = false;

    /** Populated for tag/group sources, which load their whole list once. */
    this.pool = null;

    const source = this.attrs.source;
    const ids = this.ids();

    const ready = source.all
      ? source.all().then((all) => {
          this.pool = all;
          all.forEach((model) => (this.models[model.id()] = model));
        })
      : source.resolve(ids).then((models) => {
          models.forEach((model, index) => (this.models[String(ids[index])] = model));
        });

    ready
      .catch(() => {})
      .then(() => {
        this.loading = false;
        m.redraw();
      });
  }

  ids() {
    return parseIds(this.attrs.stream());
  }

  setIds(ids) {
    this.attrs.stream(formatIds(ids));
  }

  add(model) {
    const id = Number(model.id());
    const ids = this.ids();

    this.models[model.id()] = model;

    if (this.attrs.single) {
      // Single-value mode shares this component because the search, resolution
      // and "id no longer exists" handling are identical; only the arity differs.
      this.setIds([id]);
    } else if (!ids.includes(id)) {
      this.setIds(ids.concat([id]));
    }

    // Clearing the query after a pick keeps the list usable for adding several
    // in a row without having to select-all and retype.
    this.query = '';
    this.results = [];
  }

  remove(id) {
    this.setIds(this.ids().filter((existing) => existing !== id));
  }

  onQuery(value) {
    this.query = value;

    if (this.pool) {
      this.results = this.filterPool(value);
      return;
    }

    if (value.length < 2) {
      this.results = [];
      this.searching = false;
      return;
    }

    this.searching = true;

    // Only the newest query's results are allowed to land. Without this a slow
    // response for "al" can arrive after a fast one for "alice" and replace the
    // list with stale matches.
    const token = (this.searchToken = {});

    this.attrs.source
      .search(value)
      .then((results) => {
        if (this.searchToken !== token) return;

        this.results = results;
        this.searching = false;
        m.redraw();
      })
      .catch(() => {
        if (this.searchToken !== token) return;

        this.results = [];
        this.searching = false;
        m.redraw();
      });
  }

  filterPool(value) {
    const needle = value.trim().toLowerCase();
    const source = this.attrs.source;

    return this.pool
      .filter((model) => needle === '' || String(source.label(model)).toLowerCase().includes(needle))
      .slice(0, 12);
  }

  view() {
    const source = this.attrs.source;
    const ids = this.ids();

    return m('.ClaudeReply-picker', [
      m('.ClaudeReply-chips', this.loading ? m(LoadingIndicator, { display: 'inline', size: 'small' }) : this.chips(ids)),
      m('input.FormControl.ClaudeReply-pickerInput', {
        type: 'search',
        value: this.query,
        placeholder: extractText(app.translator.trans(source.searchPlaceholder)),
        oninput: (e) => this.onQuery(e.target.value),
        // A pool-backed source can show its whole (filtered) list on focus;
        // there is nothing to wait for.
        onfocus: () => this.pool && this.onQuery(this.query),
      }),
      this.resultList(ids),
    ]);
  }

  chips(ids) {
    if (ids.length === 0) {
      return m('span.ClaudeReply-chipsEmpty', app.translator.trans(this.attrs.source.emptyLabel));
    }

    const source = this.attrs.source;

    return ids.map((id) => {
      const model = this.models[String(id)];
      const label = model ? source.label(model) : app.translator.trans(source.unknownLabel, { id });

      return m('span.ClaudeReply-chip', { className: model ? '' : 'ClaudeReply-chip--unknown', key: id }, [
        model ? source.icon(model) : m('i.icon.fas.fa-question-circle', { 'aria-hidden': 'true' }),
        m('span.ClaudeReply-chipLabel', label),
        m(
          Button,
          {
            className: 'Button Button--link ClaudeReply-chipRemove',
            icon: 'fas fa-times',
            title: extractText(app.translator.trans('ekumanov-claude-reply.admin.picker.remove')),
            onclick: () => this.remove(id),
          },
          ''
        ),
      ]);
    });
  }

  resultList(ids) {
    if (this.searching) {
      return m('.ClaudeReply-results', m('.ClaudeReply-resultsEmpty', app.translator.trans('ekumanov-claude-reply.admin.picker.searching')));
    }

    if (this.query === '' && !this.pool) return null;
    if (this.results.length === 0 && this.query === '') return null;

    const available = this.results.filter((model) => !ids.includes(Number(model.id())));

    if (available.length === 0) {
      return m('.ClaudeReply-results', m('.ClaudeReply-resultsEmpty', app.translator.trans('ekumanov-claude-reply.admin.picker.no_results')));
    }

    const source = this.attrs.source;

    return m(
      '.ClaudeReply-results',
      available.map((model) =>
        m(
          'button.ClaudeReply-result',
          {
            type: 'button',
            key: model.id(),
            onclick: () => this.add(model),
          },
          [source.icon(model), m('span', source.label(model))]
        )
      )
    );
  }
}
