<?php
/**
 * The dojo's settings: one option holding everything a dojo can change.
 */

declare(strict_types=1);

namespace DojoClub;

if (!defined('ABSPATH')) {
	exit;
}

const SETTINGS_OPTION = 'dojo_club_settings';

/**
 * @return array<string, string|int>
 */
function default_settings(): array
{
	return [
		'club_name'         => 'My Dojo',
		// Weekly session rule, used by "Generate term". Day: 0 = Sunday … 6 = Saturday
		'session_day'       => 2,
		'session_start'     => '19:00',
		'session_end'       => '20:00',
		// Ninjas this age or older can ask for an account
		'account_min_age'   => 13,
		// Below this age, an account needs parental consent (Ireland: 16)
		'consent_age'       => 16,
		// Fewer votes than this and only the total is shown
		'min_group'         => 5,
		// How long an account sign-in lasts, in days
		'account_token_days' => 30,
		// Accounts not used for this many months are deleted
		'inactive_months'   => 12,
		// Audit log entries are kept this many months
		'audit_months'      => 24,
	];
}

/**
 * All settings, with defaults filled in for anything missing.
 *
 * @return array<string, string|int>
 */
function settings(): array
{
	$saved = get_option(SETTINGS_OPTION, []);
	return array_merge(default_settings(), is_array($saved) ? $saved : []);
}

function setting(string $key): string|int
{
	return settings()[$key] ?? default_settings()[$key];
}

/**
 * Cleans the settings form. Anything invalid keeps its current value.
 *
 * @param mixed $input
 * @return array<string, string|int>
 */
function sanitize_settings(mixed $input): array
{
	$current = settings();
	if (!is_array($input)) {
		return $current;
	}

	$clean = $current;

	$name = sanitize_text_field((string) ($input['club_name'] ?? ''));
	if ($name !== '' && mb_strlen($name) <= 100) {
		$clean['club_name'] = $name;
	}

	$day = (int) ($input['session_day'] ?? -1);
	if ($day >= 0 && $day <= 6) {
		$clean['session_day'] = $day;
	}

	foreach (['session_start', 'session_end'] as $key) {
		$time = (string) ($input[$key] ?? '');
		if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
			$clean[$key] = $time;
		}
	}
	if ($clean['session_end'] <= $clean['session_start']) {
		add_settings_error(SETTINGS_OPTION, 'times', 'The session must end after it starts. Times not changed.');
		$clean['session_start'] = $current['session_start'];
		$clean['session_end'] = $current['session_end'];
	}

	// GDPR lets each country set the age of digital consent between 13 and 16
	$min_age = (int) ($input['account_min_age'] ?? 0);
	$consent = (int) ($input['consent_age'] ?? 0);
	if ($min_age >= 13 && $min_age <= 18 && $consent >= 13 && $consent <= 16 && $min_age <= $consent) {
		$clean['account_min_age'] = $min_age;
		$clean['consent_age'] = $consent;
	} else {
		add_settings_error(SETTINGS_OPTION, 'ages', 'Ages not changed: the consent age must be 13–16, and not below the account age.');
	}

	$ranges = [
		'min_group'          => [3, 50],
		'account_token_days' => [1, 90],
		'inactive_months'    => [1, 60],
		'audit_months'       => [1, 120],
	];
	foreach ($ranges as $key => [$min, $max]) {
		$value = (int) ($input[$key] ?? 0);
		if ($value >= $min && $value <= $max) {
			$clean[$key] = $value;
		}
	}

	return $clean;
}

add_action('admin_init', function (): void {
	register_setting('dojo_club', SETTINGS_OPTION, [
		'type'              => 'array',
		'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings',
		'show_in_rest'      => false,
	]);
});

// Saving goes through options.php, which needs manage_options unless told otherwise
add_filter('option_page_capability_dojo_club', fn () => 'dojo_manage_settings');
