<?php
/**
 * Plugin Name:     BTCPay Greenfield For Woocommerce
 * Plugin URI:      https://wordpress.org/plugins/btcpay-greenfield-for-woocommerce/
 * Description:     BTCPay Server is a free and open-source bitcoin payment processor which allows you to receive payments in Bitcoin and altcoins directly, with no fees, transaction cost or a middleman.
 * Author:          BTCPay Server, ndeet
 * Author URI:      https://btcpayserver.org
 * Text Domain:     btcpay-greenfield-for-woocommerce
 * Domain Path:     /languages
 * Version:         0.1.0
 * Requires PHP:    7.4
 * Tested up to:    5.8
 * Requires at least: 5.2
 *
 */

use BTCPayServer\WC\Gateway\DefaultGateway;

defined( 'ABSPATH' ) || exit();

define( 'BTCPAYSERVER_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BTCPAYSERVER_PLUGIN_URL', plugin_dir_url(__FILE__ ) );
define( 'BTCPAYSERVER_VERSION', '0.1.0' );
define( 'BTCPAYSERVER_PLUGIN_ID', 'btcpay-greenfield-for-woocommerce' );

class BTCPayServerWCPlugin {
	public function __construct() {
		$this->includes();

		if (is_admin()) {
			// Register our custom global settings page.
			add_filter(
				'woocommerce_get_settings_pages',
				function ($settings) {
					$settings[] = new \BTCPayServer\WC\Admin\GlobalSettings();

					return $settings;
				}
			);
			add_action( 'wp_ajax_handle_ajax_api_url', [$this, 'processAjaxApiUrl'] );

			$this->notConfiguredNotification();
		}
	}

	public function includes(): void {
		$autoloader = BTCPAYSERVER_PLUGIN_FILE_PATH . 'vendor/autoload.php';
		if (file_exists($autoloader)) {
			/** @noinspection PhpIncludeInspection */
			require_once $autoloader;

			// Include functions if needed.
			//require_once __DIR__ . '/includes/functions.php';
		}
	}

	public function initPaymentGateways(): array {
		// We always load the default gateway that covers all payment methods available on BTCPayServer.
		$gateways[] = DefaultGateway::class;

		// Load payment methods from BTCPay Server as separate gateways.
		if (get_option('btcpay_gf_separate_gateways') === 'yes') {
			// Call init separate payment gateways here.
			if ($separateGateways = \BTCPayServer\WC\Helper\GreenfieldApiHelper::supportedPaymentMethods()) {

				\BTCPayServer\WC\Gateway\SeparateGateways::generateClasses();

				foreach ($separateGateways as $gw) {
					$gateways[] = $gw['className'];
				}
			}
		}

		return $gateways;
	}

	public function notConfiguredNotification(): void {
		if (!\BTCPayServer\WC\Helper\GreenfieldApiHelper::getConfig()) {
			$message = sprintf(
				esc_html__(
					'Plugin not configured yet, please %1$sconfigure the plugin here%2$s',
					'btcpay-greenfield-for-woocommerce'
				),
				'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=btcpay_settings')) . '">',
				'</a>'
			);

			\BTCPayServer\WC\Admin\Notice::addNotice('error', $message);
		}
	}

	public function dependenciesNotification() {
		// Check PHP version.
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			$versionMessage = sprintf( __( 'Your PHP version is %s but BTCPay Greenfield Payment plugin requires version 7.4+.', 'btcpay-greenfield-for-woocommerce' ), PHP_VERSION );
			\BTcpayServer\WC\Admin\Notice::addNotice('error', $versionMessage);
		}

		// Check if WooCommerce is installed (taken from WC docs).
		$plugin_path = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php';

		if (
			in_array( $plugin_path, wp_get_active_and_valid_plugins() )
			|| in_array( $plugin_path, wp_get_active_network_plugins() )
		) {
			// All good.
		} else {
			$wcMessage = __('WooCommerce seems to be not installed. Make sure you do before you activate BTCPayServer Payment Gateway.', 'btcpay-greenfield-for-woocommerce');
			\BTcpayServer\WC\Admin\Notice::addNotice('error', $wcMessage);
		}

	}

	/**
	 * Handles the AJAX callback from the GlobalSettings form. Unfortunately with namespaces it seems to not work
	 * to have this method on the GlobalSettings class. So keeping it here for the time being.
	 */
	public function processAjaxApiUrl() {
		$nonce = $_POST['apiNonce'];
		if ( ! wp_verify_nonce( $nonce, 'btcpaygf-api-url-nonce' ) ) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		if ( current_user_can( 'manage_options' ) ) {
			$host = $_POST['host'];

			if (!filter_var($host, FILTER_VALIDATE_URL) || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
				wp_send_json_error("Error validating BTCPayServer URL.");
			}

			try {
				// Create the redirect url to BTCPay instance.
				$url = \BTCPayServer\Client\ApiKey::getAuthorizeUrl(
					$host,
					\BTCPayServer\WC\Helper\GreenfieldApiAuthorization::REQUIRED_PERMISSIONS,
					'WooCommerce',
					true,
					true,
					home_url('btcpay-settings-callback'),
					null
				);

				// Store the host to options before we leave the site.
				update_option('btcpay_gf_url', $host);

				// Return the redirect url.
				wp_send_json_success(['url' => $url]);
			} catch (\Throwable $e) {
				\BTCPayServer\WC\Helper\Logger::debug('Error fetching redirect url from BTCPay Server.');
			}
		}

		wp_send_json_error("Error processing Ajax request.");
	}

}

// Start everything up.
function init_btcpay_greenfield() {
	new BTCPayServerWCPlugin();
}

/**
 * Bootstrap stuff on init.
 */
add_action('init', function() {
	// Adding textdomain and translation support.
	load_plugin_textdomain('btcpay-greenfield-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');
	// Setting up and handling custom endpoint for api key redirect from BTCPay Server.
	add_rewrite_endpoint('btcpay-settings-callback', EP_ROOT);
});

// To be able to use the endpoint without appended url segments we need to do this.
add_filter('request', function($vars) {
	if (isset($vars['btcpay-settings-callback'])) {
		$vars['btcpay-settings-callback'] = true;
	}
	return $vars;
});

// Adding template redirect handling for btcpay-settings-callback.
add_action( 'template_redirect', function() {
	global $wp_query;

	// Only continue on a btcpay-settings-callback request.
	if (! isset( $wp_query->query_vars['btcpay-settings-callback'] ) ) {
		return;
	}

	$btcPaySettingsUrl = admin_url('admin.php?page=wc-settings&tab=btcpay_settings');

	$rawData = file_get_contents('php://input');
	$data = json_decode( $rawData, TRUE );

	// Seems data does get submitted with url-encoded payload.
	if (!empty($_POST)) {
		$data = $_POST;
	}

	if (isset($data['apiKey']) && isset($data['permissions'])) {
		$apiData = new \BTCPayServer\WC\Helper\GreenfieldApiAuthorization($data);
		if ($apiData->hasSingleStore() && $apiData->hasRequiredPermissions()) {
			update_option('btcpay_gf_api_key', $apiData->getApiKey());
			update_option('btcpay_gf_store_id', $apiData->getStoreID());
			\WC_Admin_Settings::add_message(__('Successfully received api key and store id from BTCPay Server API.', 'btcpay-greenfield-for-woocommerce'));
			wp_redirect($btcPaySettingsUrl);
		} else {
			\WC_Admin_Settings::add_error(__('Please make sure you only select one store on the BTCPay API authorization page.', 'btcpay-greenfield-for-woocommerce'));
			wp_redirect($btcPaySettingsUrl);
		}
	}

	\WC_Admin_Settings::add_error(__('Error processing the data from BTCPay. Please try again.', 'btcpay-greenfield-for-woocommerce'));
	wp_redirect($btcPaySettingsUrl);
});

// Initialize payment gateways and plugin.
add_filter( 'woocommerce_payment_gateways', [ 'BTCPayServerWCPlugin', 'initPaymentGateways' ] );
add_action( 'plugins_loaded', 'init_btcpay_greenfield', 0 );
