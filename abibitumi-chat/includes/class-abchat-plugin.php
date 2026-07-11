<?php
/**
 * Main orchestrator. Instantiates and boots each subsystem.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Plugin {

	/**
	 * REST controller.
	 *
	 * @var ABChat_REST
	 */
	public $rest;

	/**
	 * Widget controller.
	 *
	 * @var ABChat_Widget
	 */
	public $widget;

	/**
	 * Admin controller.
	 *
	 * @var ABChat_Admin
	 */
	public $admin;

	/**
	 * Notifications controller.
	 *
	 * @var ABChat_Notifications
	 */
	public $notifications;

	/**
	 * PWA controller.
	 *
	 * @var ABChat_PWA
	 */
	public $pwa;

	/**
	 * Gemini chatbot backend.
	 *
	 * @var ABChat_Gemini
	 */
	public $gemini;

	/**
	 * Server-Sent Events transport.
	 *
	 * @var ABChat_Stream
	 */
	public $stream;

	/**
	 * Boot.
	 */
	public function __construct() {
		load_plugin_textdomain( 'abibitumi-chat', false, dirname( ABCHAT_BASENAME ) . '/languages' );

		$this->rest          = new ABChat_REST();
		$this->widget        = new ABChat_Widget();
		$this->admin         = new ABChat_Admin();
		$this->notifications = new ABChat_Notifications();
		$this->pwa           = new ABChat_PWA();
		$this->gemini        = new ABChat_Gemini();
		$this->stream        = new ABChat_Stream();

		$this->rest->init();
		$this->widget->init();
		$this->notifications->init();
		$this->pwa->init();
		$this->gemini->init();
		$this->stream->init();

		if ( is_admin() ) {
			$this->admin->init();
		}

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'wp_ajax_abchat_noop', '__return_true' );

		// Auto-run the schema check once per version bump without needing reactivation.
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 20 );
	}

	/**
	 * Run schema upgrades when the stored version differs.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( get_option( 'abchat_db_version' ) === ABCHAT_VERSION ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) && ! is_admin() ) {
			// Only run heavy dbDelta in an admin context to avoid front-end cost.
			return;
		}
		ABChat_Activator::create_tables();
		ABChat_Activator::add_caps();
		update_option( 'abchat_db_version', ABCHAT_VERSION, false );
	}
}
