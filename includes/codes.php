<?php
/**
 * Generating, normalising and listing one-time codes.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Make codes case-insensitive and forgiving: " tiger red " and "Tiger_Red" both become "TIGER-RED".
 */
function normalise_code(string $code): string
{
	$code = strtoupper(trim($code));
	return (string) preg_replace('/[\s_-]+/', '-', $code);
}

/**
 * Pick $count unique ANIMAL-COLOUR codes at random (random_int is cryptographically secure).
 *
 * @return list<string>
 */
function random_codes(int $count): array
{
	$codes = [];
	while (count($codes) < $count) {
		$code = ANIMALS[random_int(0, count(ANIMALS) - 1)] . '-' . COLOURS[random_int(0, count(COLOURS) - 1)];
		$codes[$code] = true; // array keys keep them unique
	}
	return array_keys($codes);
}

/**
 * Replace a poll's codes with a fresh set. Refused once any code is used or the poll is finalised,
 * because slips may already have been handed out.
 *
 * @return list<string>|WP_Error
 */
function generate_codes(string $poll, int $count): array|WP_Error
{
	global $wpdb;

	if (!is_valid_slug($poll)) {
		return new WP_Error('invalid_slug', 'Poll slug can only use lowercase letters, numbers and hyphens.');
	}
	if ($count < 1 || $count > MAX_CODES) {
		return new WP_Error('invalid_count', sprintf('Number of codes must be between 1 and %d.', MAX_CODES));
	}
	if (get_result($poll) !== null) {
		return new WP_Error('finalised', 'This poll has already been finalised.');
	}
	if (count_codes($poll)['used'] > 0) {
		return new WP_Error('codes_used', 'Some codes have already been used, so they can no longer be replaced.');
	}

	$codes = random_codes($count);

	$wpdb->query('START TRANSACTION');
	$wpdb->delete(table('codes'), ['poll' => $poll]);
	foreach ($codes as $code) {
		if ($wpdb->insert(table('codes'), ['poll' => $poll, 'code' => $code]) === false) {
			$wpdb->query('ROLLBACK');
			return new WP_Error('db_error', 'Could not save the codes. Please try again.');
		}
	}
	$wpdb->query('COMMIT');

	sort($codes);
	return $codes;
}

/**
 * Delete a poll's codes. Same rule as replacing them: only while none are used.
 */
function delete_codes(string $poll): true|WP_Error
{
	global $wpdb;

	if (count_codes($poll)['used'] > 0) {
		return new WP_Error('codes_used', 'Some codes have already been used, so they can no longer be deleted.');
	}
	$wpdb->delete(table('codes'), ['poll' => $poll]);
	return true;
}

/**
 * @return array{issued: int, used: int}
 */
function count_codes(string $poll): array
{
	global $wpdb;
	$row = $wpdb->get_row($wpdb->prepare(
		'SELECT COUNT(*) AS issued, COALESCE(SUM(used), 0) AS used FROM ' . table('codes') . ' WHERE poll = %s',
		$poll
	));
	return ['issued' => (int) ($row->issued ?? 0), 'used' => (int) ($row->used ?? 0)];
}

/**
 * @return list<string>
 */
function list_codes(string $poll): array
{
	global $wpdb;
	return $wpdb->get_col($wpdb->prepare(
		'SELECT code FROM ' . table('codes') . ' WHERE poll = %s ORDER BY code',
		$poll
	));
}

/**
 * Every poll that has codes or a final result, for the admin page.
 *
 * @return list<array{poll: string, issued: int, used: int, finalised: bool}>
 */
function list_polls(): array
{
	global $wpdb;

	$polls = [];
	$rows = $wpdb->get_results(
		'SELECT poll, COUNT(*) AS issued, COALESCE(SUM(used), 0) AS used FROM ' . table('codes') . ' GROUP BY poll'
	);
	foreach ($rows as $row) {
		$polls[$row->poll] = ['poll' => $row->poll, 'issued' => (int) $row->issued, 'used' => (int) $row->used, 'finalised' => false];
	}

	$rows = $wpdb->get_results('SELECT poll, issued, total FROM ' . table('results'));
	foreach ($rows as $row) {
		$polls[$row->poll] = ['poll' => $row->poll, 'issued' => (int) $row->issued, 'used' => (int) $row->total, 'finalised' => true];
	}

	ksort($polls);
	return array_values($polls);
}
