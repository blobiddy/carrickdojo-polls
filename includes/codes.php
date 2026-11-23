<?php
/**
 * Session codes: generating, listing and deleting them.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Pick $count unique COLOUR-ANIMAL codes at random (random_int is cryptographically secure).
 *
 * @return list<string>
 */
function random_codes(int $count): array
{
	$codes = [];
	while (count($codes) < $count) {
		$code = COLOURS[random_int(0, count(COLOURS) - 1)] . '-' . ANIMALS[random_int(0, count(ANIMALS) - 1)];
		$codes[$code] = true; // array keys keep them unique
	}
	return array_keys($codes);
}

/**
 * Has anyone signed in or voted with this session's codes? If so, they're already in use.
 */
function session_in_use(string $session): bool
{
	global $wpdb;

	$uses = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . table('code_uses') . ' WHERE session = %s', $session));
	$tokens = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . table('tokens') . ' WHERE session = %s', $session));

	return $uses + $tokens > 0;
}

/**
 * Replace a session's codes with a fresh set. Refused once any code has been used,
 * because slips may already have been handed out.
 *
 * @return list<string>|WP_Error
 */
function generate_codes(string $session, int $count): array|WP_Error
{
	global $wpdb;

	if (!is_valid_session($session)) {
		return new WP_Error('invalid_session', 'Please choose the session date.');
	}
	if ($session < today()) {
		return new WP_Error('past_session', 'That session has already happened.');
	}
	if ($count < 1 || $count > MAX_CODES) {
		return new WP_Error('invalid_count', sprintf('Number of codes must be between 1 and %d.', MAX_CODES));
	}
	if (session_in_use($session)) {
		return new WP_Error('in_use', 'Codes for this session are already in use, so they can no longer be replaced.');
	}

	$codes = random_codes($count);

	$wpdb->query('START TRANSACTION');
	$wpdb->delete(table('codes'), ['session' => $session]);
	foreach ($codes as $code) {
		if ($wpdb->insert(table('codes'), ['session' => $session, 'code' => $code]) === false) {
			$wpdb->query('ROLLBACK');
			return new WP_Error('db_error', 'Could not save the codes. Please try again.');
		}
	}
	$wpdb->query('COMMIT');

	sort($codes);
	return $codes;
}

/**
 * Delete a session's codes. Same rule as replacing them: only while unused.
 */
function delete_codes(string $session): true|WP_Error
{
	global $wpdb;

	if (session_in_use($session)) {
		return new WP_Error('in_use', 'Codes for this session are already in use, so they can no longer be deleted.');
	}
	$wpdb->delete(table('codes'), ['session' => $session]);
	return true;
}

/**
 * @return list<string>
 */
function list_codes(string $session): array
{
	global $wpdb;
	return $wpdb->get_col($wpdb->prepare('SELECT code FROM ' . table('codes') . ' WHERE session = %s ORDER BY code', $session));
}

/**
 * Sessions that have codes, newest first, for the admin page.
 *
 * @return list<array{session: string, codes: int, in_use: bool}>
 */
function list_sessions(): array
{
	global $wpdb;

	$rows = $wpdb->get_results('SELECT session, COUNT(*) AS codes FROM ' . table('codes') . ' GROUP BY session ORDER BY session DESC');

	return array_map(
		fn ($row) => ['session' => $row->session, 'codes' => (int) $row->codes, 'in_use' => session_in_use($row->session)],
		$rows
	);
}

/**
 * Polls with votes waiting, or finalised, for the admin page.
 *
 * @return list<array{poll: string, votes: int, finalised: bool}>
 */
function list_polls(): array
{
	global $wpdb;

	$polls = [];
	foreach ($wpdb->get_results('SELECT poll, COUNT(*) AS votes FROM ' . table('votes') . ' GROUP BY poll') as $row) {
		$polls[$row->poll] = ['poll' => $row->poll, 'votes' => (int) $row->votes, 'finalised' => false];
	}
	foreach ($wpdb->get_results('SELECT poll, total FROM ' . table('results')) as $row) {
		$polls[$row->poll] = ['poll' => $row->poll, 'votes' => (int) $row->total, 'finalised' => true];
	}

	ksort($polls);
	return array_values($polls);
}
