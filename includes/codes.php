<?php
/**
 * Session codes: generating, listing and deleting them.
 *
 * Each session has member codes (one per ninja or mentor) and staff codes:
 * one screen code for the projector, and up to three mentor codes for mentors' laptops.
 * The two groups are generated and replaced separately, so adding mentor codes later
 * never changes member slips that have already been printed.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

const STAFF_KINDS = [KIND_SCREEN, KIND_MENTOR];

/**
 * One random code: COLOUR-ANIMAL, repeated $pairs times (random_int is cryptographically secure).
 */
function random_code(int $pairs): string
{
	$words = [];
	for ($i = 0; $i < $pairs; $i++) {
		$words[] = COLOURS[random_int(0, count(COLOURS) - 1)];
		$words[] = ANIMALS[random_int(0, count(ANIMALS) - 1)];
	}
	return implode('-', $words);
}

/**
 * Pick $count unique codes.
 *
 * @return list<string>
 */
function random_codes(int $count, int $pairs = 1): array
{
	$codes = [];
	while (count($codes) < $count) {
		$codes[random_code($pairs)] = true; // array keys keep them unique
	}
	return array_keys($codes);
}

/**
 * Has anyone signed in or voted with this session's codes of these kinds?
 *
 * @param list<string> $kinds
 */
function codes_in_use(string $session, array $kinds): bool
{
	global $wpdb;

	$placeholders = implode(', ', array_fill(0, count($kinds), '%s'));

	$tokens = (int) $wpdb->get_var($wpdb->prepare(
		'SELECT COUNT(*) FROM ' . table('tokens') . " WHERE session = %s AND kind IN ($placeholders)",
		$session,
		...$kinds
	));

	if ($tokens > 0) {
		return true;
	}

	// Only member codes vote, so code uses only matter for them
	if (!in_array(KIND_MEMBER, $kinds, true)) {
		return false;
	}

	return (int) $wpdb->get_var($wpdb->prepare(
		'SELECT COUNT(*) FROM ' . table('code_uses') . ' WHERE session = %s',
		$session
	)) > 0;
}

/**
 * Replace a group of a session's codes in one transaction.
 *
 * @param list<string>               $kinds the kinds being replaced
 * @param array<string, list<string>> $new   kind => codes to insert
 */
function replace_codes(string $session, array $kinds, array $new): true|WP_Error
{
	global $wpdb;

	$placeholders = implode(', ', array_fill(0, count($kinds), '%s'));

	$wpdb->query('START TRANSACTION');
	$wpdb->query($wpdb->prepare(
		'DELETE FROM ' . table('codes') . " WHERE session = %s AND kind IN ($placeholders)",
		$session,
		...$kinds
	));

	foreach ($new as $kind => $codes) {
		foreach ($codes as $code) {
			if (!insert_quietly(table('codes'), ['session' => $session, 'code' => $code, 'kind' => $kind])) {
				$wpdb->query('ROLLBACK');
				return new WP_Error('db_error', 'Could not save the codes. Please try again.');
			}
		}
	}

	$wpdb->query('COMMIT');
	return true;
}

/**
 * Generate member codes and/or staff codes for a session. A count of 0 leaves that group alone.
 * A group can't be replaced once any of its codes have been used, because slips may be handed out.
 */
function generate_codes(string $session, int $members, int $mentors): true|WP_Error
{
	if (!is_valid_session($session)) {
		return new WP_Error('invalid_session', 'Please choose the session date.');
	}
	if ($session < today()) {
		return new WP_Error('past_session', 'That session has already happened.');
	}
	if ($members < 0 || $members > MAX_CODES) {
		return new WP_Error('invalid_count', sprintf('Member codes must be between 0 and %d.', MAX_CODES));
	}
	if ($mentors < 0 || $mentors > MAX_MENTOR_CODES) {
		return new WP_Error('invalid_count', sprintf('Mentor codes must be between 0 and %d.', MAX_MENTOR_CODES));
	}
	if ($members === 0 && $mentors === 0) {
		return new WP_Error('invalid_count', 'Choose how many codes to generate.');
	}

	if ($members > 0) {
		if (codes_in_use($session, [KIND_MEMBER])) {
			return new WP_Error('in_use', 'Member codes for this session are already in use, so they can no longer be replaced.');
		}
		$result = replace_codes($session, [KIND_MEMBER], [KIND_MEMBER => random_codes($members, 1)]);
		if (is_wp_error($result)) {
			return $result;
		}
	}

	if ($mentors > 0) {
		if (codes_in_use($session, STAFF_KINDS)) {
			return new WP_Error('in_use', 'Mentor and screen codes for this session are already in use, so they can no longer be replaced.');
		}
		$staff = random_codes($mentors + 1, 2);
		$result = replace_codes($session, STAFF_KINDS, [
			KIND_SCREEN => [array_shift($staff)],
			KIND_MENTOR => $staff,
		]);
		if (is_wp_error($result)) {
			return $result;
		}
	}

	return true;
}

/**
 * Delete all of a session's codes. Only while none have been used.
 */
function delete_codes(string $session): true|WP_Error
{
	global $wpdb;

	if (codes_in_use($session, [KIND_MEMBER, KIND_SCREEN, KIND_MENTOR])) {
		return new WP_Error('in_use', 'Codes for this session are already in use, so they can no longer be deleted.');
	}
	$wpdb->delete(table('codes'), ['session' => $session]);
	return true;
}

/**
 * @return list<string>
 */
function list_codes(string $session, string $kind): array
{
	global $wpdb;
	return $wpdb->get_col($wpdb->prepare(
		'SELECT code FROM ' . table('codes') . ' WHERE session = %s AND kind = %s ORDER BY code',
		$session,
		$kind
	));
}

/**
 * Sessions that have codes, newest first, for the admin page.
 *
 * @return list<array{session: string, members: int, mentors: int, screens: int, in_use: bool}>
 */
function list_sessions(): array
{
	global $wpdb;

	$rows = $wpdb->get_results(
		'SELECT session,
			SUM(kind = \'member\') AS members,
			SUM(kind = \'mentor\') AS mentors,
			SUM(kind = \'screen\') AS screens
		FROM ' . table('codes') . ' GROUP BY session ORDER BY session DESC'
	);

	return array_map(fn ($row) => [
		'session' => $row->session,
		'members' => (int) $row->members,
		'mentors' => (int) $row->mentors,
		'screens' => (int) $row->screens,
		'in_use' => codes_in_use($row->session, [KIND_MEMBER, KIND_SCREEN, KIND_MENTOR]),
	], $rows);
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
