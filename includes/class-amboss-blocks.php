<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Shows an Amboss gateway in the block-based checkout. The classic checkout
 * uses the gateway class directly.
 */
final class Amboss_Blocks extends AbstractPaymentMethodType {

	public function __construct( string $gateway_id ) {
		$this->name = $gateway_id;
	}

	public function initialize(): void {
		$this->settings = get_option( Amboss_Gateway::SETTINGS_OPTION, array() );
	}

	public function is_active(): bool {
		$gateway = $this->gateway();
		return null !== $gateway && $gateway->is_available();
	}

	/**
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'amboss-blocks',
			plugins_url( 'assets/blocks.js', AMBOSS_PLUGIN_FILE ),
			array( 'wc-blocks-registry', 'wc-blocks-checkout', 'wc-settings', 'wp-data', 'wp-element', 'wp-html-entities' ),
			AMBOSS_VERSION,
			true
		);
		return array( 'amboss-blocks' );
	}

	/**
	 * @return array{title: string, description: string, has_discount: bool}
	 */
	public function get_payment_method_data(): array {
		$gateway = $this->gateway();
		return array(
			'title'       => $gateway ? $gateway->get_title() : '',
			'description' => $gateway ? $gateway->get_description() : '',
			'has_discount' => Amboss_Gateway::discount_percent() > 0,
		);
	}

	private function gateway(): ?Amboss_Gateway {
		$gateway = WC()->payment_gateways()->payment_gateways()[ $this->name ] ?? null;
		return $gateway instanceof Amboss_Gateway ? $gateway : null;
	}
}
