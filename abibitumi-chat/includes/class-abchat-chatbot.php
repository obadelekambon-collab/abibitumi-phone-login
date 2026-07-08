<?php
/**
 * Rule-based chatbot: keyword matching over configured flows, quick-reply
 * buttons, lead capture and human hand-off. Pluggable via the
 * `abchat_bot_response` filter so an AI backend can be swapped in later.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Chatbot {

	/**
	 * Generate a bot response to a visitor message or a chosen quick-reply.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $text            Free-text from the visitor.
	 * @param string $flow_id         Optional explicit flow id (quick reply click).
	 * @return array { reply:string, quickReplies:array, handoff:bool, messageId:int }
	 */
	public function respond( $conversation_id, $text, $flow_id = '' ) {
		$flows   = (array) ABChat_Settings::get( 'bot_flows' );
		$matched = null;

		if ( $flow_id ) {
			foreach ( $flows as $f ) {
				if ( $f['id'] === $flow_id ) {
					$matched = $f;
					break;
				}
			}
		}

		if ( ! $matched && '' !== trim( $text ) ) {
			$matched = $this->match_keywords( $text, $flows );
		}

		$handoff = false;
		$quick   = array();

		if ( $matched ) {
			$answer = $matched['answer'];
			if ( '__HANDOFF__' === $answer ) {
				$handoff = true;
				$reply   = ABChat_Settings::get( 'bot_fallback' );
			} else {
				$reply = $answer;
				$quick = $this->quick_replies( $flows );
			}
		} else {
			// No match: offer the menu.
			$reply = ABChat_Settings::get( 'bot_fallback' );
			$quick = $this->quick_replies( $flows );
			if ( '' !== trim( $text ) ) {
				$handoff = true; // Unmatched free text → route to a human.
			}
		}

		/**
		 * Filter the bot reply. Return a string (reply) or an array
		 * { reply, quickReplies, handoff } to fully override the engine —
		 * e.g. to call an LLM.
		 *
		 * @param string|array $reply           Current reply.
		 * @param string       $text            Visitor text.
		 * @param int          $conversation_id Conversation id.
		 */
		$filtered = apply_filters( 'abchat_bot_response', $reply, $text, $conversation_id );
		if ( is_array( $filtered ) ) {
			$reply   = isset( $filtered['reply'] ) ? $filtered['reply'] : $reply;
			$quick   = isset( $filtered['quickReplies'] ) ? $filtered['quickReplies'] : $quick;
			$handoff = isset( $filtered['handoff'] ) ? (bool) $filtered['handoff'] : $handoff;
		} elseif ( is_string( $filtered ) ) {
			$reply = $filtered;
		}

		$message_id = ABChat_DB::add_message( array(
			'conversation_id' => $conversation_id,
			'sender_type'     => 'bot',
			'sender_name'     => ABChat_Settings::get( 'bot_name' ),
			'body'            => $reply,
			'type'            => 'text',
			'meta'            => array( 'quickReplies' => $quick, 'handoff' => $handoff ),
		) );

		if ( $handoff ) {
			ABChat_DB::update_conversation( $conversation_id, array( 'status' => 'open' ) );
			ABChat_DB::add_message( array(
				'conversation_id' => $conversation_id,
				'sender_type'     => 'system',
				'body'            => __( 'A team member has been notified and will join shortly.', 'abibitumi-chat' ),
			) );
			do_action( 'abchat_bot_handoff', $conversation_id );
		}

		return array(
			'reply'        => $reply,
			'quickReplies' => $quick,
			'handoff'      => $handoff,
			'messageId'    => $message_id,
		);
	}

	/**
	 * Find the best flow whose keywords appear in the text.
	 *
	 * @param string $text  Visitor text.
	 * @param array  $flows Flows.
	 * @return array|null
	 */
	protected function match_keywords( $text, $flows ) {
		$haystack = ' ' . strtolower( wp_strip_all_tags( $text ) ) . ' ';
		$best     = null;
		$best_hit = 0;

		foreach ( $flows as $flow ) {
			$hits = 0;
			foreach ( (array) $flow['keywords'] as $kw ) {
				$kw = strtolower( trim( $kw ) );
				if ( '' !== $kw && false !== strpos( $haystack, $kw ) ) {
					$hits++;
				}
			}
			if ( $hits > $best_hit ) {
				$best_hit = $hits;
				$best     = $flow;
			}
		}
		return $best;
	}

	/**
	 * Build quick-reply buttons from flow labels.
	 *
	 * @param array $flows Flows.
	 * @return array
	 */
	protected function quick_replies( $flows ) {
		$out = array();
		foreach ( $flows as $f ) {
			$out[] = array( 'id' => $f['id'], 'label' => $f['label'] );
		}
		return $out;
	}
}
