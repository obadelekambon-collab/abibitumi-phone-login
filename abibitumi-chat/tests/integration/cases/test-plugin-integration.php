<?php
/**
 * Database-backed WordPress integration tests.
 */

class ABChat_Plugin_Integration_Test extends WP_UnitTestCase {

	/**
	 * Create plugin tables and capabilities once for this suite.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		ABChat_Activator::activate();
	}

	/**
	 * Plugin activation creates every custom table.
	 */
	public function test_activation_creates_custom_tables() {
		global $wpdb;
		foreach ( array( 'visitors', 'conversations', 'messages', 'canned', 'push' ) as $name ) {
			$table = ABChat_DB::table( $name );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
	}

	/**
	 * Visitor and operator REST endpoints are registered in WordPress.
	 */
	public function test_rest_routes_are_registered() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/abibitumi-chat/v1/session', $routes );
		$this->assertArrayHasKey( '/abibitumi-chat/v1/bot', $routes );
		$this->assertArrayHasKey( '/abibitumi-chat/v1/stream', $routes );
		$this->assertArrayHasKey( '/abibitumi-chat/v1/agent/conversations', $routes );
		$this->assertArrayHasKey( '/abibitumi-chat/v1/agent/stream', $routes );
	}

	/**
	 * Core visitor/conversation/message persistence works against wpdb.
	 */
	public function test_chat_records_round_trip_through_database() {
		$visitor = ABChat_DB::get_or_create_visitor(
			'integration-test-token',
			array( 'name' => 'Integration Visitor', 'email' => 'visitor@example.org' )
		);
		$conversation_id = ABChat_DB::create_conversation(
			array( 'visitor_id' => $visitor->id, 'department' => 'support' )
		);
		$message_id = ABChat_DB::add_message(
			array( 'conversation_id' => $conversation_id, 'sender_type' => 'visitor', 'body' => 'Hello from WordPress.' )
		);

		$conversation = ABChat_DB::get_conversation( $conversation_id );
		$messages     = ABChat_DB::get_messages( $conversation_id );
		$this->assertSame( 'support', $conversation->department );
		$this->assertSame( $message_id, (int) $messages[0]->id );
		$this->assertSame( 'Hello from WordPress.', $messages[0]->body );
	}

	/**
	 * Privacy integrations and retention scheduling are active.
	 */
	public function test_privacy_and_retention_hooks_are_active() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->assertArrayHasKey( 'abibitumi-chat', $exporters );
		$this->assertArrayHasKey( 'abibitumi-chat', $erasers );
		$this->assertNotFalse( wp_next_scheduled( ABChat_Retention::HOOK ) );
	}

	/**
	 * Secrets never enter the front-end bootstrap payload.
	 */
	public function test_public_config_never_exposes_gemini_key() {
		ABChat_Settings::update( array( 'gemini_api_key' => 'integration-secret' ) );
		$config = ABChat_Settings::public_config();
		$this->assertArrayNotHasKey( 'gemini_api_key', $config );
		$this->assertStringNotContainsString( 'integration-secret', wp_json_encode( $config ) );
	}
}
