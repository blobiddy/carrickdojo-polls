<?php
/**
 * REST endpoints, under the site's existing namespace: /json/api/polls/{slug}/...
 *
 *   POST vote      frontend only (shared secret). Body: { "code": "TIGER-RED", "answers": { ... } }
 *   POST finalise  frontend only (shared secret). Tallies votes, saves the result, deletes votes and codes.
 *   GET  results   public. The final result, once finalised.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
	exit;
}

add_action('rest_api_init', function (): void {
	$slug = '(?P<slug>[a-z0-9-]{1,100})';

	register_rest_route('api', "/polls/$slug/vote", [
		'methods' => 'POST',
		'callback' => __NAMESPACE__ . '\\rest_vote',
		'permission_callback' => __NAMESPACE__ . '\\verify_frontend',
	]);

	register_rest_route('api', "/polls/$slug/finalise", [
		'methods' => 'POST',
		'callback' => __NAMESPACE__ . '\\rest_finalise',
		'permission_callback' => __NAMESPACE__ . '\\verify_frontend',
	]);

	register_rest_route('api', "/polls/$slug/results", [
		'methods' => 'GET',
		'callback' => __NAMESPACE__ . '\\rest_results',
		'permission_callback' => '__return_true',
	]);
});

/**
 * Only the SvelteKit server may vote or finalise. It sends the shared secret from wp-config.php.
 */
function verify_frontend(WP_REST_Request $request): true|WP_Error
{
	if (!defined('CARRICK_FRONTEND_SECRET') || !is_string(CARRICK_FRONTEND_SECRET) || CARRICK_FRONTEND_SECRET === '') {
		return new WP_Error('not_configured', 'Polls are not configured.', ['status' => 503]);
	}

	$secret = $request->get_header('X-Frontend-Secret');

	// hash_equals takes the same time whether the secret is nearly right or totally wrong
	if (!is_string($secret) || !hash_equals(CARRICK_FRONTEND_SECRET, $secret)) {
		return new WP_Error('forbidden', 'Not allowed.', ['status' => 401]);
	}

	return true;
}

/**
 * Answers must be { "question-id": "answer" } or { "question-id": ["answer", ...] }.
 * The frontend checks answers against the poll's .md file; this is a second check on shape and size.
 */
function valid_answers(mixed $answers): bool
{
	if (!is_array($answers) || $answers === [] || count($answers) > 50) {
		return false;
	}

	foreach ($answers as $question => $answer) {
		if (!is_string($question) || !preg_match('/^[a-z0-9-]{1,50}$/', $question)) {
			return false;
		}
		$values = is_array($answer) ? $answer : [$answer];
		if ($values === [] || count($values) > 20 || !array_is_list($values)) {
			return false;
		}
		foreach ($values as $value) {
			if (!is_string($value) || $value === '' || mb_strlen($value) > 200) {
				return false;
			}
		}
	}

	return true;
}

/**
 * Wrong codes are counted per poll. Everyone shares the building's IP, so per-IP limits don't work here.
 */
function too_many_failures(string $poll): bool
{
	return (int) get_transient("carrick_polls_fail_$poll") >= FAIL_LIMIT;
}

function record_failure(string $poll): void
{
	$key = "carrick_polls_fail_$poll";
	$count = (int) get_transient($key);
	set_transient($key, $count + 1, FAIL_WINDOW);
}

function rest_vote(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$poll = (string) $request['slug'];
	$code = $request->get_param('code');
	$answers = $request->get_param('answers');

	if (get_result($poll) !== null) {
		return new WP_Error('poll_closed', 'This poll has closed.', ['status' => 409]);
	}
	if (too_many_failures($poll)) {
		return new WP_Error('too_many_attempts', 'Too many wrong codes. Please wait a minute and try again.', ['status' => 429]);
	}
	if (!is_string($code) || $code === '' || !valid_answers($answers)) {
		return new WP_Error('invalid_vote', 'Please enter your code and answer the questions.', ['status' => 400]);
	}

	$code = normalise_code($code);

	// One transaction: use the code (only if unused) and save the vote. Both happen, or neither does.
	$wpdb->query('START TRANSACTION');

	$used = $wpdb->query($wpdb->prepare(
		'UPDATE ' . table('codes') . ' SET used = 1 WHERE poll = %s AND code = %s AND used = 0',
		$poll,
		$code
	));

	if ($used !== 1) {
		$wpdb->query('ROLLBACK');
		record_failure($poll);
		// Same message for unknown and already-used codes, so guessing reveals nothing
		return new WP_Error('invalid_code', "That code isn't valid or has already been used.", ['status' => 400]);
	}

	$saved = $wpdb->insert(table('votes'), ['poll' => $poll, 'answers' => wp_json_encode($answers)]);

	if ($saved === false) {
		$wpdb->query('ROLLBACK');
		return new WP_Error('db_error', 'Your vote could not be saved. Please try again.', ['status' => 500]);
	}

	$wpdb->query('COMMIT');

	return new WP_REST_Response(['voted' => true], 201);
}

/**
 * @return array{poll: string, total: int, issued: int, finalised_at: string, result: array<string, array<string, int>>|null}|null
 */
function get_result(string $poll): ?array
{
	global $wpdb;

	$row = $wpdb->get_row($wpdb->prepare(
		'SELECT * FROM ' . table('results') . ' WHERE poll = %s',
		$poll
	));

	if ($row === null) {
		return null;
	}

	return [
		'poll' => $row->poll,
		'total' => (int) $row->total,
		'issued' => (int) $row->issued,
		// Stored in UTC; sent as ISO 8601 with the offset, e.g. 2026-12-05T11:50:00+00:00
		'finalised_at' => gmdate('c', (int) strtotime($row->finalised_at . ' UTC')),
		'result' => json_decode($row->result, true),
	];
}

/**
 * Tally the votes into { question: { answer: count } }, save the result, then delete the votes and codes.
 * Safe to call more than once: after the first time it just returns the saved result.
 */
function rest_finalise(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$poll = (string) $request['slug'];

	$existing = get_result($poll);
	if ($existing !== null) {
		return new WP_REST_Response($existing, 200);
	}

	$issued = count_codes($poll)['issued'];
	if ($issued === 0) {
		return new WP_Error('unknown_poll', 'No codes were issued for this poll.', ['status' => 404]);
	}

	$wpdb->query('START TRANSACTION');

	$rows = $wpdb->get_col($wpdb->prepare(
		'SELECT answers FROM ' . table('votes') . ' WHERE poll = %s FOR UPDATE',
		$poll
	));

	$tally = [];
	foreach ($rows as $json) {
		$answers = json_decode($json, true);
		if (!is_array($answers)) {
			continue;
		}
		foreach ($answers as $question => $answer) {
			foreach ((array) $answer as $value) {
				$tally[$question][$value] = ($tally[$question][$value] ?? 0) + 1;
			}
		}
	}

	$total = count($rows);

	// Too few votes: publish only the total, so no single vote can be worked out
	$result = $total >= MIN_VOTES_FOR_BREAKDOWN ? $tally : null;

	$saved = $wpdb->insert(table('results'), [
		'poll' => $poll,
		'result' => wp_json_encode($result),
		'total' => $total,
		'issued' => $issued,
		'finalised_at' => current_time('mysql', true),
	]);

	if ($saved === false) {
		$wpdb->query('ROLLBACK');
		// Most likely finalised at the same moment by another request
		$existing = get_result($poll);
		return $existing !== null
			? new WP_REST_Response($existing, 200)
			: new WP_Error('db_error', 'Could not save the result.', ['status' => 500]);
	}

	$wpdb->delete(table('votes'), ['poll' => $poll]);
	$wpdb->delete(table('codes'), ['poll' => $poll]);

	$wpdb->query('COMMIT');

	return new WP_REST_Response(get_result($poll), 201);
}

function rest_results(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	$result = get_result((string) $request['slug']);

	if ($result === null) {
		return new WP_Error('not_finalised', 'No result yet.', ['status' => 404]);
	}

	return new WP_REST_Response($result, 200);
}
