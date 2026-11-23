<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Shows the gateway in the block-based checkout. The classic checkout uses the
 * gateway class directly.
 */
final class Amboss_Blocks extends AbstractPaymentMethodType {

	protected $name = Amboss_Gateway::ID;

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . Amboss_Gateway::ID . '_settings', array() );
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
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			AMBOSS_VERSION,
			true
		);
		return array( 'amboss-blocks' );
	}

	/**
	 * @return array{title: string, description: string}
	 */
	public function get_payment_method_data(): array {
		$gateway = $this->gateway();
		return array(
			'title'       => $gateway ? $gateway->get_title() : '',
			'description' => (string) $this->get_setting( 'description' ),
		);
	}

	private function gateway(): ?Amboss_Gateway {
		$gateway = WC()->payment_gateways()->payment_gateways()[ Amboss_Gateway::ID ] ?? null;
		return $gateway instanceof Amboss_Gateway ? $gateway : null;
	}
}
