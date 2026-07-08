<?php
/**
 * Data access layer. Thin wrapper over $wpdb with helpers for every
 * entity the chat uses.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_DB {

	/**
	 * Fully-qualified table name.
	 *
	 * @param string $name Short table name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'abchat_' . $name;
	}

	/* --------------------------------------------------------------------- */
	/* Visitors                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Find or create a visitor by token.
	 *
	 * @param string $token Visitor token.
	 * @param array  $data  Optional data to seed a new row.
	 * @return object|null
	 */
	public static function get_or_create_visitor( $token, $data = array() ) {
		global $wpdb;
		$table = self::table( 'visitors' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB

		if ( $row ) {
			return $row;
		}

		$now     = current_time( 'mysql' );
		$user_id = get_current_user_id();
		$insert  = array(
			'token'      => $token,
			'name'       => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'email'      => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '',
			'phone'      => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '',
			'wp_user_id' => $user_id ? $user_id : null,
			'ip'         => self::client_ip(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
			'page_url'   => isset( $data['page_url'] ) ? esc_url_raw( $data['page_url'] ) : '',
			'referrer'   => isset( $data['referrer'] ) ? esc_url_raw( $data['referrer'] ) : '',
			'first_seen' => $now,
			'last_seen'  => $now,
			'is_online'  => 1,
		);

		if ( $user_id ) {
			$u = get_userdata( $user_id );
			if ( $u ) {
				$insert['name']  = $insert['name'] ? $insert['name'] : $u->display_name;
				$insert['email'] = $insert['email'] ? $insert['email'] : $u->user_email;
			}
		}

		$wpdb->insert( $table, $insert ); // phpcs:ignore WordPress.DB
		$insert['id'] = (int) $wpdb->insert_id;
		return (object) $insert;
	}

	/**
	 * Update visitor fields.
	 *
	 * @param int   $id   Visitor id.
	 * @param array $data Fields.
	 * @return void
	 */
	public static function update_visitor( $id, array $data ) {
		global $wpdb;
		$allowed = array( 'name', 'email', 'phone', 'page_url', 'is_online', 'last_seen' );
		$clean   = array();
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$clean[ $k ] = $data[ $k ];
			}
		}
		if ( $clean ) {
			$wpdb->update( self::table( 'visitors' ), $clean, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Mark visitors offline after inactivity (used by dashboard queries).
	 *
	 * @param int $seconds Idle threshold.
	 * @return void
	 */
	public static function expire_visitors( $seconds = 60 ) {
		global $wpdb;
		$table  = self::table( 'visitors' );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $seconds );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_online = 0 WHERE last_seen < %s AND is_online = 1", $cutoff ) ); // phpcs:ignore WordPress.DB
	}

	/* --------------------------------------------------------------------- */
	/* Conversations                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Create a conversation.
	 *
	 * @param array $data Conversation data.
	 * @return int New id.
	 */
	public static function create_conversation( array $data ) {
		global $wpdb;
		$now    = current_time( 'mysql' );
		$insert = array(
			'visitor_id'      => (int) $data['visitor_id'],
			'operator_id'     => isset( $data['operator_id'] ) ? (int) $data['operator_id'] : null,
			'department'      => isset( $data['department'] ) ? sanitize_key( $data['department'] ) : 'general',
			'status'          => 'open',
			'subject'         => isset( $data['subject'] ) ? sanitize_text_field( $data['subject'] ) : '',
			'source'          => isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'widget',
			'rating'          => null,
			'rating_comment'  => '',
			'created_at'      => $now,
			'updated_at'      => $now,
			'last_message_at' => $now,
		);
		$wpdb->insert( self::table( 'conversations' ), $insert ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a conversation by id.
	 *
	 * @param int $id Conversation id.
	 * @return object|null
	 */
	public static function get_conversation( $id ) {
		global $wpdb;
		$t = self::table( 'conversations' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Update a conversation.
	 *
	 * @param int   $id   Conversation id.
	 * @param array $data Fields.
	 * @return void
	 */
	public static function update_conversation( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::table( 'conversations' ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * List conversations for the agent dashboard.
	 *
	 * @param array $args Filters: status, department, operator_id, search, limit, offset.
	 * @return array
	 */
	public static function list_conversations( array $args = array() ) {
		global $wpdb;
		$c = self::table( 'conversations' );
		$v = self::table( 'visitors' );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[]  = 'c.status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['department'] ) ) {
			$where[]  = 'c.department = %s';
			$params[] = $args['department'];
		}
		if ( isset( $args['operator_id'] ) && '' !== $args['operator_id'] ) {
			$where[]  = 'c.operator_id = %d';
			$params[] = (int) $args['operator_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(v.name LIKE %s OR v.email LIKE %s OR c.subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

		$sql = "SELECT c.*, v.name AS visitor_name, v.email AS visitor_email, v.phone AS visitor_phone,
					v.is_online AS visitor_online, v.page_url AS visitor_page,
					(SELECT body FROM " . self::table( 'messages' ) . " m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
					(SELECT COUNT(*) FROM " . self::table( 'messages' ) . " m WHERE m.conversation_id = c.id AND m.sender_type = 'visitor' AND m.read_at IS NULL) AS unread
				FROM {$c} c
				LEFT JOIN {$v} v ON v.id = c.visitor_id
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY c.last_message_at DESC
				LIMIT %d OFFSET %d';

		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Counts per status for dashboard badges.
	 *
	 * @return array
	 */
	public static function conversation_counts() {
		global $wpdb;
		$c    = self::table( 'conversations' );
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$c} GROUP BY status" ); // phpcs:ignore WordPress.DB
		$out  = array( 'open' => 0, 'pending' => 0, 'closed' => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Messages                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Insert a message and bump the conversation timestamp.
	 *
	 * @param array $data Message data.
	 * @return int New message id.
	 */
	public static function add_message( array $data ) {
		global $wpdb;
		$now    = current_time( 'mysql' );
		$insert = array(
			'conversation_id' => (int) $data['conversation_id'],
			'sender_type'     => in_array( $data['sender_type'], array( 'visitor', 'operator', 'bot', 'system' ), true ) ? $data['sender_type'] : 'system',
			'sender_id'       => isset( $data['sender_id'] ) ? (int) $data['sender_id'] : 0,
			'sender_name'     => isset( $data['sender_name'] ) ? sanitize_text_field( $data['sender_name'] ) : '',
			'body'            => isset( $data['body'] ) ? wp_kses_post( $data['body'] ) : '',
			'type'            => isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'text',
			'attachment_url'  => isset( $data['attachment_url'] ) ? esc_url_raw( $data['attachment_url'] ) : '',
			'attachment_name' => isset( $data['attachment_name'] ) ? sanitize_text_field( $data['attachment_name'] ) : '',
			'meta'            => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : '',
			'read_at'         => null,
			'created_at'      => $now,
		);
		$wpdb->insert( self::table( 'messages' ), $insert ); // phpcs:ignore WordPress.DB
		$id = (int) $wpdb->insert_id;

		self::update_conversation(
			(int) $data['conversation_id'],
			array( 'last_message_at' => $now )
		);

		return $id;
	}

	/**
	 * Fetch messages for a conversation, optionally after a given id (polling).
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $after_id        Only return messages with id greater than this.
	 * @return array
	 */
	public static function get_messages( $conversation_id, $after_id = 0 ) {
		global $wpdb;
		$t = self::table( 'messages' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE conversation_id = %d AND id > %d ORDER BY id ASC",
				(int) $conversation_id,
				(int) $after_id
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Mark visitor messages as read by an operator (or vice versa).
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $reader          'operator' marks visitor msgs read; 'visitor' marks operator msgs read.
	 * @return void
	 */
	public static function mark_read( $conversation_id, $reader ) {
		global $wpdb;
		$t      = self::table( 'messages' );
		$target = ( 'operator' === $reader ) ? 'visitor' : 'operator';
		$now    = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET read_at = %s WHERE conversation_id = %d AND sender_type = %s AND read_at IS NULL",
				$now,
				(int) $conversation_id,
				$target
			)
		); // phpcs:ignore WordPress.DB
	}

	/* --------------------------------------------------------------------- */
	/* Typing indicators & presence (transient-backed, no table needed)      */
	/* --------------------------------------------------------------------- */

	/**
	 * Record a typing signal.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $who             'visitor' or 'operator'.
	 * @return void
	 */
	public static function set_typing( $conversation_id, $who ) {
		set_transient( 'abchat_typing_' . (int) $conversation_id . '_' . $who, 1, 8 );
	}

	/**
	 * Check a typing signal from the counterpart.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $who             The counterpart to check.
	 * @return bool
	 */
	public static function is_typing( $conversation_id, $who ) {
		return (bool) get_transient( 'abchat_typing_' . (int) $conversation_id . '_' . $who );
	}

	/* --------------------------------------------------------------------- */
	/* Canned responses                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * List canned responses (global + operator-owned).
	 *
	 * @param int $operator_id Operator id.
	 * @return array
	 */
	public static function list_canned( $operator_id = 0 ) {
		global $wpdb;
		$t = self::table( 'canned' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE operator_id = 0 OR operator_id = %d ORDER BY shortcut ASC",
				(int) $operator_id
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Save (insert/update) a canned response.
	 *
	 * @param array $data Canned data.
	 * @return int
	 */
	public static function save_canned( array $data ) {
		global $wpdb;
		$row = array(
			'operator_id' => isset( $data['operator_id'] ) ? (int) $data['operator_id'] : 0,
			'shortcut'    => sanitize_text_field( $data['shortcut'] ),
			'title'       => sanitize_text_field( $data['title'] ),
			'body'        => wp_kses_post( $data['body'] ),
		);
		if ( ! empty( $data['id'] ) ) {
			$wpdb->update( self::table( 'canned' ), $row, array( 'id' => (int) $data['id'] ) ); // phpcs:ignore WordPress.DB
			return (int) $data['id'];
		}
		$wpdb->insert( self::table( 'canned' ), $row ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a canned response.
	 *
	 * @param int $id Canned id.
	 * @return void
	 */
	public static function delete_canned( $id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'canned' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/* --------------------------------------------------------------------- */
	/* Push subscriptions (Web Push / PWA)                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Store a push subscription for an operator.
	 *
	 * @param int    $user_id      Operator user id.
	 * @param string $subscription JSON PushSubscription.
	 * @return void
	 */
	public static function save_push( $user_id, $subscription ) {
		global $wpdb;
		$t        = self::table( 'push' );
		$endpoint = '';
		$decoded  = json_decode( $subscription, true );
		if ( is_array( $decoded ) && ! empty( $decoded['endpoint'] ) ) {
			$endpoint = $decoded['endpoint'];
		}
		if ( ! $endpoint ) {
			return;
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE endpoint = %s", $endpoint ) ); // phpcs:ignore WordPress.DB
		if ( $exists ) {
			$wpdb->update( $t, array( 'user_id' => (int) $user_id, 'subscription' => $subscription ), array( 'id' => (int) $exists ) ); // phpcs:ignore WordPress.DB
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$t,
			array(
				'user_id'      => (int) $user_id,
				'endpoint'     => $endpoint,
				'subscription' => $subscription,
				'created_at'   => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get push subscriptions, optionally for a single operator.
	 *
	 * @param int $user_id Operator id (0 = all).
	 * @return array
	 */
	public static function get_push( $user_id = 0 ) {
		global $wpdb;
		$t = self::table( 'push' );
		if ( $user_id ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d", (int) $user_id ) ); // phpcs:ignore WordPress.DB
		}
		return $wpdb->get_results( "SELECT * FROM {$t}" ); // phpcs:ignore WordPress.DB
	}

	/* --------------------------------------------------------------------- */
	/* Analytics                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * High-level stats for the dashboard.
	 *
	 * @param int $days Window in days.
	 * @return array
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;
		$c      = self::table( 'conversations' );
		$m      = self::table( 'messages' );
		$since  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB
		$closed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE status = 'closed' AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB
		$msgs   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB
		$avg    = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(rating) FROM {$c} WHERE rating IS NOT NULL AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB

		$daily = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, COUNT(*) AS n FROM {$c} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY d ASC",
				$since
			)
		); // phpcs:ignore WordPress.DB

		return array(
			'total'      => $total,
			'closed'     => $closed,
			'messages'   => $msgs,
			'avg_rating' => $avg ? round( (float) $avg, 2 ) : null,
			'daily'      => $daily,
		);
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $keys as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
				$ip = trim( explode( ',', $ip )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}
}
