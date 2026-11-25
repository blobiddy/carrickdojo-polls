<?php
/**
 * The two front-end secrets. The live secret sees only live data; the test secret sees only
 * test data. Only SHA-256 hashes are stored, so a secret is shown once when it's generated.
 */

declare(strict_types=1);

namespace DojoClub;

if (!defined('ABSPATH')) {
	exit;
}

const SCOPE_LIVE = 'live';
const SCOPE_TEST = 'test';
const SCOPES = [SCOPE_LIVE, SCOPE_TEST];

function secret_option(string $scope): string
{
	return 'dojo_club_secret_' . $scope;
}

function has_secret(string $scope): bool
{
	return is_string(get_option(secret_option($scope))) && get_option(secret_option($scope)) !== '';
}

/**
 * Makes a new secret for a scope, replacing the old one. Returns it: this is the only time it can be seen.
 */
function generate_secret(string $scope): string
{
	if (!in_array($scope, SCOPES, true)) {
		throw new \InvalidArgumentException('Unknown scope');
	}
	$secret = bin2hex(random_bytes(32));
	// autoload: false, so the hash isn't loaded on every page
	update_option(secret_option($scope), hash('sha256', $secret), false);
	return $secret;
}

/**
 * Which scope a secret belongs to, or null if it matches neither.
 */
function scope_for_secret(string $secret): ?string
{
	if ($secret === '') {
		return null;
	}
	$hash = hash('sha256', $secret);
	foreach (SCOPES as $scope) {
		$stored = get_option(secret_option($scope));
		if (is_string($stored) && $stored !== '' && hash_equals($stored, $hash)) {
			return $scope;
		}
	}
	return null;
}
