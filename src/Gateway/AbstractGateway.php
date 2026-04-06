<?php

declare( strict_types=1 );

namespace BTCPayServer\WC\Gateway;

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Client\PullPayment;
use BTCPayServer\Client\Subscriptions;
use BTCPayServer\Util\PreciseNumber;
use BTCPayServer\WC\Helper\GreenfieldApiHelper;
use BTCPayServer\WC\Helper\GreenfieldApiWebhook;
use BTCPayServer\WC\Helper\Logger;
use BTCPayServer\WC\Helper\OrderStates;

abstract class AbstractGateway extends \WC_Payment_Gateway {
	const ICON_MEDIA_OPTION = 'icon_media_id';
	public $tokenType;
	public $primaryPaymentMethod;
	protected $apiHelper;
	protected $debug_php_version;
	protected $debug_plugin_version;

	public function __construct() {
		// General gateway setup.
		$this->icon              = $this->getIcon();
		$this->has_fields        = false;
		$this->order_button_text = __( 'Proceed to BTCPay', 'btcpay-greenfield-for-woocommerce' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user facing set variables.
		$this->title        = $this->getTitle();
		$this->description  = $this->getDescription();

		$this->apiHelper = new GreenfieldApiHelper();
		// Debugging & informational settings.
		$this->debug_php_version    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$this->debug_plugin_version = BTCPAYSERVER_VERSION;

		// Actions.
		add_action('admin_enqueue_scripts', [$this, 'addAdminScripts']);
		add_action('wp_enqueue_scripts', [$this, 'addPublicScripts']);
		add_action('woocommerce_update_options_payment_gateways_' . $this->getId(), [$this, 'process_admin_options']);

		// Supported features.
		$this->supports = [
			'products',
			'refunds',
		];

		if ( class_exists( 'WC_Subscriptions' ) ) {
			$this->supports = array_merge(
				$this->supports,
				[
					'subscriptions',
					'subscription_cancellation',
					'subscription_suspension',
					'subscription_reactivation',
					'subscription_amount_changes',
					'subscription_date_changes',
					'subscription_payment_method_change_customer',
					'subscription_payment_method_change_admin',
					'subscription_payment_method_change',
					'gateway_scheduled_payments',
				]
			);

			add_action(
				'woocommerce_scheduled_subscription_payment_' . $this->getId(),
				[ $this, 'process_scheduled_subscription_payment' ],
				10,
				2
			);
		}
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled' => [
				'title'       => __( 'Enabled/Disabled', 'btcpay-greenfield-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable this payment gateway.', 'btcpay-greenfield-for-woocommerce' ),
				'default'     => 'no',
				'value'       => 'yes',
				'desc_tip'    => false,
			],
			'title'       => [
				'title'       => __( 'Title', 'btcpay-greenfield-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Controls the name of this payment method as displayed to the customer during checkout.', 'btcpay-greenfield-for-woocommerce' ),
				'default'     => $this->getTitle(),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Customer Message', 'btcpay-greenfield-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Message to explain how the customer will be paying for the purchase.', 'btcpay-greenfield-for-woocommerce' ),
				'default'     => $this->getDescription(),
				'desc_tip'    => true,
			],
			'icon_upload' => [
				'type'        => 'icon_upload',
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function process_payment( $orderId ) {
		if ( ! $this->apiHelper->configured ) {
			Logger::debug( 'BTCPay Server API connection not configured, aborting. Please go to BTCPay Server settings and set it up.' );
			// todo: show error notice/make sure it fails
			throw new \Exception( __( "Can't process order. Please contact us if the problem persists.", 'btcpay-greenfield-for-woocommerce' ) );
		}

		// Load the order and check it.
		$order = new \WC_Order( $orderId );
		if ( $order->get_id() === 0 ) {
			$message = 'Could not load order id ' . $orderId . ', aborting.';
			Logger::debug( $message, true );
			throw new \Exception( $message );
		}

		if (isset($_POST['action'])) {
			$action = wc_clean( wp_unslash( $_POST['action'] ) );
			if ( in_array($action, ['btcpaygf_modal_checkout', 'btcpaygf_modal_blocks_checkout']) ) {
				Logger::debug( 'process_payment called via modal checkout.' );
			}
		}

		// Determine if modal checkout is enabled.
		$isModal = false;
		if ( get_option('btcpay_gf_modal_checkout') === 'yes' ) {
			$isModal = true;
		}

		// Check if this is a subscription order
		if ( $this->isSubscriptionOrder( $order ) ) {
			Logger::debug( 'Processing subscription order' );
			return $this->processSubscriptionPayment( $order, $isModal );
		}

		// Check for existing invoice and redirect instead.
		if ( $this->validInvoiceExists( $orderId ) ) {
			$existingInvoiceId = $order->get_meta( 'BTCPay_id' );
			Logger::debug( 'Found existing BTCPay Server invoice and redirecting to it. Invoice id: ' . $existingInvoiceId );

			$response =  [
				'result' => 'success',
				'invoiceId' => $existingInvoiceId,
				'orderCompleteLink' => $order->get_checkout_order_received_url(),
			];

			if (!$isModal) {
				$response['redirect'] = $this->apiHelper->getInvoiceRedirectUrl( $existingInvoiceId );
			}

			return $response;
		}

		// Create an invoice.
		Logger::debug( 'Creating invoice on BTCPay Server' );
		
		if ( $invoice = $this->createInvoice( $order ) ) {

			// Todo: update order status and BTCPay meta data.

			Logger::debug( 'Invoice creation successful, redirecting user.' );

			$url = $invoice->getData()['checkoutLink'];
			// Todo: needs testing, support for .onion URLs, see https://github.com/btcpayserver/woocommerce-greenfield-plugin/issues/4
			/* if ( preg_match( "/^([a-zA-Z0-9][a-zA-Z0-9-]{1,61}[a-zA-Z0-9]\.)?[a-zA-Z0-9][a-zA-Z0-9-]{1,61}[a-zA-Z0-9]\.onion$/", $_SERVER['SERVER_NAME'] ) ){
				$url = str_replace($this->apiHelper->url, $_SERVER['SERVER_NAME'], $url);
			} */

			$response = [
				'result' => 'success',
				'invoiceId' => $invoice->getData()['id'],
				'orderCompleteLink' => $order->get_checkout_order_received_url(),
			];

			if (!$isModal) {
				$response['redirect'] = $url;
			}

			return $response;
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		// Check if the BTCPay Server version used supports refunds.
		if (!$this->apiHelper->serverSupportsRefunds()) {
			$errServer = 'Your BTCPay Server does not support refunds. Make sure to run a BTCPay Server v1.7.6 or newer.';
			Logger::debug($errServer);
			return new \WP_Error('1', $errServer);
		}

		// Check if the api key has support for refunds, abort if not.
		if (!$this->apiHelper->apiKeyHasRefundPermission()) {
			$errKeyInfo = 'Your current API key does not support refunds. You will need to create a new one with the required permission. See our upgrade guide https://docs.btcpayserver.org/WooCommerce/#create-a-new-api-key';
			Logger::debug(__METHOD__ . ' : The current api key does not support refunds.' );
			return new \WP_Error('1', $errKeyInfo);
		}

		// Abort if no amount.
		if (is_null($amount)) {
			$errAmount = __METHOD__ . ': refund amount is empty, aborting.';
			Logger::debug($errAmount);
			return new \WP_Error('1', $errAmount);
		}

		$order = wc_get_order($order_id);
		$refundAmount = PreciseNumber::parseString($amount);
		$currency = $order->get_currency();
		$originalCurrency = $order->get_currency();

		// Check if order has invoice id.
		if (!$invoiceId = $order->get_meta('BTCPay_id')) {
			$errNoBtcpayId = __METHOD__ . ': no BTCPay invoice id found, aborting.';
			Logger::debug($errNoBtcpayId);
			return new \WP_Error('1', $errNoBtcpayId);
		}

		// Make sure the refund amount is not greater than the invoice amount.
		// This is done by WC and no need to do it here, refund is already saved at this stage so below won't work.
		// Leaving it here for future reference.
		/*if ($amount > $order->get_remaining_refund_amount()) {
			$errAmount = __METHOD__ . ': the refund amount can not exceed the order amount, aborting. Remaining amount ' . $order->get_remaining_refund_amount();
			Logger::debug($errAmount);
			return new \WP_Error('1', $errAmount);
		}
		*/

		// Create the payout on BTCPay Server.
		// Handle Sats-mode.
		if ($currency === 'SAT') {
			$currency = 'BTC';
			$amountBTC = bcdiv($refundAmount->__toString(), '100000000', 8);
			$refundAmount = PreciseNumber::parseString($amountBTC);
		}

		// Get payment methods.
		$paymentMethods = $this->getPaymentMethods();
		// Remove LNURL
		if (in_array('BTC_LNURLPAY', $paymentMethods) || in_array('BTC_LNURL', $paymentMethods)) {
			$paymentMethods = array_diff($paymentMethods, ['BTC_LNURLPAY', 'BTC_LNURL']);
		}

		// Refund name is limited for 50 chars, but we do not have description field available until php lib v3 is out.
		$refundName = __('Refund of order ', 'btcpay-greenfield-for-woocommerce') . $order->get_order_number() . '; ' . $reason;
		$refundName = substr($refundName, 0, 50);

		// Create the payout.
		try {
			$client = new PullPayment( $this->apiHelper->url, $this->apiHelper->apiKey);
			// todo: add reason to description with upcoming php lib v3
			$pullPayment = $client->createPullPayment(
				$this->apiHelper->storeId,
				$refundName,
				$refundAmount,
				$currency,
				null,
				null,
				false, // use setting
				null,
				null,
				array_values($paymentMethods)
			);

			if (!empty($pullPayment)) {
				$refundMsg = "PullPayment ID: " . $pullPayment->getId() . "\n";
				$refundMsg .= "Link: " . $pullPayment->getViewLink() . "\n";
				$refundMsg .= "Amount: " . $amount . " " . $originalCurrency . "\n";
				$refundMsg .= "Reason: " . $reason;
				$successMsg = 'Successfully created refund: ' . $refundMsg;

				Logger::debug($successMsg);

				// Add public or private order note.
				if (get_option('btcpay_gf_refund_note_visible') === 'yes') {
					$order->add_order_note($successMsg, 1);
				} else {
					$order->add_order_note($successMsg);
				}

				// Use add_meta_data to allow for partial refunds.
				$order->add_meta_data('BTCPay_refund', $refundMsg, false);
				$order->save();
				return true;
			} else {
				$errEmptyPullPayment = 'Error creating pull payment. Make sure you have the correct api key permissions.';
				Logger::debug($errEmptyPullPayment, true);
				return new \WP_Error('1', $errEmptyPullPayment);
			}
		} catch (\Throwable $e) {
			$errException = 'Exception creating pull payment: ' . $e->getMessage();
			Logger::debug($errException,true);
			return new \WP_Error('1', $errException);
		}

		return new \WP_Error('1', 'Error processing the refund, please check logs.');
	}

	/**
	 * Process scheduled subscription payments.
	 */
	public function process_scheduled_subscription_payment( $amount_to_charge, $renewal_order ): void {
		$order = $renewal_order instanceof \WC_Order ? $renewal_order : wc_get_order( $renewal_order );
		if ( ! $order ) {
			Logger::debug( __METHOD__ . ': renewal order not found.' );
			return;
		}

		Logger::debug( __METHOD__ . ': scheduled subscription payment for order ' . $order->get_id() . ' amount ' . $amount_to_charge );
		// call to btcpay api creating the subscription

		$subscription = $this->getSubscriptionForOrder( $order );
		$planData = $this->getBtcpayPlanData( $order, $subscription );
		if ( empty( $planData['offering_id'] ) || empty( $planData['plan_id'] ) ) {
			$order->update_status(
				'failed',
				__( 'Missing BTCPay offering/plan metadata for subscription renewal.', 'btcpay-greenfield-for-woocommerce' )
			);
			return;
		}

		$customerSelector = $this->getBtcpayCustomerSelector( $order, $subscription );
		try {
			$checkout = $this->createPlanCheckout(
				$order,
				$planData['offering_id'],
				$planData['plan_id'],
				$customerSelector,
				null
			);

			$this->storeSubscriptionMetadata(
				$order,
				$planData['offering_id'],
				$planData['plan_id'],
				$checkout,
				$subscription
			);

			$order->update_status(
				'on-hold',
				__( 'Awaiting BTCPay subscription payment.', 'btcpay-greenfield-for-woocommerce' )
			);
		} catch ( \Throwable $e ) {
			Logger::debug( __METHOD__ . ': failed to create plan checkout: ' . $e->getMessage() );
			$order->update_status(
				'failed',
				__( 'BTCPay subscription renewal failed to initialize.', 'btcpay-greenfield-for-woocommerce' )
			);
		}
	}

	public function process_admin_options() {
		// Store media id.
		$iconFieldName = 'woocommerce_' . $this->getId() . '_' . self::ICON_MEDIA_OPTION;
		if ($mediaId = sanitize_key($_POST[$iconFieldName])) {
			if ($mediaId !== $this->get_option(self::ICON_MEDIA_OPTION)) {
				$this->update_option(self::ICON_MEDIA_OPTION, $mediaId);
			}
		} else {
			// Reset to empty otherwise.
			$this->update_option(self::ICON_MEDIA_OPTION, '');
		}
		return parent::process_admin_options();
	}

	/**
	 * Generate html for handling icon uploads with media manager.
	 *
	 * Note: `generate_$type_html()` is a pattern you can use from WooCommerce Settings API to render custom fields.
	 */
	public function generate_icon_upload_html() {
		$mediaId = $this->get_option(self::ICON_MEDIA_OPTION);
		$mediaSrc = '';
		if ($mediaId) {
			$mediaSrc = wp_get_attachment_image_src($mediaId)[0];
		}
		$iconFieldName = 'woocommerce_' . $this->getId() . '_' . self::ICON_MEDIA_OPTION;

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo __('Gateway Icon:', 'btcpay-greenfield-for-woocommerce'); ?></th>
			<td class="forminp" id="btcpay_gf_icon">
				<div id="btcpay_gf_icon_container">
					<input class="btcpay-gf-icon-button" type="button"
						   name="woocommerce_btcpaygf_icon_upload_button"
						   value="<?php echo __('Upload or select icon', 'btcpay-greenfield-for-woocommerce'); ?>"
						   style="<?php echo $mediaId ? 'display:none;' : ''; ?>"
					/>
					<img class="btcpay-gf-icon-image" src="<?php echo esc_url($mediaSrc); ?>" style="<?php echo esc_attr($mediaId) ? '' : 'display:none;'; ?>" />
					<input class="btcpay-gf-icon-remove" type="button"
						   name="woocommerce_btcpaygf_icon_button_remove"
						   value="<?php echo __('Remove image', 'btcpay-greenfield-for-woocommerce'); ?>"
						   style="<?php echo $mediaId ? '' : 'display:none;'; ?>"
					/>
					<input class="input-text regular-input btcpay-gf-icon-value" type="hidden"
						   name="<?php echo esc_attr($iconFieldName); ?>"
						   id="<?php echo esc_attr($iconFieldName); ?>"
						   value="<?php echo esc_attr($mediaId); ?>"
					/>
				</div>
			</td>
		</tr>
        <?php
		return ob_get_clean();
	}

	public function getId(): string {
		return $this->id;
	}

	/**
	 * Get custom gateway icon, if any.
	 */
	public function getIcon(): string {
		$icon = null;
		if ($mediaId = $this->get_option(self::ICON_MEDIA_OPTION)) {
			if ($customIcon = wp_get_attachment_image_src($mediaId)[0]) {
				$icon = $customIcon;
			}
		}

		return $icon ?? BTCPAYSERVER_PLUGIN_URL . 'assets/images/btcpay-logo.png';
	}

	/**
	 * Add scripts.
	 */
	public function addAdminScripts($hook_suffix) {
		if ($hook_suffix === 'woocommerce_page_wc-settings') {
			wp_enqueue_media();
			wp_register_script(
				'btcpay_gf_abstract_gateway',
				BTCPAYSERVER_PLUGIN_URL . 'assets/js/backend/gatewayIconMedia.js',
				['jquery'],
				BTCPAYSERVER_VERSION
			);
			wp_enqueue_script('btcpay_gf_abstract_gateway');
			wp_localize_script(
				'btcpay_gf_abstract_gateway',
				'btcpaygfGatewayData',
				[
					'buttonText' => __('Use this image', 'btcpay-greenfield-for-woocommerce'),
					'titleText' => __('Insert image', 'btcpay-greenfield-for-woocommerce'),
				]
			);
		}
	}

	public function addPublicScripts() {
		// We only load the modal checkout scripts when enabled.
		if (get_option('btcpay_gf_modal_checkout') !== 'yes') {
			return;
		}

		if ($this->apiHelper->configured === false) {
			return;
		}

		// Load BTCPay modal JS.
		wp_enqueue_script( 'btcpay_gf_modal_js', $this->apiHelper->url . '/modal/btcpay.js', [], BTCPAYSERVER_VERSION );

		// Get page id of checkout page.
		$checkoutPageId = wc_get_page_id('checkout');
		// Check if the checkout page uses the new woocommerce blocks.
		$isBlockCheckout = has_block( 'woocommerce/checkout' , $checkoutPageId);
		if ($isBlockCheckout) {
			$scriptName = 'btcpay_gf_modal_blocks_checkout';
			$scriptFile = BTCPAYSERVER_PLUGIN_URL . 'assets/js/frontend/blocksModalCheckout.js';
		} else {
			$scriptName = 'btcpay_gf_modal_checkout';
			$scriptFile = BTCPAYSERVER_PLUGIN_URL . 'assets/js/frontend/modalCheckout.js';
		}

		// Register modal script.
		wp_register_script(
			$scriptName,
			$scriptFile,
			[ 'jquery', 'wp-data' ],
			BTCPAYSERVER_VERSION,
			true
		);

		// Pass object BTCPayWP to be available on the frontend.
		wp_localize_script( $scriptName, 'BTCPayWP', [
			'modalEnabled' => get_option( 'btcpay_gf_modal_checkout' ) === 'yes',
			'debugEnabled' => get_option( 'btcpay_gf_debug' ) === 'yes',
			'url' => admin_url( 'admin-ajax.php' ),
			'apiUrl' => $this->apiHelper->url,
			'apiNonce' => wp_create_nonce( 'btcpay-nonce' ),
			'isChangePaymentPage' => isset( $_GET['change_payment_method'] ) ? 'yes' : 'no',
			'isPayForOrderPage' => is_wc_endpoint_url( 'order-pay' ) ? 'yes' : 'no',
			'isAddPaymentMethodPage' => is_add_payment_method_page() ? 'yes' : 'no',
			'textInvoiceExpired' => _x( 'The invoice expired. Please try again, choose a different payment method or contact us if you paid but the payment did not confirm in time.', 'js', 'btcpay-greenfield-for-woocommerce' ),
			'textInvoiceInvalid' => _x( 'The invoice is invalid. Please try again, choose a different payment method or contact us if you paid but the payment did not confirm in time.', 'js', 'btcpay-greenfield-for-woocommerce' ),
			'textModalClosed' => _x( 'Payment aborted by you. Please try again or choose a different payment method.', 'js', 'btcpay-greenfield-for-woocommerce' ),
			'textProcessingError' => _x( 'Error processing checkout. Please try again or choose another payment option.', 'js', 'btcpay-greenfield-for-woocommerce' ),
		] );
		// Add the registered modal blocks script to frontend.
		wp_enqueue_script( $scriptName );
	}

	/**
	 * Process webhooks from BTCPay.
	 */
	public function processWebhook() {
		if ($rawPostData = file_get_contents("php://input")) {
			// Validate webhook request.
			// Note: getallheaders() CamelCases all headers for PHP-FPM/Nginx but for others maybe not, so "BTCPay-Sig" may becomes "Btcpay-Sig".
			$headers = getallheaders();
			foreach ($headers as $key => $value) {
				if (strtolower($key) === 'btcpay-sig') {
					$signature = $value;
				}
			}

			if (!isset($signature) || !$this->apiHelper->validWebhookRequest($signature, $rawPostData)) {
				Logger::debug('Failed to validate signature of webhook request.');
				wp_die('Webhook request validation failed.');
			}

			try {
				$postData = json_decode($rawPostData, false, 512, JSON_THROW_ON_ERROR);

				if (!isset($postData->invoiceId)) {
					Logger::debug('No BTCPay invoiceId provided, aborting.');
					wp_die('No BTCPay invoiceId provided, aborting.');
				}

				// Load the order by metadata field BTCPay_id
				$orders = wc_get_orders([
					'meta_key' => 'BTCPay_id',
					'meta_value' => $postData->invoiceId
				]);

				// Abort if no orders found.
				if (count($orders) === 0) {
					$orderFromMetadata = $this->getOrderByInvoiceMetadata( $postData->invoiceId );
					if ( $orderFromMetadata ) {
						$orders = [ $orderFromMetadata ];
					} else {
						Logger::debug('Could not load order by BTCPay invoiceId: ' . $postData->invoiceId);
						// Note: we return status 200 here for wp_die() which seems counter intuative but needs to be done
						// to not clog up the BTCPay servers webhook processing queue until it is fixed there.
						wp_die('No order found for this invoiceId.', '', ['response' => 200]);
					}
				}

				// Abort on multiple orders found.
				if (count($orders) > 1) {
					Logger::debug('Found multiple orders for invoiceId: ' . $postData->invoiceId);
					Logger::debug(print_r($orders, true));
					wp_die('Multiple orders found for this invoiceId, aborting.');
				}

				// Only continue if the order payment method contains string "btcpaygf_" to avoid processing other gateways.
				if (strpos($orders[0]->get_payment_method(), 'btcpaygf_') === false) {
					Logger::debug('Order payment method does not contain "btcpaygf_", aborting.');
					wp_send_json_success(); // return 200 OK to not mess up BTCPay queue
				}

				$this->processOrderStatus($orders[0], $postData);
				$this->maybeStoreSubscriptionCustomerId( $orders[0] );

			} catch (\Throwable $e) {
				Logger::debug('Error decoding webook payload: ' . $e->getMessage());
				Logger::debug($rawPostData);
			}
		}
	}

	protected function getOrderByInvoiceMetadata( string $invoiceId ): ?\WC_Order {
		try {
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			$invoice = $client->getInvoice( $this->apiHelper->storeId, $invoiceId );
			$data = $invoice->getData();
			$metadata = $data['metadata'] ?? $data['invoiceMetadata'] ?? null;

			if ( ! is_array( $metadata ) ) {
				return null;
			}

			if ( ! empty( $metadata['wc_order_id'] ) ) {
				$order = wc_get_order( (int) $metadata['wc_order_id'] );
				if ( $order ) {
					if ( ! $order->get_meta( 'BTCPay_id' ) ) {
						$order->update_meta_data( 'BTCPay_id', $invoiceId );
						$order->save();
					}
					return $order;
				}
			}

			if ( ! empty( $metadata['wc_subscription_id'] ) && function_exists( 'wcs_get_subscription' ) ) {
				$subscription = wcs_get_subscription( (int) $metadata['wc_subscription_id'] );
				if ( $subscription ) {
					$renewalOrder = $subscription->get_last_order( 'all', 'renewal' );
					if ( $renewalOrder ) {
						if ( ! $renewalOrder->get_meta( 'BTCPay_id' ) ) {
							$renewalOrder->update_meta_data( 'BTCPay_id', $invoiceId );
							$renewalOrder->save();
						}
						return $renewalOrder;
					}

					$parentId = $subscription->get_parent_id();
					if ( $parentId ) {
						$order = wc_get_order( $parentId );
						if ( $order ) {
							if ( ! $order->get_meta( 'BTCPay_id' ) ) {
								$order->update_meta_data( 'BTCPay_id', $invoiceId );
								$order->save();
							}
							return $order;
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			Logger::debug( __METHOD__ . ': failed to load invoice metadata: ' . $e->getMessage() );
		}

		return null;
	}

	protected function maybeStoreSubscriptionCustomerId( \WC_Order $order ): void {
		if ( ! $this->isSubscriptionOrder( $order ) ) {
			return;
		}

		$subscription = $this->getSubscriptionForOrder( $order );
		$existing = $order->get_meta( 'BTCPay_subscriber_id' );
		if ( empty( $existing ) && $subscription ) {
			$existing = $subscription->get_meta( 'BTCPay_subscriber_id' );
		}

		if ( ! empty( $existing ) ) {
			return;
		}

		$planCheckoutId = $order->get_meta( 'BTCPay_plan_checkout_id' );
		if ( empty( $planCheckoutId ) ) {
			return;
		}

		try {
			$client = new Subscriptions( $this->apiHelper->url, $this->apiHelper->apiKey );
			$checkout = $client->getPlanCheckout( $planCheckoutId );

			if ( $checkout->getInvoiceId() && ! $order->get_meta( 'BTCPay_id' ) ) {
				$order->update_meta_data( 'BTCPay_id', $checkout->getInvoiceId() );
			}

			$subscriber = $checkout->getSubscriber();
			if ( $subscriber ) {
				$customer = $subscriber->getCustomer();
				$customerId = $customer->getId();

				$order->update_meta_data( 'BTCPay_subscriber_id', $customerId );
				$order->save();

				if ( $subscription ) {
					$subscription->update_meta_data( 'BTCPay_subscriber_id', $customerId );
					$subscription->save();
				}
			}
		} catch ( \Throwable $e ) {
			Logger::debug( __METHOD__ . ': failed to read plan checkout: ' . $e->getMessage() );
		}
	}

	protected function processOrderStatus(\WC_Order $order, \stdClass $webhookData) {
		if (!in_array($webhookData->type, GreenfieldApiWebhook::WEBHOOK_EVENTS)) {
			Logger::debug('Webhook event received but ignored: ' . $webhookData->type);
			return;
		}

		Logger::debug('Updating order status with webhook event received for processing: ' . $webhookData->type);
		// Get configured order states or fall back to defaults.
		if (!$configuredOrderStates = get_option('btcpay_gf_order_states')) {
			$configuredOrderStates = (new OrderStates())->getDefaultOrderStateMappings();
		}

		// Check if the order is already in a final state, if so do not update it if the orders are protected.
		$protectedOrders = get_option('btcpay_gf_protect_order_status', 'no');

		if ($protectedOrders === 'yes') {
			// Check if the order status is either 'processing' or 'completed'
			if ($order->has_status(array('processing', 'completed'))) {
				$note = sprintf(
					__('Webhook (%s) received from BTCPay, but the order is already processing or completed, skipping to update order status. Please manually check if everything is alright.', 'btcpay-greenfield-for-woocommerce'),
					$webhookData->type
				);
				$order->add_order_note($note);
				return;
			}
		}

		switch ($webhookData->type) {
			case 'InvoiceReceivedPayment':
				if ($webhookData->afterExpiration) {
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL]);
					$order->add_order_note(__('Invoice (partial) payment incoming (unconfirmed) after invoice was already expired.', 'btcpay-greenfield-for-woocommerce'));
				} else {
					// No need to change order status here, only leave a note.
					$order->add_order_note(__('Invoice (partial) payment incoming (unconfirmed). Waiting for settlement.', 'btcpay-greenfield-for-woocommerce'));
				}

				// Store payment data (exchange rate, address).
				$this->updateWCOrderPayments($order);

				break;
			case 'InvoicePaymentSettled':
				// We can't use $webhookData->afterExpiration here as there is a bug affecting all version prior to
				// BTCPay Server v1.7.0.0, see https://github.com/btcpayserver/btcpayserver/issues/
				// Therefore we check if the invoice is in expired or expired paid partial status, instead.
				$orderStatus = $order->get_status();
				if ($orderStatus === str_replace('wc-', '', $configuredOrderStates[OrderStates::EXPIRED]) ||
					$orderStatus === str_replace('wc-', '', $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL])
				) {
					// Check if also the invoice is now fully paid.
					if (GreenfieldApiHelper::invoiceIsFullyPaid($webhookData->invoiceId)) {
						Logger::debug('Invoice fully paid.');
						$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_LATE]);
						$order->add_order_note(__('Invoice fully settled after invoice was already expired. Needs manual checking.', 'btcpay-greenfield-for-woocommerce'));
						//$order->payment_complete();
					} else {
						Logger::debug('Invoice NOT fully paid.');
						$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL]);
						$order->add_order_note(__('(Partial) payment settled but invoice not settled yet (could be more transactions incoming). Needs manual checking.', 'btcpay-greenfield-for-woocommerce'));
					}
				} else {
					// No need to change order status here, only leave a note.
					$order->add_order_note(__('Invoice (partial) payment settled.', 'btcpay-greenfield-for-woocommerce'));
				}

				// Store payment data (exchange rate, address).
				$this->updateWCOrderPayments($order);

				break;
			case 'InvoiceProcessing': // The invoice is paid in full.
				$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::PROCESSING]);
				if (isset($webhookData->overPaid) && $webhookData->overPaid) {
					$order->add_order_note(__('Invoice payment received fully with overpayment, waiting for settlement.', 'btcpay-greenfield-for-woocommerce'));
				} else {
					$order->add_order_note(__('Invoice payment received fully, waiting for settlement.', 'btcpay-greenfield-for-woocommerce'));
				}
				break;
			case 'InvoiceInvalid':
				$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::INVALID]);
				if ($webhookData->manuallyMarked) {
					$order->add_order_note(__('Invoice manually marked invalid.', 'btcpay-greenfield-for-woocommerce'));
				} else {
					$order->add_order_note(__('Invoice became invalid.', 'btcpay-greenfield-for-woocommerce'));
				}
				break;
			case 'InvoiceExpired':
				if ($webhookData->partiallyPaid) {
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL]);
					$order->add_order_note(__('Invoice expired but was paid partially, please check.', 'btcpay-greenfield-for-woocommerce'));
				} else {
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED]);
					$order->add_order_note(__('Invoice expired.', 'btcpay-greenfield-for-woocommerce'));
				}
				break;
			case 'InvoiceSettled':
				$order->payment_complete();
				if (isset($webhookData->overPaid) && $webhookData->overPaid) {
					$order->add_order_note(__('Invoice payment settled but was overpaid.', 'btcpay-greenfield-for-woocommerce'));
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::SETTLED_PAID_OVER]);
				} else {
					$order->add_order_note(__('Invoice payment settled.', 'btcpay-greenfield-for-woocommerce'));
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::SETTLED]);
				}

				// Store payment data (exchange rate, address).
				$this->updateWCOrderPayments($order);
				break;
		}
	}

	/**
	 * Checks if the order has already a BTCPay invoice set and checks if it is still
	 * valid to avoid creating multiple invoices for the same order on BTCPay Server end.
	 *
	 * @param int $orderId
	 *
	 * @return mixed Returns false if no valid invoice found or the invoice id.
	 */
	protected function validInvoiceExists( int $orderId ): bool {
		// Check order metadata for BTCPay_id.
		$order = wc_get_order($orderId);
		if ( $invoiceId = $order->get_meta( 'BTCPay_id' ) ) {
			// Validate the order status on BTCPay server.
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			try {
				Logger::debug( 'Trying to fetch existing invoice from BTCPay Server.' );
				$invoice = $client->getInvoice( $this->apiHelper->storeId, $invoiceId );
				$invalidStates = [ 'Expired', 'Invalid' ];
				if ( in_array( $invoice->getData()['status'], $invalidStates ) ) {
					return false;
				} else {
					// Check also if the payment methods match, only needed if separate payment methods enabled.
					if (get_option('btcpay_gf_separate_gateways') === 'yes') {
						$pmInvoice = $invoice->getData()['checkout']['paymentMethods'];
						$pmInvoice = str_replace('-', '_', $pmInvoice);
						sort($pmInvoice);
						$pm = $this->getPaymentMethods();
						sort($pm);
						if ($pm === $pmInvoice) {
							return true;
						}
						// Mark existing invoice as invalid.
						$order = wc_get_order($orderId);
						$order->add_order_note(__('BTCPay invoice manually set to invalid because customer went back to checkout and changed payment gateway.', 'btcpay-greenfield-for-woocommerce'));
						$this->markInvoiceInvalid($invoiceId);
						return false;
					}
					return true;
				}
			} catch ( \Throwable $e ) {
				Logger::debug( $e->getMessage() );
			}
		}

		return false;
	}

	public function markInvoiceInvalid($invoiceId): void {
		Logger::debug( 'Marking invoice as invalid: ' . $invoiceId);
		try {
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			$client->markInvoiceStatus($this->apiHelper->storeId, $invoiceId, 'Invalid');
		} catch (\Throwable $e) {
			Logger::debug('Error marking invoice invalid: ' . $e->getMessage());
		}
	}

	/**
	 * Update WC order status (if a valid mapping is set).
	 */
	public function updateWCOrderStatus(\WC_Order $order, string $status): void {
		if ($status !== OrderStates::IGNORE) {
			Logger::debug('Updating order status from ' . $order->get_status() . ' to ' . $status);
			$order->update_status($status);
		}
	}

	public function updateWCOrderPayments(\WC_Order $order): void {
		// Load payment data from API.
		try {
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			$allPaymentData = $client->getPaymentMethods($this->apiHelper->storeId, $order->get_meta('BTCPay_id'));

			foreach ($allPaymentData as $payment) {
				// Only continue if the payment method has payments made.
				if ((float) $payment->getPaymentMethodPaid() > 0.0) {
					$paymentMethodName = $payment->getPaymentMethod();
					// Update order meta data with payment methods and transactions.
					$order->update_meta_data( "BTCPay_{$paymentMethodName}_total_paid", $payment->getTotalPaid() ?? '' );
					$order->update_meta_data( "BTCPay_{$paymentMethodName}_total_amount", $payment->getAmount() ?? '' );
					$order->update_meta_data( "BTCPay_{$paymentMethodName}_total_due", $payment->getDue() ?? '' );
					$order->update_meta_data( "BTCPay_{$paymentMethodName}_total_fee", $payment->getNetworkFee() ?? '' );
					$order->update_meta_data( "BTCPay_{$paymentMethodName}_rate", $payment->getRate() ?? '' );
					if ((float) $payment->getRate() > 0.0) {
						$formattedRate = number_format((float) $payment->getRate(), wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
						$order->update_meta_data( "BTCPay_{$paymentMethodName}_rateFormatted", $formattedRate );
					}

					// For each actual payment make a separate entry to make sense of it.
					foreach ($payment->getPayments() as $index => $trx) {
						$order->update_meta_data( "BTCPay_{$paymentMethodName}_{$index}_id", $trx->getTransactionId() ?? '' );
						$order->update_meta_data( "BTCPay_{$paymentMethodName}_{$index}_timestamp", $trx->getReceivedTimestamp() ?? '' );
						$order->update_meta_data( "BTCPay_{$paymentMethodName}_{$index}_destination", $trx->getDestination() ?? '' );
						$order->update_meta_data( "BTCPay_{$paymentMethodName}_{$index}_amount", $trx->getValue() ?? '' );
						$order->update_meta_data( "BTCPay_{$paymentMethodName}_{$index}_status", $trx->getStatus() ?? '' );
						$order->update_meta_data( "BTCPay_{$paymentMethodName}_{$index}_networkFee", $trx->getFee() ?? '' );
					}

					// Save the order.
					$order->save();
				}
			}
		} catch (\Throwable $e) {
			Logger::debug( 'Error processing payment data for invoice: ' . $order->get_meta('BTCPay_id') . ' and order ID: ' . $order->get_id() );
			Logger::debug($e->getMessage());
		}
	}

	/**
	 * Create an invoice on BTCPay Server.
	 */
	public function createInvoice( \WC_Order $order ): ?\BTCPayServer\Result\Invoice {
		// In case some plugins customizing the order number we need to pass that along, defaults to internal ID.
		$orderNumber = $order->get_order_number();
		Logger::debug( 'Got order number: ' . $orderNumber . ' and order ID: ' . $order->get_id() );

		$metadata = [];

		// Send customer data only if option is set.
		if ( get_option( 'btcpay_gf_send_customer_data' ) === 'yes' ) {
			$metadata += $this->prepareCustomerMetadata( $order );
		}

		// Set included tax amount.
		$metadata['taxIncluded'] = $order->get_cart_tax();

		// POS metadata.
		$metadata['posData'] = $this->preparePosMetadata( $order );

		// Add orderUrl to make order id clickable and leading to the order edit page.
		$metadata['orderUrl'] = $order->get_edit_order_url();

		// Checkout options.
		$checkoutOptions = new InvoiceCheckoutOptions();
		$redirectUrl     = $this->get_return_url( $order );
		$checkoutOptions->setRedirectURL( $redirectUrl );
		Logger::debug( 'Setting redirect url to: ' . $redirectUrl );

		// Transaction speed.
		$transactionSpeed   = get_option( 'btcpay_gf_transaction_speed', 'default' );
		$allowedSpeedValues = [
			$checkoutOptions::SPEED_HIGH,
			$checkoutOptions::SPEED_MEDIUM,
			$checkoutOptions::SPEED_LOWMEDIUM,
			$checkoutOptions::SPEED_LOW
		];
		if ( $transactionSpeed !== 'default' && in_array($transactionSpeed, $allowedSpeedValues)) {
			$checkoutOptions->setSpeedPolicy( $transactionSpeed );
		} else {
			Logger::debug('Did not set transaction speed setting, using BTCPay Server store config instead. Invalid value given: ' . $transactionSpeed);
		}

		// Payment methods.
		if ($paymentMethods = $this->getPaymentMethods()) {
			$checkoutOptions->setPaymentMethods($paymentMethods);
		}

		// Handle payment methods of type "promotion".
		// Promotion type set 1 token per each quantity.
		if ($this->getTokenType() === 'promotion') {
			$currency = $this->primaryPaymentMethod ?? null;
			$amount = PreciseNumber::parseInt( $this->getOrderTotalItemsQuantity($order));
		} else { // Defaults.
			$currency = $order->get_currency();
			$amount = PreciseNumber::parseString( $order->get_total() ); // unlike method signature suggests, it returns string.
		}

		// Handle Sats-mode.
		// Because BTCPay does not understand SAT as a currency we need to change to BTC and adjust the amount.
		if ($currency === 'SAT') {
			$currency = 'BTC';
			$amountBTC = bcdiv($amount->__toString(), '100000000', 8);
			$amount = PreciseNumber::parseString($amountBTC);
		}

		// Create the invoice on BTCPay Server.
		$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
		try {
			$invoice = $client->createInvoice(
				$this->apiHelper->storeId,
				$currency,
				$amount,
				$orderNumber,
				null, // this is null here as we handle it in the metadata.
				$metadata,
				$checkoutOptions
			);

			$this->updateOrderMetadata( $order->get_id(), $invoice );

			return $invoice;

		} catch ( \Throwable $e ) {
			Logger::debug( $e->getMessage(), true );
			// todo: should we throw exception here to make sure there is an visible error on the page and not silently failing?
		}

		return null;
	}

	/**
	 * Check if the order contains a subscription.
	 */
	protected function isSubscriptionOrder( \WC_Order $order ): bool {
		if ( function_exists( 'wcs_order_contains_subscription' ) ) {
			return wcs_order_contains_subscription( $order );
		}

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			return \WC_Subscriptions_Order::order_contains_subscription( $order->get_id() );
		}

		return false;
	}

	/**
	 * Process subscription payment.
	 */
	protected function processSubscriptionPayment( \WC_Order $order, bool $isModal ): array {
		Logger::debug( 'Processing subscription payment for order ' . $order->get_id() );

		try {
			$planData = $this->getBtcpayPlanData( $order, $this->getSubscriptionForOrder( $order ) );
			if ( empty( $planData['offering_id'] ) || empty( $planData['plan_id'] ) ) {
				throw new \Exception( __( 'Subscription offering or plan ID not configured.', 'btcpay-greenfield-for-woocommerce' ) );
			}

			$planCheckout = $this->createPlanCheckout(
				$order,
				$planData['offering_id'],
				$planData['plan_id'],
				null,
				$order->get_billing_email()
			);

			$this->storeSubscriptionMetadata(
				$order,
				$planData['offering_id'],
				$planData['plan_id'],
				$planCheckout,
				$this->getSubscriptionForOrder( $order )
			);

			Logger::debug( 'BTCPay plan checkout created successfully: ' . $planCheckout->getId() );

			$response = [
				'result' => 'success',
				'planCheckoutId' => $planCheckout->getId(),
				'orderCompleteLink' => $order->get_checkout_order_received_url(),
				'subscription' => true,
				'redirect' => $planCheckout->getUrl(),
			];

			if ( $planCheckout->getInvoiceId() ) {
				$response['invoiceId'] = $planCheckout->getInvoiceId();
			}

			if ( $isModal ) {
				unset( $response['redirect'] );
			}

			return $response;

		} catch ( \Throwable $e ) {
			Logger::debug( 'Error processing subscription payment: ' . $e->getMessage() );
			throw new \Exception( __( 'Error processing subscription payment. Please try again.', 'btcpay-greenfield-for-woocommerce' ) );
		}
	}

protected function createPlanCheckout(
		\WC_Order $order,
		string $offeringId,
		string $planId,
		?string $customerSelector,
		?string $newSubscriberEmail
	): \BTCPayServer\Result\PlanCheckout {
		$client = new Subscriptions( $this->apiHelper->url, $this->apiHelper->apiKey );
		$subscription = $this->getSubscriptionForOrder( $order );

		$invoiceMetadata = $this->buildSubscriptionInvoiceMetadata( $order, $subscription );
		$newSubscriberMetadata = $this->buildSubscriptionInvoiceMetadata( $order, $subscription );
		$successRedirect = $this->get_return_url( $order );

		return $client->createPlanCheckout(
			$this->apiHelper->storeId,
			$offeringId,
			$planId,
			$customerSelector,
			null,
			null,
			$newSubscriberMetadata,
			$invoiceMetadata,
			null,
			null,
			null,
			$successRedirect,
			$newSubscriberEmail
		);
	}

protected function buildSubscriptionInvoiceMetadata( \WC_Order $order, ?\WC_Subscription $subscription ): array {
		$metadata = [
			'wc_order_id' => (string) $order->get_id(),
			'wc_order_number' => (string) $order->get_order_number(),
		];

		if ( $subscription ) {
			$metadata['wc_subscription_id'] = (string) $subscription->get_id();
		}

		return $metadata;
	}

protected function getSubscriptionForOrder( \WC_Order $order ): ?\WC_Subscription {
		if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
			if ( ! empty( $subscriptions ) ) {
				return array_shift( $subscriptions );
			}
		}

		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order->get_id(), [ 'order_type' => 'parent' ] );
			if ( ! empty( $subscriptions ) ) {
				return array_shift( $subscriptions );
			}
		}

		return null;
	}

protected function getBtcpayPlanData( \WC_Order $order, ?\WC_Subscription $subscription ): array {
		$offeringId = $order->get_meta( 'BTCPay_offering_id' );
		$planId = $order->get_meta( 'BTCPay_plan_id' );

		if ( ( empty( $offeringId ) || empty( $planId ) ) && $subscription ) {
			$offeringId = $subscription->get_meta( 'BTCPay_offering_id' );
			$planId = $subscription->get_meta( 'BTCPay_plan_id' );
		}

		if ( empty( $offeringId ) || empty( $planId ) ) {
			$productMeta = $this->getBtcpayPlanDataFromProducts( $order );
			$offeringId = $productMeta['offering_id'] ?? $offeringId;
			$planId = $productMeta['plan_id'] ?? $planId;
		}

		return [
			'offering_id' => $offeringId,
			'plan_id' => $planId,
		];
	}

protected function getBtcpayPlanDataFromProducts( \WC_Order $order ): array {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return [];
		}

		$matches = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			if ( ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
				continue;
			}

			$offeringId = $product->get_meta( '_btcpay_offering_id', true );
			$planId = $product->get_meta( '_btcpay_plan_id', true );
			if ( $offeringId && $planId ) {
				$matches[] = [
					'offering_id' => $offeringId,
					'plan_id' => $planId,
					'product_id' => $product->get_id(),
				];
			}
		}

		if ( empty( $matches ) ) {
			return [];
		}

		if ( count( $matches ) > 1 ) {
			throw new \Exception( __( 'Only one subscription product can be purchased per order for BTCPay subscriptions.', 'btcpay-greenfield-for-woocommerce' ) );
		}

		return $matches[0];
	}

protected function getBtcpayCustomerSelector( \WC_Order $order, ?\WC_Subscription $subscription ): ?string {
		$subscriberId = $order->get_meta( 'BTCPay_subscriber_id' );
		if ( ! empty( $subscriberId ) ) {
			return $subscriberId;
		}

		if ( $subscription ) {
			$subscriberId = $subscription->get_meta( 'BTCPay_subscriber_id' );
			if ( ! empty( $subscriberId ) ) {
				return $subscriberId;
			}
		}

		return $order->get_billing_email() ?: null;
	}

protected function storeSubscriptionMetadata(
		\WC_Order $order,
		string $offeringId,
		string $planId,
		\BTCPayServer\Result\PlanCheckout $checkout,
		?\WC_Subscription $subscription
	): void {
		$order->update_meta_data( 'BTCPay_offering_id', $offeringId );
		$order->update_meta_data( 'BTCPay_plan_id', $planId );
		$order->update_meta_data( 'BTCPay_plan_checkout_id', $checkout->getId() );

		if ( $checkout->getInvoiceId() ) {
			$order->update_meta_data( 'BTCPay_id', $checkout->getInvoiceId() );
		}

		$order->save();

		if ( $subscription ) {
			$subscription->update_meta_data( 'BTCPay_offering_id', $offeringId );
			$subscription->update_meta_data( 'BTCPay_plan_id', $planId );
			$subscription->save();
		}
	}

	/**
	 * Maps customer billing metadata.
	 */
	protected function prepareCustomerMetadata( \WC_Order $order ): array {
		return [
			'buyerEmail'    => $order->get_billing_email(),
			'buyerName'     => $order->get_formatted_billing_full_name(),
			'buyerAddress1' => $order->get_billing_address_1(),
			'buyerAddress2' => $order->get_billing_address_2(),
			'buyerCity'     => $order->get_billing_city(),
			'buyerState'    => $order->get_billing_state(),
			'buyerZip'      => $order->get_billing_postcode(),
			'buyerCountry'  => $order->get_billing_country()
		];
	}

	/**
	 * Maps POS metadata.
	 */
	protected function preparePosMetadata( $order ): string {
		$posData = [
			'WooCommerce' => [
				'Order ID'       => $order->get_id(),
				'Order Number'   => $order->get_order_number(),
				'Order URL'      => $order->get_edit_order_url(),
				'Plugin Version' => constant( 'BTCPAYSERVER_VERSION' )
			]
		];

		return json_encode( $posData, JSON_THROW_ON_ERROR );
	}

	/**
	 * References WC order metadata with BTCPay invoice data.
	 */
	protected function updateOrderMetadata( int $orderId, \BTCPayServer\Result\Invoice $invoice ) {
		// Store relevant BTCPay invoice data.
		$order = wc_get_order($orderId);
		$order->update_meta_data( 'BTCPay_redirect', $invoice->getData()['checkoutLink'] );
		$order->update_meta_data( 'BTCPay_id', $invoice->getData()['id'] );
		$order->save();
	}

	/**
	 * Return the total quantity of the whole order for all line items.
	 */
	public function getOrderTotalItemsQuantity(\WC_Order $order): int {
		$total = 0;
		foreach ($order->get_items() as $item ) {
			$total += $item->get_quantity();
		}

		return $total;
	}

	/**
	 * Get customer visible gateway title.
	 */
	public function getTitle(): string {
		return $this->get_option('title', 'BTCPay (Bitcoin, Lightning Network, ...)');
	}

	/**
	 * Get customer facing gateway description.
	 */
	public function getDescription(): string {
		return $this->get_option('description', 'You will be redirected to BTCPay to complete your purchase.');
	}

	/**
	 * Get type of BTCPay payment method/token as configured. Can be payment or promotion.
	 */
	public function getTokenType(): string {
		return $this->get_option('token_type', 'payment');
	}

	/**
	 * Get allowed BTCPay payment methods (needed for limiting invoices to specific payment methods).
	 */
	abstract public function getPaymentMethods(): array;

	/**
	 * Get offering ID from subscription products
	 */
	protected function getSubscriptionOfferingId( \WC_Order $order ): ?string {
		if ( ! class_exists( 'WC_Subscriptions_Order' ) ) {
			return null;
		}

		$subscriptions = \WC_Subscriptions_Order::get_subscriptions_for_order( $order->get_id() );
		foreach ( $subscriptions as $subscription ) {
			$items = $subscription->get_items();
			foreach ( $items as $item ) {
				$product = $item->get_product();
				if ( $product && $offeringId = $product->get_meta( '_btcpay_offering_id' ) ) {
					return $offeringId;
				}
			}
		}

		return null;
	}

	/**
	 * Get plan ID from subscription products
	 */
	protected function getSubscriptionPlanId( \WC_Order $order ): ?string {
		if ( ! class_exists( 'WC_Subscriptions_Order' ) ) {
			return null;
		}

		$subscriptions = \WC_Subscriptions_Order::get_subscriptions_for_order( $order->get_id() );
		foreach ( $subscriptions as $subscription ) {
			$items = $subscription->get_items();
			foreach ( $items as $item ) {
				$product = $item->get_product();
				if ( $product && $planId = $product->get_meta( '_btcpay_plan_id' ) ) {
					return $planId;
				}
			}
		}

		return null;
	}

	/**
	 * Create BTCPay plan checkout
	 */
	protected function createBtcpayPlanCheckout( \WC_Order $order, string $offeringId, string $planId ): \BTCPayServer\Result\PlanCheckout {
		// Check if API key has required permissions
		if ( ! $this->apiHelper->apiKeyHasManageSubscribersPermission() ) {
			throw new \Exception( __( 'Your API key does not have permission to manage subscribers. Please create a new API key with the required permissions.', 'btcpay-greenfield-for-woocommerce' ) );
		}

		$client = new Subscriptions( $this->apiHelper->url, $this->apiHelper->apiKey );
		
		// Prepare metadata
		$metadata = [
			'orderId' => $order->get_id(),
			'orderNumber' => $order->get_order_number(),
			'woocommerce' => true
		];

		// Create plan checkout
		$planCheckout = $client->createPlanCheckout(
			$this->apiHelper->storeId,
			$offeringId,
			$planId,
			null, // customerSelector - let BTCPay handle it
			1440, // durationMinutes - 24 hours
			null, // onPayBehavior
			[ 'source' => 'woocommerce' ], // newSubscriberMetadata
			$metadata, // invoiceMetadata
			$metadata, // metadata
			false, // isTrial - will be determined by plan settings
			null, // creditPurchase
			$this->get_return_url( $order ), // successRedirectLink
			$order->get_billing_email() // newSubscriberEmail
		);

		return $planCheckout;
	}
}
