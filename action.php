<?php

/**
 * User Settings plugin — action component.
 *
 * Provides three things:
 *
 *   1. A "Preferences" item in the user menu, placed just before "Update
 *      Profile" (via the MENU_ITEMS_ASSEMBLY event — template-independent).
 *
 *   2. A custom action, do=usersettings, claimed in ACTION_ACT_PREPROCESS and
 *      rendered in TPL_ACT_UNKNOWN. This is the documented way for a plugin to
 *      own a do= value: preventing the preprocess default makes DokuWiki route
 *      the action through dokuwiki\Action\Plugin, which fires TPL_ACT_UNKNOWN.
 *
 *   3. The settings page itself: a plain HTML form of every registered toggle,
 *      with Post/Redirect/Get handling that saves through the helper.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

class action_plugin_usersettings extends DokuWiki_Action_Plugin
{
    /** the do= value this plugin owns */
    const ACTION = 'usersettings';

    /**
     * Register event handlers.
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'handleMenuAssembly');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handlePreprocess');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'handleUnknown');

        // Register the built-in interface language toggle.
        $controller->register_hook(
            helper_plugin_usersettings::REGISTER_EVENT,
            'BEFORE',
            $this,
            'registerLangToggle'
        );

        // Apply the user's language choice as early as possible so that all
        // DokuWiki rendering — including TPL_ hooks further down the chain —
        // uses the right language strings.  ACTION_ACT_PREPROCESS fires before
        // any output is produced and before template rendering begins.
        $controller->register_hook(
            'ACTION_ACT_PREPROCESS',
            'BEFORE',
            $this,
            'applyUserLang',
            null,
            // run at priority -10 so we fire before handlePreprocess (0) and
            // before anything else that might read $conf['lang']
            -10
        );
    }

    /**
     * Load the storage/registration helper.
     *
     * @return helper_plugin_usersettings|null
     */
    protected function getHelper()
    {
        /** @var helper_plugin_usersettings|null $helper */
        $helper = plugin_load('helper', 'usersettings');
        return $helper;
    }

    // ---------------------------------------------------------------------
    //  1. The user-menu item
    // ---------------------------------------------------------------------

    /**
     * Insert the "Preferences" item into the user menu, just before the
     * "Update Profile" item.
     *
     * @param Doku_Event $event MENU_ITEMS_ASSEMBLY
     * @param mixed       $param
     */
    public function handleMenuAssembly(Doku_Event $event, $param)
    {
        if (!is_array($event->data) || ($event->data['view'] ?? '') !== 'user') {
            return;
        }

        try {
            $item = new \dokuwiki\plugin\usersettings\MenuItem();
        } catch (\RuntimeException $e) {
            // anonymous visitor, or the action is disabled — no menu item
            return;
        }

        if (!isset($event->data['items']) || !is_array($event->data['items'])) {
            return;
        }
        $items =& $event->data['items'];

        // find the Profile item; default to appending if it is not present
        $pos = count($items);
        foreach ($items as $i => $existing) {
            if ($existing instanceof \dokuwiki\Menu\Item\Profile) {
                $pos = $i;
                break;
            }
        }
        array_splice($items, $pos, 0, [$item]);
    }

    // ---------------------------------------------------------------------
    //  2. Claiming the custom action + handling the save
    // ---------------------------------------------------------------------

    /**
     * Claim do=usersettings and, on a form submission, save and redirect.
     *
     * @param Doku_Event $event ACTION_ACT_PREPROCESS
     * @param mixed       $param
     */
    public function handlePreprocess(Doku_Event $event, $param)
    {
        if ($event->data !== self::ACTION) {
            return;
        }

        // Preventing the default makes DokuWiki keep the action and route it
        // through dokuwiki\Action\Plugin, which will fire TPL_ACT_UNKNOWN.
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT, $ID;

        $user = $INPUT->server->str('REMOTE_USER');
        if ($user === '') {
            return; // anonymous — the rendered page shows a login notice
        }

        // not a save submission — nothing to do, the page will just render
        if (!$INPUT->post->bool('usersettings_save')) {
            return;
        }

        // CSRF protection; checkSecurityToken() shows its own error on failure
        if (!checkSecurityToken()) {
            return;
        }

        $ok = $this->saveSubmittedPreferences($user);
        msg($this->getLang($ok ? 'saved' : 'savefail'), $ok ? 1 : -1);

        // Post/Redirect/Get: a refresh must not re-submit the form
        send_redirect(wl($ID, ['do' => self::ACTION], true, '&'));
    }

    /**
     * Read the submitted toggle values for every registered toggle and store
     * them for the given user.
     *
     * Kept separate from handlePreprocess() so it carries no redirect and can
     * be exercised directly by tests. Checkboxes that are unchecked do not
     * appear in the POST data, so every registered toggle is read explicitly
     * rather than iterating whatever was submitted.
     *
     * @param string      $user   whose preferences are being written
     * @param string|null $actor  who is making the change; defaults to $user
     *                            (the admin component passes the admin here)
     * @return bool
     */
    public function saveSubmittedPreferences($user, $actor = null)
    {
        global $INPUT;

        if ($actor === null) {
            $actor = $user;
        }

        $helper = $this->getHelper();
        if ($helper === null) {
            return false;
        }

        $values = [];
        foreach ($helper->getRegisteredToggles() as $key => $def) {
            if ($def['type'] === 'checkbox') {
                $values[$key] = $INPUT->post->bool($key) ? 1 : 0;
            } else {
                $values[$key] = $INPUT->post->str($key);
            }
        }

        return $helper->setPreferences($values, $user, $actor);
    }

    // ---------------------------------------------------------------------
    //  3. Rendering the settings page
    // ---------------------------------------------------------------------

    /**
     * Render the settings page for do=usersettings.
     *
     * @param Doku_Event $event TPL_ACT_UNKNOWN
     * @param mixed       $param
     */
    public function handleUnknown(Doku_Event $event, $param)
    {
        if ($event->data !== self::ACTION) {
            return;
        }
        $event->preventDefault();
        $event->stopPropagation();

        echo $this->renderSettingsPage();
    }

    /**
     * Build the HTML of the settings page.
     *
     * @return string
     */
    public function renderSettingsPage()
    {
        global $INPUT, $ID;

        $user = $INPUT->server->str('REMOTE_USER');

        $html  = '<div class="plugin_usersettings">';
        $html .= '<h1>' . hsc($this->getLang('heading')) . '</h1>';

        if ($user === '') {
            $html .= '<p>' . hsc($this->getLang('nologin')) . '</p>';
            return $html . '</div>';
        }

        $helper  = $this->getHelper();
        $toggles = $helper ? $helper->getRegisteredToggles() : [];

        if (empty($toggles)) {
            $html .= '<p>' . hsc($this->getLang('notoggles')) . '</p>';
            return $html . '</div>';
        }

        $html .= '<p class="us-intro">' . hsc($this->getLang('intro')) . '</p>';

        $action = wl($ID, ['do' => self::ACTION], false, '&amp;');
        $html  .= '<form method="post" action="' . $action . '" class="us-form">';
        $html  .= formSecurityToken(false);

        foreach ($toggles as $key => $def) {
            $html .= $this->renderToggleRow($def, $helper->getPreference($key, $user));
        }

        $html .= '<div class="us-actions">';
        $html .= '<button type="submit" name="usersettings_save" value="1" class="button">'
               . hsc($this->getLang('save')) . '</button>';
        $html .= '</div>';
        $html .= '</form>';

        return $html . '</div>';
    }

    // ---------------------------------------------------------------------
    //  Built-in: interface language toggle
    // ---------------------------------------------------------------------

    /**
     * Contribute the "Interface language" select to the usersettings registry.
     *
     * The option list is built by scanning DOKU_INC/inc/lang/ for sub-
     * directories that contain a lang.php file — the same source the
     * Configuration Manager uses for its own language drop-down.  The scan
     * result is sorted alphabetically by language code; the site default is
     * used as the toggle's default value so the toggle appears pre-selected
     * correctly for users who have never changed it.
     *
     * @param Doku_Event $event PLUGIN_USERSETTINGS_REGISTER
     * @param mixed       $param
     */
    public function registerLangToggle(Doku_Event $event, $param)
    {
        global $conf;

        $options = $this->getAvailableLanguages();
        if (empty($options)) {
            return; // nothing to register if we cannot list languages
        }

        $siteDefault = $conf['lang'] ?? 'en';
        if (!array_key_exists($siteDefault, $options)) {
            $siteDefault = array_key_first($options);
        }

        $event->data[] = [
            'key'     => 'lang',
            'label'   => $this->getLang('lang_label'),
            'desc'    => $this->getLang('lang_desc'),
            'type'    => 'select',
            'options' => $options,
            'default' => $siteDefault,
            'plugin'  => 'usersettings',
        ];
    }

    /**
     * Build the [code => display name] map of all installed DokuWiki interface
     * languages by scanning inc/lang/.  The display name is the language code
     * itself (e.g. "en", "de", "fr") — consistent with how the Configuration
     * Manager presents the option.
     *
     * @return array  [langCode => langCode]  sorted by language code
     */
    protected function getAvailableLanguages()
    {
        $pattern = DOKU_INC . 'inc/lang/*/lang.php';
        $files   = glob($pattern);
        if ($files === false || empty($files)) {
            return [];
        }

        $langs = [];
        foreach ($files as $file) {
            $code = basename(dirname($file)); // e.g. "de" from ".../inc/lang/de/lang.php"
            if ($code === '' || $code === '.' || $code === '..') {
                continue;
            }
            $langs[$code] = $code;
        }

        ksort($langs, SORT_STRING);
        return $langs;
    }

    /**
     * Apply the logged-in user's preferred interface language, overriding the
     * site-wide $conf['lang'] before any rendering takes place.
     *
     * DokuWiki loads language strings lazily (via getLang() / $lang global
     * reloads triggered by calls to init_lang()), so changing $conf['lang']
     * here — in the earliest ACTION_ACT_PREPROCESS handler — is sufficient
     * to affect all subsequent output.
     *
     * No-op for anonymous visitors or when the user has not chosen a language
     * that differs from the site default.
     *
     * @param Doku_Event $event ACTION_ACT_PREPROCESS
     * @param mixed       $param
     */
    public function applyUserLang(Doku_Event $event, $param)
    {
        global $conf, $INPUT;

        $user = $INPUT->server->str('REMOTE_USER');
        if ($user === '') {
            return; // anonymous — use the site default
        }

        $helper = $this->getHelper();
        if ($helper === null) {
            return;
        }

        $preferred = $helper->getPreference('lang', $user);
        if ($preferred === null || $preferred === '' || $preferred === $conf['lang']) {
            return; // no preference stored or already correct
        }

        // Validate: only apply if the directory actually exists to avoid a
        // broken page when someone stores a stale language code.
        $langDir = DOKU_INC . 'inc/lang/' . $preferred;
        if (!is_dir($langDir)) {
            return;
        }

        $conf['lang'] = $preferred;

        // Re-initialise the global $lang array so immediately-following
        // getLang() calls within this request pick up the new language.
        init_lang($preferred);

        // The PLUGIN_USERSETTINGS_REGISTER event fired during getPreference()
        // above caused this action plugin's own locale to load under the old
        // $conf['lang'].  Reset the cache so subsequent getLang() calls on
        // this instance (e.g. renderSettingsPage) load the user's language.
        $this->localised = false;
        $this->lang = [];
    }

    // ---------------------------------------------------------------------
    //  Form rendering (shared between action and admin)
    // ---------------------------------------------------------------------

    /**
     * Render one toggle as a form row. Public so the admin component can
     * reuse it for its per-user edit form.
     *
     * @param array $def    a normalised toggle definition
     * @param mixed $value  the user's effective value for this toggle
     * @return string
     */
    public function renderToggleRow(array $def, $value)
    {
        $key = hsc($def['key']);

        if ($def['type'] === 'select') {
            $id   = 'us__' . $key;
            $html = '<div class="us-row us-row-select">';
            $html .= '<label class="us-label" for="' . $id . '">';
            $html .= '<span class="us-name">' . hsc($def['label']) . '</span>';
            $html .= '<select name="' . $key . '" id="' . $id . '">';
            foreach ($def['options'] as $optValue => $optLabel) {
                $selected = ((string) $optValue === (string) $value) ? ' selected="selected"' : '';
                $html .= '<option value="' . hsc((string) $optValue) . '"' . $selected . '>'
                       . hsc((string) $optLabel) . '</option>';
            }
            $html .= '</select>';
            $html .= '</label>';
        } else {
            $checked = empty($value) ? '' : ' checked="checked"';
            $html  = '<div class="us-row us-row-checkbox">';
            $html .= '<label class="us-label">';
            $html .= '<input type="checkbox" name="' . $key . '" value="1"' . $checked . ' />';
            $html .= '<span class="us-name">' . hsc($def['label']) . '</span>';
            $html .= '</label>';
        }

        if ($def['desc'] !== '') {
            $html .= '<div class="us-desc">' . hsc($def['desc']) . '</div>';
        }

        return $html . '</div>';
    }
}
