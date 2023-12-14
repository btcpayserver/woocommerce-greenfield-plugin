=== BTCPay for WooCommerce V2 ===
Contributors: ndeet, kukks, nicolasdorier
Donate link: https://btcpayserver.org/donate/
Tags: bitcoin, btcpay, BTCPay Server, btcpayserver, WooCommerce, payment gateway, accept bitcoin, bitcoin plugin, bitcoin payment processor, bitcoin e-commerce, Lightning Network, Litecoin, cryptocurrency
Requires at least: 5.2
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 2.4.0
License: MIT
License URI: https://github.com/btcpayserver/woocommerce-greenfield-plugin/blob/master/license.txt

BTCPay Server is a free and open-source bitcoin payment processor which allows you to receive payments in Bitcoin and altcoins directly, with no fees, transaction cost or a middleman.

== Description ==

= Accept Bitcoin payments in your WooCommerce powered WordPress site with BTCPay Server =

BTCPay Server for WooCommerce is a revolutionary, self-hosted, open-source payment gateway to accept Bitcoin payments. Our** seamless integration** with WooCommerce allows you to connect your self-hosted [BTCPay Server](https://btcpayserver.org) and start accepting Bitcoin payments in **just a few simple steps**.

= Features: =

* **Zero fees**: Enjoy a payment gateway with no fees. Yes, really!
* **Fully automated system**: BTCPay takes care of payments, invoice management and refunds automatically.
* **Display Bitcoin QR code at checkout**: Enhance customer experience with an easy and secure payment option.
* **No middlemen or KYC**:
    * Direct, P2P payments (going directly to your wallet)
    * Say goodbye to intermediaries and tedious paperwork
    * Transaction information is only shared between you and your customer
* **Self-hosted infrastructure**: Maintain full control over your payment gateway.
* **Direct wallet payments**: Be your own bank with a self-custodial service.
* **Lightning Network** integrated out of the box - instant, fast and low cost payments and payouts
* **Reporting and accounting** - CSV exports
* **Advanced invoice managemen**t and refunding integrated in the WooCommerce UI
* **Real-time exchange price tracking **for correct payment amounts
* **Versatile plugin system**:
    * Extend functionality according to your needs
    * Accept payments in altcoins through various plugins
* **Elegant checkout design**: Compatible with all Bitcoin wallets and enhanced with your store's logo and branding for a unique UX.
* **Point-of-sale** integration - Accept payments in your physical shops
* **Multilingual ready**: Serve a global audience right out of the box.
* **Top-notch privacy and security**: Protect your and your customers' data.
* **Community-driven support**: Get responsive assistance from our dedicated community ([Mattermost](http://chat.btcpayserver.org) or [Telegram](https://t.me/btcpayserver)).
* Extensive [documentation](https://docs.btcpayserver.org/WooCommerce) and [video](https://www.youtube.com/c/btcpayserver) tutorials

The non-profit [BTCPay Server Foundation ](https://foundation.btcpayserver.org)is committed to keeping this powerful payment gateway free forever. Our mission is to enable anyone to accept bitcoin regardless of financial, technical, social or political barriers.


== Installation ==

This plugin requires WooCommerce. Please make sure you have WooCommerce installed.

<img src="https://github.com/btcpayserver/btcpayserver-doc/blob/master/img/BTCPayWooCommerceInfoggraphic.png" alt="Infographic" />

To integrate BTCPay Server into an existing WooCommerce store, follow the steps below or check our official [installation instructions](https://docs.btcpayserver.org/WooCommerce/).

### 1. Deploy BTCPay Server (optional) ###

This step is optional, if you already have a BTCPay Server instance setup you can skip to section 2. below. To launch your BTCPay server, you can self-host it, or use a third party host.

#### 1.1 Self-hosted BTCPay ####

There are various ways to [launch a self-hosted BTCPay](https://github.com/btcpayserver/btcpayserver-doc#deployment). If you do not have technical knowledge, use the [web-wizard method](https://launchbtcpay.lunanode.com) and follow the video below.

https://www.youtube.com/watch?v=NjslXYvp8bk

For the self-hosted solutions, you will have to wait for your node to sync fully before proceeding to step 3.

#### 1.2 Third-party host ####

Those who want to test BTCPay out, or are okay with the limitations of a third-party hosting (dependency and privacy, as well as lack of some features) can use a one of many [third-party hosts](ThirdPartyHosting.md).

The video below shows you how to connect your store to such a host.

https://www.youtube.com/watch?v=IT2K8It3S3o

### 2. Install BTCPay WooCommerce Plugin ###

BTCPay WooCommerce plugin is a bridge between your BTCPay Server (payment processor) and your e-commerce store. No matter if you are using a self-hosted or third-party solution from step 1., the connection process is identical.

You can find detailed installation instructions on our [WooCommerce documentation](https://docs.btcpayserver.org/WooCommerce/).

Here is a quick walk through if you prefer a video:

https://www.youtube.com/watch?v=ULcocDKZ1Mw

###  3. Connecting your wallet ###

No matter if you're using self-hosted or server hosted by a third-party, the process of configuring your wallet is the same.

https://www.youtube.com/watch?v=xX6LyQej0NQ

### 4. Testing the checkout ###

Making a small test-purchase from your own store, will give you a piece of mind. Always make sure that everything is set up correctly before going live. The final video, guides you through the steps of setting a gap limit in your Electrum wallet and testing the checkout process.

https://www.youtube.com/watch?v=Fi3pYpzGmmo

Depending on your business model and store settings, you may want to fine tune [your order statuses](https://docs.btcpayserver.org/WooCommerce/#41-global-settings).

== Frequently Asked Questions ==

You'll find extensive documentation and answers to many of your questions on [BTCPay for WooCommerce V2 docs](https://docs.btcpayserver.org/WooCommerce) and on [BTCPay for WooCommerce integrations FAQ](https://docs.btcpayserver.org/FAQ/Integrations/#woocommerce-faq).

== Screenshots ==

1. The BTCPay Server invoice. Your customers will see this at the checkout. They can pay from their wallet by scanning a QR or copy/pasting it manually into the wallet.
2. Customizable plugin interface allows store owners to adjust store statuses according to their needs.
3. Customer will see the pay with Bitcoin button at the checkout.Text can be customized.
4. Example of successfully paid invoice.
5. Example of an easy-embeddable HTML donation payment button.
6. Example of the PoS app you can launch.

== Upgrade Notice ==
= 2.4.0 =
* New feature: Add basic support for [WooCommerce cart and checkout blocks](https://woo.com/document/cart-checkout-blocks-status/).

== Changelog ==

= 2.4.0 :: 2023-12-12 =
* Fix: Avoid error on InvoiceProcessing/InvoiceSettled event in case of paidOver property is missing.
* New feature: Add basic support for WooCommerce cart and checkout blocks.
Note: Works for default configuration; future versions will make it work with modal checkout and separate payment gateways too.

= 2.3.1 :: 2023-10-20 =
* Fix: Ensure refunds text does not exceed API field limit.

= 2.3.0 :: 2023-09-06 =
* Support for high performance order storage (HPOS)

Note: This is opt-in but brings performance improvements. Follow instructions [here](https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#how-to-enable-hpos) if you want to use it.

= 2.2.3 :: 2023-08-22 =
* Automatically create webhook after redirect.

= 2.2.2 :: 2023-08-22 =
* Fix edgecase JS error on payment method selection.

= 2.2.1 :: 2023-08-17 =
* Add tooltip with webhook callback information

= 2.2.0 :: 2023-08-17 =
* Refactor settings UI and allow manual webhook secret entry. This allows 3rd party integrators limit their API keys scope and not include the webhook permission.

= 2.1.0 :: 2023-04-03 =
* New feature: Modal / Overlay checkout mode (no redirect to BTCPay Server)

= 2.0.0 :: 2023-03-20 =
* New feature: Add support for refunds.

Note: If you are upgrading from a version < 2.0 and you want to use refunds (via pull payments) you need to create a new API key with the "Create non-approved pull payments" which is available from BTCPay Server version 1.7.6.
See this link for more information: https://docs.btcpayserver.org/WooCommerce/#create-a-new-api-key

If you do NOT use refunds. You do NOT need to do anything, your existing API key and setup will continue to work as before.

Changelog of older releases can be found [here](https://github.com/btcpayserver/woocommerce-greenfield-plugin/blob/master/changelog.txt)
