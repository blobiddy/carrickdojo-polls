<?php
/**
 * Runs when the plugin is deleted (not just deactivated) in WordPress admin.
 * Removes the tables and settings, so nothing is left behind.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

foreach (['codes', 'votes', 'results'] as $name) {
	$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'carrick_poll_' . $name);
}

delete_option('carrick_polls_db_version');
