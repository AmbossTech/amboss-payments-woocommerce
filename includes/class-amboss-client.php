<?php

defined( 'ABSPATH' ) || exit;

/**
 * Minimal client for the Amboss Payments GraphQL API. It covers only what the
 * hosted checkout needs; sends stay in the TypeScript SDK.
 */
final class Amboss_Client {

	private const GET_WALLET = 'query GetWallet($id: String!) {
		payment { wallet { find_one(id: $id) { id environment_id asset { id symbol precision type } } } }
	}';

	private const LIST_CHECKOUT_STYLES = 'query ListCheckoutStyles {
		payment { checkout_style { find_many { id name } } }
	}';

	private const CREATE_RECEIVE = 'mutation CreateReceive($input: CreateReceiveTransactionInput!) {
		payment { transaction { create_receive(input: $input) { id status checkout { id url } } } }
	}';

	private string $api_url;
	private string $api_key;

	public function __construct( string $api_url, string $api_key ) {
		$this->api_url = $api_url;
		$this->api_key = $api_key;
	}

	/**
	 * @return array{id: string, environment_id: string, asset: array{id: string, symbol: string, precision: int, type: string}}
	 */
	public function get_wallet( string $wallet_id ): array {
		$data = $this->request( self::GET_WALLET, array( 'id' => $wallet_id ) );
		return $data['payment']['wallet']['find_one'];
	}

	/**
	 * @return array<int, array{id: string, name: string}>
	 */
	public function list_checkout_styles(): array {
		$data = $this->request( self::LIST_CHECKOUT_STYLES, array() );
		return $data['payment']['checkout_style']['find_many'];
	}

	/**
	 * @param array<string, mixed> $input CreateReceiveTransactionInput.
	 * @return array{id: string, status: string, checkout: array{id: string, url: ?string}|null}
	 */
	public function create_receive( array $input ): array {
		$data = $this->request( self::CREATE_RECEIVE, array( 'input' => $input ) );
		return $data['payment']['transaction']['create_receive'];
	}

	/**
	 * @param array<string, mixed> $variables
	 * @return array<string, mixed>
	 * @throws RuntimeException On transport, HTTP or GraphQL errors.
	 */
	private function request( string $query, array $variables ): array {
		$response = wp_remote_post(
			$this->api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-api-key'    => $this->api_key,
				),
				'body'    => wp_json_encode(
					array(
						'query'     => $query,
						'variables' => $variables,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Amboss API request failed: ' . $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['errors'] ) ) {
			throw new RuntimeException( 'Amboss API error: ' . implode( '; ', array_column( $body['errors'], 'message' ) ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status || ! isset( $body['data'] ) ) {
			throw new RuntimeException( "Amboss API returned HTTP {$status}" );
		}

		return $body['data'];
	}
}
