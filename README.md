# BTCPay Greenfield Plugin for WooCommerce

## WARNING: Plugin work in progress and not working, do not use yet ;)


## Considerations

This is a first basic structure for the new Greenfield powered WooCommerce plugin without all the procedural code and quirks from the old plugin. Official WooCommerce best practices still do not use namespaces and has some weird quirks. With this plugin we use namespaces and strict types anyway to avoid WooCommerce/Wordpress specific quirks making it more difficult for contributors from other projects to contribute.

The basic structure is a mix of the plugin generator by WP cli tool (filestructure, Grunt build runner, etc.) and Composer based setup, not set in stone though, maybe we go with Github workflows instead. However, WP does not support composer yet so we need to still do fetch dependencies in the build process and adjust to their SVN based structure for committing the code to their plugin ecosystem.

From admin UI perspective (different from the legacy plugin) the global settings for BTCPayServer like the host, api key, store id, confirmation times etc. have been moved out of the default payment gateway to a separate settings form. The payment gateways will only have related config options like displayed text, icons, etc.

## Todo until feature parity with current plugin
- [] check, create webhook, WC Api endpoint for processing the response
- [] make default gateway work
- [] add API key convenience function button and routine
- [] separate payment gateways by store supported payment method
- [] order states mapping/configurabilty
- [] make build system work
- [] show admin notice if plugin not configured yet with link to config page
- [] ensure warning/uninstallable for PHP < 7.3 (plugin metadata or notice)
- [] uninstall legacy plugin upon installation(?)
- [] tbd

## Todo additional features
- [] separate payment gateway: allow combinations with other gw, e.g. allow invoice to be paid by L-USDT and HAT token (use case of token used as coupon codes for discounts)
- [] refunds
- [] tbd

