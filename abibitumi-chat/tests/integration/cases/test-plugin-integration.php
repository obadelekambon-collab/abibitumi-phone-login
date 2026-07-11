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
	 * Activation persists the root service-worker and manifest routes.
	 */
	public function test_activation_flushes_pwa_rewrite_routes() {
		global $wp_rewrite;
		$rules = $wp_rewrite->extra_rules_top;
		$this->assertArrayHasKey( '^abchat-sw\.js$', $rules );
		$this->assertArrayHasKey( '^abchat-manifest\.json$', $rules );
	}

	/**
	 * PWA scope follows subdirectory WordPress home installations.
	 */
	public function test_pwa_scope_uses_home_path() {
		$original = get_option( 'home' );
		update_option( 'home', 'http://example.org/community' );
		$this->assertSame( '/community/', ABChat_PWA::scope_path() );
		update_option( 'home', $original );
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

	/** Tidio migration persists closed history and deduplicates contacts/transcripts. */
	public function test_tidio_historical_import_round_trip() {
		$this->assertSame( 64, strlen( ABChat_DB::tidio_token( 'tidio-contact-1' ) ) );
		$contacts = array( array( 'id' => 'tidio-contact-1', 'name' => 'Ama Mensah', 'email' => 'ama-import@example.com', 'phone' => '+233555123456' ) );
		$first    = ABChat_Tidio_Importer::import_contacts( $contacts );
		$second   = ABChat_Tidio_Importer::import_contacts( $contacts );
		$this->assertSame( 1, $first['created'] );
		$this->assertSame( 1, $second['updated'] );

		$rows = array(
			array( 'date' => '2025-01-02 03:04:05', 'sender' => 'Ama', 'sender_type' => 'visitor', 'message' => 'Hello', 'visitor_email' => 'ama-import@example.com' ),
			array( 'date' => '2025-01-02 03:05:06', 'sender' => 'Agent', 'sender_type' => 'operator', 'message' => 'Welcome' ),
		);
		$first  = ABChat_Tidio_Importer::import_transcript( $rows, 'conversation.csv' );
		$second = ABChat_Tidio_Importer::import_transcript( $rows, 'conversation.csv' );
		$this->assertSame( 1, $first['conversations'] );
		$this->assertSame( 2, $first['messages'] );
		$this->assertSame( 0, $second['conversations'] );
		$this->assertSame( 2, $second['skipped'] );
		$digest = hash( 'sha256', wp_json_encode( array(
			array( 'body' => 'Hello', 'sender_name' => 'Ama', 'sender_type' => 'visitor', 'created_at' => '2025-01-02 03:04:05' ),
			array( 'body' => 'Welcome', 'sender_name' => 'Agent', 'sender_type' => 'operator', 'created_at' => '2025-01-02 03:05:06' ),
		) ) );
		$conversation = ABChat_DB::get_conversation( ABChat_DB::import_source_exists( $digest ) );
		$this->assertSame( 'closed', $conversation->status );
		$this->assertSame( 'tidio', $conversation->source );
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

	/**
	 * Chatbot quick-reply metadata survives visitor polling.
	 */
	public function test_visitor_poll_includes_chatbot_metadata() {
		$visitor = ABChat_DB::get_or_create_visitor( 'chatbot-meta-token' );
		$convo_id = ABChat_DB::create_conversation( array( 'visitor_id' => $visitor->id ) );
		ABChat_DB::add_message(
			array(
				'conversation_id' => $convo_id,
				'sender_type'     => 'bot',
				'body'            => 'Choose a topic.',
				'meta'            => array( 'quickReplies' => array( array( 'id' => 'help', 'label' => 'Help' ) ) ),
			)
		);
		$request = new WP_REST_Request( 'GET', '/abchat/v1/poll' );
		$request->set_param( '_visitor', $visitor );
		$request->set_param( 'conversation_id', $convo_id );
		$data = ( new ABChat_REST() )->poll( $request )->get_data();
		$this->assertSame( 'help', $data['messages'][0]['meta']['quickReplies'][0]['id'] );
	}

	/**
	 * Conversation exports neutralize spreadsheet formula injection.
	 */
	public function test_csv_export_cells_neutralize_formulas() {
		$this->assertSame( "'=HYPERLINK(\"https://attacker.example\")", ABChat_Admin::csv_safe_cell( '=HYPERLINK("https://attacker.example")' ) );
		$this->assertSame( "'  +cmd", ABChat_Admin::csv_safe_cell( '  +cmd' ) );
		$this->assertSame( "'\t@SUM(1,1)", ABChat_Admin::csv_safe_cell( "\t@SUM(1,1)" ) );
		$this->assertSame( 'Ordinary message', ABChat_Admin::csv_safe_cell( 'Ordinary message' ) );
		$this->assertSame( '123', ABChat_Admin::csv_safe_cell( 123 ) );
	}

	/**
	 * Visitor sessions issue an HTTP-only cookie for credential-free SSE URLs.
	 */
	public function test_session_issues_secure_stream_cookie() {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.70';
		delete_transient( 'abchat_session_ip_' . md5( '192.0.2.70' ) );
		$request  = new WP_REST_Request( 'POST', '/abchat/v1/session' );
		$response = ( new ABChat_REST() )->session( $request );
		$headers  = $response->get_headers();
		$this->assertArrayHasKey( 'Set-Cookie', $headers );
		$this->assertStringContainsString( ABChat_REST::VISITOR_COOKIE . '=', $headers['Set-Cookie'] );
		$this->assertStringContainsString( 'HttpOnly', $headers['Set-Cookie'] );
		$this->assertStringContainsString( 'SameSite=Strict', $headers['Set-Cookie'] );
		$this->assertStringNotContainsString( 'token=', file_get_contents( ABCHAT_DIR . 'assets/js/widget.js' ) );
	}

	/**
	 * Oversized visitor and bot inputs are rejected before persistence or AI.
	 */
	public function test_visitor_payload_length_is_bounded() {
		ABChat_Settings::update( array( 'max_message_length' => 100 ) );
		$visitor = ABChat_DB::get_or_create_visitor( 'oversized-message-token' );
		$convo_id = ABChat_DB::create_conversation( array( 'visitor_id' => $visitor->id ) );
		$request = new WP_REST_Request( 'POST', '/abchat/v1/message' );
		$request->set_param( '_visitor', $visitor );
		$request->set_param( 'conversation_id', $convo_id );
		$request->set_param( 'body', str_repeat( 'x', 101 ) );
		$result = ( new ABChat_REST() )->visitor_message( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'abchat_message_too_long', $result->get_error_code() );
		$this->assertCount( 0, ABChat_DB::get_messages( $convo_id ) );
	}
}
