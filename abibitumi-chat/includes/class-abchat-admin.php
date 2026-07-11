<?php
/**
 * Admin: operator dashboard, settings screen, menu, and asset loading.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Admin {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_abchat_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_abchat_apply_preset', array( $this, 'apply_preset' ) );
		add_action( 'admin_post_abchat_export_settings', array( $this, 'export_settings' ) );
		add_action( 'admin_post_abchat_import_settings', array( $this, 'import_settings' ) );
		add_action( 'admin_post_abchat_export_conversation', array( $this, 'export_conversation' ) );
		add_action( 'admin_post_abchat_run_cleanup', array( $this, 'run_cleanup' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function menu() {
		$counts = ABChat_DB::conversation_counts();
		$open   = (int) $counts['open'];
		$bubble = $open ? ' <span class="awaiting-mod">' . $open . '</span>' : '';

		add_menu_page(
			__( 'Abibitumi Chat', 'abibitumi-chat' ),
			__( 'Chat', 'abibitumi-chat' ) . $bubble,
			ABCHAT_AGENT_CAP,
			'abchat',
			array( $this, 'render_dashboard' ),
			'dashicons-format-chat',
			3
		);

		add_submenu_page(
			'abchat',
			__( 'Inbox', 'abibitumi-chat' ),
			__( 'Inbox', 'abibitumi-chat' ),
			ABCHAT_AGENT_CAP,
			'abchat',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'abchat',
			__( 'Analytics', 'abibitumi-chat' ),
			__( 'Analytics', 'abibitumi-chat' ),
			ABCHAT_AGENT_CAP,
			'abchat-analytics',
			array( $this, 'render_analytics' )
		);

		add_submenu_page(
			'abchat',
			__( 'Settings', 'abibitumi-chat' ),
			__( 'Settings', 'abibitumi-chat' ),
			'abchat_manage',
			'abchat-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Toolbar shortcut with unread badge.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public function admin_bar( $bar ) {
		if ( ! current_user_can( ABCHAT_AGENT_CAP ) ) {
			return;
		}
		$counts = ABChat_DB::conversation_counts();
		$open   = (int) $counts['open'];
		$bar->add_node( array(
			'id'    => 'abchat',
			'title' => '💬 ' . ( $open ? $open : '' ),
			'href'  => admin_url( 'admin.php?page=abchat' ),
			'meta'  => array( 'title' => __( 'Open chats', 'abibitumi-chat' ) ),
		) );
	}

	/**
	 * Enqueue dashboard assets on our screens only.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'abchat' ) ) {
			return;
		}

		wp_enqueue_style( 'abchat-admin', ABCHAT_URL . 'assets/css/admin.css', array(), ABCHAT_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'abchat-admin', ABCHAT_URL . 'assets/js/admin.js', array( 'wp-i18n' ), ABCHAT_VERSION, true );

		$operators = get_users( array( 'capability' => ABCHAT_AGENT_CAP, 'fields' => array( 'ID', 'display_name' ) ) );
		$op_list   = array();
		foreach ( $operators as $o ) {
			$op_list[] = array( 'id' => (int) $o->ID, 'name' => $o->display_name );
		}

		wp_localize_script(
			'abchat-admin',
			'ABChatAdmin',
			array(
				'restUrl'     => esc_url_raw( rest_url( ABChat_REST::NS ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'currentUser' => get_current_user_id(),
				'pollInterval'  => max( 2, (int) ABChat_Settings::get( 'agent_poll_interval' ) ),
				'streamEnabled' => (bool) ABChat_Settings::get( 'stream_enabled' ),
				'departments' => ABChat_Settings::get( 'departments' ),
				'operators'   => $op_list,
				'pushEnabled' => (bool) ABChat_Settings::get( 'push_enabled' ),
				'vapidPublic' => isset( ABChat_Notifications::vapid_keys()['publicKey'] ) ? ABChat_Notifications::vapid_keys()['publicKey'] : '',
				'swUrl'       => esc_url_raw( home_url( '/abchat-sw.js' ) ),
				'openConvo'   => isset( $_GET['conversation'] ) ? (int) $_GET['conversation'] : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'exportUrl'   => esc_url_raw(
					add_query_arg(
						array(
							'action'   => 'abchat_export_conversation',
							'_wpnonce' => wp_create_nonce( 'abchat_export_conversation' ),
						),
						admin_url( 'admin-post.php' )
					)
				),
			)
		);
	}

	/**
	 * Render the operator dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		include ABCHAT_DIR . 'templates/dashboard.php';
	}

	/**
	 * Render the analytics screen.
	 *
	 * @return void
	 */
	public function render_analytics() {
		include ABCHAT_DIR . 'templates/analytics.php';
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		if ( ! current_user_can( 'abchat_manage' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage chat settings.', 'abibitumi-chat' ) );
		}
		include ABCHAT_DIR . 'templates/settings.php';
	}

	/**
	 * Persist the settings form.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( 'abchat_manage' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'abibitumi-chat' ) );
		}
		check_admin_referer( 'abchat_settings' );

		$in = wp_unslash( $_POST );

		$checkbox = function ( $k ) use ( $in ) {
			return ! empty( $in[ $k ] ) ? 1 : 0;
		};
		$text = function ( $k, $default = '' ) use ( $in ) {
			return isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : $default;
		};

		$values = array(
			'enabled'             => $checkbox( 'enabled' ),
			'brand_name'          => $text( 'brand_name' ),
			'welcome_title'       => $text( 'welcome_title' ),
			'welcome_subtitle'    => $text( 'welcome_subtitle' ),
			'primary_color'       => sanitize_hex_color( isset( $in['primary_color'] ) ? $in['primary_color'] : '#0b7d3e' ),
			'text_color'          => sanitize_hex_color( isset( $in['text_color'] ) ? $in['text_color'] : '#ffffff' ),
			'position'            => in_array( $text( 'position' ), array( 'left', 'right' ), true ) ? $text( 'position' ) : 'right',
			'launcher_icon'       => $text( 'launcher_icon', 'chat' ),
			'avatar_url'          => esc_url_raw( isset( $in['avatar_url'] ) ? $in['avatar_url'] : '' ),
			'greeting_delay'      => absint( isset( $in['greeting_delay'] ) ? $in['greeting_delay'] : 3 ),
			'prechat_enabled'     => $checkbox( 'prechat_enabled' ),
			'prechat_name'        => $checkbox( 'prechat_name' ),
			'prechat_email'       => $checkbox( 'prechat_email' ),
			'prechat_phone'       => $checkbox( 'prechat_phone' ),
			'prechat_message'     => $text( 'prechat_message' ),
			'sound_enabled'       => $checkbox( 'sound_enabled' ),
			'show_on_mobile'      => $checkbox( 'show_on_mobile' ),
			'require_login'       => $checkbox( 'require_login' ),
			'file_uploads'        => $checkbox( 'file_uploads' ),
			'max_upload_mb'       => absint( isset( $in['max_upload_mb'] ) ? $in['max_upload_mb'] : 5 ),
			'poll_interval'       => max( 2, absint( isset( $in['poll_interval'] ) ? $in['poll_interval'] : 4 ) ),
			'agent_poll_interval' => max( 2, absint( isset( $in['agent_poll_interval'] ) ? $in['agent_poll_interval'] : 3 ) ),
			'stream_enabled'      => $checkbox( 'stream_enabled' ),
			'stream_duration'     => max( 10, min( 60, absint( isset( $in['stream_duration'] ) ? $in['stream_duration'] : 25 ) ) ),
			'transcript_email'    => $checkbox( 'transcript_email' ),
			'retention_enabled'   => $checkbox( 'retention_enabled' ),
			'retention_days'      => max( 1, absint( isset( $in['retention_days'] ) ? $in['retention_days'] : 365 ) ),
			'retention_batch'     => max( 10, min( 1000, absint( isset( $in['retention_batch'] ) ? $in['retention_batch'] : 100 ) ) ),
			'session_rate_limit'  => max( 1, absint( isset( $in['session_rate_limit'] ) ? $in['session_rate_limit'] : 30 ) ),
			'session_rate_window' => max( 60, absint( isset( $in['session_rate_window'] ) ? $in['session_rate_window'] : 3600 ) ),
			'message_rate_limit'  => max( 1, absint( isset( $in['message_rate_limit'] ) ? $in['message_rate_limit'] : 30 ) ),
			'message_rate_window' => max( 10, absint( isset( $in['message_rate_window'] ) ? $in['message_rate_window'] : 60 ) ),
			'conversation_rate_limit'  => max( 1, absint( isset( $in['conversation_rate_limit'] ) ? $in['conversation_rate_limit'] : 10 ) ),
			'conversation_rate_window' => max( 60, absint( isset( $in['conversation_rate_window'] ) ? $in['conversation_rate_window'] : 3600 ) ),
			'office_hours_enabled'=> $checkbox( 'office_hours_enabled' ),
			'offline_message'     => sanitize_textarea_field( isset( $in['offline_message'] ) ? $in['offline_message'] : '' ),
			'bot_enabled'         => $checkbox( 'bot_enabled' ),
			'bot_name'            => $text( 'bot_name' ),
			'bot_greeting'        => sanitize_textarea_field( isset( $in['bot_greeting'] ) ? $in['bot_greeting'] : '' ),
			'bot_fallback'        => sanitize_textarea_field( isset( $in['bot_fallback'] ) ? $in['bot_fallback'] : '' ),
			'bot_ai_enabled'      => $checkbox( 'bot_ai_enabled' ),
			'gemini_model'        => preg_replace( '/[^a-zA-Z0-9._-]/', '', $text( 'gemini_model', 'gemini-2.5-flash' ) ),
			'bot_rate_limit'      => max( 1, absint( isset( $in['bot_rate_limit'] ) ? $in['bot_rate_limit'] : 10 ) ),
			'bot_rate_window'     => max( 10, absint( isset( $in['bot_rate_window'] ) ? $in['bot_rate_window'] : 60 ) ),
			'notify_email'        => sanitize_email( isset( $in['notify_email'] ) ? $in['notify_email'] : '' ),
			'notify_new_chat'     => $checkbox( 'notify_new_chat' ),
			'notify_offline'      => $checkbox( 'notify_offline' ),
			'push_enabled'        => $checkbox( 'push_enabled' ),
			'pwa_enabled'         => $checkbox( 'pwa_enabled' ),
			'pwa_short_name'      => $text( 'pwa_short_name', 'Chat' ),
			'pwa_theme_color'     => sanitize_hex_color( isset( $in['pwa_theme_color'] ) ? $in['pwa_theme_color'] : '#0b7d3e' ),
		);

		if ( ! empty( $in['gemini_api_key'] ) ) {
			$values['gemini_api_key'] = sanitize_text_field( $in['gemini_api_key'] );
		} elseif ( ! empty( $in['gemini_api_key_clear'] ) ) {
			$values['gemini_api_key'] = '';
		}

		// Office hours grid.
		if ( isset( $in['office_hours'] ) && is_array( $in['office_hours'] ) ) {
			$hours = array();
			foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $d ) {
				$row       = isset( $in['office_hours'][ $d ] ) ? $in['office_hours'][ $d ] : array();
				$hours[ $d ] = array(
					'open' => ! empty( $row['open'] ) ? 1 : 0,
					'from' => isset( $row['from'] ) ? preg_replace( '/[^0-9:]/', '', $row['from'] ) : '09:00',
					'to'   => isset( $row['to'] ) ? preg_replace( '/[^0-9:]/', '', $row['to'] ) : '17:00',
				);
			}
			$values['office_hours'] = $hours;
		}

		// Departments (parallel arrays id[]/name[]).
		if ( isset( $in['dept_name'] ) && is_array( $in['dept_name'] ) ) {
			$departments = array();
			foreach ( $in['dept_name'] as $i => $name ) {
				$name = sanitize_text_field( $name );
				if ( '' === $name ) {
					continue;
				}
				$id            = sanitize_key( ! empty( $in['dept_id'][ $i ] ) ? $in['dept_id'][ $i ] : $name );
				$departments[] = array( 'id' => $id, 'name' => $name );
			}
			if ( $departments ) {
				$values['departments'] = $departments;
			}
		}

		// Bot flows (parallel arrays).
		if ( isset( $in['flow_label'] ) && is_array( $in['flow_label'] ) ) {
			$flows = array();
			foreach ( $in['flow_label'] as $i => $label ) {
				$label = sanitize_text_field( $label );
				if ( '' === $label ) {
					continue;
				}
				$flows[] = array(
					'id'       => sanitize_key( ! empty( $in['flow_id'][ $i ] ) ? $in['flow_id'][ $i ] : $label ),
					'label'    => $label,
					'keywords' => array_filter( array_map( 'trim', explode( ',', isset( $in['flow_keywords'][ $i ] ) ? sanitize_text_field( $in['flow_keywords'][ $i ] ) : '' ) ) ),
					'answer'   => sanitize_textarea_field( isset( $in['flow_answer'][ $i ] ) ? $in['flow_answer'][ $i ] : '' ),
				);
			}
			$values['bot_flows'] = $flows;
		}

		ABChat_Settings::update( $values );

		wp_safe_redirect( add_query_arg( array( 'page' => 'abchat-settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Apply a bundled site preset.
	 *
	 * @return void
	 */
	public function apply_preset() {
		if ( ! current_user_can( 'abchat_manage' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'abibitumi-chat' ) );
		}
		check_admin_referer( 'abchat_preset' );
		$slug = isset( $_POST['preset'] ) ? sanitize_file_name( wp_unslash( $_POST['preset'] ) ) : '';
		$ok   = ABChat_Presets::apply( $slug );
		$args = array( 'page' => 'abchat-settings' );
		$args[ $ok ? 'preset' : 'error' ] = $ok ? '1' : 'preset';
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Stream the current settings as a JSON download.
	 *
	 * @return void
	 */
	public function export_settings() {
		if ( ! current_user_can( 'abchat_manage' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'abibitumi-chat' ) );
		}
		check_admin_referer( 'abchat_export' );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = 'abibitumi-chat-' . sanitize_file_name( $host ? $host : 'settings' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo ABChat_Presets::export(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Import settings from an uploaded JSON file.
	 *
	 * @return void
	 */
	public function import_settings() {
		if ( ! current_user_can( 'abchat_manage' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'abibitumi-chat' ) );
		}
		check_admin_referer( 'abchat_import' );

		$ok = false;
		if ( ! empty( $_FILES['import_file']['tmp_name'] ) && is_uploaded_file( $_FILES['import_file']['tmp_name'] ) ) {
			$raw  = file_get_contents( $_FILES['import_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.Security.ValidatedSanitizedInput
			$data = json_decode( $raw, true );
			$ok   = ABChat_Presets::import( $data );
		}
		$args = array( 'page' => 'abchat-settings' );
		$args[ $ok ? 'imported' : 'error' ] = $ok ? '1' : 'import';
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Download one conversation and its messages as CSV.
	 *
	 * @return void
	 */
	public function export_conversation() {
		if ( ! current_user_can( ABCHAT_AGENT_CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'abibitumi-chat' ) );
		}
		check_admin_referer( 'abchat_export_conversation' );

		$conversation_id = isset( $_GET['conversation_id'] ) ? absint( wp_unslash( $_GET['conversation_id'] ) ) : 0;
		$conversation    = ABChat_DB::get_conversation( $conversation_id );
		if ( ! $conversation ) {
			wp_die( esc_html__( 'Conversation not found.', 'abibitumi-chat' ) );
		}

		$messages = ABChat_DB::get_messages( $conversation_id );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="abibitumi-chat-conversation-' . $conversation_id . '.csv"' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $output ) {
			wp_die( esc_html__( 'Could not create export.', 'abibitumi-chat' ) );
		}

		fputcsv( $output, array( 'conversation_id', 'message_id', 'created_at', 'sender_type', 'sender_name', 'message_type', 'body', 'attachment_url', 'attachment_name', 'read_at' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( (array) $messages as $message ) {
			fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$output,
				array_map( array( __CLASS__, 'csv_safe_cell' ), array(
					$conversation_id,
					(int) $message->id,
					$message->created_at,
					$message->sender_type,
					$message->sender_name,
					$message->type,
					$message->body,
					$message->attachment_url,
					$message->attachment_name,
					$message->read_at,
				) )
			);
		}
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Neutralize spreadsheet formulas in untrusted CSV cells.
	 *
	 * @param mixed $value Exported value.
	 * @return string
	 */
	public static function csv_safe_cell( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Run the configured retention cleanup on demand.
	 *
	 * @return void
	 */
	public function run_cleanup() {
		if ( ! current_user_can( 'abchat_manage' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'abibitumi-chat' ) );
		}
		check_admin_referer( 'abchat_run_cleanup' );
		ABChat_Retention::run();
		wp_safe_redirect( add_query_arg( array( 'page' => 'abchat-settings', 'cleanup' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
