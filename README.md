# BTCPay Greenfield Plugin for WooCommerce

For a detailed feature overview and description go to the official WordPress plugin page:
https://wordpress.org/plugins/btcpay-greenfield-for-woocommerce/

This readme is mostly about plugin development on the main repo on GitHub.

## Considerations

The basic structure is a mix of the plugin generator by WP cli tool (file structure, Grunt build runner, etc.) and Composer based setup with GitHub workflows to deploy the artifact to WordPress SVN. WP does not support composer yet so we need to still do fetch dependencies in the build process and adjust to their SVN based structure for committing the code to their plugin ecosystem.

## Todo until feature parity with old legacy plugin (completed)
- [x] check, create webhook, WC Api endpoint for processing the response
- [x] make default gateway work
- [x] add API key convenience function button and routine
- [x] separate payment gateways by store supported payment method
- [x] separate payment gateways caching
- [x] separate payment gateway support promotional token types
- [x] allow custom gateway icon upload by reusing media library (BTCPay default icon)
- [x] order states mapping/configurability
- [x] make build system work
- [x] show admin notice if plugin not configured yet with link to config page
- [x] ensure warning/uninstallable for PHP < 7.4 (plugin metadata or notice)
- [x] show warning on legacy plugin
- [x] add logging / debug mode
- [x] i18n support

## Todo new features
Check the repos issues here:
https://github.com/btcpayserver/woocommerce-greenfield-plugin/issues

## Development
```
git clone git@github.com:btcpayserver/woocommerce-greenfield-plugin.git
```

### Contributing
Feel free to open an issue to discuss a feature or provide a PR for any improvements or if you want to tackle a feature listed in the issues.

### Local development with Docker
```
docker-compose up -d
```
go to [http://localhost:8821]() and install WordPress, WooCommerce and BTCPay for WooCommerce V2 Plugin
