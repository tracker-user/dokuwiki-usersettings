# User Settings plugin for DokuWiki

A self-contained, server-side store of per-user preference toggles, with a
self-service settings page for every logged-in user and an admin overview of
everyone's choices.

It is **infrastructure for other plugins**: a feature plugin (or a template's
companion plugin) registers its own toggle with a single event handler, and
this plugin renders it, stores it, and exposes it — without ever needing to be
edited itself.

## Why this plugin exists

DokuWiki core has no server-side per-user preference store. The auth backend
holds only name, e-mail, password and groups, and DokuWiki's built-in
interface preferences live in a browser **cookie** — so they do not follow a
user from one browser or device to another.

This plugin fills that gap. Preferences are stored on the server, keyed by
user, so they apply wherever the person is logged in.

## What a user sees

A **"Preferences"** item appears in the user menu, just to the left of
"Update Profile". It opens a settings page (`do=usersettings`) listing every
registered toggle as a checkbox or a drop-down. The page is a plain HTML form —
no JavaScript — so it works in any browser.

## What an admin sees

Admin → **User Settings** shows a flat, sortable table — one row per
(user × setting): *Display name · Setting · Value · Changed by · Changed at*.
A row shows the user's explicit choice, or the toggle's default (marked as
such) when they never set one. A filter narrows the table to a single setting,
so it stays readable however many toggles exist.

Clicking a display name opens an edit form for that user. This is **Model A+**:
an admin may change anyone's preferences, and the change is recorded under the
admin's name — but it is *not* enforced. The user can always change it back.
This is how you roll out a new feature as default-on while pre-setting it off
for specific people (for example, keeping two conservative admins on the old
theme).

## Storage

One JSON file per user, under `{metadir}/usersettings/`, holding
`{key: {value, changed_at, changed_by}}`. JSON and pretty-printed, so the files
are easy to inspect or back up. The page text and the wiki changelog are never
touched. `changed_by` records whoever made the change — the user, or an admin
acting on their behalf — which is what the overview's "Changed by" column
shows.

## Registering a toggle from another plugin

This is the integration point. Your plugin hooks the
`PLUGIN_USERSETTINGS_REGISTER` event and appends one or more toggle
definitions:

```php
class action_plugin_myfeature extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook(
            'PLUGIN_USERSETTINGS_REGISTER', 'BEFORE', $this, 'registerToggles'
        );
    }

    public function registerToggles(Doku_Event $event)
    {
        // a simple on/off toggle
        $event->data[] = [
            'key'     => 'myfeature_enabled',
            'label'   => 'Enable my feature',
            'type'    => 'checkbox',
            'default' => 1,
            'desc'    => 'Show my feature on wiki pages.',
            'plugin'  => 'myfeature',
        ];

        // a choice from a fixed list
        $event->data[] = [
            'key'     => 'myfeature_mode',
            'label'   => 'My feature mode',
            'type'    => 'select',
            'options' => ['compact' => 'Compact', 'full' => 'Full view'],
            'default' => 'full',
            'plugin'  => 'myfeature',
        ];
    }
}
```

### Toggle definition fields

| Field | Required | Notes |
| --- | --- | --- |
| `key` | yes | Unique identifier; `A-Z a-z 0-9 _` only. Also the storage key and HTML field name. Prefix it with your plugin name to avoid collisions. |
| `label` | yes | Shown on the settings page and in the admin table. |
| `type` | — | `checkbox` (default) or `select`. |
| `default` | — | Default value. Checkbox: `0`/`1`. Select: one of the option keys. |
| `options` | for `select` | A `value => label` map. Required and non-empty for selects. |
| `desc` | — | Optional help text shown under the toggle. |
| `plugin` | — | Optional identifier of the registering plugin/template. |

Invalid definitions (missing key, illegal key characters, a select with no
options) are silently dropped. If two plugins register the same `key`, the
first registration wins.

A toggle definition is plain data, so a **template** can have a toggle too —
ship a tiny companion action plugin that does nothing but register it.

## Built-in toggles

### Interface language

This plugin ships with a built-in **Interface language** toggle that allows
each logged-in user to select their preferred language for DokuWiki's menus
and messages, overriding the site-wide default (`$conf['lang']`).

- **Key:** `lang`
- **Type:** `select`
- **Options:** All installed languages found in `inc/lang/`
- **Default:** The site's configured default language

The language preference is applied as early as possible in the request
lifecycle (during `ACTION_ACT_PREPROCESS`), so all rendering — template hooks,
plugins, the wiki text itself — sees the user's chosen language immediately.

Users can change it in the Preferences page. Admins can set it per-user in the
User Settings admin table. The language list is scanned from the same source
as DokuWiki's Configuration Manager.

## Reading a preference

Your plugin reads the effective value through the helper. `getPreference()`
returns the user's stored value, or the registered default if they never set
one:

```php
$prefs = plugin_load('helper', 'usersettings');
$enabled = $prefs ? $prefs->getPreference('myfeature_enabled', null) : 1;
// pass a username as the 2nd argument, or null for the current user
```

Always provide your own sensible fallback (`? ... : 1` above) in case the
User Settings plugin is not installed.

## Components

| File | Role |
| --- | --- |
| `helper.php` | Storage, the registration event, the read/write API. |
| `action.php` | The user-menu item, the `do=usersettings` settings page, and the built-in interface language toggle. |
| `admin.php` | The admin overview table and per-user edit form. |
| `MenuItem.php` | The user-menu item class. |

## Install

Drop the folder into `lib/plugins/usersettings/`, or use Admin → Extension
Manager → Manual Install. There is nothing to configure.

## Notes

- The settings page and admin pages are plain HTML forms — no JavaScript — so
  there are no old-browser concerns.
- The **built-in interface language toggle** is registered automatically by
  `action.php` and requires no configuration. It scans `inc/lang/` at
  registration time to populate the option list, so all installed languages
  are immediately available to users. The toggle applies the selected language
  during `ACTION_ACT_PREPROCESS`, before any output is produced, ensuring
  consistency throughout the request.
- This is a new, locally-developed plugin with no upstream, so — unlike the
  forked plugins on this wiki — it carries no update-suppression date.

## License

GPL 2, matching DokuWiki.
