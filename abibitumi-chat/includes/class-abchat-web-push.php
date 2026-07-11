<?php
/**
 * Optional minishlink/web-push delivery adapter.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Web_Push {

	/**
	 * Register delivery only when the Composer dependency is available.
	 *
	 * @return void
	 */
	public function init() {
		if ( self::is_available() ) {
			add_action( 'abchat_dispatch_push', array( $this, 'dispatch' ), 10, 2 );
		}
	}

	/**
	 * Whether the Web Push dependency is loaded.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( '\\Minishlink\\WebPush\\WebPush' ) && class_exists( '\\Minishlink\\WebPush\\Subscription' );
	}

	/**
	 * Queue and send a payload to stored operator subscriptions.
	 *
	 * @param array $subscriptions Stored subscription rows.
	 * @param array $payload       Notification payload.
	 * @return void
	 */
	public function dispatch( $subscriptions, $payload ) {
		if ( ! self::is_available() ) {
			return;
		}

		$keys = ABChat_Notifications::vapid_keys();
		if ( empty( $keys['publicKey'] ) || empty( $keys['privateKey'] ) ) {
			return;
		}

		$auth = array(
			'VAPID' => array(
				'subject'    => home_url( '/' ),
				'publicKey'  => $keys['publicKey'],
				'privateKey' => $keys['privateKey'],
			),
		);
		$options = array(
			'TTL'         => 300,
			'urgency'     => 'high',
			'contentType' => 'application/json',
		);
		$web_push = new \Minishlink\WebPush\WebPush( $auth, $options );
		$web_push->setReuseVAPIDHeaders( true );

		foreach ( (array) $subscriptions as $row ) {
			$subscription = json_decode( $row->subscription, true );
			if ( ! is_array( $subscription ) || empty( $subscription['endpoint'] ) ) {
				ABChat_DB::delete_push_by_endpoint( $row->endpoint );
				continue;
			}
			try {
				$web_push->queueNotification(
					\Minishlink\WebPush\Subscription::create( $subscription ),
					wp_json_encode( $payload )
				);
			} catch ( \Throwable $exception ) {
				do_action( 'abchat_push_failed', $row->endpoint, $exception->getMessage() );
			}
		}

		foreach ( $web_push->flush() as $report ) {
			if ( $report->isSuccess() ) {
				continue;
			}
			$endpoint = method_exists( $report, 'getEndpoint' ) ? (string) $report->getEndpoint() : (string) $report->getRequest()->getUri();
			if ( $report->isSubscriptionExpired() ) {
				ABChat_DB::delete_push_by_endpoint( $endpoint );
			}
			do_action( 'abchat_push_failed', $endpoint, $report->getReason() );
		}
	}
}
