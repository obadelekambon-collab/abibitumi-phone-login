<?php
/**
 * Site presets & settings portability. Ships ready-made branding/bot
 * configurations for each property (abibitumi.com,
 * repatriatetoghana.com, decadeofourrepatriation.com) and provides
 * import / export so the same plugin deploys to every site in one action.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Presets {

	/**
	 * Presets directory.
	 *
	 * @return string
	 */
	public static function dir() {
		return ABCHAT_DIR . 'presets/';
	}

	/**
	 * List available presets as slug => label.
	 *
	 * @return array
	 */
	public static function available() {
		$out   = array();
		$files = glob( self::dir() . '*.json' );
		foreach ( (array) $files as $file ) {
			$slug = basename( $file, '.json' );
			$data = self::load_raw( $slug );
			if ( null === $data ) {
				continue;
			}
			$out[ $slug ] = isset( $data['_label'] ) ? $data['_label'] : $slug;
		}
		return $out;
	}

	/**
	 * Read and decode a preset file (raw, including meta keys).
	 *
	 * @param string $slug Preset slug.
	 * @return array|null
	 */
	protected static function load_raw( $slug ) {
		$slug = sanitize_file_name( $slug );
		$file = self::dir() . $slug . '.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Load a preset's settings (meta keys stripped).
	 *
	 * @param string $slug Preset slug.
	 * @return array|null
	 */
	public static function load( $slug ) {
		$data = self::load_raw( $slug );
		if ( null === $data ) {
			return null;
		}
		return self::strip_meta( $data );
	}

	/**
	 * Apply a preset over the current settings.
	 *
	 * @param string $slug Preset slug.
	 * @return bool True on success.
	 */
	public static function apply( $slug ) {
		$settings = self::load( $slug );
		if ( null === $settings ) {
			return false;
		}
		ABChat_Settings::update( self::sanitize_import( $settings ) );
		return true;
	}

	/**
	 * Export current settings as a pretty JSON string.
	 *
	 * @return string
	 */
	public static function export() {
		$data = ABChat_Settings::all();
		$data = array( '_label' => get_bloginfo( 'name' ), '_site' => wp_parse_url( home_url(), PHP_URL_HOST ) ) + $data;
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Import settings from a decoded array (e.g. an uploaded export file).
	 *
	 * @param array $data Decoded settings.
	 * @return bool
	 */
	public static function import( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}
		ABChat_Settings::update( self::sanitize_import( self::strip_meta( $data ) ) );
		return true;
	}

	/**
	 * Remove underscore-prefixed meta keys.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	protected static function strip_meta( array $data ) {
		foreach ( array_keys( $data ) as $k ) {
			if ( is_string( $k ) && '_' === substr( $k, 0, 1 ) ) {
				unset( $data[ $k ] );
			}
		}
		return $data;
	}

	/**
	 * Whitelist + lightly sanitize imported settings against the known schema.
	 *
	 * @param array $data Incoming settings.
	 * @return array
	 */
	protected static function sanitize_import( array $data ) {
		$allowed = array_keys( ABChat_Settings::defaults() );
		$clean   = array();
		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			if ( is_string( $value ) ) {
				// Preserve multi-line values (messages) but strip tags.
				$clean[ $key ] = wp_kses_post( $value );
			} else {
				$clean[ $key ] = $value; // Arrays/ints pass through; consumers cast on read.
			}
		}
		return $clean;
	}
}

/**
 * WP-CLI: `wp abchat apply-preset <slug>`, `wp abchat list-presets`,
 * `wp abchat export [--file=<path>]`, `wp abchat import <file>`.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class ABChat_CLI {

		/**
		 * List bundled presets.
		 *
		 * ## EXAMPLES
		 *     wp abchat list-presets
		 *
		 * @subcommand list-presets
		 */
		public function list_presets() {
			foreach ( ABChat_Presets::available() as $slug => $label ) {
				WP_CLI::line( sprintf( '%-28s %s', $slug, $label ) );
			}
		}

		/**
		 * Apply a bundled preset to this site.
		 *
		 * ## OPTIONS
		 * <slug>
		 * : The preset slug (see `wp abchat list-presets`).
		 *
		 * ## EXAMPLES
		 *     wp abchat apply-preset repatriatetoghana
		 *
		 * @subcommand apply-preset
		 *
		 * @param array $args Positional args.
		 */
		public function apply_preset( $args ) {
			$slug = isset( $args[0] ) ? $args[0] : '';
			if ( ABChat_Presets::apply( $slug ) ) {
				WP_CLI::success( "Applied preset: {$slug}" );
			} else {
				WP_CLI::error( "Preset not found: {$slug}" );
			}
		}

		/**
		 * Export current settings to stdout or a file.
		 *
		 * ## OPTIONS
		 * [--file=<path>]
		 * : Write JSON to this path instead of stdout.
		 *
		 * ## EXAMPLES
		 *     wp abchat export --file=abibitumi-chat-settings.json
		 *
		 * @param array $args       Positional args.
		 * @param array $assoc_args Flags.
		 */
		public function export( $args, $assoc_args ) {
			$json = ABChat_Presets::export();
			if ( ! empty( $assoc_args['file'] ) ) {
				file_put_contents( $assoc_args['file'], $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				WP_CLI::success( 'Exported to ' . $assoc_args['file'] );
			} else {
				WP_CLI::line( $json );
			}
		}

		/**
		 * Import settings from a JSON file (an export or a preset).
		 *
		 * ## OPTIONS
		 * <file>
		 * : Path to a JSON settings file.
		 *
		 * ## EXAMPLES
		 *     wp abchat import my-settings.json
		 *
		 * @param array $args Positional args.
		 */
		public function import( $args ) {
			$file = isset( $args[0] ) ? $args[0] : '';
			if ( ! is_readable( $file ) ) {
				WP_CLI::error( "Cannot read file: {$file}" );
			}
			$data = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ABChat_Presets::import( $data ) ) {
				WP_CLI::success( 'Settings imported.' );
			} else {
				WP_CLI::error( 'Invalid settings file.' );
			}
		}
	}

	WP_CLI::add_command( 'abchat', 'ABChat_CLI' );
}
