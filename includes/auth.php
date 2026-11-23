<?php
/**
 * Who is calling: sign-in tokens, kinds of code, and the wrong-code limit.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Who a token belongs to, or an error if it's unknown or expired.
 * With $kinds, the token must also be one of those kinds of code.
 *
 * @param list<string> $kinds
 * @return array{session: string, code: string, kind: string}|WP_Error
 */
function token_owner(mixed $token, array $kinds = []): array|WP_Error
{
	global $wpdb;

	if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
		return new WP_Error('signed_out', 'Please sign in with your code.', ['status' => 401]);
	}

	$row = $wpdb->get_row($wpdb->prepare(
		'SELECT session, code, kind FROM ' . table('tokens') . ' WHERE token_hash = %s AND expires_at > %s',
		hash('sha256', $token),
		now_utc()
	));

	if ($row === null) {
		return new WP_Error('signed_out', 'Please sign in with your code.', ['status' => 401]);
	}
	if ($kinds !== [] && !in_array($row->kind, $kinds, true)) {
		return new WP_Error('wrong_kind', "This code can't do that.", ['status' => 403]);
	}

	return ['session' => $row->session, 'code' => $row->code, 'kind' => $row->kind];
}

/* Wrong-code limit, counted per session. Everyone in the building shares one IP, so per-IP limits don't work. */

function too_many_failures(string $session): bool
{
	return (int) get_transient("carrick_polls_fail_$session") >= FAIL_LIMIT;
}

function record_failure(string $session): void
{
	$key = "carrick_polls_fail_$session";
	set_transient($key, (int) get_transient($key) + 1, FAIL_WINDOW);
}
