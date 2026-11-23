<?php

defined( 'ABSPATH' ) || exit;

const AMBOSS_WEBHOOK_TOLERANCE_SECONDS = 300;

/**
 * Verifies an Amboss webhook the same way as Payments.webhooks.verify in the
 * TypeScript SDK: HMAC-SHA256 over "{timestamp}.{raw body}", hex-encoded.
 *
 * @return array<string, mixed> The decoded event.
 * @throws InvalidArgumentException When the signature, timestamp or body is invalid.
 */
function amboss_verify_webhook( string $secret, string $raw_body, string $signature, string $timestamp, int $now ): array {
	if ( '' === $secret ) {
		throw new InvalidArgumentException( 'missing_secret' );
	}
	if ( '' === $signature ) {
		throw new InvalidArgumentException( 'missing_signature' );
	}
	if ( ! is_numeric( $timestamp ) ) {
		throw new InvalidArgumentException( 'invalid_timestamp' );
	}
	if ( abs( $now - (int) $timestamp ) > AMBOSS_WEBHOOK_TOLERANCE_SECONDS ) {
		throw new InvalidArgumentException( 'timestamp_out_of_tolerance' );
	}
	if ( ! preg_match( '/^[0-9a-f]{64}$/i', $signature ) ) {
		throw new InvalidArgumentException( 'invalid_signature_format' );
	}

	$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
	if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
		throw new InvalidArgumentException( 'signature_mismatch' );
	}

	$event = json_decode( $raw_body, true );
	if ( ! is_array( $event ) ) {
		throw new InvalidArgumentException( 'invalid_payload_json' );
	}

	return $event;
}
