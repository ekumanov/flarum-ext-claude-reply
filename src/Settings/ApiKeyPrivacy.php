<?php

namespace Ekumanov\ClaudeReply\Settings;

use Flarum\Settings\Event\Deserializing;
use Flarum\Settings\Event\Saving;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Keeps a settings-table API key out of the browser.
 *
 * Flarum builds the admin page by dumping the whole settings table into the
 * page payload (`Flarum\Admin\Content\AdminPayload` — `$this->settings->all()`
 * straight into `payload['settings']`). A secret stored as an ordinary setting
 * is therefore sitting in the HTML of every admin page load, readable by any
 * admin and by every other extension's admin JavaScript.
 *
 * Core dispatches {@see Deserializing} with that array *by reference* first,
 * which is the seam this class uses:
 *
 *   - On the way out the real key is replaced with {@see self::MASK}, so the
 *     value never reaches the client. A synthetic `api_key_source` entry goes
 *     along with it so the admin UI can show where the active key actually
 *     comes from — including the case where config.php is silently winning
 *     over what the admin typed into the field.
 *   - On the way back in, a submitted value equal to the mask is dropped, so
 *     saving the settings page without touching the field cannot overwrite the
 *     stored key with a row of bullet characters.
 *
 * The round trip works because Flarum's admin page posts only *modified*
 * settings (`AdminPage.dirty()` diffs each stream against
 * `app.data.settings`). An untouched masked field is never submitted at all;
 * the Saving guard is the belt to that braces, for any other code path that
 * submits the full set. Clearing the field submits an empty string, which
 * `Extend\Settings::resetWhen` turns into a row deletion — so emptying the box
 * really does remove the key.
 */
final class ApiKeyPrivacy
{
    /**
     * Stand-in for a stored key. Any string works as long as it is stable
     * across page loads (or every load would look like an unsaved change) and
     * could never be a real key.
     */
    public const MASK = '••••••••';

    public const SOURCE_SETTING = SettingsRepository::PREFIX.'api_key_source';

    public function __construct(private readonly ApiKey $apiKey) {}

    /**
     * Registered through `Extend\Event::subscribe`, not `listen`.
     *
     * One class handles both halves of the round trip because they are one
     * decision — mask on the way out, ignore the mask on the way back — and
     * splitting them into two listener classes would let one be changed without
     * the other. `listen()` cannot express it: its `callable|string` type
     * rejects `[self::class, 'method']` for non-static methods, whatever its
     * docblock says, so a two-method class has to arrive as a subscriber.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Deserializing::class => 'whenDeserializing',
            Saving::class => 'whenSaving',
        ];
    }

    public function whenDeserializing(Deserializing $event): void
    {
        if (($event->settings[ApiKey::SETTING] ?? '') !== '') {
            $event->settings[ApiKey::SETTING] = self::MASK;
        }

        // Not a real setting — nothing registers or writes it. It is a
        // read-only hint for the admin UI: which of the three sources the
        // active key is actually coming from, so an admin typing into the
        // field can see when config.php is quietly overriding it.
        $event->settings[self::SOURCE_SETTING] = (string) $this->apiKey->source();
    }

    public function whenSaving(Saving $event): void
    {
        if (! array_key_exists(ApiKey::SETTING, $event->settings)) {
            return;
        }

        $submitted = (string) $event->settings[ApiKey::SETTING];

        if (trim($submitted) === self::MASK) {
            // Unchanged field — leave the stored key alone. SetSettingsController
            // iterates this array after the event, so unsetting is enough.
            unset($event->settings[ApiKey::SETTING]);

            return;
        }

        // A key pasted from a console or a password manager routinely arrives
        // with a trailing newline, which would then be sent in the auth header.
        $event->settings[ApiKey::SETTING] = trim($submitted);
    }
}
