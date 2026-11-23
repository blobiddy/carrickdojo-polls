<?php
/**
 * Plugin Name:       CarrickDojo Polls
 * Description:       Anonymous one-time-code polls for the CarrickDojo club site.
 * Version:           0.1.0
 * Author:            CarrickDojo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace CarrickDojo\Polls;

// Stop the file being run directly, outside WordPress
if (!defined('ABSPATH')) {
	exit;
}

const DB_VERSION = '1';

/**
 * Table names, with the site's prefix (usually wp_).
 */
function table(string $name): string
{
	global $wpdb;
	return $wpdb->prefix . 'carrick_poll_' . $name;
}

/**
 * Create or update the tables. dbDelta compares with what exists and only changes what's needed.
 * Note: dbDelta is strict about formatting (two spaces after PRIMARY KEY, one field per line).
 */
function install(): void
{
	global $wpdb;
	$charset = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// One-time codes. Deleted when the poll is finalised.
	dbDelta("CREATE TABLE " . table('codes') . " (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		poll varchar(100) NOT NULL,
		code varchar(40) NOT NULL,
		used tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY poll_code (poll, code)
	) $charset;");

	// Votes. Deliberately no code column, so a vote can't be traced to a code.
	dbDelta("CREATE TABLE " . table('votes') . " (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		poll varchar(100) NOT NULL,
		answers longtext NOT NULL,
		PRIMARY KEY  (id),
		KEY poll (poll)
	) $charset;");

	// Final tallies, kept after codes and votes are deleted.
	dbDelta("CREATE TABLE " . table('results') . " (
		poll varchar(100) NOT NULL,
		result longtext NOT NULL,
		total int unsigned NOT NULL,
		issued int unsigned NOT NULL,
		finalised_at datetime NOT NULL,
		PRIMARY KEY  (poll)
	) $charset;");

	update_option('carrick_polls_db_version', DB_VERSION);
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\install');
