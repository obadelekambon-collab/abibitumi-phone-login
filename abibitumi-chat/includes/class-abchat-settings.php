<?php
/**
 * Centralised settings store. All options live under a single wp_option
 * key so the plugin is trivially portable across sites (abibitumi.com,
 * repatriatetoghana.com, decadeofourrepatriation.com …).
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Settings {

	const OPTION_KEY = 'abchat_settings';

	/**
	 * Cached settings for the current request.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Default settings — also the schema for the settings page.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Branding / appearance.
			'enabled'            => 1,
			'brand_name'         => get_bloginfo( 'name' ),
			'welcome_title'      => __( 'Hi there! 👋', 'abibitumi-chat' ),
			'welcome_subtitle'   => __( 'How can we help you today?', 'abibitumi-chat' ),
			'primary_color'      => '#0b7d3e',
			'text_color'         => '#ffffff',
			'position'           => 'right', // right|left.
			'launcher_icon'      => 'chat', // chat|message|help.
			'avatar_url'         => '',
			'greeting_delay'     => 3, // seconds before proactive greeting.

			// Pre-chat form.
			'prechat_enabled'    => 1,
			'prechat_name'       => 1,
			'prechat_email'      => 1,
			'prechat_phone'      => 0,
			'prechat_message'    => __( 'Please leave your details and we will get back to you.', 'abibitumi-chat' ),

			// Behaviour.
			'sound_enabled'      => 1,
			'show_on_mobile'     => 1,
			'require_login'      => 0,
			'file_uploads'       => 1,
			'max_upload_mb'      => 5,
			'poll_interval'      => 4, // seconds (visitor widget).
			'agent_poll_interval'=> 3, // seconds (dashboard).
			'stream_enabled'     => 0, // Optional SSE; polling remains the fallback.
			'stream_duration'    => 25, // seconds per SSE connection.
			'transcript_email'   => 1, // email transcript to visitor on close.

			// Data lifecycle.
			'retention_enabled'  => 0,
			'retention_days'     => 365,
			'retention_batch'    => 100,

			// Abuse protection.
			'session_rate_limit' => 30,
			'session_rate_window' => 3600,

			// Office hours (24h, site timezone). Empty = always open.
			'office_hours_enabled' => 0,
			'office_hours'         => self::default_hours(),
			'offline_message'      => __( 'We are currently offline. Leave a message and we will email you back.', 'abibitumi-chat' ),

			// Chatbot.
			'bot_enabled'        => 1,
			'bot_name'           => __( 'Abena Bot', 'abibitumi-chat' ),
			'bot_greeting'       => __( 'Welcome! I can answer common questions or connect you with a person.', 'abibitumi-chat' ),
			'bot_fallback'       => __( 'Let me connect you with a team member.', 'abibitumi-chat' ),
			'bot_flows'          => self::default_flows(),
			'bot_ai_enabled'     => 0,
			'gemini_api_key'     => '',
			'gemini_model'       => 'gemini-2.5-flash',
			'bot_rate_limit'     => 10,
			'bot_rate_window'    => 60,

			// Notifications.
			'notify_email'       => get_option( 'admin_email' ),
			'notify_new_chat'    => 1,
			'notify_offline'     => 1,
			'push_enabled'       => 1,

			// Departments (routing).
			'departments'        => array(
				array( 'id' => 'general', 'name' => __( 'General', 'abibitumi-chat' ) ),
				array( 'id' => 'support', 'name' => __( 'Support', 'abibitumi-chat' ) ),
			),

			// PWA.
			'pwa_enabled'        => 1,
			'pwa_short_name'     => 'Chat',
			'pwa_theme_color'    => '#0b7d3e',
		);
	}

	/**
	 * Default office-hours grid.
	 *
	 * @return array
	 */
	protected static function default_hours() {
		$days  = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
		$hours = array();
		foreach ( $days as $d ) {
			$hours[ $d ] = array(
				'open'  => in_array( $d, array( 'sat', 'sun' ), true ) ? 0 : 1,
				'from'  => '09:00',
				'to'    => '17:00',
			);
		}
		return $hours;
	}

	/**
	 * Starter chatbot flows (keyword → answer, plus quick replies).
	 *
	 * @return array
	 */
	protected static function default_flows() {
		return array(
			array(
				'id'       => 'pricing',
				'label'    => __( 'Membership & pricing', 'abibitumi-chat' ),
				'keywords' => array( 'price', 'pricing', 'cost', 'membership', 'subscribe', 'plan' ),
				'answer'   => __( 'You can view all membership options on our Join page. Would you like the link?', 'abibitumi-chat' ),
			),
			array(
				'id'       => 'courses',
				'label'    => __( 'Courses & classes', 'abibitumi-chat' ),
				'keywords' => array( 'course', 'class', 'twi', 'language', 'lesson', 'learn' ),
				'answer'   => __( 'We offer live and self-paced classes. Tell me which language or topic interests you.', 'abibitumi-chat' ),
			),
			array(
				'id'       => 'human',
				'label'    => __( 'Talk to a person', 'abibitumi-chat' ),
				'keywords' => array( 'human', 'agent', 'person', 'support', 'help', 'representative' ),
				'answer'   => '__HANDOFF__',
			),
		);
	}

	/**
	 * Get all settings, merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION_KEY, array() );
			$saved       = is_array( $saved ) ? $saved : array();
			self::$cache = wp_parse_args( $saved, self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Persist settings (merged over existing).
	 *
	 * @param array $values Values to save.
	 * @return void
	 */
	public static function update( array $values ) {
		$current     = self::all();
		$merged      = array_merge( $current, $values );
		self::$cache = $merged;
		update_option( self::OPTION_KEY, $merged, false );
	}

	/**
	 * Public (front-end safe) subset of settings for the widget bootstrap.
	 *
	 * @return array
	 */
	public static function public_config() {
		$s = self::all();
		return array(
			'enabled'         => (bool) $s['enabled'],
			'brandName'       => $s['brand_name'],
			'welcomeTitle'    => $s['welcome_title'],
			'welcomeSubtitle' => $s['welcome_subtitle'],
			'primaryColor'    => $s['primary_color'],
			'textColor'       => $s['text_color'],
			'position'        => $s['position'],
			'launcherIcon'    => $s['launcher_icon'],
			'avatarUrl'       => $s['avatar_url'],
			'greetingDelay'   => (int) $s['greeting_delay'],
			'prechat'         => array(
				'enabled' => (bool) $s['prechat_enabled'],
				'name'    => (bool) $s['prechat_name'],
				'email'   => (bool) $s['prechat_email'],
				'phone'   => (bool) $s['prechat_phone'],
				'message' => $s['prechat_message'],
			),
			'soundEnabled'    => (bool) $s['sound_enabled'],
			'showOnMobile'    => (bool) $s['show_on_mobile'],
			'requireLogin'    => (bool) $s['require_login'],
			'fileUploads'     => (bool) $s['file_uploads'],
			'maxUploadMb'     => (int) $s['max_upload_mb'],
			'pollInterval'    => max( 2, (int) $s['poll_interval'] ),
			'streamEnabled'   => (bool) $s['stream_enabled'],
			'botEnabled'      => (bool) $s['bot_enabled'],
			'botName'         => $s['bot_name'],
			'botGreeting'     => $s['bot_greeting'],
			'botFlows'        => array_map(
				function ( $f ) {
					return array(
						'id'    => $f['id'],
						'label' => $f['label'],
					);
				},
				(array) $s['bot_flows']
			),
			'departments'     => $s['departments'],
			'isOpen'          => self::is_within_office_hours(),
			'offlineMessage'  => $s['offline_message'],
			'pushEnabled'     => (bool) $s['push_enabled'],
			'pwaEnabled'      => (bool) $s['pwa_enabled'],
		);
	}

	/**
	 * Determine whether the current moment is inside configured office hours.
	 *
	 * @return bool
	 */
	public static function is_within_office_hours() {
		$s = self::all();
		if ( empty( $s['office_hours_enabled'] ) ) {
			return true;
		}
		$now     = current_time( 'timestamp' ); // Site timezone.
		$day_map = array( 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun' );
		$day_key = $day_map[ (int) wp_date( 'N', $now ) ];
		$hours   = isset( $s['office_hours'][ $day_key ] ) ? $s['office_hours'][ $day_key ] : null;

		if ( ! $hours || empty( $hours['open'] ) ) {
			return false;
		}
		$cur  = (int) wp_date( 'Hi', $now );
		$from = (int) str_replace( ':', '', $hours['from'] );
		$to   = (int) str_replace( ':', '', $hours['to'] );
		return ( $cur >= $from && $cur <= $to );
	}
}
