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
		$this->assertArrayHasKey( '/abchat/v1/session', $routes );
		$this->assertArrayHasKey( '/abchat/v1/bot', $routes );
		$this->assertArrayHasKey( '/abchat/v1/stream', $routes );
		$this->assertArrayHasKey( '/abchat/v1/agent/conversations', $routes );
		$this->assertArrayHasKey( '/abchat/v1/agent/stream', $routes );
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

	/**
	 * Anonymous visitor session creation is bounded by client IP.
	 */
	public function test_new_visitor_session_rate_limit() {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.31';
		ABChat_Settings::update( array( 'session_rate_limit' => 2, 'session_rate_window' => 60 ) );
		delete_transient( 'abchat_session_ip_' . md5( '192.0.2.31' ) );
		$rest = new ABChat_REST();

		$this->assertFalse( $rest->check_session_rate_limit() );
		$this->assertFalse( $rest->check_session_rate_limit() );
		$limited = $rest->check_session_rate_limit();
		$this->assertWPError( $limited );
		$this->assertSame( 'abchat_session_rate_limited', $limited->get_error_code() );
		$this->assertSame( 429, $limited->get_error_data()['status'] );
	}

	/**
	 * Untrusted forwarding headers cannot rotate the abuse-control IP bucket.
	 */
	public function test_client_ip_ignores_untrusted_forwarding_headers() {
		$_SERVER['REMOTE_ADDR']          = '192.0.2.40';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.1';
		$this->assertSame( '192.0.2.40', ABChat_DB::client_ip() );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	/**
	 * Visitor messages cannot bypass the validated upload endpoint.
	 */
	public function test_visitor_message_rejects_caller_supplied_attachment_metadata() {
		$visitor = ABChat_DB::get_or_create_visitor( 'attachment-bypass-token' );
		$convo_id = ABChat_DB::create_conversation( array( 'visitor_id' => $visitor->id ) );
		$request = new WP_REST_Request( 'POST', '/abchat/v1/message' );
		$request->set_param( '_visitor', $visitor );
		$request->set_param( 'conversation_id', $convo_id );
		$request->set_param( 'body', 'Ordinary text' );
		$request->set_param( 'type', 'image' );
		$request->set_param( 'attachment_url', 'https://attacker.example/tracker.png' );
		$request->set_param( 'attachment_name', 'tracker.png' );

		$response = ( new ABChat_REST() )->visitor_message( $request );
		$this->assertSame( 200, $response->get_status() );
		$messages = ABChat_DB::get_messages( $convo_id );
		$message  = end( $messages );
		$this->assertSame( 'text', $message->type );
		$this->assertSame( '', $message->attachment_url );
		$this->assertSame( '', $message->attachment_name );
	}

	/**
	 * A fake file type cannot make an empty visitor message valid.
	 */
	public function test_visitor_message_rejects_empty_fake_file_message() {
		$visitor = ABChat_DB::get_or_create_visitor( 'empty-file-token' );
		$convo_id = ABChat_DB::create_conversation( array( 'visitor_id' => $visitor->id ) );
		$request = new WP_REST_Request( 'POST', '/abchat/v1/message' );
		$request->set_param( '_visitor', $visitor );
		$request->set_param( 'conversation_id', $convo_id );
		$request->set_param( 'body', '' );
		$request->set_param( 'type', 'file' );

		$result = ( new ABChat_REST() )->visitor_message( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'abchat_empty', $result->get_error_code() );
	}

	/**
	 * Operator messages also use the dedicated validated upload endpoint.
	 */
	public function test_agent_message_rejects_caller_supplied_attachment_metadata() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$visitor = ABChat_DB::get_or_create_visitor( 'agent-attachment-bypass-token' );
		$convo_id = ABChat_DB::create_conversation( array( 'visitor_id' => $visitor->id ) );
		$request = new WP_REST_Request( 'POST', '/abchat/v1/agent/message' );
		$request->set_param( 'conversation_id', $convo_id );
		$request->set_param( 'body', 'Operator text' );
		$request->set_param( 'type', 'image' );
		$request->set_param( 'attachment_url', 'https://attacker.example/tracker.png' );
		$request->set_param( 'attachment_name', 'tracker.png' );

		$response = ( new ABChat_REST() )->agent_message( $request );
		$this->assertSame( 200, $response->get_status() );
		$messages = ABChat_DB::get_messages( $convo_id );
		$message  = end( $messages );
		$this->assertSame( 'text', $message->type );
		$this->assertSame( '', $message->attachment_url );
		$this->assertSame( '', $message->attachment_name );
	}

	/**
	 * Operators can reach the shared upload route without a visitor token.
	 */
	public function test_operator_can_authorize_shared_upload_route() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'POST', '/abchat/v1/upload' );
		$this->assertTrue( ( new ABChat_REST() )->upload_permission( $request ) );
	}

	/**
	 * Anonymous uploads still require a valid visitor token.
	 */
	public function test_anonymous_shared_upload_route_requires_visitor_token() {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/abchat/v1/upload' );
		$result  = ( new ABChat_REST() )->upload_permission( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'abchat_no_token', $result->get_error_code() );
	}

	/**
	 * Internal operator notes never enter visitor REST payloads.
	 */
	public function test_visitor_poll_excludes_internal_notes_server_side() {
		$visitor = ABChat_DB::get_or_create_visitor( 'internal-note-privacy-token' );
		$convo_id = ABChat_DB::create_conversation( array( 'visitor_id' => $visitor->id ) );
		ABChat_DB::add_message(
			array(
				'conversation_id' => $convo_id,
				'sender_type'     => 'system',
				'body'            => 'Private operator context',
				'type'            => 'note',
			)
		);
		ABChat_DB::add_message(
			array(
				'conversation_id' => $convo_id,
				'sender_type'     => 'operator',
				'body'            => 'Public reply',
				'type'            => 'text',
			)
		);
		$request = new WP_REST_Request( 'GET', '/abchat/v1/poll' );
		$request->set_param( '_visitor', $visitor );
		$request->set_param( 'conversation_id', $convo_id );

		$data = ( new ABChat_REST() )->poll( $request )->get_data();
		$this->assertCount( 1, $data['messages'] );
		$this->assertSame( 'Public reply', $data['messages'][0]['body'] );
		$this->assertStringNotContainsString( 'Private operator context', wp_json_encode( $data ) );
	}
}
