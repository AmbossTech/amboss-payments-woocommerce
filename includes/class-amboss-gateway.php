<?php

defined( 'ABSPATH' ) || exit;

final class Amboss_Gateway extends WC_Payment_Gateway {

	public const ID = 'amboss';

	private const DEFAULT_API_URL = 'https://app.amboss.tech/graphql';

	// The wallet asset is looked up once when the settings are saved, so a slow
	// or failing API never delays cart and checkout page loads.
	private const OPTION_WALLET_ASSET = 'amboss_wallet_asset';

	// Mirrors what create_receive accepts as fiat_amount: USD, paid into a BTC or
	// USD stablecoin wallet.
	private const SUPPORTED_CURRENCY = 'USD';
	private const BTC_ASSET_TYPE     = 'BASE_ASSET';
	private const USD_STABLECOINS    = array( 'USDT', 'USDC' );

	public const PAYMENT_LABEL_BITCOIN = 'bitcoin';
	public const PAYMENT_LABEL_CASHAPP = 'cashapp';

	public const META_ATTEMPT        = '_amboss_attempt';
	public const META_TRANSACTION_ID = '_amboss_transaction_id';
	public const META_ORDER_TOTAL    = '_amboss_order_total';

	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Amboss Payments', 'amboss-payments' );
		$this->method_description = __( 'Accept Lightning payments through the Amboss hosted checkout.', 'amboss-payments' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$labels            = $this->payment_label_options();
		$this->title       = $labels[ $this->get_option( 'payment_label' ) ] ?? $labels[ self::PAYMENT_LABEL_BITCOIN ];
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable', 'amboss-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Amboss Payments', 'amboss-payments' ),
				'default' => 'no',
			),
			'payment_label'  => array(
				'title'       => __( 'Payment method name', 'amboss-payments' ),
				'type'        => 'radio',
				'options'     => $this->payment_label_options(),
				'default'     => self::PAYMENT_LABEL_BITCOIN,
				'description' => __( 'Pay with Cash App opens the checkout with Cash App selected. It needs a BTC wallet.', 'amboss-payments' ),
			),
			'description'    => array(
				'title'   => __( 'Description', 'amboss-payments' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with the Lightning Network. You go to a secure Amboss checkout page to pay.', 'amboss-payments' ),
			),
			'api_key'        => array(
				'title'       => __( 'API key', 'amboss-payments' ),
				'type'        => 'password',
				'description' => __( 'Service API key from the Amboss dashboard.', 'amboss-payments' ),
			),
			'wallet_id'      => array(
				'title'       => __( 'Wallet ID', 'amboss-payments' ),
				'type'        => 'text',
				'description' => __( 'The wallet that receives the payments.', 'amboss-payments' ),
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook secret', 'amboss-payments' ),
				'type'        => 'password',
				/* translators: %s: webhook URL */
				'description' => sprintf( __( 'Add a webhook endpoint in the Amboss dashboard with this URL: %s', 'amboss-payments' ), '<code>' . esc_html( rest_url( Amboss_Webhook::ROUTE_NAMESPACE . Amboss_Webhook::ROUTE ) ) . '</code>' ),
			),
			'style_id'       => array(
				'title'       => __( 'Checkout style ID', 'amboss-payments' ),
				'type'        => 'text',
				'description' => __( 'Optional. Branding for the checkout page.', 'amboss-payments' ),
			),
			'api_url'        => array(
				'title'   => __( 'API URL', 'amboss-payments' ),
				'type'    => 'text',
				'default' => self::DEFAULT_API_URL,
			),
		);
	}

	public function process_admin_options(): bool {
		$saved = parent::process_admin_options();
		delete_option( self::OPTION_WALLET_ASSET );

		if ( 'yes' !== $this->get_option( 'enabled' ) ) {
			return $saved;
		}

		try {
			$asset = $this->client()->get_wallet( $this->get_option( 'wallet_id' ) )['asset'];
			update_option( self::OPTION_WALLET_ASSET, $asset, false );
		} catch ( Throwable $error ) {
			WC_Admin_Settings::add_error( __( 'Amboss: cannot load the wallet. Check the API key and wallet ID.', 'amboss-payments' ) . ' ' . $error->getMessage() );
			return $saved;
		}

		if ( ! $this->supports_asset( $asset ) ) {
			/* translators: 1: wallet asset symbol, 2: payment method name, 3: store currency */
			WC_Admin_Settings::add_error( sprintf( __( 'Amboss: a %1$s wallet cannot take "%2$s" payments in a store priced in %3$s.', 'amboss-payments' ), $asset['symbol'], $this->title, get_woocommerce_currency() ) );
		}

		return $saved;
	}

	public function is_available(): bool {
		$asset = $this->wallet_asset();
		return parent::is_available()
			&& $this->is_configured()
			&& null !== $asset
			&& $this->supports_asset( $asset );
	}

	/**
	 * @param int $order_id
	 * @return array{result: string, redirect?: string}
	 */
	public function process_payment( $order_id ): array {
		$order   = wc_get_order( $order_id );
		$attempt = (int) $order->get_meta( self::META_ATTEMPT ) + 1;
		$total   = wc_format_decimal( $order->get_total(), 2 );

		try {
			$transaction = $this->client()->create_receive(
				array(
					'wallet_id'       => $this->get_option( 'wallet_id' ),
					'fiat_amount'     => array(
						'amount'   => $total,
						'currency' => $order->get_currency(),
					),
					/* translators: %s: order number */
					'description'     => sprintf( __( 'Order #%s', 'amboss-payments' ), $order->get_order_number() ),
					// A new key per attempt: the API returns the existing transaction for a
					// repeated key, even when it has expired or the order total changed.
					'idempotency_key' => "wc-{$order->get_id()}-{$attempt}",
					'metadata'        => wp_json_encode( array( 'wc_order_id' => $order->get_id() ) ),
					'checkout'        => $this->checkout_input( $order ),
				)
			);
		} catch ( Throwable $error ) {
			$this->log_error( "Checkout creation failed for order {$order->get_id()}", $error );
			wc_add_notice( __( 'The payment could not be started. Please try again.', 'amboss-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$checkout_url = $transaction['checkout']['url'] ?? '';
		if ( '' === $checkout_url ) {
			$this->log_error( "No checkout URL for transaction {$transaction['id']}", null );
			wc_add_notice( __( 'The payment could not be started. Please try again.', 'amboss-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( $this->is_cash_app() ) {
			$checkout_url = add_query_arg( 'wallet', 'cashapp', $checkout_url );
		}

		$order->update_meta_data( self::META_ATTEMPT, $attempt );
		$order->update_meta_data( self::META_TRANSACTION_ID, $transaction['id'] );
		$order->update_meta_data( self::META_ORDER_TOTAL, $total );
		/* translators: %s: Amboss transaction ID */
		$order->add_order_note( sprintf( __( 'Amboss checkout created. Transaction %s.', 'amboss-payments' ), $transaction['id'] ) );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $checkout_url,
		);
	}

	public function webhook_secret(): string {
		return (string) $this->get_option( 'webhook_secret' );
	}

	/**
	 * Renders the 'radio' settings field type, which WC_Settings_API lacks.
	 *
	 * @param array{title: string, options: array<string, string>} $data
	 */
	public function generate_radio_html( string $key, array $data ): string {
		$field_key = $this->get_field_key( $key );
		$value     = $this->get_option( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'description' => '',
				'desc_tip'    => false,
			)
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo esc_html( $data['title'] ); ?></span></legend>
					<?php foreach ( $data['options'] as $option => $label ) : ?>
						<label>
							<input type="radio" name="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $option ); ?>" <?php checked( $value, $option ); ?> />
							<?php echo esc_html( $label ); ?>
						</label><br />
					<?php endforeach; ?>
					<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param string|null $value Posted value.
	 */
	public function validate_radio_field( string $key, $value ): string {
		$options = $this->form_fields[ $key ]['options'];
		return isset( $options[ $value ] ) ? (string) $value : (string) $this->form_fields[ $key ]['default'];
	}

	/**
	 * @return array<string, string>
	 */
	private function payment_label_options(): array {
		return array(
			self::PAYMENT_LABEL_BITCOIN => __( 'Pay with Bitcoin', 'amboss-payments' ),
			self::PAYMENT_LABEL_CASHAPP => __( 'Pay with Cash App', 'amboss-payments' ),
		);
	}

	private function is_cash_app(): bool {
		return self::PAYMENT_LABEL_CASHAPP === $this->get_option( 'payment_label' );
	}

	/**
	 * @return array<string, string>
	 */
	private function checkout_input( WC_Order $order ): array {
		$input = array(
			'success_url' => $this->get_return_url( $order ),
			'return_url'  => $order->get_checkout_payment_url(),
		);

		$style_id = (string) $this->get_option( 'style_id' );
		if ( '' !== $style_id ) {
			$input['style_id'] = $style_id;
		}

		return $input;
	}

	private function is_configured(): bool {
		return '' !== $this->get_option( 'api_key' )
			&& '' !== $this->get_option( 'wallet_id' )
			&& '' !== $this->get_option( 'webhook_secret' );
	}

	/**
	 * @param array{symbol: string, type: string} $asset
	 */
	private function supports_asset( array $asset ): bool {
		if ( self::SUPPORTED_CURRENCY !== get_woocommerce_currency() ) {
			return false;
		}

		$is_btc = self::BTC_ASSET_TYPE === $asset['type'];
		// Cash App pays only bitcoin Lightning invoices.
		if ( $this->is_cash_app() ) {
			return $is_btc;
		}

		return $is_btc || in_array( $asset['symbol'], self::USD_STABLECOINS, true );
	}

	/**
	 * @return array{id: string, symbol: string, precision: int, type: string}|null
	 */
	private function wallet_asset(): ?array {
		$asset = get_option( self::OPTION_WALLET_ASSET );
		return is_array( $asset ) ? $asset : null;
	}

	private function client(): Amboss_Client {
		return new Amboss_Client( $this->get_option( 'api_url', self::DEFAULT_API_URL ), $this->get_option( 'api_key' ) );
	}

	private function log_error( string $message, ?Throwable $error ): void {
		wc_get_logger()->error(
			$error ? "{$message}: {$error->getMessage()}" : $message,
			array( 'source' => self::ID )
		);
	}
}
