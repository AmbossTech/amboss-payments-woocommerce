# Amboss Payments for WooCommerce

WooCommerce payment gateway that sends customers to the Amboss hosted checkout to pay with Bitcoin Lightning or Cash App.

## How it works

1. At checkout the plugin calls `create_receive` with a `checkout` input and redirects the customer to the checkout URL.
2. Amboss sends a signed webhook when the payment completes, fails or expires.
3. The plugin verifies the signature, checks the amount and asset, and marks the order paid.

## Setup

Before you start:

- The store must use HTTPS and be reachable from the internet. The API rejects non-HTTPS return URLs, and Amboss must be able to send webhooks to the store.
- The store currency must be USD.

### 1. Install the plugin

1. Install and activate the plugin (WooCommerce 8.3+, PHP 7.4+).
2. Go to **WooCommerce → Settings → Payments → Bitcoin Lightning**. Keep this page open in a tab.

### 2. Open your Amboss environment

1. Go to <https://app.amboss.tech/pay> and log in.
2. Under **Environments**, click the environment that you use for the store (normally the live one).

All the next steps use the left sidebar of this environment.

### 3. Get the wallet ID

1. In the sidebar, click **Wallets**.
2. Click the wallet that must receive the payments. The wallet asset must be BTC or a USD stablecoin.
3. At the top right, click the **WALLET ID** chip. This copies the ID.
4. In WooCommerce, paste the ID into **Wallet ID**.

### 4. Create the API key

1. In the sidebar, click **API Keys**, then click **New API key**.
2. In **Key Name**, type a name, for example `WooCommerce store`.
3. Optional: in **Scope to Wallet**, select the wallet from step 3.
4. In **Expiration**, keep **Never**. If you select a date, the store stops taking payments on that date.
5. Do not click **Read Only** or **Full Access**. Set the permissions one by one:

   | Resource           | Access       |
   | ------------------ | ------------ |
   | Environments       | Read         |
   | Wallets            | Read         |
   | Wallet Credentials | No access    |
   | Payments           | Read + Write |
   | Webhooks           | No access    |

6. Click **Create API Key**.
7. Click the copy icon next to the key. The key shows only one time.
8. In WooCommerce, paste the key into **API key**.
9. In Amboss, select **I've saved my API key somewhere safe**, then click **Done**.

### 5. Create the webhook

1. In WooCommerce, copy the webhook URL from the **Webhook secret** help text. It ends in `/wp-json/amboss/v1/webhook`.
2. In Amboss, in the sidebar, click **Webhooks**, then click **New endpoint**.
3. In **URL**, paste the webhook URL. Do not use the webhook.site test URL.
4. Under **Event Types**, click **Select all**. All four events must be on: `payment.pending`, `payment.completed`, `payment.failed`, `payment.expired`.
5. Click **Create Endpoint**.
6. Click the copy icon next to **Signing secret**. The secret shows only one time.
7. In WooCommerce, paste the secret into **Webhook secret**.
8. In Amboss, select **I've saved my signing secret somewhere safe**, then click **Done**.

### 6. Finish the settings

1. In WooCommerce, select **Enable Amboss Payments**.
2. Select the payment options: **Pay with Bitcoin / Crypto**, **Pay with Cash App**, or both. Each option is a separate payment method at checkout. Cash App opens the checkout with Cash App selected (`?wallet=cashapp`).
3. Optional: in **Discount (%)**, type a discount for customers who pay with Amboss.
4. Click **Save changes**. The plugin loads the wallet. If it shows an error, check the API key and the wallet ID.
5. Optional: in **Checkout style**, select a style, then click **Save changes** again. To make a style, go to **Checkout** in the Amboss sidebar.
6. Place a test order to make sure that the order changes to **Processing** (or **Completed** for downloadable products) after payment.

## Supported currencies

For a USD stablecoin wallet, the plugin sends the exact order total in the asset's base units as `amount`. For a BTC wallet, it sends the order total as `fiat_amount`, and Amboss converts it to sats.

| Store currency | Wallet asset                                       |
| -------------- | -------------------------------------------------- |
| USD            | BTC, any USD stablecoin (symbol starts with `USD`) |

## Development

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  php -d zend.assertions=1 -d assert.exception=1 tests/selfcheck.php
```

## Release

1. In `amboss-payments.php`, set the same new version in the `Version:` header and in `AMBOSS_VERSION`. Merge that change to `main`.
2. Tag the merge commit and push the tag:

   ```bash
   git tag v0.2.0 origin/main && git push origin v0.2.0
   ```

The `Release` workflow runs the self-check, builds `amboss-payments.zip` with `scripts/build-zip.sh`, and publishes a GitHub release with the zip. The workflow stops if the tag and the plugin version are different.

To build the zip locally: `scripts/build-zip.sh v0.2.0 origin/main`.
