<?php
/**
 * Front-end widget: enqueues the visitor chat bundle and prints the
 * bootstrap config + mount point in the footer of every page.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Widget {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render_root' ) );
	}

	/**
	 * Should the widget load on this request?
	 *
	 * @return bool
	 */
	protected function should_load() {
		if ( ! ABChat_Settings::get( 'enabled' ) ) {
			return false;
		}
		if ( is_admin() ) {
			return false;
		}
		if ( ABChat_Settings::get( 'require_login' ) && ! is_user_logged_in() ) {
			return false;
		}
		if ( ! ABChat_Settings::get( 'show_on_mobile' ) && wp_is_mobile() ) {
			return false;
		}
		/**
		 * Filter whether the chat widget loads on the current request.
		 *
		 * @param bool $load Whether to load.
		 */
		return (bool) apply_filters( 'abchat_should_load_widget', true );
	}

	/**
	 * Enqueue assets.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style( 'abchat-widget', ABCHAT_URL . 'assets/css/widget.css', array(), ABCHAT_VERSION );
		wp_enqueue_script( 'abchat-widget', ABCHAT_URL . 'assets/js/widget.js', array(), ABCHAT_VERSION, true );

		$user      = wp_get_current_user();
		$bootstrap = array(
			'restUrl'  => esc_url_raw( rest_url( ABChat_REST::NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'config'   => ABChat_Settings::public_config(),
			'user'     => $user->ID ? array( 'name' => $user->display_name, 'email' => $user->user_email ) : null,
			'pwa'      => array(
				'enabled' => (bool) ABChat_Settings::get( 'pwa_enabled' ),
				'swUrl'   => esc_url_raw( home_url( '/abchat-sw.js' ) ),
			),
			'i18n'     => array(
				'send'        => __( 'Send', 'abibitumi-chat' ),
				'typeMessage' => __( 'Type a message…', 'abibitumi-chat' ),
				'startChat'   => __( 'Start chat', 'abibitumi-chat' ),
				'name'        => __( 'Your name', 'abibitumi-chat' ),
				'email'       => __( 'Your email', 'abibitumi-chat' ),
				'phone'       => __( 'Your phone', 'abibitumi-chat' ),
				'department'  => __( 'Department', 'abibitumi-chat' ),
				'poweredBy'   => __( 'Powered by Abibitumi Chat', 'abibitumi-chat' ),
				'rateChat'    => __( 'How did we do?', 'abibitumi-chat' ),
				'thanks'      => __( 'Thanks for your feedback!', 'abibitumi-chat' ),
				'agentTyping' => __( 'typing…', 'abibitumi-chat' ),
				'attach'      => __( 'Attach a file', 'abibitumi-chat' ),
				'newMessages' => __( 'New messages', 'abibitumi-chat' ),
				'online'      => __( 'We are online', 'abibitumi-chat' ),
				'offline'     => __( 'We are away', 'abibitumi-chat' ),
			),
		);

		wp_add_inline_script(
			'abchat-widget',
			'window.ABChatData = ' . wp_json_encode( $bootstrap ) . ';',
			'before'
		);
	}

	/**
	 * Print the mount point.
	 *
	 * @return void
	 */
	public function render_root() {
		if ( ! $this->should_load() ) {
			return;
		}
		echo '<div id="abchat-root" aria-live="polite"></div>' . "\n";
	}
}
