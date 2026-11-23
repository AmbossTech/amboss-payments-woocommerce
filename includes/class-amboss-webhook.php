<?php

defined( 'ABSPATH' ) || exit;

final class Amboss_Webhook {

	public const ROUTE_NAMESPACE = 'amboss/v1';
	public const ROUTE           = '/webhook';

	public static function register_route(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				// The HMAC signature is the authentication.
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$gateway = WC()->payment_gateways()->payment_gateways()[ Amboss_Gateway::ID ] ?? null;
		if ( ! $gateway instanceof Amboss_Gateway ) {
			return new WP_REST_Response( array( 'error' => 'gateway_unavailable' ), 503 );
		}

		try {
			$event = amboss_verify_webhook(
				$gateway->webhook_secret(),
				$request->get_body(),
				(string) $request->get_header( 'x-webhook-signature' ),
				(string) $request->get_header( 'x-webhook-timestamp' ),
				time()
			);
		} catch ( InvalidArgumentException $error ) {
			return new WP_REST_Response( array( 'error' => $error->getMessage() ), 401 );
		}

		$data     = $event['data'] ?? array();
		$order_id = (int) ( $data['metadata']['wc_order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		// The endpoint may also receive events for payments made outside this store.
		$is_our_order = $order instanceof WC_Order
			&& Amboss_Gateway::ID === $order->get_payment_method()
			&& 'receive' === ( $data['direction'] ?? '' );
		if ( ! $is_our_order ) {
			return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
		}

		self::apply_event( $order, (string) ( $event['event_type'] ?? '' ), $data );

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function apply_event( WC_Order $order, string $event_type, array $data ): void {
		$transaction_id = (string) ( $data['id'] ?? '' );
		$is_current     = $order->get_meta( Amboss_Gateway::META_TRANSACTION_ID ) === $transaction_id;

		if ( 'payment.completed' === $event_type ) {
			self::complete( $order, $transaction_id, $is_current, $data );
			return;
		}

		$is_terminal_failure = in_array( $event_type, array( 'payment.failed', 'payment.expired' ), true );
		if ( $is_terminal_failure && $is_current && $order->has_status( 'pending' ) ) {
			/* translators: 1: event type, 2: Amboss transaction ID */
			$order->update_status( 'failed', sprintf( __( 'Amboss %1$s for transaction %2$s.', 'amboss-payments' ), $event_type, $transaction_id ) );
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function complete( WC_Order $order, string $transaction_id, bool $is_current, array $data ): void {
		if ( $order->is_paid() ) {
			return;
		}

		// A customer can still pay an older checkout after starting a new one.
		// The money arrived, so flag it instead of dropping the event.
		if ( ! $is_current ) {
			/* translators: %s: Amboss transaction ID */
			$order->update_status( 'on-hold', sprintf( __( 'Amboss payment received for an older checkout (transaction %s). Check the amount and complete the order by hand.', 'amboss-payments' ), $transaction_id ) );
			return;
		}

		// The checkout was priced from the order total. If the order was edited
		// afterwards, the payment no longer covers it.
		$total_unchanged = $order->get_meta( Amboss_Gateway::META_ORDER_TOTAL ) === wc_format_decimal( $order->get_total(), 2 );
		if ( ! $total_unchanged ) {
			/* translators: %s: Amboss transaction ID */
			$order->update_status( 'on-hold', sprintf( __( 'The order total changed after the Amboss checkout was created (transaction %s). Check the payment by hand.', 'amboss-payments' ), $transaction_id ) );
			return;
		}

		$order->payment_complete( $transaction_id );
	}
}
