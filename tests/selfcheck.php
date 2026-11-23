<?php
// Runs without WordPress: php tests/selfcheck.php

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/functions.php';

function expect_throws( callable $fn, string $message ): void {
	try {
		$fn();
	} catch ( InvalidArgumentException $error ) {
		assert( $error->getMessage() === $message, "expected {$message}, got {$error->getMessage()}" );
		return;
	}
	throw new AssertionError( "expected exception {$message}" );
}

// Vector signed with the TypeScript SDK algorithm (Node crypto).
$body      = '{"event_type":"payment.completed","data":{"id":"tx1","metadata":{"wc_order_id":42}}}';
$timestamp = '1700000000';
$signature = '62ccf2be184e8ed04096e4c4dd8adfde63404c24afb89cc06a4bedda2073bfa2';

$event = amboss_verify_webhook( 'whsec_test', $body, $signature, $timestamp, 1700000100 );
assert( $event['data']['metadata']['wc_order_id'] === 42 );
expect_throws( fn() => amboss_verify_webhook( 'wrong', $body, $signature, $timestamp, 1700000100 ), 'signature_mismatch' );
expect_throws( fn() => amboss_verify_webhook( 'whsec_test', $body . ' ', $signature, $timestamp, 1700000100 ), 'signature_mismatch' );
expect_throws( fn() => amboss_verify_webhook( 'whsec_test', $body, $signature, $timestamp, 1700000301 ), 'timestamp_out_of_tolerance' );
expect_throws( fn() => amboss_verify_webhook( 'whsec_test', $body, 'zz', $timestamp, 1700000100 ), 'invalid_signature_format' );

echo "ok\n";
