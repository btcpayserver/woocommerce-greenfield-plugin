<?php

declare( strict_types=1 );

namespace BTCPayServer\WC\Gateway;

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
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
		add_action('admin_enqueue_scripts', [$this, 'addScripts']);
		add_action('woocommerce_update_options_payment_gateways_' . $this->getId(), [$this, 'process_admin_options']);
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

		// Check for existing invoice and redirect instead.
		if ( $this->validInvoiceExists( $orderId ) ) {
			$existingInvoiceId = get_post_meta( $orderId, 'BTCPay_id', true );
			Logger::debug( 'Found existing BTCPay Server invoice and redirecting to it. Invoice id: ' . $existingInvoiceId );

			return [
				'result'   => 'success',
				'redirect' => $this->apiHelper->getInvoiceRedirectUrl( $existingInvoiceId ),
			];
		}

		// Create an invoice.
		Logger::debug( 'Creating invoice on BTCPay Server' );
		if ( $invoice = $this->createInvoice( $order ) ) {

			// Todo: update order status and BTCPay meta data.

			Logger::debug( 'Invoice creation successful, redirecting user.' );

			return [
				'result'   => 'success',
				'redirect' => $invoice->getData()['checkoutLink'],
			];
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
	public function addScripts($hook_suffix) {
		if ($hook_suffix === 'woocommerce_page_wc-settings') {
			wp_enqueue_media();
			wp_register_script(
				'btcpay_gf_abstract_gateway',
				BTCPAYSERVER_PLUGIN_URL . 'assets/js/gatewayIconMedia.js',
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
					Logger::debug('Could not load order by BTCPay invoiceId: ' . $postData->invoiceId);
					// Note: we return status 200 here for wp_die() which seems counter intuative but needs to be done
					// to not clog up the BTCPay servers webhook processing queue until it is fixed there.
					wp_die('No order found for this invoiceId.', '', ['response' => 200]);
				}

				// Abort on multiple orders found.
				if (count($orders) > 1) {
					Logger::debug('Found multiple orders for invoiceId: ' . $postData->invoiceId);
					Logger::debug(print_r($orders, true));
					wp_die('Multiple orders found for this invoiceId, aborting.');
				}

				$this->processOrderStatus($orders[0], $postData);

			} catch (\Throwable $e) {
				Logger::debug('Error decoding webook payload: ' . $e->getMessage());
				Logger::debug($rawPostData);
			}
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

		switch ($webhookData->type) {
			case 'InvoiceReceivedPayment':
				if ($webhookData->afterExpiration) {
					if ($order->get_status() === $configuredOrderStates[OrderStates::EXPIRED]) {
						$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL]);
						$order->add_order_note(__('Invoice payment received after invoice was already expired.', 'btcpay-greenfield-for-woocommerce'));
					}
				} else {
					// No need to change order status here, only leave a note.
					$order->add_order_note(__('Invoice (partial) payment received. Waiting for full payment.', 'btcpay-greenfield-for-woocommerce'));
				}

				// Store payment data (exchange rate, address).
				$this->updateWCOrderPayments($order);

				break;
			case 'InvoiceProcessing': // The invoice is paid in full.
				$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::PROCESSING]);
				if ($webhookData->overPaid) {
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
				if ($webhookData->overPaid) {
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
		if ( $invoiceId = get_post_meta( $orderId, 'BTCPay_id', true ) ) {
			// Validate the order status on BTCPay server.
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			try {
				Logger::debug( 'Trying to fetch existing invoice from BTCPay Server.' );
				$invoice       = $client->getInvoice( $this->apiHelper->storeId, $invoiceId );
				$invalidStates = [ 'Expired', 'Invalid' ];
				if ( in_array( $invoice->getData()['status'], $invalidStates ) ) {
					return false;
				} else {
					// Check also if the payment methods match.
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
				if ((float) $payment->getTotalPaid() > 0.0) {
					$paymentMethod = $payment->getPaymentMethod();
					// Update order meta data.
					update_post_meta( $order->get_id(), "BTCPay_{$paymentMethod}_destination", $payment->getDestination() ?? '' );
					update_post_meta( $order->get_id(), "BTCPay_{$paymentMethod}_amount", $payment->getAmount() ?? '' );
					update_post_meta( $order->get_id(), "BTCPay_{$paymentMethod}_paid", $payment->getTotalPaid() ?? '' );
					update_post_meta( $order->get_id(), "BTCPay_{$paymentMethod}_networkFee", $payment->getNetworkFee() ?? '' );
					update_post_meta( $order->get_id(), "BTCPay_{$paymentMethod}_rate", $payment->getRate() ?? '' );
					if ((float) $payment->getRate() > 0.0) {
						$formattedRate = number_format((float) $payment->getRate(), wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
						update_post_meta( $order->get_id(), "BTCPay_{$paymentMethod}_rateFormatted", $formattedRate );
					}
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
		update_post_meta( $orderId, 'BTCPay_redirect', $invoice->getData()['checkoutLink'] );
		update_post_meta( $orderId, 'BTCPay_id', $invoice->getData()['id'] );
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
}
