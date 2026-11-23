<?php
/**
 * REST endpoints, under /json/polls/ (the site's REST prefix is /json).
 *
 * Everything except results is for the club site's server only: it must send X-Polling-Secret.
 * The club site holds the poll definitions (.md files) and checks that a poll is open and that
 * answers are real options before calling vote. This plugin enforces codes, one vote per code
 * per poll, anonymity and clean-up.
 *
 *   POST signin                  { session, code, expires }  -> { token, expires_at }
 *   POST signout                 { token }
 *   POST me                      { token }                   -> { session, code, voted, nickname }
 *   POST vote                    { token, answers: { poll: answer } }
 *   POST {poll}/finalise                                     -> result
 *   GET  {poll}/results          public                      -> result
 *   POST quiz/points             { token, quiz, question, points } -> { nickname, score }
 *   POST quiz/leaderboard        { session, quiz, limit }    -> [ { nickname, score } ]
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
	exit;
}

const NAMESPACE_REST = 'polls';

add_action('rest_api_init', function (): void {
	$club = __NAMESPACE__ . '\\verify_club_site';
	$poll = '(?P<poll>[a-z0-9-]{1,100})';

	$routes = [
		['/signin', 'POST', 'rest_signin', $club],
		['/signout', 'POST', 'rest_signout', $club],
		['/me', 'POST', 'rest_me', $club],
		['/vote', 'POST', 'rest_vote', $club],
		['/quiz/points', 'POST', 'rest_quiz_points', $club],
		['/quiz/leaderboard', 'POST', 'rest_quiz_leaderboard', $club],
		["/$poll/finalise", 'POST', 'rest_finalise', $club],
		["/$poll/results", 'GET', 'rest_results', '__return_true'],
	];

	foreach ($routes as [$route, $method, $callback, $permission]) {
		register_rest_route(NAMESPACE_REST, $route, [
			'methods' => $method,
			'callback' => __NAMESPACE__ . '\\' . $callback,
			'permission_callback' => $permission,
		]);
	}
});

/* ---------- Wrong-code limit ---------- */

// Counted per session. Everyone in the building shares one IP, so per-IP limits don't work here.
function too_many_failures(string $session): bool
{
	return (int) get_transient("carrick_polls_fail_$session") >= FAIL_LIMIT;
}

function record_failure(string $session): void
{
	$key = "carrick_polls_fail_$session";
	set_transient($key, (int) get_transient($key) + 1, FAIL_WINDOW);
}

/* ---------- Sign-in ---------- */

/**
 * Who a token belongs to, or an error if it's unknown or expired.
 *
 * @return array{session: string, code: string}|WP_Error
 */
function token_owner(mixed $token): array|WP_Error
{
	global $wpdb;

	if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
		return new WP_Error('signed_out', 'Please sign in with your code.', ['status' => 401]);
	}

	$row = $wpdb->get_row($wpdb->prepare(
		'SELECT session, code FROM ' . table('tokens') . ' WHERE token_hash = %s AND expires_at > %s',
		hash('sha256', $token),
		now_utc()
	));

	if ($row === null) {
		return new WP_Error('signed_out', 'Please sign in with your code.', ['status' => 401]);
	}

	return ['session' => $row->session, 'code' => $row->code];
}

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

	$exists = $wpdb->get_var($wpdb->prepare(
		'SELECT 1 FROM ' . table('codes') . ' WHERE session = %s AND code = %s',
		$session,
		$code
	));

	if ($exists === null) {
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
		'expires_at' => gmdate('Y-m-d H:i:s', $expires),
	]);

	if ($saved === false) {
		return new WP_Error('db_error', 'Could not sign you in. Please try again.', ['status' => 500]);
	}

	return new WP_REST_Response(['token' => $token, 'expires_at' => gmdate('c', $expires)], 201);
}

function rest_signout(WP_REST_Request $request): WP_REST_Response
{
	global $wpdb;

	$token = $request->get_param('token');
	if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
		$wpdb->delete(table('tokens'), ['token_hash' => hash('sha256', $token)]);
	}

	// Always succeeds: signing out twice is fine
	return new WP_REST_Response(['signed_out' => true], 200);
}

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

	$nickname = $wpdb->get_var($wpdb->prepare(
		'SELECT nickname FROM ' . table('quiz_players') . ' WHERE session = %s AND code = %s',
		$owner['session'],
		$owner['code']
	));

	return new WP_REST_Response([
		'session' => $owner['session'],
		'code' => $owner['code'],
		'voted' => $voted,
		'nickname' => $nickname,
	], 200);
}

/* ---------- Voting ---------- */

/**
 * Votes on one or more polls at once (a single poll, or a whole questionnaire or RFC).
 * Each poll's answer is stored as its own row, so answers to different polls can't be linked.
 */
function rest_vote(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$owner = token_owner($request->get_param('token'));
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

	// No voting on a poll that has already been finalised
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

/* ---------- Results ---------- */

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

function rest_results(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	$result = get_result((string) $request['poll']);

	if ($result === null) {
		return new WP_Error('not_finalised', 'No result yet.', ['status' => 404]);
	}

	return new WP_REST_Response($result, 200);
}

/* ---------- Quizzes ---------- */

/**
 * A nickname for this code in this session, created the first time it's needed.
 */
function quiz_nickname(string $session, string $code): string|WP_Error
{
	global $wpdb;

	$existing = $wpdb->get_var($wpdb->prepare(
		'SELECT nickname FROM ' . table('quiz_players') . ' WHERE session = %s AND code = %s',
		$session,
		$code
	));
	if (is_string($existing)) {
		return $existing;
	}

	// Try random nicknames until one is free in this session
	for ($attempt = 0; $attempt < 50; $attempt++) {
		$nickname = NICKNAME_ADJECTIVES[random_int(0, count(NICKNAME_ADJECTIVES) - 1)] . ' '
			. ucfirst(strtolower(ANIMALS[random_int(0, count(ANIMALS) - 1)]));

		if (insert_quietly(table('quiz_players'), ['session' => $session, 'code' => $code, 'nickname' => $nickname])) {
			return $nickname;
		}
	}

	return new WP_Error('db_error', 'Could not create a nickname.', ['status' => 500]);
}

/**
 * Add points for one quiz question. Each question only scores once per player.
 * The club site works out the points (it holds the correct answers and the timer).
 */
function rest_quiz_points(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$owner = token_owner($request->get_param('token'));
	if (is_wp_error($owner)) {
		return $owner;
	}

	$quiz = $request->get_param('quiz');
	$question = $request->get_param('question');
	$points = $request->get_param('points');

	if (!is_valid_slug($quiz) || !is_valid_slug($question) || !is_int($points) || $points < 0 || $points > 1000) {
		return new WP_Error('invalid_points', 'Invalid quiz answer.', ['status' => 400]);
	}

	$nickname = quiz_nickname($owner['session'], $owner['code']);
	if (is_wp_error($nickname)) {
		return $nickname;
	}

	$first_time = insert_quietly(table('quiz_answers'), [
		'session' => $owner['session'],
		'quiz' => $quiz,
		'code' => $owner['code'],
		'question' => $question,
	]);

	if ($first_time) {
		$wpdb->query($wpdb->prepare(
			'INSERT INTO ' . table('quiz_scores') . ' (session, quiz, code, score) VALUES (%s, %s, %s, %d)
			ON DUPLICATE KEY UPDATE score = score + VALUES(score)',
			$owner['session'],
			$quiz,
			$owner['code'],
			$points
		));
	}

	$score = (int) $wpdb->get_var($wpdb->prepare(
		'SELECT score FROM ' . table('quiz_scores') . ' WHERE session = %s AND quiz = %s AND code = %s',
		$owner['session'],
		$quiz,
		$owner['code']
	));

	return new WP_REST_Response(['nickname' => $nickname, 'score' => $score, 'counted' => $first_time], 200);
}

function rest_quiz_leaderboard(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$session = $request->get_param('session');
	$quiz = $request->get_param('quiz');
	$limit = $request->get_param('limit') ?? 10;

	if (!is_valid_session($session) || !is_valid_slug($quiz) || !is_int($limit) || $limit < 1 || $limit > 50) {
		return new WP_Error('invalid_leaderboard', 'Invalid leaderboard request.', ['status' => 400]);
	}

	$rows = $wpdb->get_results($wpdb->prepare(
		'SELECT p.nickname, s.score FROM ' . table('quiz_scores') . ' s
		JOIN ' . table('quiz_players') . ' p ON p.session = s.session AND p.code = s.code
		WHERE s.session = %s AND s.quiz = %s
		ORDER BY s.score DESC, p.nickname ASC
		LIMIT %d',
		$session,
		$quiz,
		$limit
	));

	return new WP_REST_Response(
		array_map(fn ($row) => ['nickname' => $row->nickname, 'score' => (int) $row->score], $rows),
		200
	);
}
