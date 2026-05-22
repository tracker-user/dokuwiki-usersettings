<?php

/**
 * User Settings plugin — admin component.
 *
 * Provides an admin-only overview of every user's preferences as a flat,
 * sortable table: one row per (user x setting), with the columns
 *   Display name | Setting | Value | Changed by | Changed at.
 * A row's value is the user's explicit choice, or the toggle's default
 * (marked as such) when they never set one. A filter narrows the table to a
 * single setting; the table never grows wider as more toggles are added.
 *
 * Clicking a display name opens a per-user edit form (Model A+: an admin may
 * change anyone's preferences). Such a change is stored with the admin as the
 * recorded actor, and the user can still change it back themselves later.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

class admin_plugin_usersettings extends DokuWiki_Admin_Plugin
{
    /** @var string[] sort key => row field used for that sort */
    protected $sortFields = [
        'name'      => 'display_name',
        'setting'   => 'setting_label',
        'value'     => 'value_display',
        'changedby' => 'changed_by_display',
        'changedat' => 'changed_at',
    ];

    // ---------------------------------------------------------------------
    //  Admin plugin metadata
    // ---------------------------------------------------------------------

    /** Only administrators may see other users' preferences. */
    public function forAdminOnly()
    {
        return true;
    }

    /** Position in the admin menu. */
    public function getMenuSort()
    {
        return 350;
    }

    /** Admin menu label — distinct from the user menu's "Preferences". */
    public function getMenuText($language)
    {
        return $this->getLang('admin_menu');
    }

    // ---------------------------------------------------------------------
    //  Component access
    // ---------------------------------------------------------------------

    /** @return helper_plugin_usersettings|null */
    protected function getHelper()
    {
        return plugin_load('helper', 'usersettings');
    }

    /** @return action_plugin_usersettings|null */
    protected function getActionPlugin()
    {
        return plugin_load('action', 'usersettings');
    }

    // ---------------------------------------------------------------------
    //  Request handling
    // ---------------------------------------------------------------------

    /**
     * Handle a submitted per-user edit form.
     *
     * Runs only for admins (DokuWiki's admin dispatcher enforces
     * forAdminOnly() before this is called). Uses Post/Redirect/Get.
     */
    public function handle()
    {
        global $INPUT, $ID;

        if (!$INPUT->post->bool('usersettings_adminsave')) {
            return;
        }
        if (!checkSecurityToken()) {
            return;
        }

        $this->processAdminSave();

        // Post/Redirect/Get back to the overview
        send_redirect(wl($ID, ['do' => 'admin', 'page' => 'usersettings'], true, '&'));
    }

    /**
     * Validate the target user and store the submitted preferences for them,
     * recording the acting admin as the actor. Kept redirect-free so it can
     * be tested directly.
     *
     * @return bool
     */
    public function processAdminSave()
    {
        global $INPUT, $auth;

        $target = $INPUT->post->str('edituser');
        $admin  = $INPUT->server->str('REMOTE_USER');

        $userData = ($auth !== null) ? $auth->getUserData($target) : false;
        if ($userData === false) {
            msg($this->getLang('badidentuser'), -1);
            return false;
        }

        $action = $this->getActionPlugin();
        $ok = ($action !== null) && $action->saveSubmittedPreferences($target, $admin);

        $name = ($userData['name'] !== '') ? $userData['name'] : $target;
        msg(
            sprintf($this->getLang($ok ? 'adminsaved' : 'adminsavefail'), hsc($name)),
            $ok ? 1 : -1
        );
        return $ok;
    }

    // ---------------------------------------------------------------------
    //  Output
    // ---------------------------------------------------------------------

    /**
     * Render either the overview table or, when an edituser parameter is
     * present, the per-user edit form.
     */
    public function html()
    {
        global $INPUT;

        $edituser = $INPUT->get->str('edituser');
        if ($edituser !== '') {
            echo $this->renderEditForm($edituser);
        } else {
            echo $this->renderTable();
        }
    }

    // ---- overview table --------------------------------------------------

    /**
     * Build the rows of the overview: one per (user x toggle).
     *
     * @param array $users   [username => userdata] as from $auth->retrieveUsers()
     * @param array $toggles registered toggle definitions
     * @return array list of row arrays
     */
    public function buildRows(array $users, array $toggles)
    {
        $helper = $this->getHelper();
        if ($helper === null) {
            return [];
        }

        $rows = [];
        foreach ($users as $username => $userData) {
            $displayName = (!empty($userData['name'])) ? $userData['name'] : $username;
            $stored = $helper->loadUserData($username);

            foreach ($toggles as $key => $def) {
                if (isset($stored[$key]) && array_key_exists('value', $stored[$key])) {
                    $value     = $stored[$key]['value'];
                    $changedBy = $stored[$key]['changed_by'] ?? '';
                    $changedAt = (int) ($stored[$key]['changed_at'] ?? 0);
                    $isDefault = false;
                } else {
                    $value     = $def['default'];
                    $changedBy = '';
                    $changedAt = 0;
                    $isDefault = true;
                }

                $rows[] = [
                    'user'               => $username,
                    'display_name'       => $displayName,
                    'setting_key'        => $key,
                    'setting_label'      => $def['label'],
                    'value_display'      => $this->displayValue($def, $value),
                    'is_default'         => $isDefault,
                    'changed_by_display' => $isDefault ? '' : $this->resolveActor($changedBy, $users),
                    'changed_at'         => $changedAt,
                ];
            }
        }
        return $rows;
    }

    /**
     * Sort overview rows by the given column and direction.
     *
     * @param array  $rows
     * @param string $sort one of the keys of $this->sortFields
     * @param string $dir  'asc' or 'desc'
     * @return array
     */
    public function sortRows(array $rows, $sort, $dir)
    {
        $field = $this->sortFields[$sort] ?? 'display_name';

        usort($rows, function ($a, $b) use ($field) {
            if ($field === 'changed_at') {
                return $a[$field] <=> $b[$field];
            }
            return strcasecmp((string) $a[$field], (string) $b[$field]);
        });

        if ($dir === 'desc') {
            $rows = array_reverse($rows);
        }
        return $rows;
    }

    /**
     * Human-readable value of a toggle: On/Off for a checkbox, the option
     * label for a select.
     *
     * @param array $def
     * @param mixed $value
     * @return string
     */
    public function displayValue(array $def, $value)
    {
        if ($def['type'] === 'select') {
            if (isset($def['options'][$value])) {
                return (string) $def['options'][$value];
            }
            return (string) $value; // stored value no longer a defined option
        }
        return $this->getLang(empty($value) ? 'val_off' : 'val_on');
    }

    /**
     * Resolve an actor username to a display name, falling back to the raw
     * username when the actor is not (or no longer) a known user.
     *
     * @param string $actor
     * @param array  $users [username => userdata]
     * @return string
     */
    protected function resolveActor($actor, array $users)
    {
        if ($actor === '') {
            return '';
        }
        if (isset($users[$actor]) && !empty($users[$actor]['name'])) {
            return $users[$actor]['name'];
        }
        return $actor;
    }

    /**
     * Render the overview table.
     *
     * @return string
     */
    protected function renderTable()
    {
        global $INPUT, $auth;

        $helper  = $this->getHelper();
        $toggles = $helper ? $helper->getRegisteredToggles() : [];

        $html  = '<div class="plugin_usersettings_admin">';
        $html .= '<h1>' . hsc($this->getLang('admin_heading')) . '</h1>';

        if (empty($toggles)) {
            return $html . '<p>' . hsc($this->getLang('notoggles')) . '</p></div>';
        }

        $users = ($auth !== null) ? $auth->retrieveUsers(0, 0) : [];
        if (empty($users)) {
            return $html . '<p>' . hsc($this->getLang('nousers')) . '</p></div>';
        }

        // request parameters (sort links and the filter form are GET)
        $sort   = $INPUT->get->str('sort', 'name');
        $dir    = ($INPUT->get->str('dir') === 'desc') ? 'desc' : 'asc';
        $filter = $INPUT->get->str('filter');
        if (!isset($this->sortFields[$sort])) {
            $sort = 'name';
        }
        if ($filter !== '' && !isset($toggles[$filter])) {
            $filter = '';
        }

        $html .= '<p>' . hsc($this->getLang('admin_intro')) . '</p>';
        $html .= $this->renderFilter($toggles, $sort, $dir, $filter);

        // rows
        $rows = $this->buildRows($users, $toggles);
        if ($filter !== '') {
            $rows = array_values(array_filter($rows, function ($r) use ($filter) {
                return $r['setting_key'] === $filter;
            }));
        }
        $rows = $this->sortRows($rows, $sort, $dir);

        // table
        $html .= '<table class="inline plugin_usersettings_table">';
        $html .= '<thead><tr>';
        $html .= $this->sortHeader($this->getLang('th_name'), 'name', $sort, $dir, $filter);
        $html .= $this->sortHeader($this->getLang('th_setting'), 'setting', $sort, $dir, $filter);
        $html .= $this->sortHeader($this->getLang('th_value'), 'value', $sort, $dir, $filter);
        $html .= $this->sortHeader($this->getLang('th_changedby'), 'changedby', $sort, $dir, $filter);
        $html .= $this->sortHeader($this->getLang('th_changedat'), 'changedat', $sort, $dir, $filter);
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $rowClass = $row['is_default'] ? ' class="us-default-row"' : '';
            $editUrl  = $this->pageURL(['edituser' => $row['user']]);

            $html .= '<tr' . $rowClass . '>';
            $html .= '<td><a href="' . $editUrl . '">' . hsc($row['display_name']) . '</a></td>';
            $html .= '<td>' . hsc($row['setting_label']) . '</td>';
            $html .= '<td>' . hsc($row['value_display']) . '</td>';
            $html .= '<td>' . ($row['is_default']
                        ? '<span class="us-default-mark">' . hsc($this->getLang('bydefault')) . '</span>'
                        : hsc($row['changed_by_display'])) . '</td>';
            $html .= '<td>' . ($row['changed_at'] > 0 ? hsc(dformat($row['changed_at'])) : '&mdash;') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html . '</div>';
    }

    /**
     * Render the setting filter (a small GET form).
     *
     * @param array  $toggles
     * @param string $sort
     * @param string $dir
     * @param string $filter currently selected setting key
     * @return string
     */
    protected function renderFilter(array $toggles, $sort, $dir, $filter)
    {
        global $ID;

        // GET forms drop the action URL's query string, so every parameter
        // travels as an explicit field — this also survives URL rewriting.
        $html  = '<form class="us-filter" method="get" action="' . DOKU_BASE . DOKU_SCRIPT . '">';
        $html .= '<input type="hidden" name="id" value="' . hsc($ID) . '" />';
        $html .= '<input type="hidden" name="do" value="admin" />';
        $html .= '<input type="hidden" name="page" value="usersettings" />';
        $html .= '<input type="hidden" name="sort" value="' . hsc($sort) . '" />';
        $html .= '<input type="hidden" name="dir" value="' . hsc($dir) . '" />';

        $html .= '<label>' . hsc($this->getLang('filter_label')) . ' ';
        $html .= '<select name="filter">';
        $html .= '<option value="">' . hsc($this->getLang('filter_all')) . '</option>';
        foreach ($toggles as $key => $def) {
            $selected = ($filter === $key) ? ' selected="selected"' : '';
            $html .= '<option value="' . hsc($key) . '"' . $selected . '>'
                   . hsc($def['label']) . '</option>';
        }
        $html .= '</select></label> ';
        $html .= '<button type="submit">' . hsc($this->getLang('filter_apply')) . '</button>';
        return $html . '</form>';
    }

    /**
     * Render one sortable column header.
     *
     * @param string $label
     * @param string $col    sort key for this column
     * @param string $sort   currently active sort key
     * @param string $dir    currently active direction
     * @param string $filter currently active filter (preserved in the link)
     * @return string
     */
    protected function sortHeader($label, $col, $sort, $dir, $filter)
    {
        // clicking the active column flips direction; others start ascending
        $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow  = '';
        if ($sort === $col) {
            $arrow = ($dir === 'asc') ? " \u{25B2}" : " \u{25BC}";
        }

        $url = $this->pageURL(['sort' => $col, 'dir' => $newDir, 'filter' => $filter]);
        return '<th><a href="' . $url . '">' . hsc($label) . $arrow . '</a></th>';
    }

    // ---- per-user edit form ---------------------------------------------

    /**
     * Render the edit form for one user's preferences.
     *
     * @param string $user
     * @return string
     */
    protected function renderEditForm($user)
    {
        global $auth, $ID;

        $html = '<div class="plugin_usersettings_admin">';

        $userData = ($auth !== null) ? $auth->getUserData($user) : false;
        if ($userData === false) {
            $html .= '<h1>' . hsc($this->getLang('admin_heading')) . '</h1>';
            $html .= '<p>' . hsc($this->getLang('badidentuser')) . '</p>';
            $html .= '<p><a href="' . $this->pageURL() . '">'
                   . hsc($this->getLang('edit_back')) . '</a></p>';
            return $html . '</div>';
        }

        $displayName = ($userData['name'] !== '') ? $userData['name'] : $user;
        $html .= '<h1>' . hsc(sprintf($this->getLang('edit_heading'), $displayName)) . '</h1>';

        $helper  = $this->getHelper();
        $action  = $this->getActionPlugin();
        $toggles = $helper ? $helper->getRegisteredToggles() : [];

        if (empty($toggles) || $action === null) {
            $html .= '<p>' . hsc($this->getLang('notoggles')) . '</p>';
            $html .= '<p><a href="' . $this->pageURL() . '">'
                   . hsc($this->getLang('edit_back')) . '</a></p>';
            return $html . '</div>';
        }

        $formAction = wl($ID, ['do' => 'admin', 'page' => 'usersettings'], false, '&amp;');
        $html .= '<form method="post" action="' . $formAction . '" class="us-form">';
        $html .= formSecurityToken(false);
        $html .= '<input type="hidden" name="edituser" value="' . hsc($user) . '" />';
        $html .= '<input type="hidden" name="usersettings_adminsave" value="1" />';

        foreach ($toggles as $key => $def) {
            $html .= $action->renderToggleRow($def, $helper->getPreference($key, $user));
        }

        $html .= '<div class="us-actions">';
        $html .= '<button type="submit" class="button">'
               . hsc($this->getLang('save')) . '</button> ';
        $html .= '<a href="' . $this->pageURL() . '" class="us-back">'
               . hsc($this->getLang('edit_back')) . '</a>';
        $html .= '</div>';
        $html .= '</form>';

        return $html . '</div>';
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Build a URL back to this admin page with the given extra parameters.
     *
     * @param array $params
     * @return string  HTML-attribute-safe URL
     */
    protected function pageURL(array $params = [])
    {
        global $ID;
        $base = ['do' => 'admin', 'page' => 'usersettings'];
        return wl($ID, array_merge($base, $params), false, '&amp;');
    }
}
