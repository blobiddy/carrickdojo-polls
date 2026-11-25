<?php
/**
 * WP admin: the Dojo Club menu, dashboard and settings.
 */

declare(strict_types=1);

namespace DojoClub;

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_menu', function (): void {
	add_menu_page('Dojo Club', 'Dojo Club', 'dojo_access', 'dojo-club', __NAMESPACE__ . '\\render_dashboard', 'dashicons-groups', 30);
	add_submenu_page('dojo-club', 'Dashboard', 'Dashboard', 'dojo_access', 'dojo-club', __NAMESPACE__ . '\\render_dashboard');
	add_submenu_page('dojo-club', 'Settings', 'Settings', 'dojo_manage_settings', 'dojo-club-settings', __NAMESPACE__ . '\\render_settings');
});

function render_dashboard(): void
{
	?>
	<div class="wrap">
		<h1><?php echo esc_html((string) setting('club_name')); ?></h1>
		<h2>Health</h2>
		<ul>
			<?php foreach (SCOPES as $scope) : ?>
				<li>
					<?php echo has_secret($scope) ? '✅' : '⚠️'; ?>
					<?php echo esc_html(ucfirst($scope)); ?> secret
					<?php echo has_secret($scope) ? 'is set.' : 'is not set: generate it in Settings.'; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p>Version <?php echo esc_html(VERSION); ?></p>
	</div>
	<?php
}

/**
 * Generates a secret if the form was posted. Returns [scope, secret] to show once, or null.
 * The secret is never stored or redirected with: it's shown on this page load only.
 *
 * @return array{0: string, 1: string}|null
 */
function handle_secret_form(): ?array
{
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['dojo_generate_secret'])) {
		return null;
	}
	check_admin_referer('dojo_generate_secret');
	if (!current_user_can('dojo_manage_secrets')) {
		wp_die('You are not allowed to change the secrets.', 403);
	}

	$scope = sanitize_key(wp_unslash((string) $_POST['dojo_generate_secret']));
	if (!in_array($scope, SCOPES, true)) {
		wp_die('Unknown secret.', 400);
	}
	return [$scope, generate_secret($scope)];
}

function render_settings(): void
{
	$new_secret = handle_secret_form();
	$s = settings();
	$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
	$name = fn (string $key) => esc_attr(SETTINGS_OPTION . '[' . $key . ']');
	?>
	<div class="wrap">
		<h1>Dojo Club settings</h1>
		<?php settings_errors(SETTINGS_OPTION); ?>

		<?php if ($new_secret !== null) : ?>
			<div class="notice notice-warning">
				<p><strong>New <?php echo esc_html($new_secret[0]); ?> secret.</strong> Copy it now: it won't be shown again.</p>
				<p><code style="user-select: all;"><?php echo esc_html($new_secret[1]); ?></code></p>
				<p>Set it as <code>DOJO_SECRET</code> in the front end's <?php echo $new_secret[0] === SCOPE_LIVE ? 'Production' : 'local <code>.env</code> and Preview'; ?> environment variables.</p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields('dojo_club'); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dojo-club-name">Club name</label></th>
					<td><input id="dojo-club-name" name="<?php echo $name('club_name'); ?>" type="text" class="regular-text" maxlength="100" value="<?php echo esc_attr((string) $s['club_name']); ?>" required></td>
				</tr>
				<tr>
					<th scope="row">Weekly session</th>
					<td>
						<label for="dojo-day" class="screen-reader-text">Day</label>
						<select id="dojo-day" name="<?php echo $name('session_day'); ?>">
							<?php foreach ($days as $i => $day) : ?>
								<option value="<?php echo (int) $i; ?>" <?php selected((int) $s['session_day'], $i); ?>><?php echo esc_html($day); ?></option>
							<?php endforeach; ?>
						</select>
						<label for="dojo-start">from</label>
						<input id="dojo-start" name="<?php echo $name('session_start'); ?>" type="time" value="<?php echo esc_attr((string) $s['session_start']); ?>" required>
						<label for="dojo-end">to</label>
						<input id="dojo-end" name="<?php echo $name('session_end'); ?>" type="time" value="<?php echo esc_attr((string) $s['session_end']); ?>" required>
						<p class="description">Used by "Generate term". Times are in the site's timezone (Settings → General).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Accounts</th>
					<td>
						<label>Ninjas can ask for an account from age
							<input name="<?php echo $name('account_min_age'); ?>" type="number" min="13" max="18" value="<?php echo (int) $s['account_min_age']; ?>" class="small-text"></label><br>
						<label>Parental consent needed under age
							<input name="<?php echo $name('consent_age'); ?>" type="number" min="13" max="16" value="<?php echo (int) $s['consent_age']; ?>" class="small-text"></label>
						<p class="description">The age of digital consent is set by each country (13–16). In Ireland it's 16.</p>
						<label>Account sign-ins last
							<input name="<?php echo $name('account_token_days'); ?>" type="number" min="1" max="90" value="<?php echo (int) $s['account_token_days']; ?>" class="small-text"> days</label><br>
						<label>Delete accounts not used for
							<input name="<?php echo $name('inactive_months'); ?>" type="number" min="1" max="60" value="<?php echo (int) $s['inactive_months']; ?>" class="small-text"> months</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dojo-min-group">Minimum group size</label></th>
					<td>
						<input id="dojo-min-group" name="<?php echo $name('min_group'); ?>" type="number" min="3" max="50" value="<?php echo (int) $s['min_group']; ?>" class="small-text">
						<p class="description">With fewer votes than this, only the total is shown, so no one's vote can be worked out.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dojo-audit">Audit log</label></th>
					<td><label>Keep for <input id="dojo-audit" name="<?php echo $name('audit_months'); ?>" type="number" min="1" max="120" value="<?php echo (int) $s['audit_months']; ?>" class="small-text"> months</label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<?php if (current_user_can('dojo_manage_secrets')) : ?>
			<h2>Secrets</h2>
			<p>The front end's server sends one of these with every request. The <strong>live</strong> secret sees only live data; the <strong>test</strong> secret sees only test data. Generating a new one stops the old one working.</p>
			<?php foreach (SCOPES as $scope) : ?>
				<form method="post" style="margin-bottom: 1em;">
					<?php wp_nonce_field('dojo_generate_secret'); ?>
					<input type="hidden" name="dojo_generate_secret" value="<?php echo esc_attr($scope); ?>">
					<?php echo esc_html(ucfirst($scope)); ?>: <?php echo has_secret($scope) ? 'set' : '<strong>not set</strong>'; ?>
					<?php submit_button(has_secret($scope) ? 'Replace ' . $scope . ' secret' : 'Generate ' . $scope . ' secret', 'secondary', 'submit', false); ?>
				</form>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}
