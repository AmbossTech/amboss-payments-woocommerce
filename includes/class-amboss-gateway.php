<?php

defined( 'ABSPATH' ) || exit;

/**
 * "Pay with Bitcoin". Owns the settings that Amboss_Cash_App_Gateway shares.
 */
class Amboss_Gateway extends WC_Payment_Gateway {

	public const ID = 'amboss';

	public const SETTINGS_OPTION = 'woocommerce_' . self::ID . '_settings';

	private const DEFAULT_API_URL = 'https://app.amboss.tech/graphql';
	private const DASHBOARD_URL   = 'https://rails.amboss.tech/pay';

	// The wallet is looked up once when the settings are saved, so a slow or
	// failing API never delays cart and checkout page loads.
	private const OPTION_WALLET_ASSET   = 'amboss_wallet_asset';
	private const OPTION_ENVIRONMENT_ID = 'amboss_environment_id';

	// Mirrors what create_receive accepts as fiat_amount: USD, paid into a BTC or
	// USD stablecoin wallet.
	private const SUPPORTED_CURRENCY    = 'USD';
	private const BTC_ASSET_TYPE        = 'BASE_ASSET';
	private const USD_STABLECOIN_PREFIX = 'USD';

	private const MAX_DISCOUNT_PERCENT = 100;

	protected const OPTION_PAY_WITH_BITCOIN = 'pay_with_bitcoin';
	protected const OPTION_PAY_WITH_CASHAPP = 'pay_with_cashapp';

	// The settings checkbox that shows this gateway at checkout.
	protected const PAYMENT_OPTION = self::OPTION_PAY_WITH_BITCOIN;

	public const META_ATTEMPT        = '_amboss_attempt';
	public const META_TRANSACTION_ID = '_amboss_transaction_id';
	public const META_ORDER_TOTAL    = '_amboss_order_total';

	public function __construct() {
		$this->id                 = static::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Amboss Payments', 'amboss-payments' );
		$this->method_description = __( 'Accept Bitcoin Lightning and Cash App payments through the Amboss hosted checkout.', 'amboss-payments' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->payment_title();
		$this->description = $this->payment_description() . self::discount_note();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public static function is_amboss_method( string $payment_method ): bool {
		return in_array( $payment_method, array( self::ID, Amboss_Cash_App_Gateway::ID ), true );
	}

	public static function discount_percent(): float {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		return (float) ( $settings['discount_percent'] ?? 0 );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'                     => array(
				'title'   => __( 'Enable', 'amboss-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Amboss Payments', 'amboss-payments' ),
				'default' => 'no',
			),
			self::OPTION_PAY_WITH_BITCOIN => array(
				'title'   => __( 'Payment options', 'amboss-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Pay with Bitcoin', 'amboss-payments' ),
				'default' => 'yes',
			),
			self::OPTION_PAY_WITH_CASHAPP => array(
				'type'        => 'checkbox',
				'label'       => __( 'Pay with Cash App', 'amboss-payments' ),
				'default'     => 'no',
				'description' => __( 'Each option is a separate payment method at checkout. Pay with Cash App opens the checkout with Cash App selected. It needs a BTC wallet.', 'amboss-payments' ),
			),
			'discount_percent'            => array(
				'title'             => __( 'Discount (%)', 'amboss-payments' ),
				'type'              => 'number',
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => (string) self::MAX_DISCOUNT_PERCENT,
					'step' => '0.01',
				),
				'description'       => __( 'Optional. Customers who pay with Amboss get this percentage off the products. Shipping is not discounted. Set 0 for no discount.', 'amboss-payments' ),
			),
			'api_key'                     => array(
				'title'       => __( 'API key', 'amboss-payments' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: link to the API keys page */
					__( 'In the Amboss dashboard, %s. Give the key Wallets read access and Payments write access. You can limit the key to the wallet below. Copy the key and paste it here.', 'amboss-payments' ),
					$this->dashboard_link( 'api-keys/create', __( 'create an API key', 'amboss-payments' ) )
				),
			),
			'wallet_id'                   => array(
				'title'       => __( 'Wallet ID', 'amboss-payments' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: link to the wallets page */
					__( 'The wallet that receives the payments. In the Amboss dashboard, %s and open the wallet. Copy the value under "Wallet ID".', 'amboss-payments' ),
					$this->dashboard_link( 'wallets', __( 'go to Wallets', 'amboss-payments' ) )
				),
			),
			'webhook_secret'              => array(
				'title'       => __( 'Webhook secret', 'amboss-payments' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: 1: link to the webhook page, 2: webhook URL */
					__( 'Webhooks tell this store when an order is paid. In the Amboss dashboard, %1$s with this URL: %2$s Then copy the "Signing secret" and paste it here.', 'amboss-payments' ),
					$this->dashboard_link( 'webhooks/create', __( 'create a webhook endpoint', 'amboss-payments' ) ),
					'<code>' . esc_html( rest_url( Amboss_Webhook::ROUTE_NAMESPACE . Amboss_Webhook::ROUTE ) ) . '</code>'
				),
			),
			// admin_options() fills the options from the API.
			'style_id'                    => array(
				'title'   => __( 'Checkout style', 'amboss-payments' ),
				'type'    => 'select',
				'options' => array( '' => __( 'Amboss default', 'amboss-payments' ) ),
			),
			'api_url'                     => array(
				'title'   => __( 'API URL', 'amboss-payments' ),
				'type'    => 'text',
				'default' => self::DEFAULT_API_URL,
			),
		);
	}

	public function admin_options(): void {
		$this->form_fields['style_id'] = array_merge( $this->form_fields['style_id'], $this->checkout_style_choices() );
		parent::admin_options();
	}

	/**
	 * @param string|null $value Posted value.
	 */
	public function validate_discount_percent_field( string $key, $value ): string {
		$percent = min( max( (float) $value, 0 ), self::MAX_DISCOUNT_PERCENT );
		return wc_format_decimal( $percent, 2, true );
	}

	public function process_admin_options(): bool {
		$saved = parent::process_admin_options();
		delete_option( self::OPTION_WALLET_ASSET );
		delete_option( self::OPTION_ENVIRONMENT_ID );

		if ( 'yes' !== $this->get_option( 'enabled' ) ) {
			return $saved;
		}

		$pay_with_bitcoin = $this->is_option_on( self::OPTION_PAY_WITH_BITCOIN );
		$pay_with_cashapp = $this->is_option_on( self::OPTION_PAY_WITH_CASHAPP );
		if ( ! $pay_with_bitcoin && ! $pay_with_cashapp ) {
			WC_Admin_Settings::add_error( __( 'Amboss: select at least one payment option.', 'amboss-payments' ) );
			return $saved;
		}

		try {
			$wallet = $this->client()->get_wallet( $this->get_option( 'wallet_id' ) );
			$asset  = $wallet['asset'];
			update_option( self::OPTION_WALLET_ASSET, $asset, false );
			update_option( self::OPTION_ENVIRONMENT_ID, $wallet['environment_id'], false );
		} catch ( Throwable $error ) {
			WC_Admin_Settings::add_error( __( 'Amboss: cannot load the wallet. Check the API key and wallet ID.', 'amboss-payments' ) . ' ' . $error->getMessage() );
			return $saved;
		}

		if ( $pay_with_bitcoin && ! ( $this->is_usd_store() && self::accepts_bitcoin_asset( $asset ) ) ) {
			$this->add_asset_error( $asset, __( 'Pay with Bitcoin', 'amboss-payments' ) );
		}
		if ( $pay_with_cashapp && ! ( $this->is_usd_store() && self::is_btc( $asset ) ) ) {
			$this->add_asset_error( $asset, __( 'Pay with Cash App', 'amboss-payments' ) );
		}

		return $saved;
	}

	public function is_available(): bool {
		$asset = $this->wallet_asset();
		return parent::is_available()
			&& $this->is_option_on( static::PAYMENT_OPTION )
			&& $this->is_configured()
			&& null !== $asset
			&& $this->is_usd_store()
			&& $this->accepts_asset( $asset );
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

		$checkout_url = $this->checkout_url( $checkout_url );

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

	protected function payment_title(): string {
		return __( 'Pay with Bitcoin', 'amboss-payments' );
	}

	protected function payment_description(): string {
		return __( 'Pay with Bitcoin over the Lightning Network. You go to a secure Amboss checkout page to pay.', 'amboss-payments' );
	}

	/**
	 * @param array{symbol: string, type: string} $asset
	 */
	protected function accepts_asset( array $asset ): bool {
		return self::accepts_bitcoin_asset( $asset );
	}

	protected function checkout_url( string $url ): string {
		return $url;
	}

	/**
	 * @param array{symbol: string, type: string} $asset
	 */
	protected static function is_btc( array $asset ): bool {
		return self::BTC_ASSET_TYPE === $asset['type'];
	}

	/**
	 * @param array{symbol: string, type: string} $asset
	 */
	private static function accepts_bitcoin_asset( array $asset ): bool {
		return self::is_btc( $asset ) || 0 === strpos( $asset['symbol'], self::USD_STABLECOIN_PREFIX );
	}

	private static function discount_note(): string {
		$percent = self::discount_percent();
		if ( $percent <= 0 ) {
			return '';
		}
		/* translators: %s: discount percentage */
		return ' ' . sprintf( __( 'Get %s%% off.', 'amboss-payments' ), wc_format_decimal( $percent, 2, true ) );
	}

	/**
	 * Links to a page of the wallet's environment in the Amboss dashboard. Before
	 * the first save the environment is unknown, so it links to the dashboard home.
	 */
	private function dashboard_link( string $page, string $text ): string {
		$environment_id = (string) get_option( self::OPTION_ENVIRONMENT_ID, '' );
		$url            = '' === $environment_id ? self::DASHBOARD_URL : self::DASHBOARD_URL . '/' . rawurlencode( $environment_id ) . '/' . $page;
		return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html( $text ) );
	}

	/**
	 * @return array{options: array<string, string>, description: string}
	 */
	private function checkout_style_choices(): array {
		$options  = array( '' => __( 'Amboss default', 'amboss-payments' ) );
		$selected = (string) $this->get_option( 'style_id' );
		if ( '' !== $selected ) {
			// Keeps the saved style selectable when the list cannot load.
			$options[ $selected ] = $selected;
		}

		if ( '' === $this->get_option( 'api_key' ) ) {
			return array(
				'options'     => $options,
				'description' => __( 'Save your API key to choose a checkout style.', 'amboss-payments' ),
			);
		}

		try {
			$styles = $this->client()->list_checkout_styles();
		} catch ( Throwable $error ) {
			return array(
				'options'     => $options,
				'description' => __( 'Cannot load the checkout styles.', 'amboss-payments' ) . ' ' . esc_html( $error->getMessage() ),
			);
		}

		$create_link = $this->dashboard_link( 'checkout/branding/new', __( 'Create a checkout style', 'amboss-payments' ) );
		if ( array() === $styles ) {
			return array(
				'options'     => $options,
				/* translators: %s: link to create a checkout style */
				'description' => sprintf( __( 'You have no checkout styles. %s to add your logo and colors, then reload this page.', 'amboss-payments' ), $create_link ),
			);
		}

		foreach ( $styles as $style ) {
			$options[ $style['id'] ] = $style['name'];
		}
		return array(
			'options'     => $options,
			/* translators: %s: link to create a checkout style */
			'description' => sprintf( __( 'The logo and colors of the checkout page. %s.', 'amboss-payments' ), $create_link ),
		);
	}

	private function is_option_on( string $option ): bool {
		return 'yes' === $this->get_option( $option );
	}

	/**
	 * @param array{symbol: string} $asset
	 */
	private function add_asset_error( array $asset, string $payment_option ): void {
		/* translators: 1: wallet asset symbol, 2: payment option name, 3: store currency */
		WC_Admin_Settings::add_error( sprintf( __( 'Amboss: a %1$s wallet cannot take "%2$s" payments in a store priced in %3$s.', 'amboss-payments' ), $asset['symbol'], $payment_option, get_woocommerce_currency() ) );
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

	private function is_usd_store(): bool {
		return self::SUPPORTED_CURRENCY === get_woocommerce_currency();
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
