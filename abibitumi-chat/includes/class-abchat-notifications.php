<?php
/**
 * Notifications: agent emails on new chats / offline leads, visitor
 * transcript emails, and a Web Push dispatch hook for the PWA.
 *
 * Real Web Push delivery to a closed browser needs VAPID-signed requests.
 * Subscriptions are stored here and dispatch is delegated to the
 * `abchat_dispatch_push` action so a signing library (e.g.
 * minishlink/web-push) can be attached without touching core. When the
 * dashboard/PWA is open, notifications are shown client-side via the
 * Notifications API driven by polling — no server push required.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Notifications {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'abchat_conversation_started', array( $this, 'on_new_conversation' ), 10, 2 );
		add_action( 'abchat_bot_handoff', array( $this, 'on_handoff' ) );
		add_action( 'abchat_conversation_closed', array( $this, 'on_closed' ) );
	}

	/**
	 * Notify agents when a new conversation begins offline (a lead).
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param object $visitor         Visitor.
	 * @return void
	 */
	public function on_new_conversation( $conversation_id, $visitor ) {
		if ( ! ABChat_Settings::get( 'notify_new_chat' ) ) {
			return;
		}
		$open    = ABChat_Settings::is_within_office_hours();
		$subject = $open
			? sprintf( /* translators: %s: site name */ __( '[%s] New live chat started', 'abibitumi-chat' ), get_bloginfo( 'name' ) )
			: sprintf( /* translators: %s: site name */ __( '[%s] New offline chat lead', 'abibitumi-chat' ), get_bloginfo( 'name' ) );

		$lines = array(
			__( 'A visitor started a conversation.', 'abibitumi-chat' ),
			'',
			sprintf( __( 'Name: %s', 'abibitumi-chat' ), $visitor->name ? $visitor->name : __( '(not provided)', 'abibitumi-chat' ) ),
			sprintf( __( 'Email: %s', 'abibitumi-chat' ), $visitor->email ? $visitor->email : __( '(not provided)', 'abibitumi-chat' ) ),
			sprintf( __( 'Phone: %s', 'abibitumi-chat' ), $visitor->phone ? $visitor->phone : __( '(not provided)', 'abibitumi-chat' ) ),
			sprintf( __( 'Page: %s', 'abibitumi-chat' ), $visitor->page_url ),
			'',
			sprintf( __( 'Open the dashboard: %s', 'abibitumi-chat' ), admin_url( 'admin.php?page=abchat&conversation=' . $conversation_id ) ),
		);

		$this->mail_agents( $subject, implode( "\n", $lines ) );
		$this->push_agents( __( 'New conversation', 'abibitumi-chat' ), $visitor->name ? $visitor->name : __( 'A visitor needs help', 'abibitumi-chat' ), $conversation_id );
	}

	/**
	 * Notify agents on a bot → human hand-off.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return void
	 */
	public function on_handoff( $conversation_id ) {
		$subject = sprintf( /* translators: %s: site name */ __( '[%s] Visitor requested a human', 'abibitumi-chat' ), get_bloginfo( 'name' ) );
		$body    = sprintf(
			__( "A visitor asked to speak with a person.\n\nOpen the dashboard: %s", 'abibitumi-chat' ),
			admin_url( 'admin.php?page=abchat&conversation=' . $conversation_id )
		);
		$this->mail_agents( $subject, $body );
		$this->push_agents( __( 'Human requested', 'abibitumi-chat' ), __( 'A visitor wants to talk to an agent', 'abibitumi-chat' ), $conversation_id );
	}

	/**
	 * Email the transcript to the visitor when a chat closes.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return void
	 */
	public function on_closed( $conversation_id ) {
		if ( ! ABChat_Settings::get( 'transcript_email' ) ) {
			return;
		}
		$convo = ABChat_DB::get_conversation( $conversation_id );
		if ( ! $convo ) {
			return;
		}
		global $wpdb;
		$vt      = ABChat_DB::table( 'visitors' );
		$visitor = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$vt} WHERE id = %d", (int) $convo->visitor_id ) ); // phpcs:ignore WordPress.DB
		if ( ! $visitor || ! is_email( $visitor->email ) ) {
			return;
		}

		$messages = ABChat_DB::get_messages( $conversation_id );
		$lines    = array( sprintf( __( 'Here is a copy of your conversation with %s:', 'abibitumi-chat' ), get_bloginfo( 'name' ) ), '' );
		foreach ( $messages as $m ) {
			if ( 'note' === $m->type ) {
				continue; // Never leak internal notes.
			}
			$who     = 'visitor' === $m->sender_type ? __( 'You', 'abibitumi-chat' ) : ( $m->sender_name ? $m->sender_name : ucfirst( $m->sender_type ) );
			$lines[] = sprintf( '[%s] %s: %s', mysql2date( 'H:i', $m->created_at ), $who, wp_strip_all_tags( $m->body ) );
		}
		$subject = sprintf( /* translators: %s: site name */ __( 'Your conversation with %s', 'abibitumi-chat' ), get_bloginfo( 'name' ) );
		wp_mail( $visitor->email, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Email all users who hold the agent capability.
	 *
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return void
	 */
	protected function mail_agents( $subject, $body ) {
		$recipients = array();

		$configured = ABChat_Settings::get( 'notify_email' );
		if ( is_email( $configured ) ) {
			$recipients[] = $configured;
		}

		$agents = get_users( array( 'capability' => ABCHAT_AGENT_CAP, 'fields' => array( 'user_email' ) ) );
		foreach ( $agents as $a ) {
			if ( is_email( $a->user_email ) ) {
				$recipients[] = $a->user_email;
			}
		}
		$recipients = array_unique( array_filter( $recipients ) );
		if ( $recipients ) {
			wp_mail( $recipients, $subject, $body );
		}
	}

	/**
	 * Dispatch a Web Push to agents (delegated to a signing library).
	 *
	 * @param string $title Notification title.
	 * @param string $body  Notification body.
	 * @param int    $convo Conversation id.
	 * @return void
	 */
	protected function push_agents( $title, $body, $convo ) {
		if ( ! ABChat_Settings::get( 'push_enabled' ) ) {
			return;
		}
		$subs = ABChat_DB::get_push( 0 );
		if ( empty( $subs ) ) {
			return;
		}
		$payload = array(
			'title' => $title,
			'body'  => $body,
			'url'   => admin_url( 'admin.php?page=abchat&conversation=' . (int) $convo ),
			'tag'   => 'abchat-' . (int) $convo,
		);
		/**
		 * Deliver a Web Push notification. Attach a VAPID signing
		 * implementation here (e.g. minishlink/web-push).
		 *
		 * @param array $subs    Subscription rows.
		 * @param array $payload Notification payload.
		 */
		do_action( 'abchat_dispatch_push', $subs, $payload );
	}

	/**
	 * Get or lazily create a VAPID key pair for Web Push.
	 *
	 * @return array { publicKey, privateKey } base64url-encoded, or empty.
	 */
	public static function vapid_keys() {
		$keys = get_option( 'abchat_vapid', array() );
		if ( ! empty( $keys['publicKey'] ) ) {
			return $keys;
		}
		// Generate an EC P-256 key pair if OpenSSL is available.
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return array();
		}
		$res = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
		if ( ! $res ) {
			return array();
		}
		$details = openssl_pkey_get_details( $res );
		if ( empty( $details['ec']['x'] ) ) {
			return array();
		}
		$public  = "\x04" . $details['ec']['x'] . $details['ec']['y'];
		$private = $details['ec']['d'];
		$keys    = array(
			'publicKey'  => self::b64url( $public ),
			'privateKey' => self::b64url( $private ),
		);
		update_option( 'abchat_vapid', $keys, false );
		return $keys;
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	protected static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
