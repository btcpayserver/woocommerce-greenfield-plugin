=== BTCPay for WooCommerce V2 ===
Contributors: ndeet, kukks, nicolasdorier
Donate link: https://btcpayserver.org/donate/
Tags: bitcoin, btcpay, BTCPay Server, btcpayserver, WooCommerce, payment gateway, accept bitcoin, bitcoin plugin, bitcoin payment processor, bitcoin e-commerce, Lightning Network, Litecoin, cryptocurrency
Requires at least: 5.2
Tested up to: 5.9
Requires PHP: 7.4
Stable tag: 0.2.1
License: MIT
License URI: https://github.com/btcpayserver/woocommerce-greenfield-plugin/blob/master/license.txt

BTCPay Server is a free and open-source bitcoin payment processor which allows you to receive payments in Bitcoin and altcoins directly, with no fees, transaction cost or a middleman.

== Description ==

BTCPay Server is a free and open-source cryptocurrency payment processor which allows you to receive payments in Bitcoin and altcoins directly, with no fees, transaction cost or a middleman.

BTCPay Server is a non-custodial invoicing system which eliminates the involvement of a third-party. Payments with BTCPay WooCommerce Plugin go directly to your wallet, which increases the privacy and security. Your private keys are never uploaded to the server. There is no address re-use since each invoice generates a new address deriving from your xPub key.

You can run BTCPay as a self-hosted solution on your own server, or use a third-party host.

The self-hosted solution allows you not only to attach an unlimited number of stores and use the Lightning Network but also become the payment processor for others.

* Direct, peer-to-peer Bitcoin and altcoin payments
* No transaction fees (other than mining fees by cryptocurrency network itself)
* No processing fees
* No middleman
* No KYC
* User has complete control over private keys
* Enhanced privacy (no address re-use, no IP leaks to third parties)
* Enhanced security
* Self-hosted
* SegWit, Taproot support
* Lightning Network support (LND, c-lightning and Eclair)
* Altcoin support
* Attach unlimited stores, process payments for friends
* Easy-embeddable Payment buttons
* Point of Sale app

== Installation ==

This plugin requires Woocommerce. Please make sure you have Woocommerce installed.

<img src="https://github.com/btcpayserver/btcpayserver-doc/blob/master/img/BTCPayWooCommerceInfoggraphic.png" alt="Infographic" />

To integrate BTCPay Server into an existing WooCommerce store, follow the steps below.

### 1. Install BTCPay WooCommerce Plugin ###

### 2. Deploy BTCPay Server ###

To launch your BTCPay server, you can self-host it, or use a third party host.

#### 2.1 Self-hosted BTCPay ####

There are various ways to [launch a self-hosted BTCPay](https://github.com/btcpayserver/btcpayserver-doc#deployment). If you do not have technical knowledge, use the [web-wizard method](https://launchbtcpay.lunanode.com) and follow the video below.

https://www.youtube.com/watch?v=NjslXYvp8bk

For the self-hosted solutions, you will have to wait for your node to sync fully before proceeding to step 3.

#### 2.2 Third-party host ####

Those who want to test BTCPay out, or are okay with the limitations of a third-party hosting (dependency and privacy, as well as lack of some features) can use a one of many [third-party hosts](ThirdPartyHosting.md).

The video below shows you how to connect your store to such host.

https://www.youtube.com/watch?v=IT2K8It3S3o

### 3. Pairing the store ###

BTCPay WooCommerce plugin is a bridge between your BTCPay Server (payment processor) and your e-commerce store. No matter if you are using a self-hosted or third-party solution from step 2, the pairing process is identical.

Go to your store dashboard. WooCommerce > Settings > BTCPay Settings.

1. In the field, enter the full URL of your host (including the https) – https://btcpay.mydomain.com
2. Click on the "click here to generate API keys" link to get redirected to BTCPay Server Authorization page.
3. On BTCPay Server make sure you select one single store you want to connect your WooCommerce shop.
4. Click on the [Authorize app] button, you will get redirected back to WooCommerce BTCPay Settings page and your store ID will already be prefilled.
5. Optional: Change other settings as needed.
6. Click [Save] button.
7. You can now enable BTCPay payment gateway in WooCommerce > Settings > Payments


###  4. Connecting your wallet ###

No matter if you're using self-hosted or server hosted by a third-party, the process of configuring your wallet is the same.

https://www.youtube.com/watch?v=xX6LyQej0NQ

### 5. Testing the checkout ###

Making a small test-purchase from your own store, will give you a piece of mind. Always make sure that everything is set up correctly before going live. The final video, guides you through the steps of setting a gap limit in your Electrum wallet and testing the checkout process.

https://www.youtube.com/watch?v=Fi3pYpzGmmo

Depending on your business model and store settings, you may want to [configure your order statuses](https://nbitstack.com/t/how-to-set-up-order-statuses-in-woocommerce-and-btcpay/67).

== Frequently Asked Questions ==

You'll find extensive documentation and answers to many of your questions on [docs.btcpayserver.org](https://docs.btcpayserver.org/).

== Screenshots ==

1. The BTCPay Server invoice. Your customers will see this at the checkout. They can pay from their wallet by scanning a QR or copy/pasting it manually into the wallet.
2. Customizable plugin interface allows store owners to adjust store statuses according to their needs.
3. Customer will see the pay with Bitcoin button at the checkout.Text can be customized.
4. Example of successfully paid invoice.
5. Example of an easy-embeddable HTML donation payment button.
6. Example of the PoS app you can launch.

== Changelog ==
= 0.2.1 :: 2022-02-21 =
* Replace SVG by PNG logo to avoid scaling it on themes without proper CSS rules for payment gateway icons.

= 0.2.0 :: 2022-02-18 =
* Fix Cash on delivery, Bank transfer gateways missing after plugin activation.

= 0.1.10 :: 2022-02-15 =
* Make sure custom endpoint works without nice url enabled.
* Better description for setting.
* Update translation strings.

= 0.1.9 :: 2022-02-08 =
* Make sure custom endpoint works by flushing rewrite rules on plugin activation.
* Replacing usage of WC_Admin_Settings::addMessage() with our own.

= 0.1.1 :: 2022-01-13 =
* Admin notice if legacy plugin is installed
* Admin notice on missing WooCommerce / PHP version lower 7.4
* Minor changes metadata / readme.txt

= 0.1.0 :: 2022-01-13 =
* First public release for testing.
