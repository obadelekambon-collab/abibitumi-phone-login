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

	/**
	 * Locate a historical visitor imported from Tidio.
	 *
	 * @param string $email    Contact email.
	 * @param string $tidio_id Tidio contact id.
	 * @return object|null
	 */
	public static function find_imported_visitor( $email, $tidio_id ) {
		global $wpdb;
		$table = self::table( 'visitors' );
		$token = $tidio_id ? self::tidio_token( $tidio_id ) : '';
		if ( $email && $token ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s OR token = %s ORDER BY id ASC LIMIT 1", $email, $token ) ); // phpcs:ignore WordPress.DB
		}
		if ( $email ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB
		}
		if ( $token ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ) ); // phpcs:ignore WordPress.DB
		}
		return null;
	}

	/**
	 * Create an offline historical visitor.
	 *
	 * @param array $data Imported fields.
	 * @return int
	 */
	public static function create_imported_visitor( $data ) {
		global $wpdb;
		$tidio_id = isset( $data['tidio_id'] ) ? $data['tidio_id'] : '';
		$token    = $tidio_id ? self::tidio_token( $tidio_id ) : 'tidio_' . wp_generate_password( 40, false, false );
		$row      = array(
			'token'      => $token,
			'name'       => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'email'      => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '',
			'phone'      => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '',
			'wp_user_id' => null,
			'ip'         => '',
			'user_agent' => '',
			'page_url'   => '',
			'referrer'   => '',
			'first_seen' => isset( $data['created_at'] ) ? $data['created_at'] : current_time( 'mysql' ),
			'last_seen'  => isset( $data['last_seen'] ) ? $data['last_seen'] : current_time( 'mysql' ),
			'is_online'  => 0,
		);
		$ok = $wpdb->insert( self::table( 'visitors' ), $row ); // phpcs:ignore WordPress.DB
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Build a stable Tidio visitor token that fits the 64-character column. */
	public static function tidio_token( $tidio_id ) {
		return 'tidio_' . substr( hash( 'sha256', (string) $tidio_id ), 0, 58 );
	}

	/** Update non-empty imported contact fields. */
	public static function update_imported_visitor( $id, $data ) {
		global $wpdb;
		$clean = array();
		foreach ( array( 'name', 'email', 'phone' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$clean[ $key ] = $data[ $key ];
			}
		}
		if ( ! empty( $data['last_seen'] ) ) {
			$clean['last_seen'] = $data['last_seen'];
		}
		if ( $clean ) {
			$wpdb->update( self::table( 'visitors' ), $clean, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		}
	}

	/** Check whether a content-identical transcript was already imported. */
	public static function import_source_exists( $source_id ) {
		global $wpdb;
		$table   = self::table( 'conversations' );
		$subject = 'Tidio import [' . $source_id . ']';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s AND subject = %s LIMIT 1", 'tidio', $subject ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Persist one transcript atomically as a closed historical conversation.
	 *
	 * @param array  $messages  Normalized messages.
	 * @param string $filename  Source filename.
	 * @param string $source_id Stable content digest.
	 * @param array  $contact   Optional visitor identity from the transcript.
	 * @return int
	 */
	public static function create_imported_transcript( $messages, $filename, $source_id, $contact = array() ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'START TRANSACTION /* %d */', 1 ) ); // phpcs:ignore WordPress.DB
		$visitor    = self::find_imported_visitor( isset( $contact['email'] ) ? $contact['email'] : '', isset( $contact['tidio_id'] ) ? $contact['tidio_id'] : '' );
		$visitor_id = $visitor ? (int) $visitor->id : self::create_imported_visitor(
			array_merge( array( 'name' => __( 'Imported Tidio visitor', 'abibitumi-chat' ) ), $contact )
		);
		if ( ! $visitor_id ) {
			$wpdb->query( $wpdb->prepare( 'ROLLBACK /* %d */', 1 ) ); // phpcs:ignore WordPress.DB
			return 0;
		}
		$first   = reset( $messages );
		$last    = end( $messages );
		$created = $first['created_at'];
		$updated = $last['created_at'];
		$ok = $wpdb->insert(
			self::table( 'conversations' ),
			array(
				'visitor_id'      => $visitor_id,
				'operator_id'     => null,
				'department'      => 'general',
				'status'          => 'closed',
				'subject'         => 'Tidio import [' . $source_id . ']',
				'source'          => 'tidio',
				'rating'          => null,
				'rating_comment'  => sanitize_text_field( $filename ),
				'created_at'      => $created,
				'updated_at'      => $updated,
				'last_message_at' => $updated,
			)
		); // phpcs:ignore WordPress.DB
		if ( ! $ok ) {
			$wpdb->query( $wpdb->prepare( 'ROLLBACK /* %d */', 1 ) ); // phpcs:ignore WordPress.DB
			return 0;
		}
		$conversation_id = (int) $wpdb->insert_id;
		foreach ( $messages as $message ) {
			$ok = $wpdb->insert(
				self::table( 'messages' ),
				array(
					'conversation_id' => $conversation_id,
					'sender_type'     => $message['sender_type'],
					'sender_id'       => 0,
					'sender_name'     => $message['sender_name'],
					'body'            => $message['body'],
					'type'            => 'text',
					'attachment_url'  => '',
					'attachment_name' => '',
					'meta'            => wp_json_encode( array( 'imported_from' => 'tidio' ) ),
					'read_at'         => $message['created_at'],
					'created_at'      => $message['created_at'],
				)
			); // phpcs:ignore WordPress.DB
			if ( ! $ok ) {
				$wpdb->query( $wpdb->prepare( 'ROLLBACK /* %d */', 1 ) ); // phpcs:ignore WordPress.DB
				return 0;
			}
		}
		$wpdb->query( $wpdb->prepare( 'COMMIT /* %d */', 1 ) ); // phpcs:ignore WordPress.DB
		return $conversation_id;
	}

	/**
	 * Fetch visitor, conversation, and message records for a privacy request.
	 *
	 * @param string $email Visitor email.
	 * @return array
	 */
	public static function privacy_records( $email ) {
		global $wpdb;
		$visitors = self::table( 'visitors' );
		$convos   = self::table( 'conversations' );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$visitors} WHERE email = %s ORDER BY id ASC", $email ) ); // phpcs:ignore WordPress.DB
		$out      = array();

		foreach ( (array) $rows as $visitor ) {
			$visitor->conversations = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$convos} WHERE visitor_id = %d ORDER BY id ASC", (int) $visitor->id ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $visitor->conversations as $conversation ) {
				$conversation->messages = self::get_messages( $conversation->id );
			}
			$out[] = $visitor;
		}
		return $out;
	}

	/**
	 * Erase visitor records and their conversation transcripts by email.
	 *
	 * @param string $email Visitor email.
	 * @return array Number of visitors and attachment URLs.
	 */
	public static function erase_privacy_records( $email ) {
		global $wpdb;
		$visitors = self::table( 'visitors' );
		$convos   = self::table( 'conversations' );
		$messages = self::table( 'messages' );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$visitors} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB
		$attachments = array();

		foreach ( (array) $rows as $visitor ) {
			$conversation_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$convos} WHERE visitor_id = %d", (int) $visitor->id ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $conversation_ids as $conversation_id ) {
				$urls        = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_url FROM {$messages} WHERE conversation_id = %d AND attachment_url <> %s", (int) $conversation_id, '' ) ); // phpcs:ignore WordPress.DB
				$attachments = array_merge( $attachments, (array) $urls );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$messages} WHERE conversation_id = %d", (int) $conversation_id ) ); // phpcs:ignore WordPress.DB
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$convos} WHERE visitor_id = %d", (int) $visitor->id ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$visitors} WHERE id = %d", (int) $visitor->id ) ); // phpcs:ignore WordPress.DB
		}
		return array( 'visitors' => count( $rows ), 'attachments' => array_values( array_unique( $attachments ) ) );
	}

	/**
	 * Delete a bounded batch of old closed conversations and orphan visitors.
	 *
	 * @param string $cutoff MySQL datetime cutoff.
	 * @param int    $limit  Maximum conversations per run.
	 * @return array Counts and attachment URLs.
	 */
	public static function cleanup_expired_data( $cutoff, $limit = 100 ) {
		global $wpdb;
		$visitors = self::table( 'visitors' );
		$convos   = self::table( 'conversations' );
		$messages = self::table( 'messages' );
		$limit    = max( 1, min( 1000, (int) $limit ) );
		$ids      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$convos} WHERE status = %s AND last_message_at < %s ORDER BY id ASC LIMIT %d",
				'closed',
				$cutoff,
				$limit
			)
		); // phpcs:ignore WordPress.DB

		$result = array( 'conversations' => 0, 'messages' => 0, 'visitors' => 0, 'attachments' => array() );
		if ( $ids ) {
			$ids          = array_map( 'intval', $ids );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$result['attachments'] = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT attachment_url FROM {$messages} WHERE conversation_id IN ({$placeholders}) AND attachment_url <> %s",
					array_merge( $ids, array( '' ) )
				)
			); // phpcs:ignore WordPress.DB
			$result['messages'] = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$messages} WHERE conversation_id IN ({$placeholders})", $ids )
			); // phpcs:ignore WordPress.DB
			$result['conversations'] = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$convos} WHERE id IN ({$placeholders})", $ids )
			); // phpcs:ignore WordPress.DB
		}

		$result['visitors'] = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$visitors} WHERE last_seen < %s AND NOT EXISTS (SELECT 1 FROM {$convos} WHERE {$convos}.visitor_id = {$visitors}.id)",
				$cutoff
			)
		); // phpcs:ignore WordPress.DB
		return $result;
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
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$c} WHERE 1 = %d GROUP BY status", 1 ) ); // phpcs:ignore WordPress.DB
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
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE 1 = %d", 1 ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Remove a push subscription by endpoint.
	 *
	 * @param string $endpoint Push service endpoint.
	 * @return void
	 */
	public static function delete_push_by_endpoint( $endpoint ) {
		global $wpdb;
		$t = self::table( 'push' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE endpoint = %s", $endpoint ) ); // phpcs:ignore WordPress.DB
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
	 * Resolve the client IP without trusting spoofable proxy headers by default.
	 *
	 * Sites behind a trusted reverse proxy may replace the socket peer address
	 * with a validated forwarding header through the abchat_client_ip filter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
		/**
		 * Filter the resolved chat client IP for trusted reverse-proxy setups.
		 *
		 * Implementations must validate that REMOTE_ADDR belongs to a trusted
		 * proxy before returning a forwarded address.
		 *
		 * @param string $remote Socket peer address, or an empty string.
		 */
		$ip = apply_filters( 'abchat_client_ip', $remote );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? (string) $ip : $remote;
	}
}
