<?php
/**
 * PWA support: serves a web app manifest and a service worker from the
	 * site home path, and injects the
 * manifest link + theme-color meta tags.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_PWA {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'add_rewrites' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );
		add_action( 'wp_head', array( $this, 'head_tags' ) );
		add_action( 'admin_head', array( $this, 'head_tags' ) );
	}

	/**
	 * Register rewrite rules for the SW and manifest at the site root.
	 *
	 * @return void
	 */
	public function add_rewrites() {
		add_rewrite_rule( '^abchat-sw\.js$', 'index.php?abchat_pwa=sw', 'top' );
		add_rewrite_rule( '^abchat-manifest\.json$', 'index.php?abchat_pwa=manifest', 'top' );
	}

	/**
	 * Register the query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'abchat_pwa';
		return $vars;
	}

	/**
	 * Serve the SW or manifest when requested.
	 *
	 * @return void
	 */
	public function maybe_serve() {
		$what = get_query_var( 'abchat_pwa' );
		if ( ! $what ) {
			return;
		}
		if ( 'sw' === $what ) {
			$this->serve_service_worker();
		} elseif ( 'manifest' === $what ) {
			$this->serve_manifest();
		}
		exit;
	}

	/**
	 * Output the same-origin service worker JavaScript.
	 *
	 * @return void
	 */
	protected function serve_service_worker() {
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::scope_path() );
		// Stream the source through the home path so it can control the PWA.
		$file = ABCHAT_DIR . 'assets/js/sw.js';
		if ( is_readable( $file ) ) {
			echo file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Output the web app manifest JSON.
	 *
	 * @return void
	 */
	protected function serve_manifest() {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		$theme = ABChat_Settings::get( 'pwa_theme_color' );
		$name  = ABChat_Settings::get( 'brand_name' );
		$icon  = ABChat_Settings::get( 'avatar_url' );
		$icon  = $icon ? $icon : ABCHAT_URL . 'assets/img/icon-512.png';

		$manifest = array(
			'name'             => $name . ' — ' . __( 'Chat', 'abibitumi-chat' ),
			'short_name'       => ABChat_Settings::get( 'pwa_short_name' ),
			'description'      => __( 'Live chat & support', 'abibitumi-chat' ),
			'start_url'        => admin_url( 'admin.php?page=abchat&pwa=1' ),
			'scope'            => self::scope_path(),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#ffffff',
			'theme_color'      => $theme,
			'icons'            => array(
				array( 'src' => ABCHAT_URL . 'assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable' ),
				array( 'src' => ABCHAT_URL . 'assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ),
			),
		);

		echo wp_json_encode( $manifest );
	}

	/**
	 * Path controlled by the chat service worker.
	 *
	 * @return string
	 */
	public static function scope_path() {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return $path ? trailingslashit( $path ) : '/';
	}

	/**
	 * Inject manifest link + theme color into <head>.
	 *
	 * @return void
	 */
	public function head_tags() {
		if ( ! ABChat_Settings::get( 'pwa_enabled' ) ) {
			return;
		}
		printf(
			'<link rel="manifest" href="%s">' . "\n",
			esc_url( home_url( '/abchat-manifest.json' ) )
		);
		printf(
			'<meta name="theme-color" content="%s">' . "\n",
			esc_attr( ABChat_Settings::get( 'pwa_theme_color' ) )
		);
	}
}
