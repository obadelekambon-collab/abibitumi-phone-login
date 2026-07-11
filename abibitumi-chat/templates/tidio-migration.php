<?php
/** Tidio migration admin screen. @package AbibitumiChat */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Tidio Migration', 'abibitumi-chat' ); ?></h1>
	<p><?php esc_html_e( 'Import historical Tidio contacts and conversation transcripts from CSV. Run validation first, back up the database, and perform the first migration on staging.', 'abibitumi-chat' ); ?></p>

	<?php if ( is_array( $report ) ) : ?>
		<div class="notice <?php echo empty( $report['errors'] ) ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
			<p><strong><?php echo $report['dry_run'] ? esc_html__( 'Validation report', 'abibitumi-chat' ) : esc_html__( 'Import report', 'abibitumi-chat' ); ?></strong></p>
			<?php foreach ( (array) $report['files'] as $file ) : ?>
				<p><?php echo esc_html( $file['name'] ); ?>: <?php echo esc_html( wp_json_encode( $file['result'] ) ); ?></p>
			<?php endforeach; ?>
			<?php foreach ( (array) $report['errors'] as $error ) : ?><p><?php echo esc_html( $error ); ?></p><?php endforeach; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="abchat_import_tidio">
		<?php wp_nonce_field( 'abchat_import_tidio' ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Data type', 'abibitumi-chat' ); ?></th><td>
				<label><input type="radio" name="import_type" value="contacts" checked> <?php esc_html_e( 'Contacts CSV', 'abibitumi-chat' ); ?></label><br>
				<label><input type="radio" name="import_type" value="transcripts"> <?php esc_html_e( 'Conversation transcript CSV files', 'abibitumi-chat' ); ?></label>
			</td></tr>
			<tr><th scope="row"><label for="tidio-files"><?php esc_html_e( 'CSV files', 'abibitumi-chat' ); ?></label></th><td>
				<input id="tidio-files" type="file" name="tidio_files[]" accept=".csv,text/csv" multiple required>
				<p class="description"><?php esc_html_e( 'Contacts normally use one export. Select multiple files for individually exported transcripts. Maximum 20 MB per file.', 'abibitumi-chat' ); ?></p>
			</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Mode', 'abibitumi-chat' ); ?></th><td>
				<label><input type="checkbox" name="dry_run" value="1" checked> <?php esc_html_e( 'Validate only—do not write data', 'abibitumi-chat' ); ?></label>
			</td></tr>
		</table>
		<?php submit_button( __( 'Run Tidio migration', 'abibitumi-chat' ) ); ?>
	</form>
	<h2><?php esc_html_e( 'Migration behavior', 'abibitumi-chat' ); ?></h2>
	<ul class="ul-disc">
		<li><?php esc_html_e( 'Contacts are matched by email or Tidio ID; matches are updated and duplicates are avoided.', 'abibitumi-chat' ); ?></li>
		<li><?php esc_html_e( 'Transcripts become closed historical conversations and do not notify operators.', 'abibitumi-chat' ); ?></li>
		<li><?php esc_html_e( 'Content-identical transcript files are skipped when uploaded again.', 'abibitumi-chat' ); ?></li>
		<li><?php esc_html_e( 'Tidio attachments, automations, operators, and channel integrations are not contained in transcript CSV exports.', 'abibitumi-chat' ); ?></li>
	</ul>
</div>
