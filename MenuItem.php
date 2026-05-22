<?php

namespace dokuwiki\plugin\usersettings;

use dokuwiki\Menu\Item\AbstractItem;

/**
 * User-menu item for the User Settings plugin.
 *
 * Links to the plugin's own settings page (do=usersettings). The action
 * component splices an instance of this in just before the "Update Profile"
 * item of the user menu.
 *
 * Like the core Profile item, this is only available to logged-in users —
 * the constructor throws for anonymous visitors, and the action component
 * skips the item when that happens.
 */
class MenuItem extends AbstractItem
{
    /**
     * @throws \RuntimeException when the visitor is not logged in
     */
    public function __construct()
    {
        global $INPUT;

        // Set the action type before the parent constructor runs: the parent
        // derives params['do'] from $this->type, and AbstractItem::getType()
        // only auto-fills it from the class name when it is still empty.
        $this->type = 'usersettings';

        parent::__construct();

        if (!$INPUT->server->str('REMOTE_USER')) {
            throw new \RuntimeException('user settings are only for logged in users');
        }

        // Label comes from the plugin's language file. Any plugin component
        // exposes getLang(); the helper is the lightest one to borrow.
        $helper = plugin_load('helper', 'usersettings');
        if ($helper instanceof \helper_plugin_usersettings) {
            $this->label = $helper->getLang('menu');
        }

        $this->svg = __DIR__ . '/images/usersettings.svg';
    }
}
