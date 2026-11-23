<?php
/**
 * Plugin Name:       CarrickDojo Polls
 * Description:       Anonymous one-time-code polls for the CarrickDojo club site.
 * Version:           0.2.0
 * Author:            CarrickDojo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

// Stop the file being run directly, outside WordPress
if (!defined('ABSPATH')) {
	exit;
}

const DB_VERSION = '1';

// How many codes to generate by default, and the most allowed in one go
const DEFAULT_CODES = 30;
const MAX_CODES = 200;

// Fewer votes than this and only the total is published, so no single vote can be worked out
const MIN_VOTES_FOR_BREAKDOWN = 5;

// Wrong codes allowed per poll per window before voting pauses (stops guessing)
const FAIL_LIMIT = 10;
const FAIL_WINDOW = 60; // seconds

require_once __DIR__ . '/includes/words.php';
require_once __DIR__ . '/includes/codes.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/rest.php';

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

// Uploading a new zip replaces the files without re-activating, so check the tables on load too
add_action('plugins_loaded', function (): void {
	if (get_option('carrick_polls_db_version') !== DB_VERSION) {
		install();
	}
});

/**
 * Poll slugs: lowercase letters, numbers and hyphens, the same as the .md file name on the frontend.
 */
function is_valid_slug(string $slug): bool
{
	return (bool) preg_match('/^[a-z0-9-]{1,100}$/', $slug);
}
