<?php
/**
 * Personas: the names ninjas and mentors use in quizzes, e.g. "Cosmic-Kernel-Duke".
 *
 * They're built on the club site from approved word lists (src/content/personas.md), never typed,
 * and the club site checks every word against those lists. This plugin checks the shape, keeps
 * personas unique in a session, and makes sure a persona can never look like a code, so a
 * leaderboard on the projector never gives anyone's code away.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * 2 to 4 words of letters only, joined by hyphens, e.g. Cosmic-Kernel-Duke.
 */
function is_valid_persona_shape(mixed $persona): bool
{
	return is_string($persona)
		&& strlen($persona) <= 100
		&& (bool) preg_match('/^[A-Za-z]{1,20}(-[A-Za-z]{1,20}){1,3}$/', $persona);
}

/**
 * Could this persona be mistaken for a code? True if it is any code in any session, or if it has
 * the shape of a member code (a colour then an animal). Comparison ignores case.
 */
function looks_like_code(string $persona): bool
{
	global $wpdb;

	$upper = strtoupper($persona);
	$words = explode('-', $upper);

	if (count($words) === 2 && in_array($words[0], COLOURS, true) && in_array($words[1], ANIMALS, true)) {
		return true;
	}
	if (count($words) === 4
		&& in_array($words[0], COLOURS, true) && in_array($words[1], ANIMALS, true)
		&& in_array($words[2], COLOURS, true) && in_array($words[3], ANIMALS, true)) {
		return true;
	}

	return $wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . table('codes') . ' WHERE code = %s', $upper)) !== null;
}

/**
 * Set or change a code's persona for the session.
 */
function set_persona(string $session, string $code, mixed $persona): true|WP_Error
{
	global $wpdb;

	if (!is_valid_persona_shape($persona)) {
		return new WP_Error('invalid_persona', 'Please spin a persona of 2 to 4 words.', ['status' => 400]);
	}
	if (looks_like_code($persona)) {
		return new WP_Error('persona_like_code', 'That persona looks like a code. Please change a word.', ['status' => 400]);
	}

	// Swap the old persona for the new one in one transaction. The unique key refuses a persona
	// someone else in the session already has, and the rollback then keeps the old one.
	// (Not REPLACE: it would delete the other person's row instead of refusing.)
	$wpdb->query('START TRANSACTION');
	$wpdb->delete(table('personas'), ['session' => $session, 'code' => $code]);

	if (!insert_quietly(table('personas'), ['session' => $session, 'code' => $code, 'persona' => $persona])) {
		$wpdb->query('ROLLBACK');
		return new WP_Error('persona_taken', 'Someone already has that persona. Please change a word.', ['status' => 409]);
	}

	$wpdb->query('COMMIT');
	return true;
}

function get_persona(string $session, string $code): ?string
{
	global $wpdb;

	$persona = $wpdb->get_var($wpdb->prepare(
		'SELECT persona FROM ' . table('personas') . ' WHERE session = %s AND code = %s',
		$session,
		$code
	));

	return is_string($persona) ? $persona : null;
}
