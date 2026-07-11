<?php
/**
 * Tidio CSV migration parser and importer.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Tidio_Importer {

	/** Maximum accepted CSV size (20 MiB). */
	const MAX_FILE_SIZE = 20971520;

	/**
	 * Parse a Tidio contacts export.
	 *
	 * @param string $path CSV path.
	 * @return array
	 */
	public static function parse_contacts( $path ) {
		return self::parse_csv( $path, array( 'email', 'name', 'phone', 'id', 'created_at', 'conversation_rating' ) );
	}

	/**
	 * Parse one or more Tidio transcript exports.
	 *
	 * @param string $path CSV path.
	 * @return array
	 */
	public static function parse_transcript( $path ) {
		return self::parse_csv( $path, array( 'message', 'body', 'sender', 'author', 'created_at', 'date', 'conversation_id' ) );
	}

	/**
	 * Parse and normalize a CSV file without writing data.
	 *
	 * @param string $path       CSV path.
	 * @param array  $known_keys Expected columns.
	 * @return array
	 */
	private static function parse_csv( $path, $known_keys ) {
		$result = array( 'headers' => array(), 'rows' => array(), 'errors' => array() );
		if ( ! is_readable( $path ) || filesize( $path ) > self::MAX_FILE_SIZE ) {
			$result['errors'][] = __( 'The CSV is missing, unreadable, or larger than 20 MB.', 'abibitumi-chat' );
			return $result;
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			$result['errors'][] = __( 'The CSV could not be opened.', 'abibitumi-chat' );
			return $result;
		}

		$header = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $header ) {
			$result['errors'][] = __( 'The CSV is empty.', 'abibitumi-chat' );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $result;
		}

		$header = array_map( array( __CLASS__, 'normalize_header' ), $header );
		$result['headers'] = $header;
		if ( ! array_intersect( $known_keys, $header ) ) {
			$result['errors'][] = __( 'The CSV does not contain recognizable Tidio columns.', 'abibitumi-chat' );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $result;
		}

		$line = 1;
		while ( false !== ( $values = fgetcsv( $handle ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$line++;
			if ( 1 === count( $values ) && '' === trim( (string) $values[0] ) ) {
				continue;
			}
			if ( count( $values ) !== count( $header ) ) {
				$result['errors'][] = sprintf( __( 'Row %d has the wrong number of columns.', 'abibitumi-chat' ), $line );
				continue;
			}
			$row = array_combine( $header, $values );
			$result['rows'][] = array_map( 'trim', $row );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $result;
	}

	/**
	 * Normalize vendor column labels.
	 *
	 * @param string $header Header value.
	 * @return string
	 */
	public static function normalize_header( $header ) {
		$header = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header );
		$header = strtolower( trim( $header ) );
		$header = preg_replace( '/[^a-z0-9]+/', '_', $header );
		return trim( $header, '_' );
	}

	/**
	 * Import parsed contacts, updating matches by email and otherwise by Tidio ID.
	 *
	 * @param array $rows Parsed rows.
	 * @param bool  $dry_run Validate only.
	 * @return array
	 */
	public static function import_contacts( $rows, $dry_run = false ) {
		$report = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
		foreach ( $rows as $index => $row ) {
			$email = sanitize_email( self::value( $row, array( 'email' ) ) );
			$name  = sanitize_text_field( self::value( $row, array( 'name' ) ) );
			$phone = sanitize_text_field( self::value( $row, array( 'phone' ) ) );
			$tidio = sanitize_text_field( self::value( $row, array( 'id', 'contact_id' ) ) );
			if ( '' === $email && '' === $name && '' === $phone && '' === $tidio ) {
				$report['skipped']++;
				continue;
			}
			$existing = ABChat_DB::find_imported_visitor( $email, $tidio );
			if ( $dry_run ) {
				$report[ $existing ? 'updated' : 'created' ]++;
				continue;
			}
			$data = array(
				'name'       => $name,
				'email'      => $email,
				'phone'      => $phone,
				'tidio_id'   => $tidio,
				'created_at' => self::mysql_date( self::value( $row, array( 'created_at', 'created' ) ) ),
				'last_seen'  => self::mysql_date( self::value( $row, array( 'last_seen' ) ) ),
			);
			if ( $existing ) {
				ABChat_DB::update_imported_visitor( $existing->id, $data );
				$report['updated']++;
			} elseif ( ABChat_DB::create_imported_visitor( $data ) ) {
				$report['created']++;
			} else {
				$report['errors'][] = sprintf( __( 'Contact row %d could not be imported.', 'abibitumi-chat' ), $index + 2 );
			}
		}
		return $report;
	}

	/**
	 * Import a parsed transcript as one closed historical conversation.
	 *
	 * @param array  $rows    Parsed rows.
	 * @param string $name    Source filename.
	 * @param bool   $dry_run Validate only.
	 * @return array
	 */
	public static function import_transcript( $rows, $name, $dry_run = false ) {
		$report = array( 'conversations' => 0, 'messages' => 0, 'skipped' => 0, 'errors' => array() );
		$messages = array();
		$contact  = array( 'name' => '', 'email' => '', 'phone' => '', 'tidio_id' => '' );
		foreach ( $rows as $row ) {
			if ( ! $contact['email'] ) {
				$contact['email'] = sanitize_email( self::value( $row, array( 'visitor_email', 'contact_email', 'email' ) ) );
			}
			if ( ! $contact['name'] ) {
				$contact['name'] = sanitize_text_field( self::value( $row, array( 'visitor_name', 'contact_name' ) ) );
			}
			if ( ! $contact['tidio_id'] ) {
				$contact['tidio_id'] = sanitize_text_field( self::value( $row, array( 'contact_id', 'visitor_id' ) ) );
			}
			$body = sanitize_textarea_field( self::value( $row, array( 'message', 'body', 'content', 'text' ) ) );
			if ( '' === $body ) {
				$report['skipped']++;
				continue;
			}
			$sender = sanitize_text_field( self::value( $row, array( 'sender', 'author', 'sender_name', 'from' ) ) );
			$role   = strtolower( self::value( $row, array( 'sender_type', 'type', 'role' ) ) );
			$messages[] = array(
				'body'        => $body,
				'sender_name' => $sender,
				'sender_type' => self::sender_type( $role, $sender ),
				'created_at'  => self::mysql_date( self::value( $row, array( 'created_at', 'date', 'timestamp', 'time' ) ) ),
			);
		}
		if ( ! $messages ) {
			$report['errors'][] = __( 'No transcript messages were found.', 'abibitumi-chat' );
			return $report;
		}
		if ( $dry_run ) {
			$report['conversations'] = 1;
			$report['messages']      = count( $messages );
			return $report;
		}
		$source_id = hash( 'sha256', wp_json_encode( $messages ) );
		if ( ABChat_DB::import_source_exists( $source_id ) ) {
			$report['skipped'] = count( $messages );
			return $report;
		}
		$conversation_id = ABChat_DB::create_imported_transcript( $messages, sanitize_file_name( $name ), $source_id, $contact );
		if ( ! $conversation_id ) {
			$report['errors'][] = __( 'The transcript could not be imported.', 'abibitumi-chat' );
			return $report;
		}
		$report['conversations'] = 1;
		$report['messages']      = count( $messages );
		return $report;
	}

	/** Get the first available value. */
	private static function value( $row, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return trim( (string) $row[ $key ] );
			}
		}
		return '';
	}

	/** Convert an imported date to the database timezone format. */
	private static function mysql_date( $value ) {
		$timestamp = $value ? strtotime( $value ) : false;
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql' );
	}

	/** Map loose Tidio sender labels to supported roles. */
	private static function sender_type( $role, $name ) {
		$value = strtolower( $role . ' ' . $name );
		if ( false !== strpos( $value, 'visitor' ) || false !== strpos( $value, 'customer' ) || false !== strpos( $value, 'contact' ) ) {
			return 'visitor';
		}
		if ( false !== strpos( $value, 'bot' ) || false !== strpos( $value, 'lyro' ) ) {
			return 'bot';
		}
		return 'operator';
	}
}
