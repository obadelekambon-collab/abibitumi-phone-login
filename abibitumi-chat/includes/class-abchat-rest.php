<?php
/**
 * REST API. Visitor endpoints are token-authenticated; agent endpoints
 * require the ABCHAT_AGENT_CAP capability.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_REST {

	const NS = 'abchat/v1';
	const VISITOR_COOKIE = 'abchat_session';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$visitor = array( $this, 'visitor_permission' );
		$agent   = array( $this, 'agent_permission' );

		// ---- Visitor ---------------------------------------------------- //
		register_rest_route( self::NS, '/session', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'session' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/conversation', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'start_conversation' ),
			'permission_callback' => $visitor,
		) );
		register_rest_route( self::NS, '/message', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'visitor_message' ),
			'permission_callback' => $visitor,
		) );
		register_rest_route( self::NS, '/poll', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'poll' ),
			'permission_callback' => $visitor,
		) );
		register_rest_route( self::NS, '/typing', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'visitor_typing' ),
			'permission_callback' => $visitor,
		) );
		register_rest_route( self::NS, '/read', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'visitor_read' ),
			'permission_callback' => $visitor,
		) );
		register_rest_route( self::NS, '/rate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rate' ),
			'permission_callback' => $visitor,
		) );
		register_rest_route( self::NS, '/bot', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bot_reply' ),
			'permission_callback' => $visitor,
		) );
		register_rest_route( self::NS, '/upload', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload' ),
			'permission_callback' => array( $this, 'upload_permission' ),
		) );

		// ---- Agent ------------------------------------------------------ //
		register_rest_route( self::NS, '/agent/conversations', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'agent_list' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/conversation/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'agent_conversation' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/message', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'agent_message' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/poll', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'agent_poll' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/typing', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'agent_typing' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/read', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'agent_read' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/assign', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'agent_assign' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/status', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'agent_status' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/visitors', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'agent_visitors' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/canned', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'agent_canned_list' ),
				'permission_callback' => $agent,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'agent_canned_save' ),
				'permission_callback' => $agent,
			),
		) );
		register_rest_route( self::NS, '/agent/canned/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'agent_canned_delete' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'agent_stats' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/presence', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'agent_presence' ),
			'permission_callback' => $agent,
		) );
		register_rest_route( self::NS, '/agent/push', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'agent_push_subscribe' ),
			'permission_callback' => $agent,
		) );
	}

	/* --------------------------------------------------------------------- */
	/* Permissions                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Visitor permission: a valid token that resolves to a visitor row.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return bool|WP_Error
	 */
	public function visitor_permission( $req ) {
		$token = $this->token_from( $req );
		if ( ! $token ) {
			return new WP_Error( 'abchat_no_token', __( 'Missing session token.', 'abibitumi-chat' ), array( 'status' => 401 ) );
		}
		$visitor = $this->visitor_by_token( $token );
		if ( ! $visitor ) {
			return new WP_Error( 'abchat_bad_token', __( 'Invalid session.', 'abibitumi-chat' ), array( 'status' => 401 ) );
		}
		$req->set_param( '_visitor', $visitor );
		return true;
	}

	/**
	 * Agent permission.
	 *
	 * @return bool
	 */
	public function agent_permission() {
		return current_user_can( ABCHAT_AGENT_CAP );
	}

	/**
	 * Upload permission: an operator capability or a valid visitor token.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return true|WP_Error
	 */
	public function upload_permission( $req ) {
		if ( current_user_can( ABCHAT_AGENT_CAP ) ) {
			return true;
		}
		return $this->visitor_permission( $req );
	}

	/**
	 * Extract token from header or param.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return string
	 */
	protected function token_from( $req ) {
		$token = $req->get_header( 'x_abchat_token' );
		if ( ! $token ) {
			$token = $req->get_param( 'token' );
		}
		if ( ! $token && ! empty( $_COOKIE[ self::VISITOR_COOKIE ] ) ) {
			$token = wp_unslash( $_COOKIE[ self::VISITOR_COOKIE ] );
		}
		return $token ? sanitize_text_field( $token ) : '';
	}

	/**
	 * Look up a visitor by token.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	protected function visitor_by_token( $token ) {
		global $wpdb;
		$t = ABChat_DB::table( 'visitors' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Ensure a conversation belongs to a visitor.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param object $visitor         Visitor row.
	 * @return object|WP_Error
	 */
	protected function owned_conversation( $conversation_id, $visitor ) {
		$c = ABChat_DB::get_conversation( $conversation_id );
		if ( ! $c || (int) $c->visitor_id !== (int) $visitor->id ) {
			return new WP_Error( 'abchat_forbidden', __( 'Conversation not found.', 'abibitumi-chat' ), array( 'status' => 403 ) );
		}
		return $c;
	}

	/* --------------------------------------------------------------------- */
	/* Visitor endpoints                                                     */
	/* --------------------------------------------------------------------- */

	/**
	 * Initialise or refresh a visitor session.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function session( $req ) {
		if ( ! ABChat_Settings::get( 'enabled' ) ) {
			return new WP_REST_Response( array( 'enabled' => false ), 200 );
		}

		$token = $this->token_from( $req );
		$data  = array(
			'page_url' => $req->get_param( 'page_url' ),
			'referrer' => $req->get_param( 'referrer' ),
		);

		if ( $token ) {
			$visitor = $this->visitor_by_token( $token );
			if ( $visitor ) {
				ABChat_DB::update_visitor( $visitor->id, array( 'last_seen' => current_time( 'mysql' ), 'is_online' => 1, 'page_url' => $data['page_url'] ? esc_url_raw( $data['page_url'] ) : $visitor->page_url ) );
			}
		}

		if ( empty( $visitor ) ) {
			$limited = $this->check_session_rate_limit( $req );
			if ( is_wp_error( $limited ) ) {
				return $limited;
			}
			$token   = wp_generate_password( 40, false, false );
			$visitor = ABChat_DB::get_or_create_visitor( $token, $data );
		}

		$response = new WP_REST_Response(
			array(
				'enabled' => true,
				'token'   => $token,
				'visitor' => array(
					'id'    => (int) $visitor->id,
					'name'  => $visitor->name,
					'email' => $visitor->email,
					'phone' => $visitor->phone,
				),
				'config'  => ABChat_Settings::public_config(),
			),
			200
		);
		$response->header( 'Set-Cookie', $this->visitor_cookie_header( $token ) );
		return $response;
	}

	/**
	 * Build the secure visitor-session cookie header used by EventSource.
	 *
	 * @param string $token Visitor token.
	 * @return string
	 */
	protected function visitor_cookie_header( $token ) {
		$path = wp_parse_url( rest_url( self::NS ), PHP_URL_PATH );
		$path = $path ? trailingslashit( $path ) : '/';
		$parts = array(
			self::VISITOR_COOKIE . '=' . rawurlencode( $token ),
			'Path=' . $path,
			'Max-Age=' . ( 30 * DAY_IN_SECONDS ),
			'HttpOnly',
			'SameSite=Strict',
		);
		if ( is_ssl() ) {
			$parts[] = 'Secure';
		}
		return implode( '; ', $parts );
	}

	/**
	 * Start a conversation.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_conversation( $req ) {
		$visitor = $req->get_param( '_visitor' );
		$limited = $this->check_conversation_rate_limit( $visitor );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		// Capture pre-chat details onto the visitor.
		$update = array();
		foreach ( array( 'name', 'email', 'phone' ) as $f ) {
			$val = $req->get_param( $f );
			if ( null !== $val && '' !== $val ) {
				$update[ $f ] = ( 'email' === $f ) ? sanitize_email( $val ) : sanitize_text_field( $val );
			}
		}
		if ( $update ) {
			ABChat_DB::update_visitor( $visitor->id, $update );
			$visitor = $this->visitor_by_token( $visitor->token );
		}

		$department = $req->get_param( 'department' );
		$convo_id   = ABChat_DB::create_conversation(
			array(
				'visitor_id' => $visitor->id,
				'department' => $department ? sanitize_key( $department ) : 'general',
				'subject'    => $req->get_param( 'subject' ),
				'source'     => 'widget',
			)
		);

		// System line + optional bot greeting.
		$open = ABChat_Settings::is_within_office_hours();
		if ( ! $open ) {
			ABChat_DB::add_message( array(
				'conversation_id' => $convo_id,
				'sender_type'     => 'system',
				'body'            => ABChat_Settings::get( 'offline_message' ),
				'type'            => 'text',
			) );
			ABChat_DB::update_conversation( $convo_id, array( 'status' => 'pending' ) );
		} elseif ( ABChat_Settings::get( 'bot_enabled' ) ) {
			ABChat_DB::add_message( array(
				'conversation_id' => $convo_id,
				'sender_type'     => 'bot',
				'sender_name'     => ABChat_Settings::get( 'bot_name' ),
				'body'            => ABChat_Settings::get( 'bot_greeting' ),
				'type'            => 'text',
			) );
		}

		/**
		 * Fires when a new conversation is created.
		 *
		 * @param int    $convo_id Conversation id.
		 * @param object $visitor  Visitor row.
		 */
		do_action( 'abchat_conversation_started', $convo_id, $visitor );

		$convo = ABChat_DB::get_conversation( $convo_id );
		return new WP_REST_Response(
			array(
				'conversation' => $this->shape_conversation( $convo ),
				'messages'     => $this->shape_messages( ABChat_DB::get_messages( $convo_id ), false ),
				'isOpen'       => $open,
			),
			200
		);
	}

	/**
	 * Visitor sends a message.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function visitor_message( $req ) {
		$visitor = $req->get_param( '_visitor' );
		$convo   = $this->owned_conversation( (int) $req->get_param( 'conversation_id' ), $visitor );
		if ( is_wp_error( $convo ) ) {
			return $convo;
		}
		$limited = $this->check_message_rate_limit( $visitor );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$body = trim( (string) $req->get_param( 'body' ) );
		if ( '' === $body ) {
			return new WP_Error( 'abchat_empty', __( 'Message is empty.', 'abibitumi-chat' ), array( 'status' => 400 ) );
		}

		$msg_id = ABChat_DB::add_message( array(
			'conversation_id' => $convo->id,
			'sender_type'     => 'visitor',
			'sender_id'       => $visitor->id,
			'sender_name'     => $visitor->name ? $visitor->name : __( 'Visitor', 'abibitumi-chat' ),
			'body'            => $body,
			'type'            => 'text',
		) );

		// Reopen if it had been closed.
		if ( 'closed' === $convo->status ) {
			ABChat_DB::update_conversation( $convo->id, array( 'status' => 'open' ) );
		}

		delete_transient( 'abchat_typing_' . $convo->id . '_visitor' );

		do_action( 'abchat_visitor_message', $convo->id, $msg_id, $visitor );

		return new WP_REST_Response( array( 'id' => $msg_id ), 200 );
	}

	/**
	 * Poll for new messages, typing state and conversation status.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function poll( $req ) {
		$visitor = $req->get_param( '_visitor' );
		ABChat_DB::update_visitor( $visitor->id, array( 'last_seen' => current_time( 'mysql' ), 'is_online' => 1 ) );

		$convo = $this->owned_conversation( (int) $req->get_param( 'conversation_id' ), $visitor );
		if ( is_wp_error( $convo ) ) {
			return $convo;
		}
		$after    = (int) $req->get_param( 'after' );
		$messages = ABChat_DB::get_messages( $convo->id, $after );

		return new WP_REST_Response(
			array(
				'messages'      => $this->shape_messages( $messages, false ),
				'agentTyping'   => ABChat_DB::is_typing( $convo->id, 'operator' ),
				'status'        => $convo->status,
				'operatorName'  => $this->operator_name( $convo->operator_id ),
			),
			200
		);
	}

	/**
	 * Visitor typing signal.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function visitor_typing( $req ) {
		$visitor = $req->get_param( '_visitor' );
		$convo   = $this->owned_conversation( (int) $req->get_param( 'conversation_id' ), $visitor );
		if ( is_wp_error( $convo ) ) {
			return $convo;
		}
		ABChat_DB::set_typing( $convo->id, 'visitor' );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Visitor marks operator messages read.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function visitor_read( $req ) {
		$visitor = $req->get_param( '_visitor' );
		$convo   = $this->owned_conversation( (int) $req->get_param( 'conversation_id' ), $visitor );
		if ( is_wp_error( $convo ) ) {
			return $convo;
		}
		ABChat_DB::mark_read( $convo->id, 'visitor' );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Rate a conversation.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rate( $req ) {
		$visitor = $req->get_param( '_visitor' );
		$convo   = $this->owned_conversation( (int) $req->get_param( 'conversation_id' ), $visitor );
		if ( is_wp_error( $convo ) ) {
			return $convo;
		}
		$rating = max( 1, min( 5, (int) $req->get_param( 'rating' ) ) );
		ABChat_DB::update_conversation( $convo->id, array(
			'rating'         => $rating,
			'rating_comment' => sanitize_textarea_field( (string) $req->get_param( 'comment' ) ),
		) );
		ABChat_DB::add_message( array(
			'conversation_id' => $convo->id,
			'sender_type'     => 'system',
			'body'            => sprintf( /* translators: %d: star rating */ __( 'Visitor rated this conversation %d/5.', 'abibitumi-chat' ), $rating ),
		) );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Bot reply endpoint (keyword/flow driven).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bot_reply( $req ) {
		$visitor = $req->get_param( '_visitor' );
		$convo   = $this->owned_conversation( (int) $req->get_param( 'conversation_id' ), $visitor );
		if ( is_wp_error( $convo ) ) {
			return $convo;
		}
		$limited = $this->check_bot_rate_limit( $visitor );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		$bot    = new ABChat_Chatbot();
		$result = $bot->respond( $convo->id, (string) $req->get_param( 'text' ), $req->get_param( 'flow_id' ) );
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Consume the per-IP new-session rate-limit bucket.
	 *
	 * @param WP_REST_Request|null $request Current request.
	 * @return false|WP_Error
	 */
	public function check_session_rate_limit( $request = null ) {
		$ip = ABChat_DB::client_ip();
		if ( '' === $ip ) {
			return false;
		}
		$key = 'abchat_session_ip_' . md5( $ip );
		/**
		 * Filter the session rate-limit key for custom trusted-proxy setups.
		 *
		 * @param string               $key     Transient key.
		 * @param string               $ip      Resolved client IP.
		 * @param WP_REST_Request|null $request Current request.
		 */
		$key = apply_filters( 'abchat_session_rate_limit_key', $key, $ip, $request );
		return $this->consume_rate_limit(
			$key,
			max( 1, (int) ABChat_Settings::get( 'session_rate_limit', 30 ) ),
			max( 60, (int) ABChat_Settings::get( 'session_rate_window', 3600 ) ),
			'abchat_session_rate_limited'
		);
	}

	/**
	 * Consume the visitor and IP bot rate-limit buckets.
	 *
	 * @param object $visitor Visitor row.
	 * @return false|WP_Error
	 */
	public function check_bot_rate_limit( $visitor ) {
		$limit  = max( 1, (int) ABChat_Settings::get( 'bot_rate_limit', 10 ) );
		$window = max( 10, (int) ABChat_Settings::get( 'bot_rate_window', 60 ) );
		$ip     = ! empty( $visitor->ip ) ? (string) $visitor->ip : '';
		$keys   = array(
			array( 'abchat_bot_v_' . (int) $visitor->id, $limit ),
		);

		if ( '' !== $ip ) {
			$keys[] = array( 'abchat_bot_ip_' . md5( $ip ), $limit * 3 );
		}

		/**
		 * Filter bot rate-limit buckets for custom proxy or cache setups.
		 *
		 * @param array  $keys    Array of { transient key, limit } pairs.
		 * @param object $visitor Visitor row.
		 * @param int    $window  Window length in seconds.
		 */
		$keys = apply_filters( 'abchat_bot_rate_limit_keys', $keys, $visitor, $window );

		foreach ( (array) $keys as $bucket ) {
			if ( ! is_array( $bucket ) || count( $bucket ) < 2 ) {
				continue;
			}

			$limited = $this->consume_rate_limit( $bucket[0], $bucket[1], $window, 'abchat_bot_rate_limited' );
			if ( is_wp_error( $limited ) ) {
				return $limited;
			}
		}

		return false;
	}

	/**
	 * Consume visitor and IP buckets for ordinary visitor messages.
	 *
	 * @param object $visitor Visitor row.
	 * @return false|WP_Error
	 */
	public function check_message_rate_limit( $visitor ) {
		$limit  = max( 1, (int) ABChat_Settings::get( 'message_rate_limit', 30 ) );
		$window = max( 10, (int) ABChat_Settings::get( 'message_rate_window', 60 ) );
		$keys   = array( array( 'abchat_message_v_' . (int) $visitor->id, $limit ) );
		if ( ! empty( $visitor->ip ) ) {
			$keys[] = array( 'abchat_message_ip_' . md5( (string) $visitor->ip ), $limit * 3 );
		}
		/**
		 * Filter visitor message rate-limit buckets.
		 *
		 * @param array  $keys    Array of { transient key, limit } pairs.
		 * @param object $visitor Visitor row.
		 * @param int    $window  Window length in seconds.
		 */
		$keys = apply_filters( 'abchat_message_rate_limit_keys', $keys, $visitor, $window );
		foreach ( (array) $keys as $bucket ) {
			if ( ! is_array( $bucket ) || count( $bucket ) < 2 ) {
				continue;
			}
			$limited = $this->consume_rate_limit( $bucket[0], $bucket[1], $window, 'abchat_message_rate_limited' );
			if ( is_wp_error( $limited ) ) {
				return $limited;
			}
		}
		return false;
	}

	/**
	 * Consume visitor and IP buckets for new conversations.
	 *
	 * @param object $visitor Visitor row.
	 * @return false|WP_Error
	 */
	public function check_conversation_rate_limit( $visitor ) {
		$limit  = max( 1, (int) ABChat_Settings::get( 'conversation_rate_limit', 10 ) );
		$window = max( 60, (int) ABChat_Settings::get( 'conversation_rate_window', 3600 ) );
		$keys   = array( array( 'abchat_conversation_v_' . (int) $visitor->id, $limit ) );
		if ( ! empty( $visitor->ip ) ) {
			$keys[] = array( 'abchat_conversation_ip_' . md5( (string) $visitor->ip ), $limit * 3 );
		}
		/**
		 * Filter conversation creation rate-limit buckets.
		 *
		 * @param array  $keys    Array of { transient key, limit } pairs.
		 * @param object $visitor Visitor row.
		 * @param int    $window  Window length in seconds.
		 */
		$keys = apply_filters( 'abchat_conversation_rate_limit_keys', $keys, $visitor, $window );
		foreach ( (array) $keys as $bucket ) {
			if ( ! is_array( $bucket ) || count( $bucket ) < 2 ) {
				continue;
			}
			$limited = $this->consume_rate_limit( $bucket[0], $bucket[1], $window, 'abchat_conversation_rate_limited' );
			if ( is_wp_error( $limited ) ) {
				return $limited;
			}
		}
		return false;
	}

	/**
	 * Consume one fixed-window transient rate-limit bucket.
	 *
	 * @param string $key     Transient key.
	 * @param int    $max     Maximum requests.
	 * @param int    $window  Window in seconds.
	 * @param string $code    REST error code.
	 * @return false|WP_Error
	 */
	protected function consume_rate_limit( $key, $max, $window, $code ) {
		$key    = sanitize_key( $key );
		$max    = max( 1, (int) $max );
		$window = max( 1, (int) $window );
		$state  = get_transient( $key );
		if ( ! is_array( $state ) || empty( $state['reset'] ) || (int) $state['reset'] <= time() ) {
			$state = array( 'count' => 0, 'reset' => time() + $window );
		}
		if ( (int) $state['count'] >= $max ) {
			return new WP_Error(
				$code,
				__( 'Too many requests. Please wait and try again.', 'abibitumi-chat' ),
				array( 'status' => 429, 'retry_after' => max( 1, (int) $state['reset'] - time() ) )
			);
		}
		$state['count']++;
		set_transient( $key, $state, max( 1, (int) $state['reset'] - time() ) );
		return false;
	}

	/**
	 * Validated file upload from a visitor or operator.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( $req ) {
		if ( ! ABChat_Settings::get( 'file_uploads' ) ) {
			return new WP_Error( 'abchat_uploads_off', __( 'File uploads are disabled.', 'abibitumi-chat' ), array( 'status' => 403 ) );
		}
		$visitor = $req->get_param( '_visitor' );
		if ( $visitor ) {
			$convo = $this->owned_conversation( (int) $req->get_param( 'conversation_id' ), $visitor );
			if ( is_wp_error( $convo ) ) {
				return $convo;
			}
			$sender_type = 'visitor';
			$sender_id   = $visitor->id;
			$sender_name = $visitor->name;
		} elseif ( current_user_can( ABCHAT_AGENT_CAP ) ) {
			$convo = ABChat_DB::get_conversation( (int) $req->get_param( 'conversation_id' ) );
			if ( ! $convo ) {
				return new WP_Error( 'abchat_not_found', __( 'Not found.', 'abibitumi-chat' ), array( 'status' => 404 ) );
			}
			$user        = wp_get_current_user();
			$sender_type = 'operator';
			$sender_id   = $user->ID;
			$sender_name = $user->display_name;
		} else {
			return new WP_Error( 'abchat_forbidden', __( 'Upload not permitted.', 'abibitumi-chat' ), array( 'status' => 403 ) );
		}

		$files = $req->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'abchat_no_file', __( 'No file provided.', 'abibitumi-chat' ), array( 'status' => 400 ) );
		}

		return $this->handle_upload( $files['file'], $convo->id, $sender_type, $sender_id, $sender_name );
	}

	/* --------------------------------------------------------------------- */
	/* Agent endpoints                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * List conversations.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function agent_list( $req ) {
		ABChat_DB::expire_visitors( 60 );
		$rows = ABChat_DB::list_conversations( array(
			'status'      => $req->get_param( 'status' ) ? sanitize_key( $req->get_param( 'status' ) ) : 'open',
			'department'  => $req->get_param( 'department' ) ? sanitize_key( $req->get_param( 'department' ) ) : '',
			'operator_id' => ( null !== $req->get_param( 'mine' ) && $req->get_param( 'mine' ) ) ? get_current_user_id() : '',
			'search'      => $req->get_param( 'search' ) ? sanitize_text_field( $req->get_param( 'search' ) ) : '',
			'limit'       => 60,
		) );

		return new WP_REST_Response(
			array(
				'conversations' => array_map( array( $this, 'shape_list_row' ), $rows ),
				'counts'        => ABChat_DB::conversation_counts(),
			),
			200
		);
	}

	/**
	 * Full conversation with messages.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function agent_conversation( $req ) {
		$convo = ABChat_DB::get_conversation( (int) $req['id'] );
		if ( ! $convo ) {
			return new WP_Error( 'abchat_not_found', __( 'Not found.', 'abibitumi-chat' ), array( 'status' => 404 ) );
		}
		ABChat_DB::mark_read( $convo->id, 'operator' );
		$visitor = $this->visitor_row( $convo->visitor_id );

		return new WP_REST_Response(
			array(
				'conversation' => $this->shape_conversation( $convo ),
				'messages'     => $this->shape_messages( ABChat_DB::get_messages( $convo->id ) ),
				'visitor'      => $this->shape_visitor( $visitor ),
			),
			200
		);
	}

	/**
	 * Agent sends a message (or internal note).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function agent_message( $req ) {
		$convo = ABChat_DB::get_conversation( (int) $req->get_param( 'conversation_id' ) );
		if ( ! $convo ) {
			return new WP_Error( 'abchat_not_found', __( 'Not found.', 'abibitumi-chat' ), array( 'status' => 404 ) );
		}
		$user = wp_get_current_user();
		$body = trim( (string) $req->get_param( 'body' ) );
		$type = $req->get_param( 'type' );
		$type = $type ? sanitize_key( $type ) : 'text';

		if ( '' === $body ) {
			return new WP_Error( 'abchat_empty', __( 'Message is empty.', 'abibitumi-chat' ), array( 'status' => 400 ) );
		}

		// Auto-claim if unassigned.
		if ( empty( $convo->operator_id ) ) {
			ABChat_DB::update_conversation( $convo->id, array( 'operator_id' => $user->ID ) );
		}

		$msg_id = ABChat_DB::add_message( array(
			'conversation_id' => $convo->id,
			'sender_type'     => ( 'note' === $type ) ? 'system' : 'operator',
			'sender_id'       => $user->ID,
			'sender_name'     => $user->display_name,
			'body'            => $body,
			'type'            => ( 'note' === $type ) ? 'note' : 'text',
		) );

		delete_transient( 'abchat_typing_' . $convo->id . '_operator' );
		do_action( 'abchat_operator_message', $convo->id, $msg_id, $user->ID );

		return new WP_REST_Response( array( 'id' => $msg_id ), 200 );
	}

	/**
	 * Agent poll — new messages for an open conversation + list refresh flag.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function agent_poll( $req ) {
		$convo = ABChat_DB::get_conversation( (int) $req->get_param( 'conversation_id' ) );
		if ( ! $convo ) {
			return new WP_Error( 'abchat_not_found', __( 'Not found.', 'abibitumi-chat' ), array( 'status' => 404 ) );
		}
		$after = (int) $req->get_param( 'after' );
		return new WP_REST_Response(
			array(
				'messages'      => $this->shape_messages( ABChat_DB::get_messages( $convo->id, $after ) ),
				'visitorTyping' => ABChat_DB::is_typing( $convo->id, 'visitor' ),
				'status'        => $convo->status,
				'counts'        => ABChat_DB::conversation_counts(),
			),
			200
		);
	}

	/**
	 * Agent typing signal.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function agent_typing( $req ) {
		ABChat_DB::set_typing( (int) $req->get_param( 'conversation_id' ), 'operator' );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Agent marks visitor messages read.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function agent_read( $req ) {
		ABChat_DB::mark_read( (int) $req->get_param( 'conversation_id' ), 'operator' );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Assign (claim / transfer) a conversation.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function agent_assign( $req ) {
		$convo = ABChat_DB::get_conversation( (int) $req->get_param( 'conversation_id' ) );
		if ( ! $convo ) {
			return new WP_Error( 'abchat_not_found', __( 'Not found.', 'abibitumi-chat' ), array( 'status' => 404 ) );
		}
		$operator = $req->get_param( 'operator_id' );
		$operator = ( null === $operator || '' === $operator ) ? get_current_user_id() : (int) $operator;
		ABChat_DB::update_conversation( $convo->id, array( 'operator_id' => $operator ) );

		$name = $this->operator_name( $operator );
		ABChat_DB::add_message( array(
			'conversation_id' => $convo->id,
			'sender_type'     => 'system',
			'body'            => sprintf( /* translators: %s: operator name */ __( 'Conversation assigned to %s.', 'abibitumi-chat' ), $name ),
		) );
		return new WP_REST_Response( array( 'ok' => true, 'operator' => $name ), 200 );
	}

	/**
	 * Change conversation status (open/pending/closed).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function agent_status( $req ) {
		$convo  = ABChat_DB::get_conversation( (int) $req->get_param( 'conversation_id' ) );
		if ( ! $convo ) {
			return new WP_Error( 'abchat_not_found', __( 'Not found.', 'abibitumi-chat' ), array( 'status' => 404 ) );
		}
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		if ( ! in_array( $status, array( 'open', 'pending', 'closed' ), true ) ) {
			return new WP_Error( 'abchat_bad_status', __( 'Invalid status.', 'abibitumi-chat' ), array( 'status' => 400 ) );
		}
		ABChat_DB::update_conversation( $convo->id, array( 'status' => $status ) );

		if ( 'closed' === $status ) {
			ABChat_DB::add_message( array(
				'conversation_id' => $convo->id,
				'sender_type'     => 'system',
				'body'            => __( 'Conversation closed.', 'abibitumi-chat' ),
			) );
			do_action( 'abchat_conversation_closed', $convo->id );
		}
		return new WP_REST_Response( array( 'ok' => true, 'status' => $status ), 200 );
	}

	/**
	 * List currently online visitors.
	 *
	 * @return WP_REST_Response
	 */
	public function agent_visitors() {
		global $wpdb;
		ABChat_DB::expire_visitors( 60 );
		$t    = ABChat_DB::table( 'visitors' );
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE is_online = 1 ORDER BY last_seen DESC LIMIT 100" ); // phpcs:ignore WordPress.DB
		return new WP_REST_Response( array( 'visitors' => array_map( array( $this, 'shape_visitor' ), $rows ) ), 200 );
	}

	/**
	 * List canned responses.
	 *
	 * @return WP_REST_Response
	 */
	public function agent_canned_list() {
		$rows = ABChat_DB::list_canned( get_current_user_id() );
		return new WP_REST_Response( array( 'canned' => $rows ), 200 );
	}

	/**
	 * Save a canned response.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function agent_canned_save( $req ) {
		$id = ABChat_DB::save_canned( array(
			'id'          => (int) $req->get_param( 'id' ),
			'operator_id' => $req->get_param( 'shared' ) ? 0 : get_current_user_id(),
			'shortcut'    => (string) $req->get_param( 'shortcut' ),
			'title'       => (string) $req->get_param( 'title' ),
			'body'        => (string) $req->get_param( 'body' ),
		) );
		return new WP_REST_Response( array( 'id' => $id ), 200 );
	}

	/**
	 * Delete a canned response.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function agent_canned_delete( $req ) {
		ABChat_DB::delete_canned( (int) $req['id'] );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Dashboard stats.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function agent_stats( $req ) {
		$days = $req->get_param( 'days' ) ? (int) $req->get_param( 'days' ) : 30;
		return new WP_REST_Response( ABChat_DB::stats( $days ), 200 );
	}

	/**
	 * Operator presence heartbeat; returns new-conversation counts.
	 *
	 * @return WP_REST_Response
	 */
	public function agent_presence() {
		update_user_meta( get_current_user_id(), 'abchat_last_active', current_time( 'mysql' ) );
		return new WP_REST_Response( array( 'counts' => ABChat_DB::conversation_counts() ), 200 );
	}

	/**
	 * Store a Web Push subscription for the current operator.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function agent_push_subscribe( $req ) {
		$sub = $req->get_param( 'subscription' );
		if ( is_array( $sub ) ) {
			$sub = wp_json_encode( $sub );
		}
		ABChat_DB::save_push( get_current_user_id(), (string) $sub );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Shared upload handler                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Validate and store an uploaded file, then post it as a message.
	 *
	 * @param array  $file        $_FILES entry.
	 * @param int    $convo_id    Conversation id.
	 * @param string $sender_type Sender type.
	 * @param int    $sender_id   Sender id.
	 * @param string $sender_name Sender name.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function handle_upload( $file, $convo_id, $sender_type, $sender_id, $sender_name ) {
		$max = (int) ABChat_Settings::get( 'max_upload_mb' ) * 1024 * 1024;
		if ( isset( $file['size'] ) && $file['size'] > $max ) {
			return new WP_Error( 'abchat_too_big', __( 'File is too large.', 'abibitumi-chat' ), array( 'status' => 400 ) );
		}

		$allowed = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'gif'      => 'image/gif',
			'webp'     => 'image/webp',
			'pdf'      => 'application/pdf',
			'doc'      => 'application/msword',
			'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'txt'      => 'text/plain',
		);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = array( 'test_form' => false, 'mimes' => $allowed );
		$moved     = wp_handle_upload( $file, $overrides );

		if ( isset( $moved['error'] ) ) {
			return new WP_Error( 'abchat_upload_failed', $moved['error'], array( 'status' => 400 ) );
		}

		$is_image = strpos( (string) $moved['type'], 'image/' ) === 0;
		$msg_id   = ABChat_DB::add_message( array(
			'conversation_id' => $convo_id,
			'sender_type'     => $sender_type,
			'sender_id'       => $sender_id,
			'sender_name'     => $sender_name,
			'body'            => $is_image ? '' : sanitize_file_name( $file['name'] ),
			'type'            => $is_image ? 'image' : 'file',
			'attachment_url'  => $moved['url'],
			'attachment_name' => sanitize_file_name( $file['name'] ),
		) );

		return new WP_REST_Response(
			array(
				'id'   => $msg_id,
				'url'  => $moved['url'],
				'name' => sanitize_file_name( $file['name'] ),
				'type' => $is_image ? 'image' : 'file',
			),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Shapers                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Shape a conversation for output.
	 *
	 * @param object $c Conversation row.
	 * @return array
	 */
	protected function shape_conversation( $c ) {
		return array(
			'id'         => (int) $c->id,
			'status'     => $c->status,
			'department' => $c->department,
			'operatorId' => (int) $c->operator_id,
			'operator'   => $this->operator_name( $c->operator_id ),
			'rating'     => $c->rating ? (int) $c->rating : null,
			'createdAt'  => $c->created_at,
		);
	}

	/**
	 * Shape a list row for the dashboard.
	 *
	 * @param object $r Row.
	 * @return array
	 */
	protected function shape_list_row( $r ) {
		return array(
			'id'          => (int) $r->id,
			'status'      => $r->status,
			'department'  => $r->department,
			'operatorId'  => (int) $r->operator_id,
			'visitorName' => $r->visitor_name ? $r->visitor_name : __( 'Visitor', 'abibitumi-chat' ),
			'visitorEmail'=> $r->visitor_email,
			'online'      => (bool) $r->visitor_online,
			'page'        => $r->visitor_page,
			'lastMessage' => wp_strip_all_tags( (string) $r->last_message ),
			'unread'      => (int) $r->unread,
			'updatedAt'   => $r->last_message_at,
		);
	}

	/**
	 * Shape messages for output.
	 *
	 * @param array $messages      Rows.
	 * @param bool  $include_notes Whether internal notes may be returned.
	 * @return array
	 */
	protected function shape_messages( $messages, $include_notes = true ) {
		$out = array();
		foreach ( (array) $messages as $m ) {
			if ( ! $include_notes && 'note' === $m->type ) {
				continue;
			}
			$out[] = array(
				'id'         => (int) $m->id,
				'senderType' => $m->sender_type,
				'senderName' => $m->sender_name,
				'body'       => $m->body,
				'type'       => $m->type,
				'attachment' => $m->attachment_url ? array( 'url' => $m->attachment_url, 'name' => $m->attachment_name ) : null,
				'read'       => ! empty( $m->read_at ),
				'createdAt'  => $m->created_at,
				'time'       => mysql2date( get_option( 'time_format' ), $m->created_at ),
			);
		}
		return $out;
	}

	/**
	 * Shape a visitor for output.
	 *
	 * @param object $v Visitor row.
	 * @return array
	 */
	protected function shape_visitor( $v ) {
		if ( ! $v ) {
			return array();
		}
		return array(
			'id'        => (int) $v->id,
			'name'      => $v->name ? $v->name : __( 'Visitor', 'abibitumi-chat' ),
			'email'     => $v->email,
			'phone'     => $v->phone,
			'ip'        => $v->ip,
			'page'      => $v->page_url,
			'referrer'  => $v->referrer,
			'online'    => (bool) $v->is_online,
			'wpUserId'  => (int) $v->wp_user_id,
			'firstSeen' => $v->first_seen,
			'lastSeen'  => $v->last_seen,
			'userAgent' => $v->user_agent,
		);
	}

	/**
	 * Visitor row helper.
	 *
	 * @param int $id Visitor id.
	 * @return object|null
	 */
	protected function visitor_row( $id ) {
		global $wpdb;
		$t = ABChat_DB::table( 'visitors' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Operator display name.
	 *
	 * @param int $id User id.
	 * @return string
	 */
	protected function operator_name( $id ) {
		if ( ! $id ) {
			return '';
		}
		$u = get_userdata( $id );
		return $u ? $u->display_name : '';
	}
}
