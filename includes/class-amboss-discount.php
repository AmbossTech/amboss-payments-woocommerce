<?php

defined( 'ABSPATH' ) || exit;

/**
 * Takes the settings discount off the cart when the customer picks an Amboss
 * payment method. The order total, and so the Amboss payment, includes it.
 */
final class Amboss_Discount {

	private const STORE_API_NAMESPACE = 'amboss-payments';
	private const FEE_ID              = 'amboss_discount';

	public static function register(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'apply' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_classic_checkout_script' ) );
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_store_api_callback' ) );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( self::class, 'use_checkout_payment_method' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( self::class, 'mark_fee_item' ), 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( self::class, 'limit_order_pay_gateways' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'enforce_on_store_api_order' ), 10, 2 );
		add_action( 'woocommerce_before_pay_action', array( self::class, 'enforce_on_order_pay' ) );
	}

	public static function apply( WC_Cart $cart ): void {
		$percent = Amboss_Gateway::discount_percent();
		if ( $percent <= 0 || ! WC()->session ) {
			return;
		}

		$payment_method = (string) WC()->session->get( 'chosen_payment_method' );
		if ( ! Amboss_Gateway::is_amboss_method( $payment_method ) ) {
			return;
		}

		$discount = round( (float) $cart->get_subtotal() * $percent / 100, wc_get_price_decimals() );
		if ( $discount <= 0 ) {
			return;
		}

		$cart->fees_api()->add_fee(
			array(
				'id'      => self::FEE_ID,
				/* translators: %s: discount percentage */
				'name'    => sprintf( __( 'Amboss payment discount (%s%%)', 'amboss-payments' ), wc_format_decimal( $percent, 2, true ) ),
				'amount'  => -$discount,
				'taxable' => false,
			)
		);
	}

	public static function mark_fee_item( WC_Order_Item_Fee $item, string $fee_key ): void {
		if ( self::FEE_ID === $fee_key ) {
			$item->add_meta_data( '_' . self::FEE_ID, 'yes', true );
		}
	}

	/**
	 * The order-pay page charges the saved order total and offers every gateway,
	 * so an order with the discount may only be paid with Amboss.
	 *
	 * @param array<string, WC_Payment_Gateway> $gateways
	 * @return array<string, WC_Payment_Gateway>
	 */
	public static function limit_order_pay_gateways( array $gateways ): array {
		if ( ! is_wc_endpoint_url( 'order-pay' ) ) {
			return $gateways;
		}
		$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
		if ( ! $order instanceof WC_Order || ! self::has_discount( $order ) ) {
			return $gateways;
		}
		return array_filter(
			$gateways,
			static function ( string $id ): bool {
				return Amboss_Gateway::is_amboss_method( $id );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	public static function enforce_on_store_api_order( WC_Order $order, WP_REST_Request $request ): void {
		self::remove_unless_amboss( $order, (string) $request->get_param( 'payment_method' ) );
	}

	public static function enforce_on_order_pay( WC_Order $order ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the nonce before this action.
		self::remove_unless_amboss( $order, sanitize_key( wp_unslash( $_POST['payment_method'] ?? '' ) ) );
	}

	/**
	 * Every path that pays an order ends here, so the discount stays on Amboss
	 * payments even when a request skips the filtered gateway list.
	 */
	private static function remove_unless_amboss( WC_Order $order, string $payment_method ): void {
		if ( Amboss_Gateway::is_amboss_method( $payment_method ) ) {
			return;
		}

		$removed = false;
		foreach ( $order->get_fees() as $item_id => $fee ) {
			if ( 'yes' === $fee->get_meta( '_' . self::FEE_ID ) ) {
				$order->remove_item( $item_id );
				$removed = true;
			}
		}
		if ( $removed ) {
			$order->calculate_totals( false );
		}
	}

	private static function has_discount( WC_Order $order ): bool {
		foreach ( $order->get_fees() as $fee ) {
			if ( 'yes' === $fee->get_meta( '_' . self::FEE_ID ) ) {
				return true;
			}
		}
		return false;
	}

	// The classic checkout does not recalculate totals when the payment method changes.
	public static function enqueue_classic_checkout_script(): void {
		if ( Amboss_Gateway::discount_percent() <= 0 || ! is_checkout() ) {
			return;
		}
		wp_add_inline_script(
			'wc-checkout',
			'jQuery( function ( $ ) { $( "form.checkout" ).on( "change", "input[name=payment_method]", function () { $( document.body ).trigger( "update_checkout" ); } ); } );'
		);
	}

	/**
	 * The Store API builds the order from the cart before it saves the request's
	 * payment method, so a stale session would add or drop the discount. This
	 * hook runs before it recalculates the cart.
	 */
	public static function use_checkout_payment_method( WC_Customer $customer, WP_REST_Request $request ): void {
		$payment_method = $request->get_param( 'payment_method' );
		if ( is_string( $payment_method ) && WC()->session ) {
			WC()->session->set( 'chosen_payment_method', sanitize_key( $payment_method ) );
		}
	}

	// The block checkout sends the chosen payment method here, see assets/blocks.js.
	public static function register_store_api_callback(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::STORE_API_NAMESPACE,
				'callback'  => static function ( array $data ): void {
					WC()->session->set( 'chosen_payment_method', sanitize_key( (string) ( $data['payment_method'] ?? '' ) ) );
				},
			)
		);
	}
}
