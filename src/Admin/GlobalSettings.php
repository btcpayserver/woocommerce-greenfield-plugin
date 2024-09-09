<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Admin;

use BTCPayServer\Client\ApiKey;
use BTCPayServer\Client\StorePaymentMethod;
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

	public function output(): void
	{
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
		// Can't use apiHelper because of caching.
		if ($webhookConfig = get_option('btcpay_gf_webhook')) {
			$whId = $webhookConfig['id'];
		}

		// Todo: check why $this->apiHelper->webhookIsSetup() is cached, also others above.
		if (!empty($webhookConfig['secret'])) {
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
				'desc' => sprintf( _x( 'This plugin version is %s and your PHP version is %s. Check out our <a href="https://docs.btcpayserver.org/WooCommerce/" target="_blank">installation instructions</a>. If you need assistance, please come on our <a href="https://chat.btcpayserver.org" target="_blank">chat</a>. Thank you for using BTCPay!', 'global_settings', 'btcpay-greenfield-for-woocommerce' ), BTCPAYSERVER_VERSION, PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ),
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
				'type'        => 'text',
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
				'type' => 'text',
				'desc' => _x( 'If left empty an webhook will created automatically on save. Only fill out if you know the webhook secret and the webhook was created manually on BTCPay Server.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'desc_tip' => _x( 'The BTCPay webhook endpoint can be reached here: ' . site_url() . '/wc-api/btcpaygf_default/', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'default' => '',
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
				'title'       => esc_html_x( 'Default Customer Message', 'btcpay-greenfield-for-woocommerce' ),
				'type'        => 'textarea',
				'desc' => esc_html_x( 'Message to explain how the customer will be paying for the purchase. Can be overwritten on a per gateway basis.', 'btcpay-greenfield-for-woocommerce' ),
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
			'customer_data' => [
				'title' => __( 'Send customer data to BTCPayServer', 'btcpay-greenfield-for-woocommerce' ),
				'type' => 'checkbox',
				'default' => 'no',
				'desc' => _x( 'If you want customer email, address, etc. sent to BTCPay Server enable this option. By default for privacy and GDPR reasons this is disabled.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'id' => 'btcpay_gf_send_customer_data'
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
				'desc' => _x( 'If enabled, it will show the order refund note also to the customer and trigger an email to customer with the refund link.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ),
				'id' => 'btcpay_gf_refund_note_visible'
			],
			'debug' => [
				'title' => __( 'Debug Log', 'btcpay-greenfield-for-woocommerce' ),
				'type' => 'checkbox',
				'default' => 'no',
				'desc' => sprintf( _x( 'Enable logging <a href="%s" class="button">View Logs</a>', 'global_settings', 'btcpay-greenfield-for-woocommerce' ), Logger::getLogFileUrl()),
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
		// If we have url, storeID and apiKey we want to check if the api key works and register a webhook.
		Logger::debug('Saving GlobalSettings.');
		if ( $this->hasNeededApiCredentials() ) {
			// Check if api key works for this store.
			$apiUrl  = esc_url_raw( $_POST['btcpay_gf_url'] );
			$apiKey  = sanitize_text_field( $_POST['btcpay_gf_api_key'] );
			$storeId = sanitize_text_field( $_POST['btcpay_gf_store_id'] );
			$manualWhSecret = sanitize_text_field( $_POST['btcpay_gf_whsecret'] );

			// todo: fix change of url + key + storeid not leading to recreation of webhook.
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
					// Check if we already have a webhook registered for that store.
					if ( GreenfieldApiWebhook::webhookExists( $apiUrl, $apiKey, $storeId, $manualWhSecret ) ) {

						if ( $manualWhSecret && $this->apiHelper->webhook['secret'] !== $manualWhSecret) {
							// Store manual webhook in options table.
							update_option(
								'btcpay_gf_webhook',
								[
									'id' => 'manual',
									'secret' => $manualWhSecret,
									'url' => 'manual'
								]
							);

							$messageWebhookManual = __( 'Successfully setup manual webhook.', 'btcpay-greenfield-for-woocommerce' );
							Notice::addNotice('success', $messageWebhookManual, true );
							Logger::debug( $messageWebhookManual );
						} else {
							$messageReuseWebhook = __( 'Webhook already exists, skipping webhook creation.', 'btcpay-greenfield-for-woocommerce' );
							Notice::addNotice('info', $messageReuseWebhook, true);
							Logger::debug($messageReuseWebhook);
						}
					} else {
						// When the webhook secret was set manually we just store it and not try to create it.
						if ( $manualWhSecret ) {
							// Store manual webhook in options table.
							update_option(
								'btcpay_gf_webhook',
								[
									'id' => 'manual',
									'secret' => $manualWhSecret,
									'url' => 'manual'
								]
							);

							$messageWebhookManual = __( 'Successfully setup manual webhook.', 'btcpay-greenfield-for-woocommerce' );
							Notice::addNotice('success', $messageWebhookManual, true );
							Logger::debug( $messageWebhookManual );
						}

						// Register a new webhook automatically.
						if ( empty($manualWhSecret) ) {
							if ( GreenfieldApiWebhook::registerWebhook( $apiUrl, $apiKey, $storeId ) ) {
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
						}
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
							__('Exception loading wallet information (payment methods) from BTCPay Server: %s.', 'btcpay-greenfield-for-woocommerce'),
							$e->getMessage()
						);
						Logger::debug($messagePaymentMethodsCallError);
						Notice::addNotice('error', $messagePaymentMethodsCallError );
					}
				}
			} catch ( \Throwable $e ) {
				$messageException = sprintf(
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

}
