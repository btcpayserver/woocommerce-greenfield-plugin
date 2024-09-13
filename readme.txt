=== BTCPay Server - Accept Bitcoin payments in WooCommerce ===
Contributors: ndeet, kukks, nicolasdorier
Donate link: https://btcpayserver.org/donate/
Tags: Bitcoin, Lightning Network, BTCPay Server, WooCommerce, payment gateway
Requires at least: 5.9
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 2.7.0
License: MIT
License URI: https://github.com/btcpayserver/woocommerce-greenfield-plugin/blob/master/license.txt

BTCPay Server is a free and open-source bitcoin payment processor which allows you to receive payments in Bitcoin and altcoins directly, with no fees, transaction cost or a middleman.

== Description ==

= Accept Bitcoin payments in your WooCommerce powered WordPress site with BTCPay Server =

BTCPay Server for WooCommerce is a revolutionary, self-hosted, open-source payment gateway to accept Bitcoin payments. Our **seamless integration** with WooCommerce allows you to connect your self-hosted [BTCPay Server](https://btcpayserver.org) and start accepting Bitcoin payments in **[just a few simple steps](https://docs.btcpayserver.org/WooCommerce)**.

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
* **Real-time exchange price tracking** for correct payment amounts
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

1. Provides a Bitcoin / Lightning Network (and other) payment gateway on checkout.
2. Your customers can pay by scanning the QR-Code with their wallet or copy and paste the receiving address.
3. After successful payment the customers will get redirected to the order page. The order will be marked as paid automatically.
4. On the settings form you can connect to your BTCPay Server instance by just entering the URL and clicking on "Generate API Key" button.
5. You will get redirected to your BTCPay Server instance and just need to confirm the permissions of the API key. You will get redirected back to the settings form and the webhook will get set up automatically. You are ready to go.
6. On BTCPay Server you have extensive reporting and accounting features.

== Upgrade Notice ==



= 2.7.0 =
* IMPORTANT: If you use the "Separate Payment gateways" feature, when you upgrade your BTCPay Server to version 2.0.0 or newer, you will need to reconfigure your payment gateways in WooCommerce. This is due to the new way of handling and naming payment methods in BTCPay Server.
* Feature: Add option to notify customers on refund order notes.
* Feature: BTCPay Server 2.0.0 compatibility.
* Fixes see changelog.

== Changelog ==
= 2.7.0 :: 2024-09-04 =
* Feature: Add option to notify customers on refund order notes.
* Feature: BTCPay Server 2.0.0 compatibility.
* Fix: Make sure to not process orders if the assigned payment gateway is not one of BTCPay.
* Fix: Make sure payment methods are set on refunds.
* Fix: Wrong currency in refund comment.
* Fix: Deprecation warnings.
* Maintenance: Update NodeJS dependencies.
* Maintenance: Update PHP library to v2.7.0.

= 2.6.2 :: 2024-04-09 =
* Fix: Dismissing the review notification forever, finally.

= 2.6.1 :: 2024-04-04 =
* Fix: Error when processing full amount refunds.
* Fix: Show warning when bcmath extension is missing.
* Make it possible to dismiss the review notification forever.

= 2.6.0 :: 2024-02-27 =
* Update PHP BTCPay library to 2.3.0, minimum PHP version 8.0.
* Show warning when .local domain is used for BTCPay Server URL.
* Change BTCPay Server URL placeholder to official demo server.

= 2.5.0 :: 2024-01-31 =
* Fix: Formatting in readme.txt
* Add support for modal overlay for checkout blocks.


Changelog of older releases can be found [here](https://github.com/btcpayserver/woocommerce-greenfield-plugin/blob/master/changelog.txt)
