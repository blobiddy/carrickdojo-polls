<?php
/**
 * Plugin Name:       Dojo Club
 * Description:       Sessions, anonymous polls, quizzes, RFCs, session projects, a lending library and ninja accounts for CoderDojo clubs.
 * Version:           1.0.0-dev
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Author:            CarrickDojo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dojo-club
 */

declare(strict_types=1);

namespace DojoClub;

// Stop the file being run directly, outside WordPress
if (!defined('ABSPATH')) {
	exit;
}

const VERSION = '1.0.0-dev';

// REST routes live under /<rest prefix>/dojo/v1/
const API_NAMESPACE = 'dojo/v1';

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/secrets.php';
require_once __DIR__ . '/includes/rest.php';

if (is_admin()) {
	require_once __DIR__ . '/includes/admin.php';
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate');

/**
 * Runs when the plugin is activated (and again on every re-activation, so it must be safe to repeat).
 */
function activate(): void
{
	add_option(SETTINGS_OPTION, default_settings());
	add_ninja_role();
}
