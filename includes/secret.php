<?php
/**
 * The polling secret shared with the club site (DOJO_POLLING_SECRET).
 *
 * wp-config.php can't be edited on our host, so an admin sets it on the Polls page.
 * Only a SHA-256 hash is stored: nobody, admins included, can read the secret back.
 * If DOJO_POLLING_SECRET is ever defined in wp-config.php, that takes priority.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

const SECRET_OPTION = 'carrick_polls_secret_hash';
const MIN_SECRET_LENGTH = 32;

function secret_from_config(): ?string
{
	return defined('DOJO_POLLING_SECRET') && is_string(DOJO_POLLING_SECRET) && DOJO_POLLING_SECRET !== ''
		? DOJO_POLLING_SECRET
		: null;
}

function stored_secret_hash(): ?string
{
	$config = secret_from_config();
	if ($config !== null) {
		return hash('sha256', $config);
	}

	$hash = get_option(SECRET_OPTION);
	return is_string($hash) && strlen($hash) === 64 ? $hash : null;
}

function is_secret_set(): bool
{
	return stored_secret_hash() !== null;
}

function save_secret(string $secret): true|WP_Error
{
	if (strlen($secret) < MIN_SECRET_LENGTH) {
		return new WP_Error('secret_too_short', sprintf('The secret must be at least %d characters.', MIN_SECRET_LENGTH));
	}
	// autoload false: only loaded when a request needs it
	update_option(SECRET_OPTION, hash('sha256', $secret), false);
	return true;
}

/**
 * Only the club site's server may call the write endpoints. It sends the secret in X-Polling-Secret.
 */
function verify_club_site(WP_REST_Request $request): true|WP_Error
{
	$stored = stored_secret_hash();
	if ($stored === null) {
		return new WP_Error('not_configured', 'Polls are not set up yet.', ['status' => 503]);
	}

	$sent = $request->get_header('X-Polling-Secret');

	// hash_equals takes the same time whether the secret is nearly right or totally wrong
	if (!is_string($sent) || !hash_equals($stored, hash('sha256', $sent))) {
		return new WP_Error('forbidden', 'Not allowed.', ['status' => 401]);
	}

	return true;
}
