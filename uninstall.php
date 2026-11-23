<?php
/**
 * Runs when the plugin is deleted (not just deactivated) in WordPress admin.
 * Removes the tables, settings, role and scheduled clean-up, so nothing is left behind.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

foreach (['codes', 'code_uses', 'tokens', 'votes', 'results', 'quiz_players', 'quiz_scores', 'quiz_answers'] as $name) {
	$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'carrick_poll_' . $name);
}

delete_option('carrick_polls_db_version');
delete_option('carrick_polls_secret_hash');

remove_role('poll_manager');
get_role('administrator')?->remove_cap('manage_carrick_polls');

wp_clear_scheduled_hook('carrick_polls_cleanup');
