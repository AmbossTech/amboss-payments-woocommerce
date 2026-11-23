<?php

defined( 'ABSPATH' ) || exit;

/**
 * "Pay with Cash App". Uses the Amboss_Gateway settings and opens the hosted
 * checkout with Cash App selected.
 */
final class Amboss_Cash_App_Gateway extends Amboss_Gateway {

	public const ID = 'amboss_cashapp';

	protected const PAYMENT_OPTION = self::OPTION_PAY_WITH_CASHAPP;

	public function __construct() {
		parent::__construct();
		$this->method_title = __( 'Amboss Payments (Cash App)', 'amboss-payments' );

		// This gateway has no settings form, so a save here would clear the shared settings.
		remove_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function get_option_key(): string {
		return self::SETTINGS_OPTION;
	}

	public function admin_options(): void {
		printf(
			'<p>%s <a href="%s">%s</a></p>',
			esc_html__( 'Pay with Cash App uses the Amboss Payments settings.', 'amboss-payments' ),
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . parent::ID ) ),
			esc_html__( 'Open the Amboss Payments settings', 'amboss-payments' )
		);
	}

	protected function payment_title(): string {
		return __( 'Pay with Cash App', 'amboss-payments' );
	}

	protected function payment_description(): string {
		return __( 'Pay with Cash App. You go to a secure Amboss checkout page to pay.', 'amboss-payments' );
	}

	/**
	 * Cash App pays only bitcoin Lightning invoices.
	 *
	 * @param array{symbol: string, type: string} $asset
	 */
	protected function accepts_asset( array $asset ): bool {
		return self::is_btc( $asset );
	}

	protected function checkout_url( string $url ): string {
		return add_query_arg( 'wallet', 'cashapp', $url );
	}
}
