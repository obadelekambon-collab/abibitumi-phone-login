<?php
/**
 * Operator dashboard (three-pane inbox).
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap" id="abchat-dashboard">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Chat Inbox', 'abibitumi-chat' ); ?></h1>

	<div class="abchat-wrap">
		<div class="abchat-panes">

			<!-- Inbox -->
			<div class="abchat-inbox">
				<div class="abchat-inbox-head">
					<input type="search" id="abchat-search" class="abchat-search" placeholder="<?php esc_attr_e( 'Search name, email…', 'abibitumi-chat' ); ?>">
					<div class="abchat-tabs">
						<button class="abchat-tab active" data-filter="open"><?php esc_html_e( 'Open', 'abibitumi-chat' ); ?> <span id="abchat-count-open">0</span></button>
						<button class="abchat-tab" data-filter="pending"><?php esc_html_e( 'Pending', 'abibitumi-chat' ); ?> <span id="abchat-count-pending">0</span></button>
						<button class="abchat-tab" data-filter="closed"><?php esc_html_e( 'Closed', 'abibitumi-chat' ); ?> <span id="abchat-count-closed">0</span></button>
					</div>
				</div>
				<label class="abchat-mine-row">
					<input type="checkbox" id="abchat-mine"> <?php esc_html_e( 'Only my conversations', 'abibitumi-chat' ); ?>
				</label>
				<div class="abchat-list" id="abchat-list"></div>
			</div>

			<!-- Conversation -->
			<div class="abchat-conversation" id="abchat-conversation">
				<div class="abchat-no-convo">
					<span class="dashicons dashicons-format-chat" style="font-size:48px;width:48px;height:48px;"></span>
					<p><?php esc_html_e( 'Select a conversation to start replying.', 'abibitumi-chat' ); ?></p>
				</div>
				<div class="abchat-convo-inner">
					<div class="abchat-convo-head" id="abchat-convo-head"></div>
					<div class="abchat-messages" id="abchat-messages"></div>
					<div class="abchat-agent-typing" id="abchat-agent-typing" hidden><?php esc_html_e( 'Visitor is typing…', 'abibitumi-chat' ); ?></div>
					<div class="abchat-reply">
						<div class="abchat-canned-pop" id="abchat-canned-pop" hidden></div>
						<form class="abchat-reply-form" id="abchat-reply-form">
							<textarea id="abchat-reply" maxlength="<?php echo esc_attr( ABChat_Settings::get( 'max_message_length', 5000 ) ); ?>" placeholder="<?php esc_attr_e( 'Type your reply…  (use / for canned replies)', 'abibitumi-chat' ); ?>"></textarea>
							<div class="abchat-reply-tools">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Send', 'abibitumi-chat' ); ?></button>
								<button type="button" class="button" id="abchat-note-btn" title="<?php esc_attr_e( 'Internal note (visitor cannot see)', 'abibitumi-chat' ); ?>"><?php esc_html_e( 'Note', 'abibitumi-chat' ); ?></button>
								<label class="abchat-file-label" title="<?php esc_attr_e( 'Attach file', 'abibitumi-chat' ); ?>">
									<span class="dashicons dashicons-paperclip"></span>
									<input type="file" id="abchat-file" hidden>
								</label>
							</div>
						</form>
					</div>
				</div>
			</div>

			<!-- Visitor info -->
			<div class="abchat-visitor" id="abchat-visitor">
				<p style="color:#aaa;"><?php esc_html_e( 'Visitor details appear here.', 'abibitumi-chat' ); ?></p>
			</div>

		</div>
	</div>
</div>
