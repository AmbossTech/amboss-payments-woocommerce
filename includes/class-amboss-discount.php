<?php

defined( 'ABSPATH' ) || exit;

/**
 * Takes the settings discount off the cart when the customer picks an Amboss
 * payment method. The order total, and so the Amboss payment, includes it.
 */
final class Amboss_Discount {

	private const STORE_API_NAMESPACE = 'amboss-payments';

	public static function register(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'apply' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_classic_checkout_script' ) );
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_store_api_callback' ) );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( self::class, 'use_checkout_payment_method' ), 10, 2 );
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

		/* translators: %s: discount percentage */
		$cart->add_fee( sprintf( __( 'Amboss payment discount (%s%%)', 'amboss-payments' ), wc_format_decimal( $percent, 2, true ) ), -$discount, false );
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
