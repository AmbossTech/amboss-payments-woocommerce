# Amboss Payments for WooCommerce

WooCommerce payment gateway that sends customers to the Amboss hosted checkout to pay with Bitcoin Lightning or Cash App.

## How it works

1. At checkout the plugin calls `create_receive` with a `checkout` input and redirects the customer to the checkout URL.
2. Amboss sends a signed webhook when the payment completes, fails or expires.
3. The plugin verifies the signature, checks the amount and asset, and marks the order paid.

## Setup

1. Install and activate the plugin (WooCommerce 8.3+, PHP 7.4+).
2. In **WooCommerce → Settings → Payments → Amboss Payments**, enter the API key, wallet ID and webhook secret. Each field tells you where to get the value in the Amboss dashboard.
3. Select the payment options: **Pay with Bitcoin**, **Pay with Cash App**, or both. Each option is a separate payment method at checkout. Cash App opens the checkout with Cash App selected (`?wallet=cashapp`) and needs a BTC wallet.
4. Optional: set a discount for customers who pay with Amboss, and choose a checkout style.
5. The store must use HTTPS. The API rejects non-HTTPS return URLs.

## Supported currencies

The plugin sends the order total as `fiat_amount`. Amboss converts it to the wallet asset.

| Store currency | Wallet asset                                       |
| -------------- | -------------------------------------------------- |
| USD            | BTC, any USD stablecoin (symbol starts with `USD`) |

## Development

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  php -d zend.assertions=1 -d assert.exception=1 tests/selfcheck.php
```
