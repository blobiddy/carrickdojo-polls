<?php
/**
 * Live quizzes.
 *
 * The quiz itself (questions, correct answers, timings) is a .md file on the club site, which works
 * out the current question from the quiz's start time and durations. The mentor laptop can override
 * that schedule (start now, pause, resume, shift time, show the leaderboard, end); this plugin stores
 * those overrides as the quiz's "run", so every device and the projector stay in step.
 *
 * Players are known only by their persona. Everything here is deleted the day after the session.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
	exit;
}

const QUIZ_ACTIONS = ['start', 'pause', 'resume', 'shift', 'leaderboard', 'end'];
const MAX_POINTS = 1000;
const MAX_SHIFT_SECONDS = 3600;
const MAX_LEADERBOARD_SECONDS = 120;

/**
 * A quiz's run, or the default (no overrides: follow the .md schedule).
 *
 * @return array{started_at: ?string, paused_at: ?string, shift_seconds: int, leaderboard_until: ?string, ended: bool, now: string}
 */
function quiz_run(string $session, string $quiz): array
{
	global $wpdb;

	$row = $wpdb->get_row($wpdb->prepare(
		'SELECT * FROM ' . table('quiz_runs') . ' WHERE session = %s AND quiz = %s',
		$session,
		$quiz
	));

	$iso = fn (?string $value) => $value === null ? null : utc_to_iso($value);

	return [
		'started_at' => $iso($row->started_at ?? null),
		'paused_at' => $iso($row->paused_at ?? null),
		'shift_seconds' => (int) ($row->shift_seconds ?? 0),
		'leaderboard_until' => $iso($row->leaderboard_until ?? null),
		'ended' => (bool) ($row->ended ?? false),
		// The server's clock, so devices can correct for their own clock being out
		'now' => gmdate('c'),
	];
}

/**
 * Mentor only. Body: { token, quiz, action, seconds }
 *   start        start now (or restart), clearing any pause and shift
 *   pause        freeze the schedule
 *   resume       continue; the paused time is added to the shift
 *   shift        move the schedule by seconds: negative skips ahead, positive gives extra time
 *   leaderboard  show the leaderboard for seconds
 *   end          end the quiz
 * Reply: the run.
 */
function rest_quiz_control(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$owner = token_owner($request->get_param('token'), [KIND_MENTOR]);
	if (is_wp_error($owner)) {
		return $owner;
	}

	$quiz = $request->get_param('quiz');
	$action = $request->get_param('action');
	$seconds = $request->get_param('seconds') ?? 0;

	if (!is_valid_slug($quiz) || !in_array($action, QUIZ_ACTIONS, true) || !is_int($seconds)) {
		return new WP_Error('invalid_control', 'Invalid quiz control.', ['status' => 400]);
	}
	if ($action === 'shift' && ($seconds === 0 || abs($seconds) > MAX_SHIFT_SECONDS)) {
		return new WP_Error('invalid_control', sprintf('Shift must be between 1 and %d seconds either way.', MAX_SHIFT_SECONDS), ['status' => 400]);
	}
	if ($action === 'leaderboard' && ($seconds < 1 || $seconds > MAX_LEADERBOARD_SECONDS)) {
		return new WP_Error('invalid_control', sprintf('Leaderboard time must be between 1 and %d seconds.', MAX_LEADERBOARD_SECONDS), ['status' => 400]);
	}

	$runs = table('quiz_runs');
	$where = $wpdb->prepare('WHERE session = %s AND quiz = %s', $owner['session'], $quiz);
	$now = now_utc();

	// Make sure the quiz has a run row to update
	insert_quietly($runs, ['session' => $owner['session'], 'quiz' => $quiz]);

	match ($action) {
		'start' => $wpdb->query($wpdb->prepare(
			"UPDATE $runs SET started_at = %s, paused_at = NULL, shift_seconds = 0, leaderboard_until = NULL, ended = 0 $where",
			$now
		)),
		'pause' => $wpdb->query($wpdb->prepare(
			"UPDATE $runs SET paused_at = %s $where AND paused_at IS NULL",
			$now
		)),
		'resume' => $wpdb->query($wpdb->prepare(
			"UPDATE $runs SET shift_seconds = shift_seconds + TIMESTAMPDIFF(SECOND, paused_at, %s), paused_at = NULL $where AND paused_at IS NOT NULL",
			$now
		)),
		'shift' => $wpdb->query($wpdb->prepare(
			"UPDATE $runs SET shift_seconds = shift_seconds + %d $where",
			$seconds
		)),
		'leaderboard' => $wpdb->query($wpdb->prepare(
			"UPDATE $runs SET leaderboard_until = %s $where",
			gmdate('Y-m-d H:i:s', time() + $seconds)
		)),
		'end' => $wpdb->query("UPDATE $runs SET ended = 1 $where"),
	};

	return new WP_REST_Response(quiz_run($owner['session'], $quiz), 200);
}

/**
 * Members only, and only with a persona. One answer per question; the club site works out the points.
 * Body: { token, quiz, question, answer, points }  Reply: { counted, score }
 */
function rest_quiz_answer(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	$owner = token_owner($request->get_param('token'), [KIND_MEMBER]);
	if (is_wp_error($owner)) {
		return $owner;
	}

	$quiz = $request->get_param('quiz');
	$question = $request->get_param('question');
	$answer = $request->get_param('answer');
	$points = $request->get_param('points');

	if (!is_valid_slug($quiz) || !is_valid_slug($question)
		|| !is_string($answer) || $answer === '' || mb_strlen($answer) > 200
		|| !is_int($points) || $points < 0 || $points > MAX_POINTS) {
		return new WP_Error('invalid_answer', 'Invalid quiz answer.', ['status' => 400]);
	}

	if (get_persona($owner['session'], $owner['code']) === null) {
		return new WP_Error('no_persona', 'Please choose a persona first.', ['status' => 409]);
	}
	if (quiz_run($owner['session'], $quiz)['ended']) {
		return new WP_Error('quiz_ended', 'This quiz has ended.', ['status' => 409]);
	}

	// The primary key allows one answer per question per player
	$counted = insert_quietly(table('quiz_answers'), [
		'session' => $owner['session'],
		'quiz' => $quiz,
		'code' => $owner['code'],
		'question' => $question,
		'answer' => $answer,
		'points' => $points,
	]);

	return new WP_REST_Response([
		'counted' => $counted,
		'score' => quiz_score($owner['session'], $quiz, $owner['code']),
	], 200);
}

function quiz_score(string $session, string $quiz, string $code): int
{
	global $wpdb;

	return (int) $wpdb->get_var($wpdb->prepare(
		'SELECT COALESCE(SUM(points), 0) FROM ' . table('quiz_answers') . ' WHERE session = %s AND quiz = %s AND code = %s',
		$session,
		$quiz,
		$code
	));
}

/**
 * Members only. A player's own view.
 * Body: { token, quiz }  Reply: { persona, score, rank, answered: [question], run }
 */
function rest_quiz_me(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$owner = token_owner($request->get_param('token'), [KIND_MEMBER]);
	if (is_wp_error($owner)) {
		return $owner;
	}

	$quiz = $request->get_param('quiz');
	if (!is_valid_slug($quiz)) {
		return new WP_Error('invalid_quiz', 'Unknown quiz.', ['status' => 400]);
	}

	$answers = table('quiz_answers');
	$score = quiz_score($owner['session'], $quiz, $owner['code']);

	// Rank: one more than the number of players with a higher score
	$ahead = (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM (
			SELECT code, SUM(points) AS score FROM $answers WHERE session = %s AND quiz = %s GROUP BY code HAVING score > %d
		) AS ahead",
		$owner['session'],
		$quiz,
		$score
	));

	$answered = $wpdb->get_col($wpdb->prepare(
		"SELECT question FROM $answers WHERE session = %s AND quiz = %s AND code = %s",
		$owner['session'],
		$quiz,
		$owner['code']
	));

	return new WP_REST_Response([
		'persona' => get_persona($owner['session'], $owner['code']),
		'score' => $score,
		'rank' => $ahead + 1,
		'answered' => $answered,
		'run' => quiz_run($owner['session'], $quiz),
	], 200);
}

/**
 * The top players by persona, optionally counting only some questions (for "before this round").
 *
 * @param list<string>|null $questions
 * @return list<array{persona: string, score: int}>
 */
function quiz_leaderboard(string $session, string $quiz, int $limit, ?array $questions = null): array
{
	global $wpdb;

	$filter = '';
	$args = [$session, $quiz];

	if ($questions !== null) {
		if ($questions === []) {
			return [];
		}
		$filter = ' AND a.question IN (' . implode(', ', array_fill(0, count($questions), '%s')) . ')';
		$args = [...$args, ...$questions];
	}

	$rows = $wpdb->get_results($wpdb->prepare(
		'SELECT p.persona, SUM(a.points) AS score
		FROM ' . table('quiz_answers') . ' a
		JOIN ' . table('personas') . " p ON p.session = a.session AND p.code = a.code
		WHERE a.session = %s AND a.quiz = %s$filter
		GROUP BY a.code, p.persona
		ORDER BY score DESC, p.persona ASC
		LIMIT %d",
		...[...$args, $limit]
	));

	return array_map(fn ($row) => ['persona' => $row->persona, 'score' => (int) $row->score], $rows);
}

/**
 * Screen and mentor only. Everything the projector needs.
 * Body: { token, quiz, question, before: [question], limit }
 *   question  the current question, for the answered count and the answer tally
 *   before    the questions before this round, for the previous leaderboard (rank changes)
 * Reply: { run, players, answered, tally, leaderboard, previous }
 */
function rest_quiz_live(WP_REST_Request $request): WP_REST_Response|WP_Error
{
	global $wpdb;

	$owner = token_owner($request->get_param('token'), [KIND_SCREEN, KIND_MENTOR]);
	if (is_wp_error($owner)) {
		return $owner;
	}

	$quiz = $request->get_param('quiz');
	$question = $request->get_param('question');
	$before = $request->get_param('before');
	$limit = $request->get_param('limit') ?? 10;

	if (!is_valid_slug($quiz)
		|| ($question !== null && !is_valid_slug($question))
		|| !is_int($limit) || $limit < 1 || $limit > 50) {
		return new WP_Error('invalid_live', 'Invalid quiz request.', ['status' => 400]);
	}
	if ($before !== null) {
		if (!is_array($before) || !array_is_list($before) || count($before) > 200) {
			return new WP_Error('invalid_live', 'Invalid quiz request.', ['status' => 400]);
		}
		foreach ($before as $item) {
			if (!is_valid_slug($item)) {
				return new WP_Error('invalid_live', 'Invalid quiz request.', ['status' => 400]);
			}
		}
	}

	$session = $owner['session'];
	$answers = table('quiz_answers');

	$players = (int) $wpdb->get_var($wpdb->prepare(
		'SELECT COUNT(*) FROM ' . table('personas') . ' WHERE session = %s',
		$session
	));

	$answered = 0;
	$tally = [];
	if ($question !== null) {
		foreach ($wpdb->get_results($wpdb->prepare(
			"SELECT answer, COUNT(*) AS count FROM $answers WHERE session = %s AND quiz = %s AND question = %s GROUP BY answer",
			$session,
			$quiz,
			$question
		)) as $row) {
			$tally[$row->answer] = (int) $row->count;
			$answered += (int) $row->count;
		}
	}

	return new WP_REST_Response([
		'run' => quiz_run($session, $quiz),
		'players' => $players,
		'answered' => $answered,
		'tally' => (object) $tally,
		'leaderboard' => quiz_leaderboard($session, $quiz, $limit),
		'previous' => $before === null ? null : quiz_leaderboard($session, $quiz, $limit, $before),
	], 200);
}
