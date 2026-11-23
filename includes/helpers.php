<?php
/**
 * Small shared helpers: validation, normalising codes, dates.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use DateTimeImmutable;
use DateTimeZone;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Poll and quiz slugs: lowercase letters, numbers and hyphens, with at least one letter
 * (PHP turns all-number keys into integers). Same as the .md file names on the club site.
 */
function is_valid_slug(mixed $slug): bool
{
	return is_string($slug) && (bool) preg_match('/^[a-z0-9-]*[a-z][a-z0-9-]*$/', $slug) && strlen($slug) <= 100;
}

/**
 * Session IDs are the session's date, e.g. 2026-11-24.
 */
function is_valid_session(mixed $session): bool
{
	if (!is_string($session) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $session, $parts)) {
		return false;
	}
	return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

/**
 * Codes are case-insensitive and forgiving: " red tiger " and "Red_Tiger" both become "RED-TIGER".
 */
function normalise_code(string $code): string
{
	return (string) preg_replace('/[\s_-]+/', '-', strtoupper(trim($code)));
}

/**
 * Today's date in the club's timezone, e.g. 2026-11-24.
 */
function today(): string
{
	return (new DateTimeImmutable('now', new DateTimeZone(TIMEZONE)))->format('Y-m-d');
}

/**
 * Current time in UTC, as stored in the database.
 */
function now_utc(): string
{
	return gmdate('Y-m-d H:i:s');
}

/**
 * A stored UTC time as ISO 8601, e.g. 2026-11-24T19:50:00+00:00
 */
function utc_to_iso(string $mysql): string
{
	return gmdate('c', (int) strtotime($mysql . ' UTC'));
}

/**
 * Readable session date for admins and slips, e.g. "Tuesday 24 November 2026".
 */
function session_label(string $session): string
{
	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $session, new DateTimeZone(TIMEZONE));
	return $date ? $date->format('l j F Y') : $session;
}

/**
 * An answer is one option, or a list of options for pick-several questions.
 */
function is_valid_answer(mixed $answer): bool
{
	$values = is_array($answer) ? $answer : [$answer];

	if ($values === [] || count($values) > 20 || !array_is_list($values)) {
		return false;
	}
	foreach ($values as $value) {
		if (!is_string($value) || $value === '' || mb_strlen($value) > 200) {
			return false;
		}
	}
	return count(array_unique($values)) === count($values);
}

/**
 * Insert a row where a duplicate key is an expected outcome (e.g. a code voting twice).
 * Stops WordPress printing the database error into the response. Returns false on a duplicate.
 */
function insert_quietly(string $table, array $data): bool
{
	global $wpdb;

	$previous = $wpdb->suppress_errors(true);
	$saved = $wpdb->insert($table, $data);
	$wpdb->suppress_errors($previous);

	return $saved !== false;
}
