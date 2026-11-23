<?php
/**
 * The Polls admin page: generate, print and delete session codes; see polls; set the polling secret.
 * Open to Poll managers and admins (CAPABILITY). Only admins can set the secret.
 * Every form is protected with a nonce.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

if (!defined('ABSPATH')) {
	exit;
}

const PAGE = 'carrick-polls';

add_action('admin_menu', function (): void {
	add_menu_page('Polls', 'Polls', CAPABILITY, PAGE, __NAMESPACE__ . '\\render_page', 'dashicons-chart-bar', 26);
});

add_action('admin_post_carrick_polls_generate', __NAMESPACE__ . '\\handle_generate');
add_action('admin_post_carrick_polls_delete', __NAMESPACE__ . '\\handle_delete');
add_action('admin_post_carrick_polls_secret', __NAMESPACE__ . '\\handle_secret');

function page_url(array $args = []): string
{
	return add_query_arg(array_merge(['page' => PAGE], $args), admin_url('admin.php'));
}

/**
 * Back to the page with a message to show.
 */
function redirect_with(string $type, string $message, array $args = []): never
{
	wp_safe_redirect(page_url(array_merge($args, ['notice' => $type, 'message' => rawurlencode($message)])));
	exit;
}

function check_request(string $action, string $capability = CAPABILITY): void
{
	if (!current_user_can($capability)) {
		wp_die('Not allowed.', 403);
	}
	check_admin_referer($action);
}

function posted(string $key): string
{
	return sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
}

function handle_generate(): void
{
	check_request('carrick_polls_generate');

	$session = posted('session');
	$members = absint(posted('members'));
	$mentors = absint(posted('mentors'));

	$result = generate_codes($session, $members, $mentors);

	if (is_wp_error($result)) {
		redirect_with('error', $result->get_error_message());
	}

	redirect_with('success', sprintf('Codes generated for %s.', session_label($session)), ['print' => $session]);
}

function handle_delete(): void
{
	check_request('carrick_polls_delete');

	$session = posted('session');
	$result = delete_codes($session);

	if (is_wp_error($result)) {
		redirect_with('error', $result->get_error_message());
	}

	redirect_with('success', sprintf('Codes deleted for %s.', session_label($session)));
}

function handle_secret(): void
{
	check_request('carrick_polls_secret', 'manage_options');

	// Not sanitised as text: the secret must be saved exactly as entered
	$secret = trim((string) wp_unslash($_POST['secret'] ?? ''));
	$result = save_secret($secret);

	if (is_wp_error($result)) {
		redirect_with('error', $result->get_error_message());
	}

	redirect_with('success', 'Polling secret saved.');
}

function render_page(): void
{
	if (!current_user_can(CAPABILITY)) {
		return;
	}

	$print = sanitize_text_field(wp_unslash($_GET['print'] ?? ''));

	echo '<div class="wrap">';
	echo '<h1>Polls</h1>';

	render_notice();

	if ($print !== '' && is_valid_session($print)) {
		render_print($print);
	} else {
		render_setup_warning();
		render_generate_form();
		render_sessions();
		render_polls();
		if (current_user_can('manage_options')) {
			render_secret_form();
		}
	}

	echo '</div>';
}

function render_notice(): void
{
	$type = sanitize_key($_GET['notice'] ?? '');
	$message = sanitize_text_field(wp_unslash($_GET['message'] ?? ''));

	if ($message === '' || !in_array($type, ['success', 'error'], true)) {
		return;
	}

	printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($type), esc_html($message));
}

function render_setup_warning(): void
{
	if (is_secret_set()) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>Polls are not set up yet.</strong> An administrator needs to set the polling secret at the bottom of this page.</p></div>';
}

function render_generate_form(): void
{
	?>
	<h2>Generate session codes</h2>
	<p>
		<strong>Member codes</strong> are for ninjas and mentors: one each. They sign in, pick a persona, vote once in every poll open that session, and play quizzes.<br>
		<strong>Mentor codes</strong> are for mentors' laptops: they see everything and control quizzes and polls, but can't vote or play.
		A <strong>screen code</strong> for the projector is made with them.<br>
		Codes only work on the day, and are deleted the day after.
	</p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="carrick_polls_generate">
		<?php wp_nonce_field('carrick_polls_generate'); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="carrick-session">Session date</label></th>
				<td><input id="carrick-session" name="session" type="date" required min="<?php echo esc_attr(today()); ?>" value="<?php echo esc_attr(today()); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="carrick-members">Member codes</label></th>
				<td><input id="carrick-members" name="members" type="number" min="0" max="<?php echo (int) MAX_CODES; ?>" value="<?php echo (int) DEFAULT_CODES; ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="carrick-mentors">Mentor codes</label></th>
				<td>
					<input id="carrick-mentors" name="mentors" type="number" min="0" max="<?php echo (int) MAX_MENTOR_CODES; ?>" value="<?php echo (int) DEFAULT_MENTOR_CODES; ?>">
					<p class="description">Plus one screen code. Set to 0 to leave a session's mentor and screen codes as they are.</p>
				</td>
			</tr>
		</table>
		<?php submit_button('Generate codes'); ?>
	</form>
	<p class="description">Set member codes to 0 to add mentor codes without changing member slips already printed. Codes can be replaced until someone has signed in with one.</p>
	<?php
}

function render_sessions(): void
{
	$sessions = list_sessions();

	echo '<h2>Sessions</h2>';

	if ($sessions === []) {
		echo '<p>No session codes yet.</p>';
		return;
	}
	?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col">Session</th>
				<th scope="col">Member codes</th>
				<th scope="col">Mentor codes</th>
				<th scope="col">Screen code</th>
				<th scope="col">Status</th>
				<th scope="col">Actions</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($sessions as $row) : ?>
				<tr>
					<td><?php echo esc_html(session_label($row['session'])); ?></td>
					<td><?php echo (int) $row['members']; ?></td>
					<td><?php echo (int) $row['mentors']; ?></td>
					<td><?php echo $row['screens'] > 0 ? 'Yes' : 'No'; ?></td>
					<td><?php echo $row['in_use'] ? 'In use' : 'Not used yet'; ?></td>
					<td>
						<a class="button" href="<?php echo esc_url(page_url(['print' => $row['session']])); ?>">Print codes</a>
						<?php if (!$row['in_use']) : ?>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
								<input type="hidden" name="action" value="carrick_polls_delete">
								<input type="hidden" name="session" value="<?php echo esc_attr($row['session']); ?>">
								<?php wp_nonce_field('carrick_polls_delete'); ?>
								<button type="submit" class="button button-link-delete">Delete codes</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function render_polls(): void
{
	$polls = list_polls();

	echo '<h2>Polls</h2>';
	echo '<p>Polls are written as <code>.md</code> files on the club site. This list shows polls that have votes or a final result.</p>';

	if ($polls === []) {
		echo '<p>No votes yet.</p>';
		return;
	}
	?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col">Poll</th>
				<th scope="col">Votes</th>
				<th scope="col">Status</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($polls as $poll) : ?>
				<tr>
					<td><code><?php echo esc_html($poll['poll']); ?></code></td>
					<td><?php echo (int) $poll['votes']; ?></td>
					<td><?php echo $poll['finalised'] ? 'Finalised' : 'Open'; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function render_secret_form(): void
{
	?>
	<h2>Polling secret</h2>
	<?php if (secret_from_config() !== null) : ?>
		<p>Set in <code>wp-config.php</code> (<code>DOJO_POLLING_SECRET</code>). Change it there.</p>
	<?php else : ?>
		<p>
			Status: <strong><?php echo is_secret_set() ? 'Set' : 'Not set'; ?></strong>.
			This must match <code>DOJO_POLLING_SECRET</code> in the club site's Vercel settings.
			Only a hash is stored, so it can't be shown again. Saving replaces the old one.
		</p>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="carrick_polls_secret">
			<?php wp_nonce_field('carrick_polls_secret'); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="carrick-secret">New secret</label></th>
					<td>
						<input id="carrick-secret" name="secret" type="password" class="regular-text" required minlength="<?php echo (int) MIN_SECRET_LENGTH; ?>" autocomplete="off">
						<p class="description">At least <?php echo (int) MIN_SECRET_LENGTH; ?> characters. Create one with <code>openssl rand -hex 32</code>.</p>
					</td>
				</tr>
			</table>
			<?php submit_button('Save secret'); ?>
		</form>
	<?php endif; ?>
	<?php
}

/**
 * Codes as cut-out slips. Print with Ctrl+P: the print styles hide the WordPress admin around them.
 * Mentor and screen codes are on their own page, so they're never handed out by mistake.
 */
function render_print(string $session): void
{
	$label = session_label($session);
	$members = list_codes($session, KIND_MEMBER);
	$staff = [
		...array_map(fn ($code) => [$code, 'Mentor laptop code'], list_codes($session, KIND_MENTOR)),
		...array_map(fn ($code) => [$code, 'Projector screen code'], list_codes($session, KIND_SCREEN)),
	];
	?>
	<p class="carrick-no-print">
		<a href="<?php echo esc_url(page_url()); ?>">&larr; Back to polls</a>
		&nbsp; Press <kbd>Ctrl</kbd>+<kbd>P</kbd> to print, then cut along the dashed lines.
		Hand member slips out at random. Keep mentor and screen codes with the mentors.
	</p>

	<?php if ($members === [] && $staff === []) : ?>
		<p>No codes for <?php echo esc_html($label); ?>.</p>
		<?php return; ?>
	<?php endif; ?>

	<style>
		.carrick-slips { display: grid; grid-template-columns: repeat(3, 1fr); }
		.carrick-slip { border: 1px dashed #999; padding: 1.2em 0.5em; text-align: center; break-inside: avoid; }
		.carrick-slip strong { display: block; margin: 0.3em 0; font-size: 1.4em; letter-spacing: 0.05em; font-family: monospace; overflow-wrap: anywhere; }
		.carrick-slip span { display: block; font-size: 0.85em; color: #555; }
		.carrick-staff { break-before: page; }
		.carrick-staff .carrick-slip { border-style: solid; border-width: 2px; }
		@media print {
			#adminmenumain, #wpadminbar, #wpfooter, .notice, .carrick-no-print, .wrap > h1 { display: none !important; }
			#wpcontent, #wpbody-content { margin: 0 !important; padding: 0 !important; }
		}
	</style>

	<?php if ($members !== []) : ?>
		<div class="carrick-slips">
			<?php foreach ($members as $code) : ?>
				<div class="carrick-slip">
					<span>CarrickDojo code for <?php echo esc_html($label); ?></span>
					<strong><?php echo esc_html($code); ?></strong>
					<span>Sign in at club.carrickdojo.com. Keep it to yourself!</span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ($staff !== []) : ?>
		<div class="carrick-staff">
			<h2>Mentors only: <?php echo esc_html($label); ?></h2>
			<div class="carrick-slips">
				<?php foreach ($staff as [$code, $what]) : ?>
					<div class="carrick-slip">
						<span><?php echo esc_html($what); ?></span>
						<strong><?php echo esc_html($code); ?></strong>
						<span>Do not hand out</span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>
	<?php
}
