<?php
/**
 * Plugin Name:       CarrickDojo Polls
 * Description:       Anonymous session-code polls, questionnaires, RFCs and quizzes for the CarrickDojo club site.
 * Version:           0.4.0
 * Author:            CarrickDojo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

// Stop the file being run directly, outside WordPress
if (!defined('ABSPATH')) {
	exit;
}

const DB_VERSION = '3';

// The club's timezone. Session IDs are dates in this timezone.
const TIMEZONE = 'Europe/Dublin';

// Kinds of code:
//   member  ninjas and mentors: vote, pick a persona, play quizzes
//   screen  the projector: sees live counts and quizzes, can't vote or play
//   mentor  the mentor's laptop: sees everything and controls quizzes and polls, can't vote or play
const KIND_MEMBER = 'member';
const KIND_SCREEN = 'screen';
const KIND_MENTOR = 'mentor';

// How many codes to generate by default, and the most allowed
const DEFAULT_CODES = 30;
const MAX_CODES = 200;
const DEFAULT_MENTOR_CODES = 3;
const MAX_MENTOR_CODES = 3;

// Fewer votes than this and only the total is published, so no single vote can be worked out
const MIN_VOTES_FOR_BREAKDOWN = 5;

// Wrong codes allowed per session per window before sign-in pauses (stops guessing)
const FAIL_LIMIT = 10;
const FAIL_WINDOW = 60; // seconds

// A sign-in never lasts longer than this, even if the club site asks for longer
const MAX_SIGNIN_HOURS = 12;

// The capability that opens the Polls admin page, and the role that has it
const CAPABILITY = 'manage_carrick_polls';
const ROLE = 'poll_manager';

require_once __DIR__ . '/includes/words.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/secret.php';
require_once __DIR__ . '/includes/codes.php';
require_once __DIR__ . '/includes/personas.php';
require_once __DIR__ . '/includes/cleanup.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/rest-session.php';
require_once __DIR__ . '/includes/rest-votes.php';
require_once __DIR__ . '/includes/rest-quiz.php';
require_once __DIR__ . '/includes/admin.php';

/**
 * Table names, with the site's prefix (usually wp_).
 */
function table(string $name): string
{
	global $wpdb;
	return $wpdb->prefix . 'carrick_poll_' . $name;
}

/**
 * Create or update the tables, role and scheduled clean-up.
 * dbDelta is strict about formatting: two spaces after PRIMARY KEY, one field per line.
 */
function install(): void
{
	global $wpdb;
	$charset = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$installed = get_option('carrick_polls_db_version');

	// Version 1 tables were never used and have a different shape: replace them
	if ($installed === '1') {
		foreach (['codes', 'votes', 'results'] as $old) {
			$wpdb->query('DROP TABLE IF EXISTS ' . table($old));
		}
	}

	// Version 2 quiz tables were never used and are replaced by personas and new quiz tables
	if ($installed === '1' || $installed === '2') {
		foreach (['quiz_players', 'quiz_scores', 'quiz_answers'] as $old) {
			$wpdb->query('DROP TABLE IF EXISTS ' . table($old));
		}
	}

	// Session codes. Deleted the day after their session.
	dbDelta("CREATE TABLE " . table('codes') . " (
		session char(10) NOT NULL,
		code varchar(60) NOT NULL,
		kind varchar(10) NOT NULL DEFAULT 'member',
		PRIMARY KEY  (session, code)
	) $charset;");

	// Which code has voted in which poll. No ID and no timestamp, so rows can't be put in time order
	// and matched against votes. Deleted with the session's codes, or when the poll is finalised.
	dbDelta("CREATE TABLE " . table('code_uses') . " (
		session char(10) NOT NULL,
		code varchar(60) NOT NULL,
		poll varchar(100) NOT NULL,
		PRIMARY KEY  (session, code, poll)
	) $charset;");

	// Sign-ins on the club site. Only a hash of the token is stored.
	dbDelta("CREATE TABLE " . table('tokens') . " (
		token_hash char(64) NOT NULL,
		session char(10) NOT NULL,
		code varchar(60) NOT NULL,
		kind varchar(10) NOT NULL DEFAULT 'member',
		expires_at datetime NOT NULL,
		PRIMARY KEY  (token_hash),
		KEY session (session)
	) $charset;");

	// Votes. No code, and a random ID instead of a counting one, so a person's votes
	// across several polls can't be linked by being next to each other.
	dbDelta("CREATE TABLE " . table('votes') . " (
		id char(32) NOT NULL,
		poll varchar(100) NOT NULL,
		answer text NOT NULL,
		PRIMARY KEY  (id),
		KEY poll (poll)
	) $charset;");

	// Final tallies, kept after votes are deleted.
	dbDelta("CREATE TABLE " . table('results') . " (
		poll varchar(100) NOT NULL,
		result longtext NOT NULL,
		total int unsigned NOT NULL,
		finalised_at datetime NOT NULL,
		PRIMARY KEY  (poll)
	) $charset;");

	// Personas (e.g. Cosmic-Kernel-Duke), one per code per session, unique in the session.
	// The unique key ignores case. Deleted the day after the session.
	dbDelta("CREATE TABLE " . table('personas') . " (
		session char(10) NOT NULL,
		code varchar(60) NOT NULL,
		persona varchar(100) NOT NULL,
		PRIMARY KEY  (session, code),
		UNIQUE KEY session_persona (session, persona)
	) $charset;");

	// A live quiz's state, set by the mentor laptop: when it started, pauses, and time shifts.
	// The club site works out the current question from this and the quiz's .md file.
	dbDelta("CREATE TABLE " . table('quiz_runs') . " (
		session char(10) NOT NULL,
		quiz varchar(100) NOT NULL,
		started_at datetime NULL,
		paused_at datetime NULL,
		shift_seconds int NOT NULL DEFAULT 0,
		leaderboard_until datetime NULL,
		ended tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (session, quiz)
	) $charset;");

	// Quiz answers and points, one per question per player. Deleted the day after the session.
	dbDelta("CREATE TABLE " . table('quiz_answers') . " (
		session char(10) NOT NULL,
		quiz varchar(100) NOT NULL,
		code varchar(60) NOT NULL,
		question varchar(100) NOT NULL,
		answer varchar(200) NOT NULL,
		points int unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (session, quiz, code, question),
		KEY quiz_question (session, quiz, question)
	) $charset;");

	install_role();
	schedule_cleanup();

	update_option('carrick_polls_db_version', DB_VERSION);
}

/**
 * Poll managers can open the Polls admin page and nothing else beyond their own profile.
 * Administrators get the same capability.
 */
function install_role(): void
{
	if (get_role(ROLE) === null) {
		add_role(ROLE, 'Poll manager', ['read' => true, CAPABILITY => true]);
	}
	get_role('administrator')?->add_cap(CAPABILITY);
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\install');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\unschedule_cleanup');

// Uploading a new zip replaces the files without re-activating, so check the tables on load too
add_action('plugins_loaded', function (): void {
	if (get_option('carrick_polls_db_version') !== DB_VERSION) {
		install();
	}
});
