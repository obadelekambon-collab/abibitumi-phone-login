<?php
/**
 * Optional Server-Sent Events transport with polling fallback in clients.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Stream {

	const VISITOR_ROUTE = '/abchat/v1/stream';
	const AGENT_ROUTE   = '/abchat/v1/agent/stream';

	/**
	 * Register routes and intercept their REST output for streaming.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 4 );
	}

	/**
	 * Register authenticated stream routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$rest = new ABChat_REST();
		register_rest_route(
			ABChat_REST::NS,
			'/stream',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'placeholder' ),
				'permission_callback' => array( $rest, 'visitor_permission' ),
			)
		);
		register_rest_route(
			ABChat_REST::NS,
			'/agent/stream',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'placeholder' ),
				'permission_callback' => array( $rest, 'agent_permission' ),
			)
		);
	}

	/**
	 * Placeholder result replaced by serve().
	 *
	 * @return WP_REST_Response
	 */
	public function placeholder() {
		return new WP_REST_Response( array( 'stream' => true ), 200 );
	}

	/**
	 * Stream updates for the two SSE routes.
	 *
	 * @param bool             $served  Whether the response was served.
	 * @param WP_REST_Response $result  REST result.
	 * @param WP_REST_Request  $request REST request.
	 * @param WP_REST_Server   $server  REST server.
	 * @return bool
	 */
	public function serve( $served, $result, $request, $server ) {
		unset( $server );
		$route = $request->get_route();
		if ( ! ABChat_Settings::get( 'stream_enabled' ) || ! in_array( $route, array( self::VISITOR_ROUTE, self::AGENT_ROUTE ), true ) ) {
			return $served;
		}

		if ( ! is_object( $result ) || ! method_exists( $result, 'get_status' ) || 200 !== (int) $result->get_status() ) {
			return $served;
		}

		if ( self::VISITOR_ROUTE === $route && ! is_object( $request->get_param( '_visitor' ) ) ) {
			return $served;
		}
		if ( self::AGENT_ROUTE === $route && ! current_user_can( ABCHAT_AGENT_CAP ) ) {
			return $served;
		}

		if ( headers_sent() ) {
			return $served;
		}

		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );

		while ( ob_get_level() ) {
			ob_end_flush();
		}

		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$deadline = time() + max( 10, min( 60, (int) ABChat_Settings::get( 'stream_duration', 25 ) ) );
		$last     = '';

		while ( time() < $deadline && ! connection_aborted() ) {
			$data = ( self::VISITOR_ROUTE === $route ) ? $this->visitor_data( $request ) : $this->agent_data( $request );
			$json = wp_json_encode( $data );
			if ( $json !== $last ) {
				echo "event: update\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				echo 'data: ' . $json . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				$last = $json;
			} else {
				echo ": keepalive\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			flush();
			sleep( 2 );
		}

		echo "event: reconnect\ndata: {}\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		flush();
		return true;
	}

	/**
	 * Build visitor stream data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	protected function visitor_data( $request ) {
		$visitor = $request->get_param( '_visitor' );
		$convo   = ABChat_DB::get_conversation( (int) $request->get_param( 'conversation_id' ) );
		if ( ! $convo || (int) $convo->visitor_id !== (int) $visitor->id ) {
			return array( 'error' => 'not_found' );
		}

		return array(
			'messages'     => $this->shape_messages( ABChat_DB::get_messages( $convo->id, (int) $request->get_param( 'after' ) ), false ),
			'agentTyping'  => ABChat_DB::is_typing( $convo->id, 'operator' ),
			'status'       => $convo->status,
			'operatorName' => $this->operator_name( $convo->operator_id ),
		);
	}

	/**
	 * Build operator stream data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	protected function agent_data( $request ) {
		$conversation_id = (int) $request->get_param( 'conversation_id' );
		$data            = array( 'counts' => ABChat_DB::conversation_counts() );
		if ( $conversation_id ) {
			$data['messages']      = $this->shape_messages( ABChat_DB::get_messages( $conversation_id, (int) $request->get_param( 'after' ) ) );
			$data['visitorTyping'] = ABChat_DB::is_typing( $conversation_id, 'visitor' );
		}
		return $data;
	}

	/**
	 * Shape message rows for stream clients.
	 *
	 * @param array $messages      Message rows.
	 * @param bool  $include_notes Whether internal notes may be returned.
	 * @return array
	 */
	protected function shape_messages( $messages, $include_notes = true ) {
		$out = array();
		foreach ( (array) $messages as $message ) {
			if ( ! $include_notes && 'note' === $message->type ) {
				continue;
			}
			$out[] = array(
				'id'         => (int) $message->id,
				'senderType' => $message->sender_type,
				'senderName' => $message->sender_name,
				'body'       => $message->body,
				'type'       => $message->type,
				'attachment' => $message->attachment_url ? array( 'url' => $message->attachment_url, 'name' => $message->attachment_name ) : null,
				'read'       => ! empty( $message->read_at ),
				'createdAt'  => $message->created_at,
				'time'       => mysql2date( get_option( 'time_format' ), $message->created_at ),
			);
		}
		return $out;
	}

	/**
	 * Resolve an operator display name.
	 *
	 * @param int $operator_id User ID.
	 * @return string
	 */
	protected function operator_name( $operator_id ) {
		$user = $operator_id ? get_userdata( $operator_id ) : null;
		return $user ? $user->display_name : '';
	}
}
