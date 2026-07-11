<?php
/**
 * Optional Google Gemini backend for the chatbot.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Gemini {

	/**
	 * Register the chatbot response filter.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'abchat_bot_response', array( $this, 'filter_response' ), 10, 3 );
	}

	/**
	 * Ask Gemini for a response, retaining the rule response on any failure.
	 *
	 * @param string|array $reply           Rule-engine response.
	 * @param string       $text            Visitor message.
	 * @param int          $conversation_id Conversation ID.
	 * @return string|array
	 */
	public function filter_response( $reply, $text, $conversation_id ) {
		$text = trim( wp_strip_all_tags( $text ) );
		$key  = $this->api_key();
		if ( ! ABChat_Settings::get( 'bot_ai_enabled' ) || '' === $key || '' === $text ) {
			return $reply;
		}

		$model = preg_replace( '/[^a-zA-Z0-9._-]/', '', ABChat_Settings::get( 'gemini_model', 'gemini-2.5-flash' ) );
		if ( '' === $model ) {
			$model = 'gemini-2.5-flash';
		}
		$context = $this->journey_context( $conversation_id );

		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
			array(
				'timeout' => 12,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $key,
				),
				'body'    => wp_json_encode(
					array(
						'system_instruction' => array(
							'parts' => array(
								array(
									'text' => sprintf(
										/* translators: %s: website name. */
									__( 'You are a friendly sales and onboarding support agent for %1$s. Answer briefly. Use the visitor journey only as context; never claim they viewed something not listed. Help them take the next relevant purchase or onboarding step without pressure. If you are unsure or the visitor needs a person, reply exactly HANDOFF.%2$s', 'abibitumi-chat' ),
									get_bloginfo( 'name' ),
									$context
									),
								),
							),
						),
						'contents'           => array(
							array( 'parts' => array( array( 'text' => $text ) ) ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $reply;
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$output = isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ? trim( wp_strip_all_tags( $data['candidates'][0]['content']['parts'][0]['text'] ) ) : '';
		if ( '' === $output ) {
			return $reply;
		}

		if ( 'HANDOFF' === strtoupper( trim( $output, " \t\n\r\0\x0B.!" ) ) ) {
			return array(
				'reply'   => ABChat_Settings::get( 'bot_fallback' ),
				'handoff' => true,
			);
		}

		return array(
			'reply'   => $output,
			'handoff' => false,
		);
	}

	/** Build a small, privacy-safe page-journey context for the AI prompt. */
	protected function journey_context( $conversation_id ) {
		$conversation = ABChat_DB::get_conversation( (int) $conversation_id );
		if ( ! $conversation ) {
			return '';
		}
		$lines = array();
		foreach ( ABChat_DB::recent_page_views( $conversation->visitor_id, 5 ) as $view ) {
			$lines[] = '- ' . wp_strip_all_tags( $view->title ? $view->title : $view->url ) . ' (' . $view->url . ')';
		}
		return $lines ? "\nRecent visitor journey:\n" . implode( "\n", $lines ) : '';
	}

	/**
	 * Resolve the API key without exposing it to the front end.
	 *
	 * @return string
	 */
	protected function api_key() {
		if ( defined( 'ABCHAT_GEMINI_API_KEY' ) ) {
			return trim( (string) ABCHAT_GEMINI_API_KEY );
		}

		$environment_key = getenv( 'ABCHAT_GEMINI_API_KEY' );
		if ( false !== $environment_key && '' !== trim( $environment_key ) ) {
			return trim( $environment_key );
		}

		return trim( (string) ABChat_Settings::get( 'gemini_api_key', '' ) );
	}
}
