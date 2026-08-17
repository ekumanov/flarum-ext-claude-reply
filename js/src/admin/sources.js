import app from 'flarum/admin/app';

/**
 * Adapters that tell {@link EntityPicker} how to find, resolve and render one
 * kind of thing. Three of them, and they differ in exactly one interesting way:
 * whether the list is small enough to hold in memory.
 *
 * Tags and groups are: both endpoints are unpaginated indexes, so one request
 * up front turns typing into a local filter with no network in the loop. Users
 * are not — a forum has as many as it has — so those are searched server-side
 * through the same `filter[q]` endpoint the composer's mention autocomplete
 * uses.
 */

/**
 * One in-flight request per collection, shared by every picker on the page.
 *
 * Four pool-backed pickers are rendered at once (two for tags, two for groups),
 * and each would otherwise fetch the whole collection on mount — four requests
 * for two collections, every time the settings page is opened.
 */
const pools = {};

function pool(key, load) {
  pools[key] = pools[key] || load();

  return pools[key];
}

function labelUser(user) {
  const name = user.displayName();
  const username = user.username();

  // Nicknames are on for many forums, and "Gene" alone is not enough to pick
  // the right account out of a list. Show both, unless they are the same.
  return name && username && name !== username ? name + ' (' + username + ')' : name || username || '#' + user.id();
}

/**
 * A live-searched source over the users endpoint.
 *
 * Resolving already-selected ids is done one at a time rather than through a
 * bulk filter: the users index has no documented id filter, individual shows
 * are cached by the store, and these lists hold a handful of accounts, not a
 * page of them.
 */
export const userSource = {
  searchPlaceholder: 'ekumanov-claude-reply.admin.picker.search_users',
  emptyLabel: 'ekumanov-claude-reply.admin.picker.empty_users',
  unknownLabel: 'ekumanov-claude-reply.admin.picker.unknown_user',

  search(query) {
    return app.store.find('users', { filter: { q: query }, page: { limit: 8 } });
  },

  resolve(ids) {
    return Promise.all(
      ids.map((id) => {
        const cached = app.store.getById('users', String(id));

        // Resolve rather than reject on a missing account: an id left behind by
        // a deleted member must still render as a removable chip, or the admin
        // cannot clear it.
        return cached ? Promise.resolve(cached) : app.store.find('users', String(id)).catch(() => null);
      })
    );
  },

  label: labelUser,

  icon(user) {
    const url = user.avatarUrl && user.avatarUrl();

    return url ? m('img.ClaudeReply-chipAvatar', { src: url, alt: '' }) : m('i.icon.fas.fa-user', { 'aria-hidden': 'true' });
  },
};

/** Every tag, loaded once. */
export const tagSource = {
  searchPlaceholder: 'ekumanov-claude-reply.admin.picker.search_tags',
  emptyLabel: 'ekumanov-claude-reply.admin.picker.empty_tags',
  unknownLabel: 'ekumanov-claude-reply.admin.picker.unknown_tag',

  all() {
    return pool('tags', () => app.store.find('tags', { page: { limit: 500 } }));
  },

  label(tag) {
    const parent = tag.parent && tag.parent();

    // A bare "Gear" is ambiguous when two parents both have one; the path is
    // what an admin actually recognises.
    return parent ? parent.name() + ' → ' + tag.name() : tag.name();
  },

  icon(tag) {
    return m('span.ClaudeReply-chipSwatch', { style: { background: tag.color() || 'var(--muted-color)' } });
  },
};

/** Every group, loaded once. */
export const groupSource = {
  searchPlaceholder: 'ekumanov-claude-reply.admin.picker.search_groups',
  emptyLabel: 'ekumanov-claude-reply.admin.picker.empty_groups',
  unknownLabel: 'ekumanov-claude-reply.admin.picker.unknown_group',

  all() {
    return pool('groups', () =>
      app.store.find('groups').then((groups) =>
        // The Guest group is excluded deliberately: a guest cannot post, so it
        // can never summon a reply, and offering it would imply otherwise.
        groups.filter((group) => group.id() !== '2')
      )
    );
  },

  label(group) {
    return group.namePlural();
  },

  icon(group) {
    const icon = group.icon();

    return icon ? m('i.icon', { className: icon, 'aria-hidden': 'true' }) : m('i.icon.fas.fa-users', { 'aria-hidden': 'true' });
  },
};
