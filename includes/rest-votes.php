<?php
/**
 * Voting, finalising and results.
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
 * Members only. Votes on one or more polls at once (a single poll, or a whole questionnaire or RFC).
 * Each poll's answer is stored as its own row, so answers to different polls can't be linked.
 * Body: { token, answers: { poll: answer } }  Reply: { voted: [poll] }
 */
function rest_vote(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$owner = token_owner($request->get_param('token'), [KIND_MEMBER]);
	if (is_wp_error($owner)) {
		return $owner;
	}

	$answers = $request->get_param('answers');

	if (!is_array($answers) || $answers === [] || count($answers) > 50) {
		return new WP_Error('invalid_vote', 'Please answer the questions.', ['status' => 400]);
	}
	foreach ($answers as $poll => $answer) {
		if (!is_valid_slug($poll) || !is_valid_answer($answer)) {
			return new WP_Error('invalid_vote', 'Please answer the questions.', ['status' => 400]);
		}
	}

	$polls = array_keys($answers);

	// No voting on a poll that has been finalised (closed, possibly early by a mentor)
	$placeholders = implode(', ', array_fill(0, count($polls), '%s'));
	$closed = $wpdb->get_col($wpdb->prepare(
		'SELECT poll FROM ' . table('results') . " WHERE poll IN ($placeholders)",
		...$polls
	));
	if ($closed !== []) {
		return new WP_Error('poll_closed', 'This poll has closed.', ['status' => 409, 'polls' => $closed]);
	}

	// One transaction: record each code use and save each vote. All happen, or none do.
	$wpdb->query('START TRANSACTION');

	foreach ($answers as $poll => $answer) {
		// The primary key (session, code, poll) refuses a second use of the same code in the same poll
		$used = insert_quietly(table('code_uses'), [
			'session' => $owner['session'],
			'code' => $owner['code'],
			'poll' => $poll,
		]);

		if (!$used) {
			$wpdb->query('ROLLBACK');
			return new WP_Error('already_voted', "You've already voted in this poll.", ['status' => 409, 'poll' => $poll]);
		}

		$saved = $wpdb->insert(table('votes'), [
			'id' => bin2hex(random_bytes(16)),
			'poll' => $poll,
			'answer' => wp_json_encode($answer),
		]);

		if ($saved === false) {
			$wpdb->query('ROLLBACK');
			return new WP_Error('db_error', 'Your vote could not be saved. Please try again.', ['status' => 500]);
		}
	}

	$wpdb->query('COMMIT');

	return new WP_REST_Response(['voted' => $polls], 201);
}

/**
 * @return array{poll: string, total: int, finalised_at: string, result: array<string, int>|null}|null
 */
function get_result(string $poll): ?array
{
	global $wpdb;

	$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . table('results') . ' WHERE poll = %s', $poll));

	if ($row === null) {
		return null;
	}

	return [
		'poll' => $row->poll,
		'total' => (int) $row->total,
		'finalised_at' => utc_to_iso($row->finalised_at),
		'result' => json_decode($row->result, true),
	];
}

/**
 * Tally the votes into { answer: count }, save the result, then delete the votes and code uses.
 * Called by the club site when a poll closes, or when a mentor closes it early.
 * Safe to call more than once: after the first time it just returns the saved result.
 */
function rest_finalise(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$poll = (string) $request['poll'];

	if (!is_valid_slug($poll)) {
		return new WP_Error('invalid_poll', 'Unknown poll.', ['status' => 400]);
	}

	$existing = get_result($poll);
	if ($existing !== null) {
		return new WP_REST_Response($existing, 200);
	}

	$wpdb->query('START TRANSACTION');

	$rows = $wpdb->get_col($wpdb->prepare(
		'SELECT answer FROM ' . table('votes') . ' WHERE poll = %s FOR UPDATE',
		$poll
	));

	$tally = [];
	foreach ($rows as $json) {
		foreach ((array) json_decode($json, true) as $value) {
			if (is_string($value)) {
				$tally[$value] = ($tally[$value] ?? 0) + 1;
			}
		}
	}
	arsort($tally);

	$total = count($rows);

	// Too few votes: publish only the total, so no single vote can be worked out
	$result = $total >= MIN_VOTES_FOR_BREAKDOWN ? $tally : null;

	$saved = $wpdb->insert(table('results'), [
		'poll' => $poll,
		'result' => wp_json_encode($result),
		'total' => $total,
		'finalised_at' => now_utc(),
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
	$wpdb->delete(table('code_uses'), ['poll' => $poll]);

	$wpdb->query('COMMIT');

	return new WP_REST_Response(get_result($poll), 201);
}

/**
 * Public. The final result, or 404 before the poll is finalised.
 */
function rest_results(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	$result = get_result((string) $request['poll']);

	if ($result === null) {
		return new WP_Error('not_finalised', 'No result yet.', ['status' => 404]);
	}

	return new WP_REST_Response($result, 200);
}
