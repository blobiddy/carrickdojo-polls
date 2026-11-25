<?php
/**
 * Runs when the plugin is deleted in WP admin. Removes everything the plugin added.
 * Take a backup first (Tools → Backup) if the data might be needed again.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('dojo_club_settings');
delete_option('dojo_club_secret_live');
delete_option('dojo_club_secret_test');

// Dojo roles ticked on adults' profiles
delete_metadata('user', 0, 'dojo_roles', '', true);

remove_role('dojo_ninja');
