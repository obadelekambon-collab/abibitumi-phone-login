<?php
/**
 * Settings screen.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s     = ABChat_Settings::all();
$days  = array( 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday' );

/**
 * Checkbox helper.
 *
 * @param string $name  Field name.
 * @param mixed  $val   Current value.
 * @param string $label Label text.
 */
$checkbox = function ( $name, $val, $label ) {
	printf(
		'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>',
		esc_attr( $name ),
		checked( ! empty( $val ), true, false ),
		esc_html( $label )
	);
};
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Abibitumi Chat — Settings', 'abibitumi-chat' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'abibitumi-chat' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="abchat-settings-form">
		<input type="hidden" name="action" value="abchat_save_settings">
		<?php wp_nonce_field( 'abchat_settings' ); ?>

		<!-- General -->
		<div class="abchat-section">
			<h2><?php esc_html_e( 'General', 'abibitumi-chat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Enable chat widget', 'abibitumi-chat' ); ?></th>
					<td><?php $checkbox( 'enabled', $s['enabled'], __( 'Show the chat widget on the site', 'abibitumi-chat' ) ); ?></td>
				</tr>
				<tr>
					<th><label for="brand_name"><?php esc_html_e( 'Brand name', 'abibitumi-chat' ); ?></label></th>
					<td><input name="brand_name" id="brand_name" type="text" class="regular-text" value="<?php echo esc_attr( $s['brand_name'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="welcome_title"><?php esc_html_e( 'Welcome title', 'abibitumi-chat' ); ?></label></th>
					<td><input name="welcome_title" id="welcome_title" type="text" class="regular-text" value="<?php echo esc_attr( $s['welcome_title'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="welcome_subtitle"><?php esc_html_e( 'Welcome subtitle / greeting', 'abibitumi-chat' ); ?></label></th>
					<td><input name="welcome_subtitle" id="welcome_subtitle" type="text" class="large-text" value="<?php echo esc_attr( $s['welcome_subtitle'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Colors', 'abibitumi-chat' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Primary', 'abibitumi-chat' ); ?> <input name="primary_color" type="color" value="<?php echo esc_attr( $s['primary_color'] ); ?>"></label>
						&nbsp;&nbsp;
						<label><?php esc_html_e( 'Text', 'abibitumi-chat' ); ?> <input name="text_color" type="color" value="<?php echo esc_attr( $s['text_color'] ); ?>"></label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Position', 'abibitumi-chat' ); ?></th>
					<td>
						<select name="position">
							<option value="right" <?php selected( $s['position'], 'right' ); ?>><?php esc_html_e( 'Bottom right', 'abibitumi-chat' ); ?></option>
							<option value="left" <?php selected( $s['position'], 'left' ); ?>><?php esc_html_e( 'Bottom left', 'abibitumi-chat' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="avatar_url"><?php esc_html_e( 'Avatar image URL', 'abibitumi-chat' ); ?></label></th>
					<td><input name="avatar_url" id="avatar_url" type="url" class="large-text" value="<?php echo esc_attr( $s['avatar_url'] ); ?>" placeholder="https://…"></td>
				</tr>
				<tr>
					<th><label for="greeting_delay"><?php esc_html_e( 'Proactive greeting delay (s)', 'abibitumi-chat' ); ?></label></th>
					<td><input name="greeting_delay" id="greeting_delay" type="number" min="0" value="<?php echo esc_attr( $s['greeting_delay'] ); ?>"></td>
				</tr>
			</table>
		</div>

		<!-- Behaviour -->
		<div class="abchat-section">
			<h2><?php esc_html_e( 'Behaviour', 'abibitumi-chat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Display', 'abibitumi-chat' ); ?></th>
					<td>
						<?php $checkbox( 'show_on_mobile', $s['show_on_mobile'], __( 'Show on mobile devices', 'abibitumi-chat' ) ); ?><br>
						<?php $checkbox( 'require_login', $s['require_login'], __( 'Only show to logged-in members', 'abibitumi-chat' ) ); ?><br>
						<?php $checkbox( 'sound_enabled', $s['sound_enabled'], __( 'Play sound on new message', 'abibitumi-chat' ) ); ?><br>
						<?php $checkbox( 'file_uploads', $s['file_uploads'], __( 'Allow file uploads', 'abibitumi-chat' ) ); ?>
					</td>
				</tr>
				<tr>
					<th><label for="max_upload_mb"><?php esc_html_e( 'Max upload size (MB)', 'abibitumi-chat' ); ?></label></th>
					<td><input name="max_upload_mb" id="max_upload_mb" type="number" min="1" value="<?php echo esc_attr( $s['max_upload_mb'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Poll interval (s)', 'abibitumi-chat' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Visitor', 'abibitumi-chat' ); ?> <input name="poll_interval" type="number" min="2" value="<?php echo esc_attr( $s['poll_interval'] ); ?>"></label>
						&nbsp;&nbsp;
						<label><?php esc_html_e( 'Agent', 'abibitumi-chat' ); ?> <input name="agent_poll_interval" type="number" min="2" value="<?php echo esc_attr( $s['agent_poll_interval'] ); ?>"></label>
					</td>
				</tr>
			</table>
		</div>

		<!-- Pre-chat -->
		<div class="abchat-section">
			<h2><?php esc_html_e( 'Pre-chat form', 'abibitumi-chat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Collect before chat', 'abibitumi-chat' ); ?></th>
					<td>
						<?php $checkbox( 'prechat_enabled', $s['prechat_enabled'], __( 'Enable pre-chat form', 'abibitumi-chat' ) ); ?><br>
						<?php $checkbox( 'prechat_name', $s['prechat_name'], __( 'Ask for name', 'abibitumi-chat' ) ); ?><br>
						<?php $checkbox( 'prechat_email', $s['prechat_email'], __( 'Ask for email', 'abibitumi-chat' ) ); ?><br>
						<?php $checkbox( 'prechat_phone', $s['prechat_phone'], __( 'Ask for phone', 'abibitumi-chat' ) ); ?>
					</td>
				</tr>
				<tr>
					<th><label for="prechat_message"><?php esc_html_e( 'Pre-chat message', 'abibitumi-chat' ); ?></label></th>
					<td><input name="prechat_message" id="prechat_message" type="text" class="large-text" value="<?php echo esc_attr( $s['prechat_message'] ); ?>"></td>
				</tr>
			</table>
		</div>

		<!-- Departments -->
		<div class="abchat-section">
			<h2><?php esc_html_e( 'Departments', 'abibitumi-chat' ); ?></h2>
			<div class="abchat-repeatable" id="abchat-departments">
				<?php foreach ( (array) $s['departments'] as $d ) : ?>
					<div class="row">
						<input type="text" name="dept_id[]" value="<?php echo esc_attr( $d['id'] ); ?>" placeholder="<?php esc_attr_e( 'id (slug)', 'abibitumi-chat' ); ?>">
						<input type="text" name="dept_name[]" value="<?php echo esc_attr( $d['name'] ); ?>" placeholder="<?php esc_attr_e( 'Name', 'abibitumi-chat' ); ?>">
						<button type="button" class="button abchat-remove-row">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button" data-add="abchat-departments" data-template="dept"><?php esc_html_e( '+ Add department', 'abibitumi-chat' ); ?></button>
		</div>

		<!-- Office hours -->
		<div class="abchat-section">
			<h2><?php esc_html_e( 'Office hours', 'abibitumi-chat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Enable office hours', 'abibitumi-chat' ); ?></th>
					<td><?php $checkbox( 'office_hours_enabled', $s['office_hours_enabled'], __( 'Show as away outside these hours', 'abibitumi-chat' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Weekly schedule', 'abibitumi-chat' ); ?></th>
					<td>
						<table class="abchat-hours-grid">
							<?php foreach ( $days as $key => $label ) :
								$row = isset( $s['office_hours'][ $key ] ) ? $s['office_hours'][ $key ] : array( 'open' => 0, 'from' => '09:00', 'to' => '17:00' ); ?>
								<tr>
									<td><label><input type="checkbox" name="office_hours[<?php echo esc_attr( $key ); ?>][open]" value="1" <?php checked( ! empty( $row['open'] ) ); ?>> <?php echo esc_html( $label ); ?></label></td>
									<td><input type="time" name="office_hours[<?php echo esc_attr( $key ); ?>][from]" value="<?php echo esc_attr( $row['from'] ); ?>"></td>
									<td>&ndash;</td>
									<td><input type="time" name="office_hours[<?php echo esc_attr( $key ); ?>][to]" value="<?php echo esc_attr( $row['to'] ); ?>"></td>
								</tr>
							<?php endforeach; ?>
						</table>
					</td>
				</tr>
				<tr>
					<th><label for="offline_message"><?php esc_html_e( 'Away message', 'abibitumi-chat' ); ?></label></th>
					<td><textarea name="offline_message" id="offline_message" class="large-text" rows="2"><?php echo esc_textarea( $s['offline_message'] ); ?></textarea></td>
				</tr>
			</table>
		</div>

		<!-- Chatbot -->
		<div class="abchat-section">
			<h2><?php esc_html_e( 'Chatbot', 'abibitumi-chat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Enable chatbot', 'abibitumi-chat' ); ?></th>
					<td><?php $checkbox( 'bot_enabled', $s['bot_enabled'], __( 'Answer with the bot before an agent replies', 'abibitumi-chat' ) ); ?></td>
				</tr>
				<tr>
					<th><label for="bot_name"><?php esc_html_e( 'Bot name', 'abibitumi-chat' ); ?></label></th>
					<td><input name="bot_name" id="bot_name" type="text" class="regular-text" value="<?php echo esc_attr( $s['bot_name'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="bot_greeting"><?php esc_html_e( 'Bot greeting', 'abibitumi-chat' ); ?></label></th>
					<td><textarea name="bot_greeting" id="bot_greeting" class="large-text" rows="2"><?php echo esc_textarea( $s['bot_greeting'] ); ?></textarea></td>
				</tr>
				<tr>
					<th><label for="bot_fallback"><?php esc_html_e( 'Fallback / hand-off message', 'abibitumi-chat' ); ?></label></th>
					<td><textarea name="bot_fallback" id="bot_fallback" class="large-text" rows="2"><?php echo esc_textarea( $s['bot_fallback'] ); ?></textarea></td>
				</tr>
			</table>
			<h4><?php esc_html_e( 'Flows (keyword → answer)', 'abibitumi-chat' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Comma-separated keywords. Use __HANDOFF__ as the answer to route the visitor to a human.', 'abibitumi-chat' ); ?></p>
			<div class="abchat-repeatable" id="abchat-flows">
				<?php foreach ( (array) $s['bot_flows'] as $f ) : ?>
					<div class="row">
						<input type="text" name="flow_id[]" value="<?php echo esc_attr( $f['id'] ); ?>" placeholder="id" style="max-width:90px;">
						<input type="text" name="flow_label[]" value="<?php echo esc_attr( $f['label'] ); ?>" placeholder="<?php esc_attr_e( 'Button label', 'abibitumi-chat' ); ?>">
						<input type="text" name="flow_keywords[]" value="<?php echo esc_attr( implode( ', ', (array) $f['keywords'] ) ); ?>" placeholder="<?php esc_attr_e( 'keywords', 'abibitumi-chat' ); ?>">
						<textarea name="flow_answer[]" rows="1" placeholder="<?php esc_attr_e( 'Answer', 'abibitumi-chat' ); ?>"><?php echo esc_textarea( $f['answer'] ); ?></textarea>
						<button type="button" class="button abchat-remove-row">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button" data-add="abchat-flows" data-template="flow"><?php esc_html_e( '+ Add flow', 'abibitumi-chat' ); ?></button>
		</div>

		<!-- Notifications & PWA -->
		<div class="abchat-section">
			<h2><?php esc_html_e( 'Notifications & PWA', 'abibitumi-chat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="notify_email"><?php esc_html_e( 'Notification email', 'abibitumi-chat' ); ?></label></th>
					<td><input name="notify_email" id="notify_email" type="email" class="regular-text" value="<?php echo esc_attr( $s['notify_email'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Send email when', 'abibitumi-chat' ); ?></th>
					<td>
						<?php $checkbox( 'notify_new_chat', $s['notify_new_chat'], __( 'A new conversation starts', 'abibitumi-chat' ) ); ?><br>
						<?php $checkbox( 'transcript_email', $s['transcript_email'], __( 'Email transcript to visitor on close', 'abibitumi-chat' ) ); ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Web Push (PWA)', 'abibitumi-chat' ); ?></th>
					<td>
						<?php $checkbox( 'push_enabled', $s['push_enabled'], __( 'Enable browser push for agents', 'abibitumi-chat' ) ); ?><br>
						<?php $checkbox( 'pwa_enabled', $s['pwa_enabled'], __( 'Enable installable PWA (manifest + service worker)', 'abibitumi-chat' ) ); ?>
					</td>
				</tr>
				<tr>
					<th><label for="pwa_short_name"><?php esc_html_e( 'PWA short name', 'abibitumi-chat' ); ?></label></th>
					<td>
						<input name="pwa_short_name" id="pwa_short_name" type="text" value="<?php echo esc_attr( $s['pwa_short_name'] ); ?>">
						<label style="margin-left:12px;"><?php esc_html_e( 'Theme color', 'abibitumi-chat' ); ?> <input name="pwa_theme_color" type="color" value="<?php echo esc_attr( $s['pwa_theme_color'] ); ?>"></label>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save settings', 'abibitumi-chat' ) ); ?>
	</form>
</div>

<script>
( function () {
	var templates = {
		dept: '<div class="row"><input type="text" name="dept_id[]" placeholder="id"><input type="text" name="dept_name[]" placeholder="Name"><button type="button" class="button abchat-remove-row">&times;</button></div>',
		flow: '<div class="row"><input type="text" name="flow_id[]" placeholder="id" style="max-width:90px;"><input type="text" name="flow_label[]" placeholder="Button label"><input type="text" name="flow_keywords[]" placeholder="keywords"><textarea name="flow_answer[]" rows="1" placeholder="Answer"></textarea><button type="button" class="button abchat-remove-row">&times;</button></div>'
	};
	document.querySelectorAll( '[data-add]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var box = document.getElementById( btn.dataset.add );
			var tmp = document.createElement( 'div' );
			tmp.innerHTML = templates[ btn.dataset.template ];
			box.appendChild( tmp.firstChild );
		} );
	} );
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'abchat-remove-row' ) ) {
			e.target.closest( '.row' ).remove();
		}
	} );
} )();
</script>
