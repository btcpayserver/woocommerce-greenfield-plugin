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
define( 'BTCPAYSERVER_PLUGIN_URL', plugin_dir_url(__FILE__ ) );
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
			if ($separateGateways = \BTCPayServer\WC\Helper\GreenfieldApiHelper::supportedPaymentMethods()) {
				self::initSeparatePaymentGateways($separateGateways);

				foreach ($separateGateways as $gw) {
					$gateways[] = $gw['className'];
				}
			}
		}

		return $gateways;
	}

	public function initSeparatePaymentGateways(array $gateways) {
		foreach ( $gateways as $gw ) {
			$className = $gw['className'];
			$symbol = $gw['symbol'];
			$id = 'btcpaygf_' . strtolower($symbol);
			// todo: mode (payment, promotion)
			// todo: icon upload
			if ( ! class_exists( $className ) ) {
				// Build the class structure.
				//$classcode = "declare( strict_types=1 );";
				//$classcode .= "namespace BTCPayServer\WC\Gateway;";
				$classcode = "use BTCPayServer\WC\Gateway\AbstractGateway;";
				$classcode .= "class {$className} extends AbstractGateway { ";
				// $classcode .= "public \$token_mode;";
				$classcode .= "public \$token_symbol;";
				$classcode .= "public function __construct() { ";
				$classcode .= "parent::__construct();";
				$classcode .= "\$this->id = '{$id}';";
				$classcode .= "\$this->method_title = 'BTCPay Asset NEWWW: {$symbol}';";
				$classcode .= "\$this->method_description = 'This is an additional asset managed by BTCPay.';";
				//$classcode .= "\$this->title = '{$symbol}';"; // todo: get name from config
				//$classcode .= "\$this->token_mode = '{$token['mode']}';";
				$classcode .= "\$this->token_symbol = '{$symbol}';";
				//$classcode .= "\$this->icon = '{$token['icon']}';"; // todo get from config
				$classcode .= "\$this->init_settings();";
				$classcode .= "}" . PHP_EOL;
				$classcode .= "public function getDefaultTitle() { ";
				$classcode .= "return \$this->get_option('title', '{$symbol}');";
				$classcode .= "}" . PHP_EOL;
				$classcode .= "public function getDefaultDescription() { " ;
				$classcode .= "return \$this->get_option('description', 'You will be redirected to BTCPay to complete your purchase.');";
				$classcode .= "}" . PHP_EOL;
				$classcode .= "public function getSettingsDescription() { " ;
				$classcode .= "return \"default settings description alt\";";
				$classcode .= "}" . PHP_EOL;
				$classcode .= "public function getPaymentMethods() {
									return ['{$symbol}'];
			                   }";
				$classcode .= "}";

				// Initialize it on the fly.
				eval( $classcode );
			}
		}
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
