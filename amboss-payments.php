<?php
/**
 * Plugin Name:          Amboss Payments for WooCommerce
 * Description:          Accept Bitcoin Lightning and Cash App payments through the Amboss hosted checkout.
 * x-release-please-start-version
 * Version:              0.1.0
 * x-release-please-end
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.3
 * Author:               Amboss
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          amboss-payments
 */

defined( 'ABSPATH' ) || exit;

define( 'AMBOSS_VERSION', '0.1.0' ); // x-release-please-version
define( 'AMBOSS_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/functions.php';

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		require_once __DIR__ . '/includes/class-amboss-client.php';
		require_once __DIR__ . '/includes/class-amboss-gateway.php';
		require_once __DIR__ . '/includes/class-amboss-cash-app-gateway.php';
		require_once __DIR__ . '/includes/class-amboss-webhook.php';
		require_once __DIR__ . '/includes/class-amboss-discount.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = Amboss_Gateway::class;
				$gateways[] = Amboss_Cash_App_Gateway::class;
				return $gateways;
			}
		);
		add_action( 'rest_api_init', array( Amboss_Webhook::class, 'register_route' ) );
		Amboss_Discount::register();
	}
);

add_action(
	'woocommerce_blocks_payment_method_type_registration',
	static function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ): void {
		require_once __DIR__ . '/includes/class-amboss-blocks.php';
		$registry->register( new Amboss_Blocks( Amboss_Gateway::ID ) );
		$registry->register( new Amboss_Blocks( Amboss_Cash_App_Gateway::ID ) );
	}
);
