=== Amboss Payments for WooCommerce ===
Contributors: amboss
Tags: bitcoin, lightning, cash app, stablecoin, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept Bitcoin, crypto and Cash App payments in your WooCommerce store through the Amboss hosted checkout.

== Description ==

Amboss Payments for WooCommerce adds Amboss to the checkout of your store. You do not write code.

At checkout, the customer goes to the secure Amboss checkout page and pays. Then the customer goes back to your store. A signed webhook tells your store that the order is paid, and the plugin changes the order status.

**Payment methods**

* **Pay with Bitcoin / Crypto.** The customer pays with Bitcoin over Lightning, or with other crypto.
* **Pay with Cash App.** The checkout page opens with Cash App selected. The customer pays with the bitcoin in their Cash App.

You can turn on one payment method or both.

**Features**

* Receive payments in a BTC wallet or in a USD stablecoin wallet. With a stablecoin wallet, you receive the exact order total in USD.
* Optional discount for customers who pay with Amboss. The checkout shows the discount in the payment method title. Shipping is not discounted.
* Optional checkout branding with your logo and colours.
* Works with the classic checkout and the block checkout.

**Requirements**

* An Amboss Payments account. Sign up at [app.amboss.tech](https://app.amboss.tech/pay).
* The store currency is USD.
* The store uses HTTPS and is reachable from the internet.

== External services ==

This plugin connects to Amboss Payments, a service of Amboss, to take payments. It does not work without this service.

**Amboss Payments API** (`https://app.amboss.tech/graphql`)

* When you save the settings, the plugin sends your API key and wallet ID to load the wallet asset and the list of checkout styles.
* When a customer places an order with an Amboss payment method, the plugin sends: the order total and currency, a description with your store name and the order number, the WooCommerce order ID, the wallet ID, the checkout style ID, and the URLs of the order-received page and the order payment page.
* The plugin does not send the customer's name, email address, phone number or postal address.

**Amboss hosted checkout** (`https://ambosspay.com`)

* The plugin sends the customer to the checkout page to pay. The customer's browser loads this page directly from Amboss.

**Webhooks**

* Amboss sends payment events to your store at `/wp-json/amboss/v1/webhook`. The plugin verifies the signature of each event with your webhook secret.

Terms of service: https://amboss.tech/terms
Privacy policy: https://amboss.tech/privacy

== Installation ==

1. Install and activate the plugin.
2. Go to **WooCommerce → Settings → Payments → Bitcoin Lightning**.
3. Go to [app.amboss.tech/pay](https://app.amboss.tech/pay) and open your environment.
4. In **Wallets**, open your wallet and click the **WALLET ID** chip. Paste the ID into **Wallet ID**.
5. In **API Keys**, click **New API key**. Set Environments to **Read**, Wallets to **Read** and Payments to **Read + Write**. Paste the key into **API key**.
6. In **Webhooks**, click **New endpoint**. Paste the webhook URL from the plugin settings, click **Select all** under **Event Types**, then click **Create Endpoint**. Paste the signing secret into **Webhook secret**.
7. Select **Enable Amboss Payments** and the payment methods, then click **Save changes**.

The full guide is at [docs.amboss.tech/payments/woocommerce](https://docs.amboss.tech/payments/woocommerce).

== Frequently Asked Questions ==

= Which currencies does the plugin support? =

The store currency must be USD. The wallet can be BTC or a USD stablecoin.

= The payment methods do not show at checkout. What do I do? =

Make sure that the store currency is USD and that the wallet is BTC or a USD stablecoin. Save the settings again and read the error at the top of the page.

= The order stays in Pending payment after the customer pays. What do I do? =

Make sure that the webhook URL is correct, that all four events are selected, and that the signing secret in the plugin matches the endpoint. Your store must be reachable over HTTPS.

= Where can I get help? =

Email support@amboss.tech.

== Screenshots ==

1. The Amboss payment methods at checkout, with the discount in the title.

== Changelog ==

= 0.1.0 =
* First release.
