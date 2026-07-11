<?php
/**
 * Activation / deactivation: schema, roles, capabilities, seed options.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Activator {

	/**
	 * Run on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::add_caps();

		if ( false === get_option( ABChat_Settings::OPTION_KEY ) ) {
			update_option( ABChat_Settings::OPTION_KEY, ABChat_Settings::defaults(), false );
		}

		// VAPID keys for Web Push are generated lazily by the notifications class.
		ABChat_Retention::schedule();
		update_option( 'abchat_db_version', ABCHAT_VERSION, false );
		flush_rewrite_rules();
	}

	/**
	 * Run on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( ABChat_Retention::HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Create custom tables via dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$visitors = ABChat_DB::table( 'visitors' );
		$convos   = ABChat_DB::table( 'conversations' );
		$messages = ABChat_DB::table( 'messages' );
		$canned   = ABChat_DB::table( 'canned' );
		$push     = ABChat_DB::table( 'push' );

		$sql = array();

		$sql[] = "CREATE TABLE {$visitors} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token VARCHAR(64) NOT NULL,
			name VARCHAR(191) DEFAULT '' NOT NULL,
			email VARCHAR(191) DEFAULT '' NOT NULL,
			phone VARCHAR(64) DEFAULT '' NOT NULL,
			wp_user_id BIGINT UNSIGNED DEFAULT NULL,
			ip VARCHAR(64) DEFAULT '' NOT NULL,
			user_agent VARCHAR(255) DEFAULT '' NOT NULL,
			page_url TEXT NULL,
			referrer TEXT NULL,
			first_seen DATETIME NULL,
			last_seen DATETIME NULL,
			is_online TINYINT(1) DEFAULT 0 NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY wp_user_id (wp_user_id),
			KEY is_online (is_online)
		) {$charset};";

		$sql[] = "CREATE TABLE {$convos} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			visitor_id BIGINT UNSIGNED NOT NULL,
			operator_id BIGINT UNSIGNED DEFAULT NULL,
			department VARCHAR(64) DEFAULT 'general' NOT NULL,
			status VARCHAR(20) DEFAULT 'open' NOT NULL,
			subject VARCHAR(255) DEFAULT '' NOT NULL,
			source VARCHAR(32) DEFAULT 'widget' NOT NULL,
			rating TINYINT DEFAULT NULL,
			rating_comment TEXT NULL,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			last_message_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY visitor_id (visitor_id),
			KEY operator_id (operator_id),
			KEY status (status),
			KEY last_message_at (last_message_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$messages} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL,
			sender_type VARCHAR(20) DEFAULT 'visitor' NOT NULL,
			sender_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			sender_name VARCHAR(191) DEFAULT '' NOT NULL,
			body LONGTEXT NULL,
			type VARCHAR(20) DEFAULT 'text' NOT NULL,
			attachment_url TEXT NULL,
			attachment_name VARCHAR(255) DEFAULT '' NOT NULL,
			meta LONGTEXT NULL,
			read_at DATETIME DEFAULT NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY read_at (read_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$canned} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			operator_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			shortcut VARCHAR(64) DEFAULT '' NOT NULL,
			title VARCHAR(191) DEFAULT '' NOT NULL,
			body LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY operator_id (operator_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$push} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			endpoint VARCHAR(500) NOT NULL,
			subscription LONGTEXT NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		// Seed a couple of canned responses on first install.
		$existing = $wpdb->get_var( "SELECT COUNT(*) FROM {$canned}" ); // phpcs:ignore WordPress.DB
		if ( ! $existing ) {
			ABChat_DB::save_canned( array( 'operator_id' => 0, 'shortcut' => '/hi', 'title' => 'Greeting', 'body' => 'Hello! Thanks for reaching out to us. How can I help you today?' ) );
			ABChat_DB::save_canned( array( 'operator_id' => 0, 'shortcut' => '/thanks', 'title' => 'Thanks', 'body' => 'Thank you for contacting us. Is there anything else I can help with?' ) );
			ABChat_DB::save_canned( array( 'operator_id' => 0, 'shortcut' => '/wait', 'title' => 'One moment', 'body' => 'Give me one moment while I look into that for you.' ) );
		}
	}

	/**
	 * Grant the agent capability to admins & editors, and register a role.
	 *
	 * @return void
	 */
	public static function add_caps() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( ABCHAT_AGENT_CAP );
			$admin->add_cap( 'abchat_manage' );
		}
		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( ABCHAT_AGENT_CAP );
		}

		// A dedicated agent role for support staff.
		if ( ! get_role( 'abchat_operator' ) ) {
			add_role(
				'abchat_operator',
				__( 'Chat Operator', 'abibitumi-chat' ),
				array(
					'read'            => true,
					ABCHAT_AGENT_CAP  => true,
				)
			);
		}
	}
}
