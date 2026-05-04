<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Admin;

use BTCPayServer\Client\ApiKey;
use BTCPayServer\Client\StorePaymentMethod;
use BTCPayServer\Client\Subscriptions;
use BTCPayServer\WC\Gateway\SeparateGateways;
use BTCPayServer\WC\Helper\GreenfieldApiAuthorization;
use BTCPayServer\WC\Helper\GreenfieldApiHelper;
use BTCPayServer\WC\Helper\GreenfieldApiWebhook;
use BTCPayServer\WC\Helper\Logger;
use BTCPayServer\WC\Helper\OrderStates;

/**
 * todo: add validation of host/url
 */
class GlobalSettings extends \WC_Settings_Page {
	private GreenfieldApiHelper $apiHelper;
	public function __construct()
	{
		$this->id = 'btcpay_settings';
		$this->label = __( 'BTCPay Settings', 'btcpay-greenfield-for-woocommerce' );
		$this->apiHelper = new GreenfieldApiHelper();
		// Register custom field type order_states with OrderStatesField class.
		add_action('woocommerce_admin_field_order_states', [(new OrderStates()), 'renderOrderStatesHtml']);
		add_action('woocommerce_admin_field_custom_markup', [$this, 'output_custom_markup_field']);

		if (is_admin()) {
			// Register and include JS.
			wp_register_script('btcpay_gf_global_settings', BTCPAYSERVER_PLUGIN_URL . 'assets/js/backend/apiKeyRedirect.js', ['jquery'], BTCPAYSERVER_VERSION);
			wp_enqueue_script('btcpay_gf_global_settings');
			wp_localize_script( 'btcpay_gf_global_settings',
				'BTCPayGlobalSettings',
				[
					'url' => admin_url( 'admin-ajax.php' ),
					'apiNonce' => wp_create_nonce( 'btcpaygf-api-url-nonce' ),
				]
			);

			// Order states warning script.
			wp_register_script('btcpay_gf_order_states_warning', BTCPAYSERVER_PLUGIN_URL . 'assets/js/backend/orderStatesWarning.js', ['jquery'], BTCPAYSERVER_VERSION);
			wp_enqueue_script('btcpay_gf_order_states_warning');
			wp_localize_script('btcpay_gf_order_states_warning',
				'BTCPayOrderStatesWarning',
				[
					'warningText' => __('Warning: Changing this from the default "On hold" while "Protect order status" is enabled is not recommended as it could have unintended side effects. <a href="https://docs.btcpayserver.org/FAQ/Integrations/#overriding-the-paid-payment-status" target="_blank">Learn more</a>.', 'btcpay-greenfield-for-woocommerce'),
				]
			);

			// Register and include CSS.
			wp_register_style( 'btcpay_gf_admin_styles', BTCPAYSERVER_PLUGIN_URL . 'assets/css/admin.css', array(), BTCPAYSERVER_VERSION );
			wp_enqueue_style( 'btcpay_gf_admin_styles' );

			// Check if PHP bcmath is available.
			if ( ! function_exists('bcdiv') ) {
				$bcmathMessage = __('The PHP bcmath extension is not installed. Make sure it is available otherwise the "Sats-Mode" will not work.', 'btcpay-greenfield-for-woocommerce');
				Notice::addNotice('error', $bcmathMessage);
			}
		}
		parent::__construct();
	}

	public function get_sections(): array
	{
		$sections = [
			'' => __( 'General', 'btcpay-greenfield-for-woocommerce' ),
		];

		if ( class_exists( 'WC_Subscriptions' ) ) {
			$sections['subscription_products'] = __( 'Subscription Products', 'btcpay-greenfield-for-woocommerce' );
		}

		return $sections;
	}

	public function output(): void
	{
		global $current_section;

		if ( $current_section === 'subscription_products' ) {
			$this->outputSubscriptionProducts();
			return;
		}

		echo '<h1>' . _x('BTCPay Server Payments settings', 'global_settings', 'btcpay-greenfield-for-woocommerce') . '</h1>';
		$settings = $this->get_settings_for_default_section();
		\WC_Admin_Settings::output_fields($settings);
	}

	public function get_settings_for_default_section(): array
	{
		return $this->getGlobalSettings();
	}

	public function getGlobalSettings(): array
	{
		Logger::debug('Entering Global Settings form.');

		// Check setup status and prepare output.
		$storedApiKey = get_option('btcpay_gf_api_key');
		$storedStoreId = get_option('btcpay_gf_store_id');
		$storedUrl = get_option('btcpay_gf_url');

		$setupStatus = '';
		if ($storedUrl && $storedStoreId && $storedApiKey) {
			$setupStatus = '<p class="btcpay-connection-success">' . _x('BTCPay Server connected.', 'global_settings', 'btcpay-greenfield-for-woocommerce') . '</p>';
		} else {
			$setupStatus = '<p class="btcpay-connection-error">' . _x('Not connected. Please use the setup wizard above or check advanced settings to manually enter connection settings.', 'global_settings', 'btcpay-greenfield-for-woocommerce') . '</p>';
		}

		// Check webhook status and prepare output.
		$whStatus = '';
		$whId = '';
		$storedWebhookSecret = '';
		// Can't use apiHelper because of caching.
		$webhookConfig = get_option('btcpay_gf_webhook', []);
		if (is_array($webhookConfig)) {
			$whId = isset($webhookConfig['id']) ? (string) $webhookConfig['id'] : '';
			$storedWebhookSecret = GreenfieldApiWebhook::isUsableSecret($webhookConfig['secret'] ?? null)
				? $webhookConfig['secret']
				: '';
		}

		// Todo: check why $this->apiHelper->webhookIsSetup() is cached, also others above.
		if ($storedWebhookSecret !== '') {
			$whStatus = '<p class="btcpay-connection-success">' . _x('Webhook setup automatically.', 'global_settings', 'btcpay-greenfield-for-woocommerce') . ' ID: ' . $whId . '</p>';
		} else {
			$whStatus = '<p class="btcpay-connection-error">' . _x('No webhook setup, yet.', 'global_settings', 'btcpay-greenfield-for-woocommerce') . '</p>';
		}

		if ($this->apiHelper->webhookIsSetupManual()) {
			$whStatus = '<p class="btcpay-connection-success">' . _x('Webhook setup manually with webhook secret.', 'global_settings', 'btcpay-greenfield-for-woocommerce') . ' ID: ' . $whId . '</p>';
		}

		return [
			// Section connection.
			'title_connection' => [
				'title' => esc_html_x(
					'Connection settings',
					'global_settings',
					'btcpay-greenfield-for-woocommerce'
				),
				'type' => 'title',
				'desc' => sprintf(
					/* translators: 1: BTCPay plugin version, 2: Current PHP major and minor version. */
					_x( 'This plugin version is %1$s and your PHP version is %2$s. Check out our <a href="https://docs.btcpayserver.org/WooCommerce/" target="_blank">installation instructions</a>. If you need assistance, please come on our <a href="https://chat.btcpayserver.org" target="_blank">chat</a>. Thank you for using BTCPay!', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
					BTCPAYSERVER_VERSION,
					PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
				),
				'id' => 'btcpay_gf_connection'
			],
			'url' => [
				'title' => esc_html_x(
					'BTCPay Server URL',
					'global_settings',
					'btcpay-greenfield-for-woocommerce'
				),
				'type' => 'text',
				'desc' => esc_html_x( 'URL/host to your BTCPay Server instance. Note: if you use a self hosted node like Umbrel, RaspiBlitz, myNode, etc. you will have to make sure your node is reachable from the internet. You can do that through <a href="https://docs.btcpayserver.org/Deployment/ReverseProxyToTor/" target="_blank">Tor</a>, <a href="https://docs.btcpayserver.org/Docker/cloudflare-tunnel/" target="_blank">Cloudflare</a> or <a href="https://docs.btcpayserver.org/Deployment/ReverseSSHtunnel/" target="_blank">SSH (advanced)</a>.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'placeholder' => esc_attr_x( 'https://mainnet.demo.btcpayserver.org', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'desc_tip'    => true,
				'id' => 'btcpay_gf_url'
			],
			'wizard' => [
				'title'       => esc_html_x( 'Setup wizard', 'global_settings','btcpay-greenfield-for-woocommerce' ),
				'type'  => 'custom_markup',
				'markup'  => '<button class="button button-primary btcpay-api-key-link" target="_blank">Generate API key</button>',
				'id'    => 'btcpay_gf_wizard_button' // a unique ID
			],
			'status' => [
				'title'       => esc_html_x( 'Setup status', 'global_settings','btcpay-greenfield-for-woocommerce' ),
				'type'  => 'custom_markup',
				'markup'  => $setupStatus,
				'id'    => 'btcpay_gf_status'
			],
			'connection_details' => [
				'title' => __( 'Advanced settings', 'btcpay-greenfield-for-woocommerce' ),
				'type' => 'checkbox',
				'default' => 'no',
				'desc' => _x( 'Show all connection settings / manual setup.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'id' => 'btcpay_gf_connection_details'
			],
			'api_key' => [
				'title'       => esc_html_x( 'BTCPay API Key', 'global_settings','btcpay-greenfield-for-woocommerce' ),
				'type'        => 'password',
				'desc' => _x( 'Your BTCPay API Key. If you do not have any yet use the setup wizard above.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'default'     => '',
				'id' => 'btcpay_gf_api_key'
			],
			'store_id' => [
				'title'       => esc_html_x( 'Store ID', 'global_settings','btcpay-greenfield-for-woocommerce' ),
				'type'        => 'text',
				'desc_tip' => _x( 'Your BTCPay Store ID. You can find it on the store settings page on your BTCPay Server.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'default'     => '',
				'id' => 'btcpay_gf_store_id'
			],
			'whsecret' => [
				'title' => esc_html_x( 'Webhook secret (optional)', 'global_settings','btcpay-greenfield-for-woocommerce' ),
				'type' => 'password',
				'desc' => _x( 'Leave the existing secret unchanged to keep the current webhook. Clear it to create a new webhook automatically on save. For manual setup, copy the exact secret from BTCPay Server (at least 16 characters for a new secret). Use a randomly generated secret.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'desc_tip' => _x( 'The BTCPay webhook endpoint can be reached here: ' . site_url() . '/wc-api/btcpaygf_default/', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'default' => '',
				'value' => $storedWebhookSecret,
				'is_option' => false,
				'custom_attributes' => ['autocomplete' => 'new-password'],
				'id' => 'btcpay_gf_whsecret'
			],
			'whstatus' => [
				'title'       => esc_html_x( 'Webhook status', 'global_settings','btcpay-greenfield-for-woocommerce' ),
				'type'  => 'custom_markup',
				'markup'  => $whStatus,
				'id'    => 'btcpay_gf_whstatus'
			],
			'sectionend_connection' => [
				'type' => 'sectionend',
				'id' => 'btcpay_gf_connection',
			],
			// Section general.
			'title' => [
				'title' => esc_html_x(
					'General settings',
					'global_settings',
					'btcpay-greenfield-for-woocommerce'
				),
				'type' => 'title',
				'id' => 'btcpay_gf'
			],
			'default_description' => [
				'title'       => esc_html_x( 'Default checkout text', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'type'        => 'textarea',
				'desc' => esc_html_x( 'Text shown at checkout unless overridden in the payment gateway settings.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'default'     => esc_html_x('You will be redirected to BTCPay to complete your purchase.', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
				'desc_tip'    => true,
				'id' => 'btcpay_gf_default_description'
			],
			'transaction_speed' => [
				'title'       => esc_html_x( 'Invoice pass to "settled" state after', 'btcpay-greenfield-for-woocommerce' ),
				'type'        => 'select',
				'desc' => esc_html_x('An invoice becomes settled after the payment has this many confirmations...', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
				'options'     => [
					'default'    => _x('Keep BTCPay Server store level configuration', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
					'high'       => _x('0 confirmation on-chain', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
					'medium'     => _x('1 confirmation on-chain', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
					'low-medium' => _x('2 confirmations on-chain', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
					'low'        => _x('6 confirmations on-chain', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
				],
				'default'     => 'default',
				'desc_tip'    => true,
				'id' => 'btcpay_gf_transaction_speed'
			],
			'order_states' => [
				'type' => 'order_states',
				'id' => 'btcpay_gf_order_states'
			],
			'protect_orders' => [
				'title' => __( 'Protect order status', 'btcpay-greenfield-for-woocommerce' ),
				'type' => 'checkbox',
				'default' => 'yes',
				'desc' => _x( 'Protects order status from changing if it is already "processing" or "completed". This will protect against orders getting cancelled via webhook if they were paid in the meantime with another payment gateway. Default is ON.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'id' => 'btcpay_gf_protect_order_status'
			],
			'modal_checkout' => [
				'title' => __( 'Modal checkout', 'btcpay-greenfield-for-woocommerce' ),
				'type' => 'checkbox',
				'default' => 'no',
				'desc' => _x( 'Opens a modal overlay on the checkout page instead of redirecting to BTCPay Server.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'id' => 'btcpay_gf_modal_checkout'
			],
			'separate_gateways' => [
				'title' => __( 'Separate Payment Gateways', 'btcpay-greenfield-for-woocommerce' ),
				'type' => 'checkbox',
				'default' => 'no',
				'desc' => _x( 'Make all supported and enabled payment methods available as their own payment gateway. This opens new possibilities like discounts for specific payment methods. See our <a href="https://docs.btcpayserver.org/FAQ/Integrations/#how-to-configure-additional-token-support-separate-payment-gateways" target="_blank">full guide here</a>', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'id' => 'btcpay_gf_separate_gateways'
			],
			'sats_mode' => [
				'title' => __( 'Sats-Mode', 'btcpay-greenfield-for-woocommerce' ),
				'type' => 'checkbox',
				'default' => 'no',
				'desc' => _x( 'Makes Satoshis/Sats available as currency "SAT" (can be found in WooCommerce->Settings->General) and handles conversion to BTC before creating the invoice on BTCPay.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'id' => 'btcpay_gf_sats_mode'
			],
			'refund_note_visible' => [
				'title' => __( 'Customer visible refunds', 'btcpay-greenfield-for-woocommerce' ),
				'type' => 'checkbox',
				'default' => 'no',
				'desc' => _x( 'If enabled, it will show the order refund note also to the customer, trigger an email to customer with the refund link, and display refund links on the customer order details page.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'id' => 'btcpay_gf_refund_note_visible'
			],
			'debug' => [
				'title' => __( 'Debug Log', 'btcpay-greenfield-for-woocommerce' ),
				'type' => 'checkbox',
				'default' => 'no',
				'desc' => sprintf(
					/* translators: %s: URL of the WooCommerce log viewer. */
					_x( 'Enable logging <a href="%s" class="button">View Logs</a>', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
					Logger::getLogFileUrl()
				),
				'id' => 'btcpay_gf_debug'
			],
			'sectionend' => [
				'type' => 'sectionend',
				'id' => 'btcpay_gf',
			],
		];
	}

	/**
	 * On saving the settings form make sure to check if the API key works and register a webhook if needed.
	 */
	public function save() {
		global $current_section;

		if ( $current_section === 'subscription_products' ) {
			$this->saveSubscriptionProducts();
			return;
		}

		// If we have url, storeID and apiKey we want to check if the api key works and register a webhook.
		Logger::debug('Saving GlobalSettings.');
		if ( $this->hasNeededApiCredentials() ) {
			// Check if api key works for this store.
			$apiUrl  = esc_url_raw( wp_unslash( $_POST['btcpay_gf_url'] ) );
			$apiKey  = sanitize_text_field( wp_unslash( $_POST['btcpay_gf_api_key'] ) );
			$storeId = sanitize_text_field( wp_unslash( $_POST['btcpay_gf_store_id'] ) );
			// Check if the provided API key has the right scope and permissions.
			try {
				$apiClient  = new ApiKey( $apiUrl, $apiKey );
				$apiKeyData = $apiClient->getCurrent();
				$apiAuth    = new GreenfieldApiAuthorization( $apiKeyData->getData() );
				$hasError   = false;

				if ( ! $apiAuth->hasSingleStore() ) {
					$messageSingleStore = __( 'The provided API key scope is valid for multiple stores, please make sure to create one for a single store.', 'btcpay-greenfield-for-woocommerce' );
					Notice::addNotice('error', $messageSingleStore );
					Logger::debug($messageSingleStore, true);
					$hasError = true;
				}

				if ( ! $apiAuth->hasRequiredPermissions() ) {
					$messagePermissionsError = sprintf(
						/* translators: %s: Comma-separated list of required BTCPay API permissions. */
						__( 'The provided API key does not match the required permissions. Please make sure the following permissions are are given: %s', 'btcpay-greenfield-for-woocommerce' ),
						implode( ', ', GreenfieldApiAuthorization::REQUIRED_PERMISSIONS )
					);
					Notice::addNotice('error', $messagePermissionsError );
					Logger::debug( $messagePermissionsError, true );
					$hasError = true;
				}

				// Check server info and sync status.
				if ($serverInfo = GreenfieldApiHelper::getServerInfo()) {
					Logger::debug( 'Serverinfo: ' . print_r( $serverInfo, true ), true );

					// Show/log notice if the node is not fully synced yet and no invoice creation is possible.
					if ((int) $serverInfo->getData()['fullySynched'] !== 1 ) {
						$messageNotSynched = __( 'Your BTCPay Server is not fully synched yet. Until fully synched the checkout will not work.', 'btcpay-greenfield-for-woocommerce' );
						Notice::addNotice('error', $messageNotSynched, false);
						Logger::debug($messageNotSynched);
					}

					// Show a notice if the BTCPay Server version does not work with refunds.
					// This needs the btcpay.store.cancreatenonapprovedpullpayments permission which was introduced with
					// BTCPay Server v1.7.6
					if (version_compare($serverInfo->getVersion(), '1.7.6', '<')) {
						$messageRefundsSupport = __( 'Your BTCPay Server version does not support refunds, please update to at least version 1.7.6 or newer.', 'btcpay-greenfield-for-woocommerce' );
						Notice::addNotice('error', $messageRefundsSupport, false);
						Logger::debug($messageRefundsSupport);
					} else {
						// Check if the configured api key has refunds permission; show notice if not.
						if (!$apiAuth->hasRefundsPermission()) {
							$messageRefundsPermissionMissing = __( 'Your api key does not support refunds, if you want to use that feature you need to create a new API key with permission. See our guide <a href="https://docs.btcpayserver.org/WooCommerce/#create-a-new-api-key" target="_blank" rel="noreferrer">here</a>.', 'btcpay-greenfield-for-woocommerce' );
							Notice::addNotice('info', $messageRefundsPermissionMissing, true);
							Logger::debug($messageRefundsPermissionMissing);
						}
					}
				}

				// Continue creating the webhook if the API key permissions are OK.
				if ( false === $hasError ) {
					if (!$this->saveWebhookSettings($apiUrl, $apiKey, $storeId)) {
						return;
					}

					// Make sure there is at least one payment method configured.
					try {
						$pmClient = new StorePaymentMethod( $apiUrl, $apiKey );
						if (($pmClient->getPaymentMethods($storeId)) === []) {
							$messagePaymentMethodsError = __( 'No wallet configured on your BTCPay Server store settings. Make sure to add at least one otherwise this plugin will not work.', 'btcpay-greenfield-for-woocommerce' );
							Notice::addNotice('error', $messagePaymentMethodsError );
							Logger::debug($messagePaymentMethodsError, true);
						}
					} catch (\Throwable $e) {
						$messagePaymentMethodsCallError = sprintf(
							/* translators: %s: Error message from the BTCPay API request. */
							__('Exception loading wallet information (payment methods) from BTCPay Server: %s.', 'btcpay-greenfield-for-woocommerce'),
							$e->getMessage()
						);
						Logger::debug($messagePaymentMethodsCallError);
						Notice::addNotice('error', $messagePaymentMethodsCallError );
					}
				}
			} catch ( \Throwable $e ) {
				$messageException = sprintf(
					/* translators: %s: Error message from the BTCPay API request. */
					__( 'Error fetching data for this API key from server. Please check if the key is valid. Error: %s', 'btcpay-greenfield-for-woocommerce' ),
					$e->getMessage()
				);
				Notice::addNotice('error', $messageException );
				Logger::debug($messageException, true);
			}

		} else {
			$messageNotConnecting = 'Did not try to connect to BTCPay Server API because one of the required information was missing: URL, key or storeID';
			Notice::addNotice('warning', $messageNotConnecting);
			Logger::debug($messageNotConnecting);
		}

		// If Sats-Mode enabled but bcmath missing show notice and delete the setting.
		$satsMode = sanitize_text_field( $_POST['btcpay_gf_sats_mode'] ?? '' );
		if ( $satsMode && ! function_exists('bcdiv') ) {
			unset($_POST['btcpay_gf_sats_mode']);
			$bcmathMessage = __('The PHP bcmath extension is not installed. Make sure it is available otherwise the "Sats-Mode" will not work. Disabled Sats-Mode until requirements are met.', 'btcpay-greenfield-for-woocommerce');
			Notice::addNotice('error', $bcmathMessage);
		}

		parent::save();

		// Purge separate payment methods cache.
		SeparateGateways::cleanUpGeneratedFilesAndCache();
		GreenfieldApiHelper::clearSupportedPaymentMethodsCache();
	}

	/**
	 * Keep automatic webhooks automatic unless a different secret is explicitly entered.
	 */
	private function saveWebhookSettings(string $apiUrl, string $apiKey, string $storeId): bool {
		$storedWebhook = get_option('btcpay_gf_webhook', []);
		$storedWebhook = is_array($storedWebhook) ? $storedWebhook : [];
		$storedWebhookSecret = GreenfieldApiWebhook::isUsableSecret($storedWebhook['secret'] ?? null)
			? $storedWebhook['secret']
			: '';
		$webhookSecretWasSubmitted = array_key_exists('btcpay_gf_whsecret', $_POST);
		$submittedSecret = $webhookSecretWasSubmitted ? wp_unslash($_POST['btcpay_gf_whsecret']) : $storedWebhookSecret;

		// Signing secrets are opaque: sanitizing or trimming them changes the signature.
		if (!is_string($submittedSecret) || ($submittedSecret !== '' && !GreenfieldApiWebhook::isUsableSecret($submittedSecret))) {
			\WC_Admin_Settings::add_error(__('Invalid webhook secret. Copy the actual secret from BTCPay Server; "manual" is not a secret. Settings were not saved.', 'btcpay-greenfield-for-woocommerce'));
			return false;
		}

		$secretChanged = $submittedSecret !== '' && $submittedSecret !== $storedWebhookSecret;
		// Existing shorter secrets remain valid; apply the minimum only to newly entered secrets.
		if ($secretChanged && preg_match('/\A.{16,}\z/us', $submittedSecret) !== 1) {
			\WC_Admin_Settings::add_error(__('A new webhook secret must contain at least 16 characters. Copy a randomly generated secret from BTCPay Server. Settings were not saved.', 'btcpay-greenfield-for-woocommerce'));
			return false;
		}

		$regenerateWebhook = $webhookSecretWasSubmitted && $submittedSecret === '' && !empty($storedWebhook['id']);
		$manualWhSecret = ($storedWebhook['id'] ?? '') === 'manual' ? $submittedSecret : null;

		try {
			if ($secretChanged) {
				// "manual" identifies the setup mode, never the signing secret.
				$manualWebhook = ['id' => 'manual', 'secret' => $submittedSecret, 'url' => 'manual'];
				if (!update_option('btcpay_gf_webhook', $manualWebhook) && get_option('btcpay_gf_webhook') !== $manualWebhook) {
					throw new \RuntimeException('Could not store the manual webhook configuration.');
				}
				$message = __('Successfully setup manual webhook.', 'btcpay-greenfield-for-woocommerce');
			} elseif (!$regenerateWebhook && GreenfieldApiWebhook::webhookExists($apiUrl, $apiKey, $storeId, $manualWhSecret)) {
				$message = __('Webhook already exists, skipping webhook creation.', 'btcpay-greenfield-for-woocommerce');
				Notice::addNotice('info', $message, true);
				Logger::debug($message);
				return true;
			} else {
				$registeredWebhook = GreenfieldApiWebhook::registerWebhook($apiUrl, $apiKey, $storeId);
				if (!$registeredWebhook) {
					throw new \RuntimeException('Could not register a new webhook on the store.');
				}

				// Only delete a replaced webhook belonging to this server and store.
				if (
					$regenerateWebhook &&
					$storedWebhook['id'] !== 'manual' &&
					$storedWebhook['id'] !== $registeredWebhook->getId() &&
					rtrim((string) get_option('btcpay_gf_url'), '/') === rtrim($apiUrl, '/') &&
					get_option('btcpay_gf_store_id') === $storeId
				) {
					GreenfieldApiWebhook::deleteWebhook($apiUrl, $apiKey, $storeId, (string) $storedWebhook['id']);
				}
				$message = __('Successfully registered a new webhook on BTCPay Server.', 'btcpay-greenfield-for-woocommerce');
			}

			Notice::addNotice('success', $message, true);
			Logger::debug($message);
			return true;
		} catch (\Throwable $e) {
			Logger::debug('Could not save webhook settings: ' . $e->getMessage(), true);
			\WC_Admin_Settings::add_error(__('Could not verify or save the webhook on BTCPay Server. Previous settings were kept. Please try again.', 'btcpay-greenfield-for-woocommerce'));
			return false;
		}
	}

	private function hasNeededApiCredentials(): bool {
		if (
			!empty($_POST['btcpay_gf_url']) &&
			!empty($_POST['btcpay_gf_api_key']) &&
			!empty($_POST['btcpay_gf_store_id'])
		) {
			return true;
		}
		return false;
	}

	public function output_custom_markup_field($value) {
		echo '<tr valign="top">';
		if (!empty($value['title'])) {
			echo '<th scope="row" class="titledesc">' . esc_html($value['title']) . '</th>';
		} else {
			echo '<th scope="row" class="titledesc">&nbsp;</th>';
		}

		echo '<td class="forminp" id="' . $value['id'] . '">';
		echo $value['markup'];
		echo '</td>';
		echo '</tr>';
	}

	private function outputSubscriptionProducts(): void
	{
		if ( ! $this->apiHelper->configured ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Please configure your BTCPay Server connection first.', 'btcpay-greenfield-for-woocommerce' );
			echo '</p></div>';
			return;
		}

		if ( ! $this->apiHelper->apiKeyHasManageSubscribersPermission() ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Your API key does not have the "Manage Subscribers" permission. Please create a new API key with this permission to use subscription features.', 'btcpay-greenfield-for-woocommerce' );
			echo '</p></div>';
			return;
		}

		$offerings = $this->getOfferingsWithPlans();
		$subscriptionProducts = $this->getSubscriptionProducts();
		$mappings = get_option( 'btcpay_gf_subscription_mappings', [] );

		$mappedProductIds = [];
		foreach ( $mappings as $mapping ) {
			if ( ! empty( $mapping['product_id'] ) ) {
				$mappedProductIds[ $mapping['product_id'] ] = $mapping['offering_id'] . '|' . $mapping['plan_id'];
			}
		}

		echo '<h2>' . esc_html__( 'Subscription Product Mapping', 'btcpay-greenfield-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Map your BTCPay Server subscription plans to WooCommerce subscription products. Each plan can be mapped to one product and vice versa.', 'btcpay-greenfield-for-woocommerce' ) . '</p>';

		if ( empty( $offerings ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'No offerings found on your BTCPay Server. Please create an offering with plans first.', 'btcpay-greenfield-for-woocommerce' );
			echo '</p></div>';
			return;
		}

		if ( empty( $subscriptionProducts ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'No subscription products found in your store. Please create subscription products in WooCommerce first.', 'btcpay-greenfield-for-woocommerce' );
			echo '</p></div>';
			return;
		}

		echo '<form method="post" action="">';
		wp_nonce_field( 'btcpay_gf_subscription_mappings', 'btcpay_gf_subscription_mappings_nonce' );

		foreach ( $offerings as $offering ) {
			$plans = $offering['plans'];
			if ( empty( $plans ) ) {
				continue;
			}

			$offeringLabel = ! empty( $offering['app_name'] ) ? $offering['app_name'] : $offering['id'];

			echo '<table class="btcpay-subscription-mapping widefat striped">';
			echo '<thead><tr>';
			echo '<th colspan="2"><strong>' . esc_html( sprintf(
				__( 'Offering: %s', 'btcpay-greenfield-for-woocommerce' ),
				$offeringLabel
			) ) . '</strong></th>';
			echo '</tr><tr>';
			echo '<th>' . esc_html__( 'Plan', 'btcpay-greenfield-for-woocommerce' ) . '</th>';
			echo '<th>' . esc_html__( 'WooCommerce Product', 'btcpay-greenfield-for-woocommerce' ) . '</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ( $plans as $plan ) {
				$planKey = $offering['id'] . '|' . $plan['id'];
				$currentProductId = '';
				foreach ( $mappings as $mapping ) {
					if ( $mapping['offering_id'] === $offering['id'] && $mapping['plan_id'] === $plan['id'] ) {
						$currentProductId = $mapping['product_id'];
						break;
					}
				}

				$planLabel = $plan['name'];
				if ( ! empty( $plan['price'] ) && ! empty( $plan['currency'] ) ) {
					$planLabel .= ' (' . $plan['price'] . ' ' . strtoupper( $plan['currency'] ) . '/' . $plan['recurring_type'] . ')';
				}

				echo '<tr>';
				echo '<td>' . esc_html( $planLabel ) . '</td>';
				echo '<td>';
				echo '<select name="btcpay_mapping[' . esc_attr( $offering['id'] ) . '][' . esc_attr( $plan['id'] ) . ']" class="btcpay-plan-product-select">';
				echo '<option value="">' . esc_html__( '— Not mapped —', 'btcpay-greenfield-for-woocommerce' ) . '</option>';

				foreach ( $subscriptionProducts as $product ) {
					$productId = $product['id'];
					$disabled = '';
					if ( isset( $mappedProductIds[ $productId ] ) && $mappedProductIds[ $productId ] !== $planKey ) {
						$disabled = ' disabled';
					}
					$selected = ( (int) $currentProductId === $productId ) ? ' selected' : '';
					echo '<option value="' . esc_attr( $productId ) . '"' . $selected . $disabled . '>';
					echo esc_html( $product['name'] . ' (#' . $productId . ')' );
					if ( $disabled ) {
						echo esc_html( ' — ' . __( 'already mapped', 'btcpay-greenfield-for-woocommerce' ) );
					}
					echo '</option>';
				}

				echo '</select>';
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<p class="submit"><button type="submit" class="button button-primary" value="save">';
		echo esc_html__( 'Save Mappings', 'btcpay-greenfield-for-woocommerce' );
		echo '</button></p>';
		echo '</form>';
	}

	private function saveSubscriptionProducts(): void
	{
		if ( ! isset( $_POST['btcpay_gf_subscription_mappings_nonce'] )
			|| ! wp_verify_nonce( $_POST['btcpay_gf_subscription_mappings_nonce'], 'btcpay_gf_subscription_mappings' )
		) {
			return;
		}

		$rawMappings = $_POST['btcpay_mapping'] ?? [];
		$mappings = [];
		$usedProductIds = [];

		foreach ( $rawMappings as $offeringId => $plans ) {
			$offeringId = sanitize_text_field( $offeringId );
			if ( ! is_array( $plans ) ) {
				continue;
			}

			foreach ( $plans as $planId => $productId ) {
				$planId = sanitize_text_field( $planId );
				$productId = absint( $productId );
				if ( ! $productId ) {
					continue;
				}

				if ( in_array( $productId, $usedProductIds, true ) ) {
					continue;
				}

				$mappings[] = [
					'offering_id' => $offeringId,
					'plan_id'     => $planId,
					'product_id'  => $productId,
				];
				$usedProductIds[] = $productId;
			}
		}

		update_option( 'btcpay_gf_subscription_mappings', $mappings );
		Notice::addNotice( 'success', __( 'Subscription product mappings saved.', 'btcpay-greenfield-for-woocommerce' ) );
	}

	private function getOfferingsWithPlans(): array
	{
		$cacheKey = 'btcpay_gf_offerings';
		$cached = get_transient( $cacheKey );
		if ( $cached !== false ) {
			return $cached;
		}

		$offerings = [];
		try {
			$client = new Subscriptions( $this->apiHelper->url, $this->apiHelper->apiKey );
			$offeringList = $client->getOfferings( $this->apiHelper->storeId );

			foreach ( $offeringList->all() as $offering ) {
				$plans = [];
				foreach ( $offering->getPlans() as $plan ) {
					$plans[] = [
						'id'             => $plan->getId(),
						'name'           => $plan->getName(),
						'price'          => $plan->getPrice(),
						'currency'       => $plan->getCurrency(),
						'recurring_type' => $plan->getRecurringType(),
					];
				}
				$offerings[] = [
					'id'       => $offering->getId(),
					'app_name' => $offering->getAppName(),
					'plans'    => $plans,
				];
			}
		} catch ( \Throwable $e ) {
			Logger::debug( 'Error fetching offerings: ' . $e->getMessage() );
			Notice::addNotice( 'error', sprintf(
				__( 'Could not fetch offerings from BTCPay Server: %s', 'btcpay-greenfield-for-woocommerce' ),
				$e->getMessage()
			) );
			return [];
		}

		set_transient( $cacheKey, $offerings, 120 );
		return $offerings;
	}

	private function getSubscriptionProducts(): array
	{
		$products = wc_get_products( [
			'type'   => 'subscription',
			'limit'  => -1,
			'status' => 'publish',
		] );

		$result = [];
		foreach ( $products as $product ) {
			$result[] = [
				'id'   => $product->get_id(),
				'name' => $product->get_name(),
			];
		}

		return $result;
	}

}
