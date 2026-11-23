<?php
/**
 * Tools -> Polls admin page: generate, print and delete codes, and see each poll's status.
 * Admins only (manage_options). Every form is protected with a nonce.
 */

declare(strict_types=1);

namespace CarrickDojo\Polls;

if (!defined('ABSPATH')) {
	exit;
}

const PAGE = 'carrick-polls';

add_action('admin_menu', function (): void {
	add_management_page('Polls', 'Polls', 'manage_options', PAGE, __NAMESPACE__ . '\\render_page');
});

add_action('admin_post_carrick_polls_generate', __NAMESPACE__ . '\\handle_generate');
add_action('admin_post_carrick_polls_delete', __NAMESPACE__ . '\\handle_delete');

function page_url(array $args = []): string
{
	return add_query_arg(array_merge(['page' => PAGE], $args), admin_url('tools.php'));
}

/**
 * Back to the page with a message to show.
 */
function redirect_with(string $type, string $message, array $args = []): never
{
	wp_safe_redirect(page_url(array_merge($args, ['notice' => $type, 'message' => rawurlencode($message)])));
	exit;
}

function check_request(string $action): void
{
	if (!current_user_can('manage_options')) {
		wp_die('Not allowed.', 403);
	}
	check_admin_referer($action);
}

function handle_generate(): void
{
	check_request('carrick_polls_generate');

	$poll = sanitize_text_field(wp_unslash($_POST['poll'] ?? ''));
	$count = absint($_POST['count'] ?? DEFAULT_CODES);

	$result = generate_codes($poll, $count);

	if (is_wp_error($result)) {
		redirect_with('error', $result->get_error_message());
	}

	redirect_with('success', sprintf('%d codes generated for "%s".', count($result), $poll), ['print' => $poll]);
}

function handle_delete(): void
{
	check_request('carrick_polls_delete');

	$poll = sanitize_text_field(wp_unslash($_POST['poll'] ?? ''));
	$result = delete_codes($poll);

	if (is_wp_error($result)) {
		redirect_with('error', $result->get_error_message());
	}

	redirect_with('success', sprintf('Codes deleted for "%s".', $poll));
}

function render_page(): void
{
	if (!current_user_can('manage_options')) {
		return;
	}

	$print = sanitize_text_field(wp_unslash($_GET['print'] ?? ''));

	echo '<div class="wrap">';
	echo '<h1>Polls</h1>';

	render_notice();

	if ($print !== '' && is_valid_slug($print)) {
		render_print($print);
	} else {
		render_generate_form();
		render_poll_list();
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

function render_generate_form(): void
{
	?>
	<h2>Generate codes</h2>
	<p>The poll slug must match the poll's <code>.md</code> file name on the club site, e.g. <code>site-direction</code>.</p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="carrick_polls_generate">
		<?php wp_nonce_field('carrick_polls_generate'); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="carrick-poll">Poll slug</label></th>
				<td><input id="carrick-poll" name="poll" type="text" class="regular-text" required pattern="[a-z0-9\-]{1,100}"></td>
			</tr>
			<tr>
				<th scope="row"><label for="carrick-count">Number of codes</label></th>
				<td><input id="carrick-count" name="count" type="number" min="1" max="<?php echo (int) MAX_CODES; ?>" value="<?php echo (int) DEFAULT_CODES; ?>"></td>
			</tr>
		</table>
		<?php submit_button('Generate codes'); ?>
	</form>
	<p class="description">Generating again replaces a poll's codes, but only while none have been used.</p>
	<?php
}

function render_poll_list(): void
{
	$polls = list_polls();

	echo '<h2>Polls</h2>';

	if ($polls === []) {
		echo '<p>No polls yet.</p>';
		return;
	}
	?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col">Poll</th>
				<th scope="col">Codes issued</th>
				<th scope="col">Votes</th>
				<th scope="col">Status</th>
				<th scope="col">Actions</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($polls as $poll) : ?>
				<tr>
					<td><code><?php echo esc_html($poll['poll']); ?></code></td>
					<td><?php echo (int) $poll['issued']; ?></td>
					<td><?php echo (int) $poll['used']; ?></td>
					<td><?php echo $poll['finalised'] ? 'Finalised' : 'Open for codes'; ?></td>
					<td>
						<?php if (!$poll['finalised']) : ?>
							<a class="button" href="<?php echo esc_url(page_url(['print' => $poll['poll']])); ?>">Print codes</a>
							<?php if ($poll['used'] === 0) : ?>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
									<input type="hidden" name="action" value="carrick_polls_delete">
									<input type="hidden" name="poll" value="<?php echo esc_attr($poll['poll']); ?>">
									<?php wp_nonce_field('carrick_polls_delete'); ?>
									<button type="submit" class="button button-link-delete">Delete codes</button>
								</form>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Codes as cut-out slips. Print with Ctrl+P: the print styles hide the WordPress admin around them.
 */
function render_print(string $poll): void
{
	$codes = list_codes($poll);
	?>
	<p class="carrick-no-print">
		<a href="<?php echo esc_url(page_url()); ?>">&larr; Back to polls</a>
		&nbsp; Press <kbd>Ctrl</kbd>+<kbd>P</kbd> to print, then cut along the dashed lines and hand slips out at random.
	</p>

	<?php if ($codes === []) : ?>
		<p>No codes for <code><?php echo esc_html($poll); ?></code>.</p>
		<?php return; ?>
	<?php endif; ?>

	<style>
		.carrick-slips { display: grid; grid-template-columns: repeat(3, 1fr); }
		.carrick-slip { border: 1px dashed #999; padding: 1.2em 0.5em; text-align: center; break-inside: avoid; }
		.carrick-slip strong { display: block; font-size: 1.4em; letter-spacing: 0.05em; font-family: monospace; }
		.carrick-slip span { font-size: 0.85em; color: #555; }
		@media print {
			#adminmenumain, #wpadminbar, #wpfooter, .notice, .carrick-no-print, .wrap > h1 { display: none !important; }
			#wpcontent, #wpbody-content { margin: 0 !important; padding: 0 !important; }
		}
	</style>

	<div class="carrick-slips">
		<?php foreach ($codes as $code) : ?>
			<div class="carrick-slip">
				<span>CarrickDojo poll code</span>
				<strong><?php echo esc_html($code); ?></strong>
				<span>Use once at club.carrickdojo.com</span>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}
