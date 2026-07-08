<?php
/**
 * Analytics screen.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$days  = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification
$days  = $days ? $days : 30;
$stats = ABChat_DB::stats( $days );

$max = 1;
foreach ( (array) $stats['daily'] as $d ) {
	$max = max( $max, (int) $d->n );
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Chat Analytics', 'abibitumi-chat' ); ?></h1>

	<form method="get" style="margin:12px 0;">
		<input type="hidden" name="page" value="abchat-analytics">
		<label><?php esc_html_e( 'Range:', 'abibitumi-chat' ); ?>
			<select name="days" onchange="this.form.submit()">
				<option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'abibitumi-chat' ); ?></option>
				<option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( 'Last 30 days', 'abibitumi-chat' ); ?></option>
				<option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( 'Last 90 days', 'abibitumi-chat' ); ?></option>
			</select>
		</label>
	</form>

	<div class="abchat-stats">
		<div class="abchat-stat"><div class="n"><?php echo esc_html( $stats['total'] ); ?></div><div class="l"><?php esc_html_e( 'Conversations', 'abibitumi-chat' ); ?></div></div>
		<div class="abchat-stat"><div class="n"><?php echo esc_html( $stats['closed'] ); ?></div><div class="l"><?php esc_html_e( 'Resolved', 'abibitumi-chat' ); ?></div></div>
		<div class="abchat-stat"><div class="n"><?php echo esc_html( $stats['messages'] ); ?></div><div class="l"><?php esc_html_e( 'Messages', 'abibitumi-chat' ); ?></div></div>
		<div class="abchat-stat"><div class="n"><?php echo $stats['avg_rating'] ? esc_html( $stats['avg_rating'] ) . ' ★' : '—'; ?></div><div class="l"><?php esc_html_e( 'Avg. rating', 'abibitumi-chat' ); ?></div></div>
	</div>

	<div class="abchat-chart">
		<strong><?php esc_html_e( 'Conversations per day', 'abibitumi-chat' ); ?></strong>
		<div class="abchat-bars">
			<?php foreach ( (array) $stats['daily'] as $d ) :
				$h = round( ( (int) $d->n / $max ) * 100 );
				?>
				<div class="abchat-bar" style="height:<?php echo esc_attr( $h ); ?>%;" title="<?php echo esc_attr( $d->d . ': ' . $d->n ); ?>">
					<span><?php echo esc_html( gmdate( 'j/n', strtotime( $d->d ) ) ); ?></span>
				</div>
			<?php endforeach; ?>
			<?php if ( empty( $stats['daily'] ) ) : ?>
				<p style="color:#999;"><?php esc_html_e( 'No data for this range yet.', 'abibitumi-chat' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</div>
