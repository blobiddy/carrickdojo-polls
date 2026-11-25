<?php
/**
 * REST API basics: the secret check every route uses, and a status route to test it.
 */

declare(strict_types=1);

namespace DojoClub;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Scopes worked out by require_secret(), per request. Kept outside the request's parameters
 * so nothing a client sends can set it.
 *
 * @return \WeakMap<\WP_REST_Request, string>
 */
function scopes(): \WeakMap
{
	static $scopes = null;
	return $scopes ??= new \WeakMap();
}

/**
 * Permission callback for routes the front end's server calls. Works out the scope (live or
 * test) from the X-Dojo-Secret header.
 */
function require_secret(\WP_REST_Request $request): true|\WP_Error
{
	$scope = scope_for_secret((string) $request->get_header('X-Dojo-Secret'));
	if ($scope === null) {
		return new \WP_Error('dojo_forbidden', 'Not allowed.', ['status' => 403]);
	}
	scopes()[$request] = $scope;
	return true;
}

/**
 * The scope require_secret() worked out: every query must be limited to it. Only for
 * routes protected by require_secret().
 */
function request_scope(\WP_REST_Request $request): string
{
	$scope = scopes()[$request] ?? null;
	if ($scope === null) {
		throw new \LogicException('request_scope() used on a route without require_secret()');
	}
	return $scope;
}

add_action('rest_api_init', function (): void {
	// GET /dojo/v1/status: shows the front end is connected, and with which secret
	register_rest_route(API_NAMESPACE, '/status', [
		'methods'             => 'GET',
		'permission_callback' => __NAMESPACE__ . '\\require_secret',
		'callback'            => fn (\WP_REST_Request $request) => new \WP_REST_Response([
			'club'    => setting('club_name'),
			'version' => VERSION,
			'scope'   => request_scope($request),
		], 200),
	]);
});
