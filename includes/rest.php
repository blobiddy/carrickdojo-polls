<?php
/**
 * REST routes, under /json/polls/ (the site's REST prefix is /json).
 *
 * Everything except results is for the club site's server only: it must send X-Polling-Secret.
 * The club site holds the polls and quizzes (.md files): it checks a poll is open, that answers are
 * real options, runs quiz timers and works out points. This plugin enforces codes and what each
 * kind of code may do, one vote per code per poll, anonymity and clean-up.
 *
 * See README.md for every endpoint's body and reply.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

if (!defined('ABSPATH')) {
	exit;
}

const NAMESPACE_REST = 'polls';

add_action('rest_api_init', function (): void {
	$club = __NAMESPACE__ . '\\verify_club_site';
	$poll = '(?P<poll>[a-z0-9-]{1,100})';

	$routes = [
		// Signing in and out, personas, live counts (rest-session.php)
		['/signin', 'POST', 'rest_signin', $club],
		['/signout', 'POST', 'rest_signout', $club],
		['/signout-all', 'POST', 'rest_signout_all', $club],
		['/me', 'POST', 'rest_me', $club],
		['/persona', 'POST', 'rest_persona', $club],
		['/live', 'POST', 'rest_live', $club],

		// Polls (rest-votes.php)
		['/vote', 'POST', 'rest_vote', $club],
		["/$poll/finalise", 'POST', 'rest_finalise', $club],
		["/$poll/results", 'GET', 'rest_results', '__return_true'],

		// Quizzes (rest-quiz.php)
		['/quiz/control', 'POST', 'rest_quiz_control', $club],
		['/quiz/answer', 'POST', 'rest_quiz_answer', $club],
		['/quiz/me', 'POST', 'rest_quiz_me', $club],
		['/quiz/live', 'POST', 'rest_quiz_live', $club],
	];

	foreach ($routes as [$route, $method, $callback, $permission]) {
		register_rest_route(NAMESPACE_REST, $route, [
			'methods' => $method,
			'callback' => __NAMESPACE__ . '\\' . $callback,
			'permission_callback' => $permission,
		]);
	}
});
