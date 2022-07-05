# BTCPay Greenfield Plugin for WooCommerce

For a detailed feature overview and description go to the official WordPress plugin page:
https://wordpress.org/plugins/btcpay-greenfield-for-woocommerce/

This readme is mostly about plugin development on the main repo on GitHub.

## Considerations

The basic structure is a mix of the plugin generator by WP cli tool (file structure, Grunt build runner, etc.) and Composer based setup with GitHub workflows to deploy the artifact to WordPress SVN. WP does not support composer yet so we need to still do fetch dependencies in the build process and adjust to their SVN based structure for committing the code to their plugin ecosystem. This is all handled by the Github workflow.

## Todo new features
Check the repos issues here:
https://github.com/btcpayserver/woocommerce-greenfield-plugin/issues

## Development
```
git clone git@github.com:btcpayserver/woocommerce-greenfield-plugin.git
```

**Install dependencies with Composer:**
```
composer install
```

### Contributing
Feel free to open an issue to discuss a feature or provide a PR for any improvements or if you want to tackle a feature listed in the issues.

### Local development with Docker
**Install dependencies**
```
composer install
```

**Run docker compose to spin containers up**
```
docker-compose up -d
```
Go to [http://localhost:8821]() and install WordPress, WooCommerce and BTCPay for WooCommerce V2 Plugin.
