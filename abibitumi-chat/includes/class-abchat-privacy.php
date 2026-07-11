<?php
/**
 * WordPress personal-data exporter and eraser integration.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Privacy {

	/**
	 * Register privacy hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Register the personal-data exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['abibitumi-chat'] = array(
			'exporter_friendly_name' => __( 'Abibitumi Chat', 'abibitumi-chat' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal-data eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['abibitumi-chat'] = array(
			'eraser_friendly_name' => __( 'Abibitumi Chat', 'abibitumi-chat' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export chat data associated with an email address.
	 *
	 * @param string $email_address Request email.
	 * @param int    $page          Export page.
	 * @return array
	 */
	public function export( $email_address, $page = 1 ) {
		if ( 1 !== (int) $page ) {
			return array( 'data' => array(), 'done' => true );
		}

		$data = array();
		foreach ( ABChat_DB::privacy_records( sanitize_email( $email_address ) ) as $visitor ) {
			$data[] = array(
				'group_id'    => 'abibitumi-chat',
				'group_label' => __( 'Chat visitor profile', 'abibitumi-chat' ),
				'item_id'     => 'abchat-visitor-' . (int) $visitor->id,
				'data'        => array(
					array( 'name' => __( 'Name', 'abibitumi-chat' ), 'value' => $visitor->name ),
					array( 'name' => __( 'Email', 'abibitumi-chat' ), 'value' => $visitor->email ),
					array( 'name' => __( 'Phone', 'abibitumi-chat' ), 'value' => $visitor->phone ),
					array( 'name' => __( 'IP address', 'abibitumi-chat' ), 'value' => $visitor->ip ),
					array( 'name' => __( 'User agent', 'abibitumi-chat' ), 'value' => $visitor->user_agent ),
					array( 'name' => __( 'First seen', 'abibitumi-chat' ), 'value' => $visitor->first_seen ),
					array( 'name' => __( 'Last seen', 'abibitumi-chat' ), 'value' => $visitor->last_seen ),
				),
			);

			foreach ( (array) $visitor->conversations as $conversation ) {
				$transcript = array();
				foreach ( (array) $conversation->messages as $message ) {
					$transcript[] = sprintf( '[%1$s] %2$s: %3$s', $message->created_at, $message->sender_name ? $message->sender_name : $message->sender_type, $message->body );
				}
				$data[] = array(
					'group_id'    => 'abibitumi-chat',
					'group_label' => __( 'Chat conversations', 'abibitumi-chat' ),
					'item_id'     => 'abchat-conversation-' . (int) $conversation->id,
					'data'        => array(
						array( 'name' => __( 'Created', 'abibitumi-chat' ), 'value' => $conversation->created_at ),
						array( 'name' => __( 'Department', 'abibitumi-chat' ), 'value' => $conversation->department ),
						array( 'name' => __( 'Status', 'abibitumi-chat' ), 'value' => $conversation->status ),
						array( 'name' => __( 'Transcript', 'abibitumi-chat' ), 'value' => implode( "\n", $transcript ) ),
					),
				);
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Erase chat data associated with an email address.
	 *
	 * @param string $email_address Request email.
	 * @param int    $page          Eraser page.
	 * @return array
	 */
	public function erase( $email_address, $page = 1 ) {
		$removed = 0;
		if ( 1 === (int) $page ) {
			$removed = ABChat_DB::erase_privacy_records( sanitize_email( $email_address ) );
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
