<?php

/**
 * User Settings plugin — storage and registration helper.
 *
 * This component owns three things:
 *
 *   1. The per-user preference store. One JSON file per user under the meta
 *      directory, holding {key => {value, changed_at, changed_by}}. JSON is
 *      used deliberately so the files are human-readable and easy to inspect
 *      or back up.
 *
 *   2. The PLUGIN_USERSETTINGS_REGISTER event. Other plugins (and template
 *      companion plugins) hook it to declare their own toggles, so this
 *      plugin never needs editing when a new toggle is added elsewhere.
 *
 *   3. The read/write API used by this plugin's own settings page and admin
 *      table, and by feature plugins that want to read a user's preference.
 *
 * Access control is intentionally NOT enforced here — this is a storage
 * primitive. Callers are responsible: the settings page only ever writes the
 * current user's own file, and the admin component is gated to admins by
 * DokuWiki's admin dispatcher.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

class helper_plugin_usersettings extends DokuWiki_Plugin
{
    /** Event other plugins hook to declare their toggles. */
    const REGISTER_EVENT = 'PLUGIN_USERSETTINGS_REGISTER';

    /** @var array|null cached, normalised toggle definitions for this request */
    protected $toggles = null;

    // ---------------------------------------------------------------------
    //  Storage location
    // ---------------------------------------------------------------------

    /**
     * Directory holding the per-user preference files.
     *
     * Lives under the meta directory: persistent (unlike the cache dir) and,
     * because it is inside data/, not served by the web server.
     *
     * @return string absolute path (created if missing)
     */
    public function getStorePath()
    {
        global $conf;
        $path = $conf['metadir'] . '/usersettings';
        io_mkdir_p($path);
        return $path;
    }

    /**
     * Absolute path of one user's preference file. The username is
     * rawurlencoded so any character is safe in a filename.
     *
     * @param string $user
     * @return string
     */
    protected function getUserFile($user)
    {
        return $this->getStorePath() . '/' . rawurlencode($user) . '.json';
    }

    // ---------------------------------------------------------------------
    //  Raw per-user data
    // ---------------------------------------------------------------------

    /**
     * Load one user's stored preferences.
     *
     * @param string $user
     * @return array  [key => ['value'=>mixed, 'changed_at'=>int, 'changed_by'=>string]]
     *                empty array if the user has no stored preferences
     */
    public function loadUserData($user)
    {
        $file = $this->getUserFile($user);
        if (!file_exists($file)) {
            return [];
        }
        $raw = io_readFile($file, false);
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // ---------------------------------------------------------------------
    //  Registered toggles
    // ---------------------------------------------------------------------

    /**
     * Collect the toggle definitions contributed by other plugins.
     *
     * Fires PLUGIN_USERSETTINGS_REGISTER; handlers append definition arrays
     * to the event data. Each definition is validated and normalised;
     * unusable ones are dropped, and a key registered more than once keeps
     * its first registration. Cached for the duration of the request.
     *
     * @return array  [key => normalised definition]
     */
    public function getRegisteredToggles()
    {
        if ($this->toggles !== null) {
            return $this->toggles;
        }

        $raw = [];
        \dokuwiki\Extension\Event::createAndTrigger(self::REGISTER_EVENT, $raw);

        $toggles = [];
        foreach ((array) $raw as $def) {
            $def = $this->normaliseDefinition($def);
            if ($def === null) {
                continue; // invalid — skip
            }
            if (isset($toggles[$def['key']])) {
                continue; // duplicate key — first registration wins
            }
            $toggles[$def['key']] = $def;
        }

        $this->toggles = $toggles;
        return $toggles;
    }

    /**
     * Get one toggle definition by key.
     *
     * @param string $key
     * @return array|null
     */
    public function getToggle($key)
    {
        $toggles = $this->getRegisteredToggles();
        return $toggles[$key] ?? null;
    }

    /**
     * Validate and normalise one toggle definition supplied by a registering
     * plugin. Returns a clean definition with every expected field present,
     * or null if the definition is unusable.
     *
     * Expected input fields:
     *   key      (string, required)  unique identifier; [A-Za-z0-9_] only
     *   label    (string, required)  shown on the settings page / admin table
     *   type     (string)            'checkbox' (default) or 'select'
     *   default  (mixed)             default value
     *   options  (array)             value=>label map; required for 'select'
     *   desc     (string, optional)  help text
     *   plugin   (string, optional)  id of the registering plugin/template
     *
     * @param mixed $def
     * @return array|null
     */
    protected function normaliseDefinition($def)
    {
        if (!is_array($def)) return null;
        if (empty($def['key']) || !is_string($def['key'])) return null;
        if (empty($def['label']) || !is_string($def['label'])) return null;

        // the key is used as a storage key and an HTML form field name
        if (!preg_match('/^[A-Za-z0-9_]+$/', $def['key'])) return null;

        $type = $def['type'] ?? 'checkbox';
        if (!in_array($type, ['checkbox', 'select'], true)) {
            $type = 'checkbox';
        }

        $clean = [
            'key'    => $def['key'],
            'label'  => $def['label'],
            'type'   => $type,
            'desc'   => (isset($def['desc']) && is_string($def['desc'])) ? $def['desc'] : '',
            'plugin' => (isset($def['plugin']) && is_string($def['plugin'])) ? $def['plugin'] : '',
        ];

        if ($type === 'select') {
            // a select needs a non-empty value=>label option map
            if (empty($def['options']) || !is_array($def['options'])) {
                return null;
            }
            $clean['options'] = $def['options'];
            // the default must be one of the option keys
            $default = $def['default'] ?? null;
            if ($default === null || !array_key_exists($default, $def['options'])) {
                $default = array_key_first($def['options']);
            }
            $clean['default'] = $default;
        } else {
            // checkbox: default coerced to a clean 0/1
            $clean['default'] = empty($def['default']) ? 0 : 1;
        }

        return $clean;
    }

    // ---------------------------------------------------------------------
    //  Read API
    // ---------------------------------------------------------------------

    /**
     * The effective value of a preference for a user: the value the user has
     * explicitly stored, or — if they never set one — the toggle's registered
     * default. This is the method feature plugins call.
     *
     * @param string      $key
     * @param string|null $user  defaults to the current user
     * @return mixed  the value, or null if $key is not a registered toggle
     */
    public function getPreference($key, $user = null)
    {
        $toggle = $this->getToggle($key);
        if ($toggle === null) {
            return null; // unknown toggle
        }

        if ($user === null) {
            global $INPUT;
            $user = $INPUT->server->str('REMOTE_USER');
        }
        if ($user === '') {
            return $toggle['default']; // anonymous — no stored preferences
        }

        $data = $this->loadUserData($user);
        if (isset($data[$key]) && array_key_exists('value', $data[$key])) {
            return $data[$key]['value'];
        }
        return $toggle['default'];
    }

    /**
     * The stored record for one key (value + changed_at + changed_by), or null
     * if the user has no explicit value for it. Used by the admin table to
     * show who changed a setting and when.
     *
     * @param string $key
     * @param string $user
     * @return array|null
     */
    public function getRecord($key, $user)
    {
        $data = $this->loadUserData($user);
        return $data[$key] ?? null;
    }

    // ---------------------------------------------------------------------
    //  Write API
    // ---------------------------------------------------------------------

    /**
     * Write one or more preferences for a user.
     *
     * Each value is validated against its registered toggle definition:
     * unknown keys are ignored, checkbox values coerced to 0/1, and select
     * values must be a defined option. The timestamp and the actor (whoever
     * is making the change — the user themselves, or an admin) are recorded.
     *
     * @param array  $values  [key => value]
     * @param string $user    whose preferences are being written
     * @param string $actor   who is making the change
     * @return bool  true on success (also true when there was nothing to change)
     */
    public function setPreferences(array $values, $user, $actor)
    {
        if ($user === '' || $user === null || $actor === '' || $actor === null) {
            return false;
        }

        $toggles = $this->getRegisteredToggles();
        $file    = $this->getUserFile($user);

        io_lock($file);

        $data    = $this->loadUserData($user);
        $now     = time();
        $changed = false;

        foreach ($values as $key => $value) {
            if (!isset($toggles[$key])) {
                continue; // not a registered toggle — ignore
            }
            $clean = $this->sanitiseValue($toggles[$key], $value);
            if ($clean === null) {
                continue; // invalid value for this toggle — ignore
            }
            // no-op write avoidance: skip if the value is unchanged
            if (isset($data[$key]) && $data[$key]['value'] === $clean) {
                continue;
            }
            $data[$key] = [
                'value'      => $clean,
                'changed_at' => $now,
                'changed_by' => $actor,
            ];
            $changed = true;
        }

        if (!$changed) {
            io_unlock($file);
            return true; // nothing to write — treated as success
        }

        $ok = io_saveFile($file, json_encode($data, JSON_PRETTY_PRINT));
        io_unlock($file);
        return (bool) $ok;
    }

    /**
     * Coerce a submitted value to a valid value for a toggle.
     *
     * @param array $toggle  a normalised toggle definition
     * @param mixed $value
     * @return mixed|null  the clean value, or null if it cannot be used
     */
    protected function sanitiseValue(array $toggle, $value)
    {
        if (!is_scalar($value)) {
            return null;
        }
        if ($toggle['type'] === 'select') {
            return array_key_exists($value, $toggle['options']) ? $value : null;
        }
        // checkbox
        return empty($value) ? 0 : 1;
    }
}
