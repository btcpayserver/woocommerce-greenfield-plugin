<?php
/**
 * Plugin Name:     BTCPay For Woocommerce V2
 * Plugin URI:      https://wordpress.org/plugins/btcpay-greenfield-for-woocommerce/
 * Description:     BTCPay Server is a free and open-source bitcoin payment processor which allows you to receive payments in Bitcoin and altcoins directly, with no fees, transaction cost or a middleman.
 * Author:          BTCPay Server
 * Author URI:      https://btcpayserver.org
 * Text Domain:     btcpay-greenfield-for-woocommerce
 * Domain Path:     /languages
 * Version:         2.7.0
 * Requires PHP:    8.0
 * Tested up to:    6.6
 * Requires at least: 6.2
 * WC requires at least: 7.0
 * WC tested up to: 9.3
 */

use BTCPayServer\WC\Admin\Notice;
use BTCPayServer\WC\Gateway\DefaultGateway;
use BTCPayServer\WC\Gateway\SeparateGateways;
use BTCPayServer\WC\Helper\GreenfieldApiAuthorization;
use BTCPayServer\WC\Helper\GreenfieldApiWebhook;
use BTCPayServer\WC\Helper\SatsMode;
use BTCPayServer\WC\Helper\GreenfieldApiHelper;
use BTCPayServer\WC\Helper\Logger;

defined( 'ABSPATH' ) || exit();

define( 'BTCPAYSERVER_VERSION', '2.7.0' );
define( 'BTCPAYSERVER_VERSION_KEY', 'btcpay_gf_version' );
define( 'BTCPAYSERVER_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BTCPAYSERVER_PLUGIN_URL', plugin_dir_url(__FILE__ ) );
define( 'BTCPAYSERVER_PLUGIN_ID', 'btcpay-greenfield-for-woocommerce' );

class BTCPayServerWCPlugin {

	private static $instance;

	public function __construct() {
		$this->includes();

		add_action( 'woocommerce_thankyou_btcpaygf_default', ['BTCPayServerWCPlugin', 'orderStatusThankYouPage'], 10, 1);
		add_action( 'wp_ajax_btcpaygf_modal_checkout', [$this, 'processAjaxModalCheckout'] );
		add_action( 'wp_ajax_btcpaygf_notifications', [$this, 'processAjaxNotification'] );
		add_action( 'wp_ajax_nopriv_btcpaygf_modal_checkout', [$this, 'processAjaxModalCheckout'] );
		add_action( 'admin_enqueue_scripts', [$this, 'enqueueAdminScripts'] );
		add_action( 'wp_ajax_btcpaygf_modal_blocks_checkout', [$this, 'processAjaxModalBlocksCheckout'] );
		add_action( 'wp_ajax_nopriv_btcpaygf_modal_blocks_checkout', [$this, 'processAjaxModalBlocksCheckout'] );

		// Run the updates.
		\BTCPayServer\WC\Helper\UpdateManager::processUpdates();

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

			$this->dependenciesNotification();
			$this->legacyPluginNotification();
			$this->notConfiguredNotification();
			$this->submitReviewNotification();
		}
	}

	public function includes(): void {
		$autoloader = BTCPAYSERVER_PLUGIN_FILE_PATH . 'vendor/autoload.php';
		if (file_exists($autoloader)) {
			/** @noinspection PhpIncludeInspection */
			require_once $autoloader;
		}

		if (get_option('btcpay_gf_separate_gateways') === 'yes' && is_dir(SeparateGateways::GENERATED_PATH)) {
			$generatedFiles = glob(SeparateGateways::GENERATED_PATH . DIRECTORY_SEPARATOR . GreenfieldApiHelper::PM_CLASS_NAME_PREFIX . '*.php');
			foreach($generatedFiles as $file) {
				require_once $file;
			}
		}

		// Make sure WP internal functions are available.
		if ( ! function_exists('is_plugin_active') ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Setup other dependencies.
		// Make SAT / Sats as currency available.
		if (get_option('btcpay_gf_sats_mode') === 'yes') {
			SatsMode::instance();
		}
	}

	/**
	 * Add scripts to admin pages.
	 */
	public function enqueueAdminScripts(): void {
		wp_register_script('btcpaygf-notifications', plugin_dir_url(__FILE__) . 'assets/js/backend/notifications.js', ['jquery'], BTCPAYSERVER_VERSION);
		wp_enqueue_script('btcpaygf-notifications');
		wp_localize_script('btcpaygf-notifications', 'BTCPayNotifications', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('btcpaygf-notifications-nonce')
		]);
	}

	public static function initPaymentGateways($gateways): array {
		// We always load the default gateway that covers all payment methods available on BTCPayServer.
		$gateways[] = DefaultGateway::class;

		// Load payment methods from BTCPay Server as separate gateways.
		if (get_option('btcpay_gf_separate_gateways') === 'yes') {
			// Call init separate payment gateways here.
			if ($separateGateways = \BTCPayServer\WC\Helper\GreenfieldApiHelper::supportedPaymentMethods()) {

				\BTCPayServer\WC\Gateway\SeparateGateways::generateClasses();

				foreach ($separateGateways as $gw) {
					$gateways[] = $gw['className'];
					// Thank you page overrides.
					add_action('woocommerce_thankyou_btcpaygf_' . strtolower($gw['symbol']), ['BTCPayServerWCPlugin', 'orderStatusThankYouPage'], 10, 1);
				}
			}
		}

		return $gateways;
	}

	/**
	 * Displays notice (and link to config page) on admin dashboard if the plugin is not configured yet.
	 */
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

			Notice::addNotice('error', $message);
		}
	}

	/**
	 * Checks and displays notice on admin dashboard if PHP version is too low or WooCommerce not installed.
	 */
	public function dependenciesNotification() {
		// Check PHP version.
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			$versionMessage = sprintf( __( 'Your PHP version is %s but BTCPay Greenfield Payment plugin requires version 8.0+.', 'btcpay-greenfield-for-woocommerce' ), PHP_VERSION );
			Notice::addNotice('error', $versionMessage);
		}

		// Check if WooCommerce is installed.
		if ( ! is_plugin_active('woocommerce/woocommerce.php') ) {
			$wcMessage = __('WooCommerce seems to be not installed. Make sure you do before you activate BTCPayServer Payment Gateway.', 'btcpay-greenfield-for-woocommerce');
			Notice::addNotice('error', $wcMessage);
		}

		// Check if PHP cURL is available.
		if ( ! function_exists('curl_init') ) {
			$curlMessage = __('The PHP cURL extension is not installed. Make sure it is available otherwise this plugin will not work.', 'btcpay-greenfield-for-woocommerce');
			Notice::addNotice('error', $curlMessage);
		}
	}

	/**
	 * Checks and displays notice on admin dashboard if the legacy BTCPay plugin is installed.
	 */
	public function legacyPluginNotification() {
		if ( is_plugin_active('btcpay-for-woocommerce/class-wc-gateway-btcpay.php') ) {
			$legacyMessage = __('Seems you have the old BTCPay for WooCommerce plugin installed. While it should work it is strongly recommended to not run both versions but rely on the maintained version (BTCPay Greenfield for WooCommerce).', 'btcpay-greenfield-for-woocommerce');
			Notice::addNotice('warning', $legacyMessage, true);
		}
	}

	/**
	 * Shows a notice on the admin dashboard to periodically ask for a review.
	 */
	public function submitReviewNotification() {
		if (!get_option('btcpay_gf_review_dismissed_forever') && !get_transient('btcpay_gf_review_dismissed')) {
			$reviewMessage = sprintf(
				__( 'Thank you for using BTCPay for WooCommerce! If you like the plugin, we would love if you %1$sleave us a review%2$s. %3$sRemind me later%4$s %5$sStop reminding me forever!%6$s', 'btcpay-greenfield-for-woocommerce' ),
				'<a href="https://wordpress.org/support/plugin/btcpay-greenfield-for-woocommerce/reviews/?filter=5#new-post" target="_blank">',
				'</a>',
				'<button class="btcpay-review-dismiss">',
				'</button>',
				'<button class="btcpay-review-dismiss-forever">',
				'</button>'
			);

			Notice::addNotice('info', $reviewMessage, false, 'btcpay-review-notice');
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
			$host = filter_var($_POST['host'], FILTER_VALIDATE_URL);

			if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
				wp_send_json_error("Error validating BTCPayServer URL.");
			}

			$permissions = array_merge(GreenfieldApiAuthorization::REQUIRED_PERMISSIONS, GreenfieldApiAuthorization::OPTIONAL_PERMISSIONS);

			try {
				// Create the redirect url to BTCPay instance.
				$url = \BTCPayServer\Client\ApiKey::getAuthorizeUrl(
					$host,
					$permissions,
					'WooCommerce',
					true,
					true,
					home_url('?btcpay-settings-callback'),
					null
				);

				// Store the host to options before we leave the site.
				update_option('btcpay_gf_url', $host);

				// Return the redirect url.
				wp_send_json_success(['url' => $url]);
			} catch (\Throwable $e) {
				Logger::debug('Error fetching redirect url from BTCPay Server.');
			}
		}

		wp_send_json_error("Error processing Ajax request.");
	}

	/**
	 * Handles the modal AJAX callback from the checkout page.
	 */
	public function processAjaxModalCheckout() {

		Logger::debug('Entering ' . __METHOD__);
		Logger::debug('$_POST: ' . print_r($_POST, true));

		$nonce = sanitize_text_field($_POST['apiNonce']);
		if ( ! wp_verify_nonce( $nonce, 'btcpay-nonce' ) ) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		if ( get_option('btcpay_gf_modal_checkout') !== 'yes' ) {
			wp_die('Modal checkout mode not enabled.', '', ['response' => 400]);
		}

		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			WC()->checkout()->process_checkout();
		} catch (\Throwable $e) {
			Logger::debug('Error processing modal checkout ajax callback: ' . $e->getMessage());
		}
	}

	/**
	 * Handles the modal AJAX callback on the blocks checkout page.
	 */
	public function processAjaxModalBlocksCheckout() {

		Logger::debug('Entering ' . __METHOD__);
		Logger::debug('$_POST: ' . print_r($_POST, true));

		$nonce = sanitize_text_field($_POST['apiNonce']);
		if ( ! wp_verify_nonce( $nonce, 'btcpay-nonce' ) ) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		if ( get_option('btcpay_gf_modal_checkout') !== 'yes' ) {
			wp_die('Modal checkout mode not enabled.', '', ['response' => 400]);
		}

		$selectedPaymentGateway = sanitize_text_field($_POST['paymentGateway']);
		$orderId = sanitize_text_field($_POST['orderId']);
		$order = wc_get_order($orderId);

		if ($order) {

			$orderPaymentMethod = $order->get_payment_method();
			if (empty($orderPaymentMethod) || $orderPaymentMethod !== $selectedPaymentGateway) {
				$order->set_payment_method($selectedPaymentGateway);
				$order->save();
			}

			$payment_gateways = \WC_Payment_Gateways::instance();

			if ($payment_gateway = $payment_gateways->payment_gateways()[$selectedPaymentGateway]) {

				// Run the process_payment() method.
				$result = $payment_gateway->process_payment($order->get_id());

				if (isset($result['result']) && $result['result'] === 'success') {
					wp_send_json_success($result);
				} else {
					wp_send_json_error($result);
				}

			} else {
				wp_send_json_error('Payment gateway not found.');
			}
		} else {
			wp_send_json_error('Order not found, stopped processing.');
		}

		wp_die();
	}

	/**
	 * Handles the AJAX callback to dismiss review notification.
	 */
	public function processAjaxNotification() {
		if ( ! check_ajax_referer( 'btcpaygf-notifications-nonce', 'nonce' ) ) {
			wp_die( 'Unauthorized!', '', [ 'response' => 401 ] );
		}

		$dismissForever = filter_var($_POST['dismiss_forever'], FILTER_VALIDATE_BOOL);

		if ($dismissForever) {
			update_option('btcpay_gf_review_dismissed_forever', true);
		} else {
			// Dismiss review notice for 30 days.
			set_transient('btcpay_gf_review_dismissed', true, DAY_IN_SECONDS * 30);
		}

		wp_send_json_success();
	}

	/**
	 * Displays the payment status on the thank you page.
	 */
	public static function orderStatusThankYouPage($order_id)
	{
		if (!$order = wc_get_order($order_id)) {
			return;
		}

		$title = _x('Payment Status', 'btcpay-greenfield-for-woocommerce');

		$orderData = $order->get_data();
		$status = $orderData['status'];

		switch ($status)
		{
			case 'on-hold':
				$statusDesc = _x('Waiting for payment settlement', 'btcpay-greenfield-for-woocommerce');
				break;
			case 'processing':
				$statusDesc = _x('Payment processing', 'btcpay-greenfield-for-woocommerce');
				break;
			case 'completed':
				$statusDesc = _x('Payment settled', 'btcpay-greenfield-for-woocommerce');
				break;
			case 'failed':
				$statusDesc = _x('Payment failed', 'btcpay-greenfield-for-woocommerce');
				break;
			default:
				$statusDesc = _x(ucfirst($status), 'btcpay-greenfield-for-woocommerce');
				break;
		}

		echo "
		<section class='woocommerce-order-payment-status'>
		    <h2 class='woocommerce-order-payment-status-title'>{$title}</h2>
		    <p><strong>{$statusDesc}</strong></p>
		</section>
		";
	}

	/**
	 * Register WooCommerce Blocks support.
	 */
	public static function blocksSupport() {
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register(new \BTCPayServer\WC\Blocks\DefaultGatewayBlocks());
				}
			);
		}
	}

	/**
	 * Gets the main plugin loader instance.
	 *
	 * Ensures only one instance can be loaded.
	 */
	public static function instance(): \BTCPayServerWCPlugin {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

// Start everything up.
function init_btcpay_greenfield() {
	\BTCPayServerWCPlugin::instance();
}

/**
 * Bootstrap stuff on init.
 */
add_action('init', function() {
	// Adding textdomain and translation support.
	load_plugin_textdomain('btcpay-greenfield-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');
	// Setting up and handling custom endpoint for api key redirect from BTCPay Server.
	add_rewrite_endpoint('btcpay-settings-callback', EP_ROOT);
	// Flush rewrite rules only once after activation.
	if ( ! get_option('btcpaygf_permalinks_flushed') ) {
		flush_rewrite_rules(false);
		update_option('btcpaygf_permalinks_flushed', 1);
	}
});

// Action links on plugin overview.
add_filter( 'plugin_action_links_btcpay-greenfield-for-woocommerce/btcpay-greenfield-for-woocommerce.php', function ( $links ) {

	// Settings link.
	$settings_url = esc_url( add_query_arg(
		[
		'page' => 'wc-settings',
		'tab' => 'btcpay_settings'
		],
		get_admin_url() . 'admin.php'
	) );

	$settings_link = "<a href='$settings_url'>" . __( 'Settings', 'btcpay-greenfield-for-woocommerce' ) . '</a>';

	$logs_link = "<a target='_blank' href='" . Logger::getLogFileUrl() . "'>" . __('Debug log', 'btcpay-greenfield-for-woocommerce') . "</a>";

	$docs_link = "<a target='_blank' href='". esc_url('https://docs.btcpayserver.org/WooCommerce/') . "'>" . __('Docs', 'btcpay-greenfield-for-woocommerce') . "</a>";

	$support_link = "<a target='_blank' href='". esc_url('https://chat.btcpayserver.org/') . "'>" . __('Support Chat', 'btcpay-greenfield-for-woocommerce') . "</a>";

	array_unshift(
		$links,
		$settings_link,
		$logs_link,
		$docs_link,
		$support_link
	);

	return $links;
} );

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
	Logger::debug('Redirect payload: ' . print_r($rawData, true));

	$data = json_decode( $rawData, true );

	// Check if the payload api key comes from the actually requested server. Abort if not.
	$storedUrl = get_option('btcpay_gf_url');
	if (!GreenfieldApiHelper::checkApiKeyWorks($storedUrl, sanitize_text_field($_POST['apiKey']))) {
		$messageAbort = __('Error on verifiying redirected API wey with stored BTCPay Server url. Aborting API wizard. Please try again or do a manual setup.', 'btcpay-greenfield-for-woocommerce');
		Logger::debug($messageAbort);
		Notice::addNotice('error', $messageAbort);
		wp_redirect($btcPaySettingsUrl);
	}

	// Data does get submitted with url-encoded payload, so parse $_POST here.
	if (!empty($_POST)) {
		$data['apiKey'] = sanitize_html_class($_POST['apiKey'] ?? null);
		if (is_array($_POST['permissions'])) {
			foreach ($_POST['permissions'] as $key => $value) {
				$data['permissions'][$key] = sanitize_text_field($_POST['permissions'][$key] ?? null);
			}
		}
	}

	if (isset($data['apiKey']) && isset($data['permissions'])) {
		$apiData = new \BTCPayServer\WC\Helper\GreenfieldApiAuthorization($data);
		if ($apiData->hasSingleStore() && $apiData->hasRequiredPermissions()) {
			update_option('btcpay_gf_api_key', $apiData->getApiKey());
			update_option('btcpay_gf_store_id', $apiData->getStoreID());
			update_option('btcpay_gf_connection_details', 'yes');
			Notice::addNotice('success', __('Successfully received api key and store id from BTCPay Server API. Please finish setup by saving this settings form.', 'btcpay-greenfield-for-woocommerce'));

			// Register a webhook.
			if (GreenfieldApiWebhook::registerWebhook($storedUrl, $apiData->getApiKey(), $apiData->getStoreID())) {
				$messageWebhookSuccess = __( 'Successfully registered a new webhook on BTCPay Server.', 'btcpay-greenfield-for-woocommerce' );
				Notice::addNotice('success', $messageWebhookSuccess, true );
				Logger::debug( $messageWebhookSuccess );
			} else {
				$messageWebhookError = __( 'Could not register a new webhook on the store.', 'btcpay-greenfield-for-woocommerce' );
				Notice::addNotice('error', $messageWebhookError );
				Logger::debug($messageWebhookError, true);
				// Cleanup existing conf.
				delete_option('btcpay_gf_webhook');
			}

			wp_redirect($btcPaySettingsUrl);
		} else {
			Notice::addNotice('error', __('Please make sure you only select one store on the BTCPay API authorization page.', 'btcpay-greenfield-for-woocommerce'));
			wp_redirect($btcPaySettingsUrl);
		}
	}

	Notice::addNotice('error', __('Error processing the data from BTCPay. Please try again.', 'btcpay-greenfield-for-woocommerce'));
	wp_redirect($btcPaySettingsUrl);
});

// Installation routine.
register_activation_hook( __FILE__, function() {
	update_option('btcpaygf_permalinks_flushed', 0);
	update_option( BTCPAYSERVER_VERSION_KEY, BTCPAYSERVER_VERSION );
});

// Initialize payment gateways and plugin.
add_filter( 'woocommerce_payment_gateways', [ 'BTCPayServerWCPlugin', 'initPaymentGateways' ] );
add_action( 'plugins_loaded', 'init_btcpay_greenfield', 0 );

// Mark support for HPOS / COT.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// Register WooCommerce Blocks integration.
add_action( 'woocommerce_blocks_loaded', [ 'BTCPayServerWCPlugin', 'blocksSupport' ] );
