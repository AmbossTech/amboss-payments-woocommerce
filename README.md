# Amboss Payments for WooCommerce

WooCommerce payment gateway that sends customers to the Amboss hosted checkout to pay over Lightning.

## How it works

1. At checkout the plugin calls `create_receive` with a `checkout` input and redirects the customer to the checkout URL.
2. Amboss sends a signed webhook when the payment completes, fails or expires.
3. The plugin verifies the signature, checks the amount and asset, and marks the order paid.

## Setup

1. Install and activate the plugin (WooCommerce 8.3+, PHP 7.4+).
2. In **WooCommerce → Settings → Payments → Amboss Payments**, enter the API key and wallet ID.
3. In the Amboss dashboard, add a webhook endpoint with the URL shown in the settings. Paste its secret into the plugin.
4. Pick the payment method name: **Pay with Bitcoin** or **Pay with Cash App**. Cash App opens the checkout with Cash App selected (`?wallet=cashapp`) and needs a BTC wallet.
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
