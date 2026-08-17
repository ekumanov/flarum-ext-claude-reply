/**
 * The settings values behind every picker on this page are comma-separated id
 * lists, and they stay that way on purpose: `allowed_user_ids` and
 * `allowed_tag_ids` predate the pickers, so an install configured by hand keeps
 * working, and a hand-edited value with odd spacing still parses. The server
 * reads them with the same tolerance (Settings\SettingsRepository::idListSetting).
 */

/**
 * @param {string} raw
 * @returns {number[]}
 */
export function parseIds(raw) {
  return String(raw || '')
    .split(/[\s,;]+/)
    .map((part) => part.trim())
    .filter((part) => /^[0-9]+$/.test(part))
    .map(Number)
    .filter((id) => id > 0)
    .filter((id, index, all) => all.indexOf(id) === index);
}

/**
 * @param {number[]} ids
 * @returns {string}
 */
export function formatIds(ids) {
  return ids.join(', ');
}
