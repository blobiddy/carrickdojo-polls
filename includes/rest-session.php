<?php
/**
 * Signing in and out, personas, and live counts for the projector.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Body: { session, code, expires }  Reply: { token, kind, expires_at }
 */
function rest_signin(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	cleanup();

	$session = $request->get_param('session');
	$code = $request->get_param('code');

	if (!is_valid_session($session) || !is_string($code) || trim($code) === '') {
		return new WP_Error('invalid_signin', 'Please enter the code from your slip.', ['status' => 400]);
	}
	// Codes only work on the day of their session
	if ($session !== today()) {
		return new WP_Error('wrong_day', 'Codes only work on the day of the session.', ['status' => 400]);
	}
	if (too_many_failures($session)) {
		return new WP_Error('too_many_attempts', 'Too many wrong codes. Please wait a minute and try again.', ['status' => 429]);
	}

	$code = normalise_code($code);

	$kind = $wpdb->get_var($wpdb->prepare(
		'SELECT kind FROM ' . table('codes') . ' WHERE session = %s AND code = %s',
		$session,
		$code
	));

	if (!is_string($kind)) {
		record_failure($session);
		return new WP_Error('invalid_code', "That code isn't valid for today's session.", ['status' => 400]);
	}

	// The club site asks for the sign-in to end with the session; never longer than MAX_SIGNIN_HOURS
	$now = time();
	$latest = $now + MAX_SIGNIN_HOURS * HOUR_IN_SECONDS;
	$requested = is_string($request->get_param('expires')) ? strtotime($request->get_param('expires')) : false;
	$expires = ($requested !== false && $requested > $now) ? min($requested, $latest) : $latest;

	$token = bin2hex(random_bytes(32));

	$saved = $wpdb->insert(table('tokens'), [
		'token_hash' => hash('sha256', $token),
		'session' => $session,
		'code' => $code,
		'kind' => $kind,
		'expires_at' => gmdate('Y-m-d H:i:s', $expires),
	]);

	if ($saved === false) {
		return new WP_Error('db_error', 'Could not sign you in. Please try again.', ['status' => 500]);
	}

	return new WP_REST_Response(['token' => $token, 'kind' => $kind, 'expires_at' => gmdate('c', $expires)], 201);
}

/**
 * Body: { token }  Always succeeds: signing out twice is fine.
 */
function rest_signout(WP_REST_Request $request): WP_REST_Response
{
	global $wpdb;

	$token = $request->get_param('token');
	if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
		$wpdb->delete(table('tokens'), ['token_hash' => hash('sha256', $token)]);
	}

	return new WP_REST_Response(['signed_out' => true], 200);
}

/**
 * Mentor only. Signs out every member in the session, e.g. at the end of the night.
 * Body: { token }  Reply: { signed_out: count }
 */
function rest_signout_all(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$owner = token_owner($request->get_param('token'), [KIND_MENTOR]);
	if (is_wp_error($owner)) {
		return $owner;
	}

	$count = $wpdb->query($wpdb->prepare(
		'DELETE FROM ' . table('tokens') . ' WHERE session = %s AND kind = %s',
		$owner['session'],
		KIND_MEMBER
	));

	return new WP_REST_Response(['signed_out' => (int) $count], 200);
}

/**
 * Body: { token }  Reply: { session, code, kind, voted, persona }
 */
function rest_me(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$owner = token_owner($request->get_param('token'));
	if (is_wp_error($owner)) {
		return $owner;
	}

	$voted = $wpdb->get_col($wpdb->prepare(
		'SELECT poll FROM ' . table('code_uses') . ' WHERE session = %s AND code = %s ORDER BY poll',
		$owner['session'],
		$owner['code']
	));

	return new WP_REST_Response([
		'session' => $owner['session'],
		'code' => $owner['code'],
		'kind' => $owner['kind'],
		'voted' => $voted,
		'persona' => get_persona($owner['session'], $owner['code']),
	], 200);
}

/**
 * Members only. Set or change the persona for this session.
 * Body: { token, persona }  Reply: { persona }
 */
function rest_persona(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	$owner = token_owner($request->get_param('token'), [KIND_MEMBER]);
	if (is_wp_error($owner)) {
		return $owner;
	}

	$persona = $request->get_param('persona');
	$saved = set_persona($owner['session'], $owner['code'], $persona);

	return is_wp_error($saved) ? $saved : new WP_REST_Response(['persona' => $persona], 200);
}

/**
 * Screen and mentor only. Live counts for the projector: totals only, never how anyone voted.
 * Body: { token, polls: [poll] }
 * Reply: { session, members, signed_in, polls: { poll: { votes, finalised } } }
 */
function rest_live(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$owner = token_owner($request->get_param('token'), [KIND_SCREEN, KIND_MENTOR]);
	if (is_wp_error($owner)) {
		return $owner;
	}

	$polls = $request->get_param('polls') ?? [];
	if (!is_array($polls) || count($polls) > 50 || !array_is_list($polls)) {
		return new WP_Error('invalid_polls', 'Invalid list of polls.', ['status' => 400]);
	}
	foreach ($polls as $poll) {
		if (!is_valid_slug($poll)) {
			return new WP_Error('invalid_polls', 'Invalid list of polls.', ['status' => 400]);
		}
	}

	$members = (int) $wpdb->get_var($wpdb->prepare(
		'SELECT COUNT(*) FROM ' . table('codes') . ' WHERE session = %s AND kind = %s',
		$owner['session'],
		KIND_MEMBER
	));

	$signed_in = (int) $wpdb->get_var($wpdb->prepare(
		'SELECT COUNT(DISTINCT code) FROM ' . table('tokens') . ' WHERE session = %s AND kind = %s AND expires_at > %s',
		$owner['session'],
		KIND_MEMBER,
		now_utc()
	));

	$counts = [];
	foreach ($polls as $poll) {
		$result = get_result($poll);
		$counts[$poll] = $result !== null
			? ['votes' => $result['total'], 'finalised' => true]
			: ['votes' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . table('votes') . ' WHERE poll = %s', $poll)), 'finalised' => false];
	}

	return new WP_REST_Response([
		'session' => $owner['session'],
		'members' => $members,
		'signed_in' => $signed_in,
		'polls' => (object) $counts,
	], 200);
}
