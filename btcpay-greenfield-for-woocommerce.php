<?php
/**
 * Plugin Name:     BTCPay Greenfield For Woocommerce
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     PLUGIN DESCRIPTION HERE
 * Author:          YOUR NAME HERE
 * Author URI:      YOUR SITE HERE
 * Text Domain:     btcpay-greenfield
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Btcpay_Greenfield_For_Woocommerce
 *
 * todo: textdomain needs to match plugin slug, re-use or new slug? https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/
 */

use BTCPayServer\WC\Gateway\DefaultGateway;
use BTCPayServer\WC\Helper\SettingsHelper;

defined( 'ABSPATH' ) || exit();

define( 'BTCPAYSERVER_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BTCPAYSERVER_TEXTDOMAIN', 'btcpay-greenfield-for-woocommerce' );
define( 'BTCPAYSERVER_VERSION', '0.0.1' );
define( 'BTCPAYSERVER_PLUGIN_ID', 'btcpay-greenfield-for-woocommerce' );


// todo: textdomain
// https://github.com/btcpayserver/woocommerce-plugin/commit/e9938a4260b5585be6ca9b84b33e892bf5016020


class BTCPayServerWCPlugin {
	public function __construct() {
		$this->includes();

		if (is_admin()) {
			add_filter(
				'woocommerce_get_settings_pages',
				function ($settings) {
					$settings[] = new \BTCPayServer\WC\Admin\GlobalSettings();

					return $settings;
				}
			);
		}


	}

	public function includes() {
		$autoloader = BTCPAYSERVER_PLUGIN_FILE_PATH . 'vendor/autoload.php';
		if (file_exists($autoloader)) {
			/** @noinspection PhpIncludeInspection */
			require_once $autoloader;

			// Include functions if needed.
			//require_once __DIR__ . '/includes/functions.php';
		}
	}

	public function initPaymentGateways() {
		// We always load the default gateway that covers all payment methods available on BTCPayServer.
		$gateways[] = DefaultGateway::class;

		// todo: check if api keys configured (could also be in constructor)
		// if not show admin notice in admin UI with link: BTCPayServer API keys missing Please [set your API keys here].

		// Load payment methods from BTCPS as separate gateways.
		if (get_option('btcpay_gf_separate_gateways') === 'yes') {
			// Call init separate payment gateways here.
		}

		return $gateways;
	}

	public static function getSettingsHelper() {
		static $settings_helper;

		if ( ! $settings_helper ) {
			$settings_helper = new SettingsHelper();
		}

		return $settings_helper;
	}

	public function checkDependencies() {
		// Check PHP version
		// todo there should be a built in functionality, eg. plugin dockblock or similar
		if ( version_compare( PHP_VERSION, '7.3', '<' ) ) {
			add_action( 'admin_init', [$this, 'phpVersionNotice'] );
		}

		// Check if WooCommerce is installed. Taken from WC docs.
		$plugin_path = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php';

		if (
			in_array( $plugin_path, wp_get_active_and_valid_plugins() )
			|| in_array( $plugin_path, wp_get_active_network_plugins() )
		) {
			// all good
		} else {
			add_action( 'admin_init', [$this, 'wooCommerceNotice'] );
		}

	}

	// todo: move to Notification class
	private function phpVersionNotice() {
		$message = sprintf( __( 'Your PHP version is %s but BTCPay Greenfield Payment plugin requires version 7.3+.', BTCPAYSERVER_TEXTDOMAIN ), PHP_VERSION );
		echo '<div class="notice notice-error"><p style="font-size: 16px">' . $message . '</p></div>';
	}

	private function wooCommerceNotice() {
		$message = 'WooCommerce seems to be not installed. Make sure you do before you activate BTCPayServer Payment Gateway.';
		echo '<div class="notice notice-error"><p style="font-size: 16px">' . $message . '</p></div>';
	}
}

// Start everything up.
function init_btcpay_greenfield() {
	new BTCPayServerWCPlugin();
}

add_filter( 'woocommerce_payment_gateways', [ 'BTCPayServerWCPlugin', 'initPaymentGateways' ] );
add_action( 'plugins_loaded', 'init_btcpay_greenfield', 0 );
