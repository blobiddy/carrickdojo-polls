<?php
/**
 * Dojo roles for adults (set as tick boxes on their WordPress profile), the capabilities
 * each one grants, and the dojo_ninja role for ninja accounts.
 */

declare(strict_types=1);

namespace DojoClub;

if (!defined('ABSPATH')) {
	exit;
}

const ROLES_META = 'dojo_roles';
const NINJA_ROLE = 'dojo_ninja';

/**
 * Every capability the plugin checks. dojo_access lets someone see the Dojo Club menu.
 */
const CAPABILITIES = [
	'dojo_access',
	'dojo_assign_roles',
	'dojo_manage_settings',
	'dojo_manage_secrets',
	'dojo_backup',
	'dojo_test_tools',
	'dojo_manage_sessions',
	'dojo_print_codes',
	'dojo_manage_polls',
	'dojo_manage_proposals',
	'dojo_manage_projects',
	'dojo_manage_library',
	'dojo_library_desk',
	'dojo_approve',
	'dojo_delete_any',
	'dojo_manage_accounts',
	'dojo_privacy',
	'dojo_view_audit',
	'dojo_view_reports',
];

/**
 * @return array<string, array{label: string, caps: list<string>}>
 */
function dojo_roles(): array
{
	$mentor = [
		'dojo_access',
		'dojo_manage_sessions',
		'dojo_print_codes',
		'dojo_manage_polls',
		'dojo_manage_projects',
		'dojo_manage_library',
		'dojo_library_desk',
		'dojo_approve',
	];

	return [
		'champion'     => ['label' => 'Champion', 'caps' => CAPABILITIES],
		'safeguarding' => [
			'label' => 'Safeguarding Officer',
			'caps'  => ['dojo_access', 'dojo_approve', 'dojo_delete_any', 'dojo_manage_accounts', 'dojo_privacy', 'dojo_view_audit'],
		],
		'technical'    => [
			'label' => 'Technical Mentor',
			'caps'  => [...$mentor, 'dojo_manage_settings', 'dojo_test_tools'],
		],
		'mentor'       => ['label' => 'Mentor', 'caps' => $mentor],
		'volunteer'    => ['label' => 'Volunteer', 'caps' => ['dojo_access', 'dojo_library_desk', 'dojo_print_codes']],
		'committee'    => [
			'label' => 'Committee',
			'caps'  => ['dojo_access', 'dojo_view_reports', 'dojo_manage_proposals'],
		],
	];
}

/**
 * The dojo roles ticked on a user's profile.
 *
 * @return list<string>
 */
function user_dojo_roles(int $user_id): array
{
	$saved = get_user_meta($user_id, ROLES_META, true);
	if (!is_array($saved)) {
		return [];
	}
	return array_values(array_intersect(array_keys(dojo_roles()), $saved));
}

/**
 * Grants capabilities from dojo roles as WordPress checks them. Nothing is written to the
 * user's stored capabilities, so changing the table above changes everyone at once.
 * Site administrators get every dojo capability.
 */
add_filter('user_has_cap', function (array $allcaps, array $caps, array $args, \WP_User $user): array {
	if (!empty($allcaps['manage_options'])) {
		foreach (CAPABILITIES as $cap) {
			$allcaps[$cap] = true;
		}
		return $allcaps;
	}

	$roles = dojo_roles();
	foreach (user_dojo_roles($user->ID) as $role) {
		foreach ($roles[$role]['caps'] as $cap) {
			$allcaps[$cap] = true;
		}
	}
	return $allcaps;
}, 10, 4);

/**
 * Ninja accounts: a role with no capabilities at all. They sign in only through the club site.
 */
function add_ninja_role(): void
{
	if (get_role(NINJA_ROLE) === null) {
		add_role(NINJA_ROLE, 'Dojo ninja', []);
	}
}

/**
 * The "Dojo roles" tick boxes on user profiles. Only adults get them: never ninja accounts.
 */
function render_roles_field(\WP_User $user): void
{
	if (!current_user_can('dojo_assign_roles') || in_array(NINJA_ROLE, $user->roles, true)) {
		return;
	}

	$ticked = user_dojo_roles($user->ID);
	?>
	<h2>Dojo roles</h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Roles</th>
			<td>
				<fieldset>
					<legend class="screen-reader-text">Dojo roles</legend>
					<?php foreach (dojo_roles() as $key => $role) : ?>
						<label>
							<input type="checkbox" name="dojo_roles[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $ticked, true)); ?>>
							<?php echo esc_html($role['label']); ?>
						</label><br>
					<?php endforeach; ?>
					<p class="description">A person can have more than one role.</p>
				</fieldset>
			</td>
		</tr>
	</table>
	<?php
}

add_action('show_user_profile', __NAMESPACE__ . '\\render_roles_field');
add_action('edit_user_profile', __NAMESPACE__ . '\\render_roles_field');

/**
 * Saves the tick boxes. WordPress has already checked the profile form's nonce by now.
 */
function save_roles_field(int $user_id): void
{
	if (!current_user_can('dojo_assign_roles') || !current_user_can('edit_user', $user_id)) {
		return;
	}
	$user = get_userdata($user_id);
	if ($user === false || in_array(NINJA_ROLE, $user->roles, true)) {
		return;
	}

	$posted = isset($_POST['dojo_roles']) && is_array($_POST['dojo_roles'])
		? array_map('sanitize_key', wp_unslash($_POST['dojo_roles']))
		: [];
	$roles = array_values(array_intersect(array_keys(dojo_roles()), $posted));

	update_user_meta($user_id, ROLES_META, $roles);
}

add_action('personal_options_update', __NAMESPACE__ . '\\save_roles_field');
add_action('edit_user_profile_update', __NAMESPACE__ . '\\save_roles_field');
