<?php
/**
 * Deletes everything that could identify anyone, as soon as it's no longer needed:
 * codes, code uses, sign-ins and quiz data from past sessions, and expired sign-ins.
 *
 * Runs daily (WP-Cron) and before every sign-in, so it happens even if WP-Cron is late.
 * Votes are kept until their poll is finalised; they contain no codes.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

if (!defined('ABSPATH')) {
	exit;
}

const CLEANUP_HOOK = 'carrick_polls_cleanup';

function cleanup(): void
{
	global $wpdb;

	$today = today();

	foreach (['codes', 'code_uses', 'tokens', 'quiz_players', 'quiz_scores', 'quiz_answers'] as $name) {
		$wpdb->query($wpdb->prepare('DELETE FROM ' . table($name) . ' WHERE session < %s', $today));
	}

	$wpdb->query($wpdb->prepare('DELETE FROM ' . table('tokens') . ' WHERE expires_at <= %s', now_utc()));
}

add_action(CLEANUP_HOOK, __NAMESPACE__ . '\\cleanup');

function schedule_cleanup(): void
{
	if (!wp_next_scheduled(CLEANUP_HOOK)) {
		wp_schedule_event(time(), 'daily', CLEANUP_HOOK);
	}
}

function unschedule_cleanup(): void
{
	wp_clear_scheduled_hook(CLEANUP_HOOK);
}
