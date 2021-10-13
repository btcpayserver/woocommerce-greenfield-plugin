<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Gateway;

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Util\PreciseNumber;
use BTCPayServer\WC\Helper\GreenfieldApiHelper;
use BTCPayServer\WC\Helper\Logger;

abstract class AbstractGateway extends \WC_Payment_Gateway {
// initialze

// setup config
	protected $apiHelper;


	public function __construct() {
		// General
		//$this->id                = strtolower( self::class );
		$this->icon              = plugin_dir_url( __FILE__ ) . 'assets/img/icon.png';
		$this->has_fields        = false;
		$this->order_button_text = __( 'Proceed to BTCPay', BTCPAYSERVER_TEXTDOMAIN );

		// Set gateway title, only shown for admins in WC payments settings tab.
		$this->method_title       = 'BTCPay - ' . $this->getDefaultTitle();
		$this->method_description = $this->getSettingsDescription();

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		//$this->title        = $this->get_option( 'title' );
		//$this->description  = $this->get_option( 'description' );
		//$this->order_states = $this->get_option( 'order_states' );

		$this->apiHelper = new GreenfieldApiHelper();
		// Debugging & informational settings.
		$this->debug_php_version    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$this->debug_plugin_version = BTCPAYSERVER_VERSION;
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields(): void {
		$this->form_fields = [
			'title' => [
				'title'       => __('Title', BTCPAYSERVER_TEXTDOMAIN),
				'type'        => 'text',
				'description' => __('Controls the name of this payment method as displayed to the customer during checkout.', BTCPAYSERVER_TEXTDOMAIN),
				'default'     => $this->getDefaultTitle(),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __('Customer Message', BTCPAYSERVER_TEXTDOMAIN),
				'type'        => 'textarea',
				'description' => __('Message to explain how the customer will be paying for the purchase.', BTCPAYSERVER_TEXTDOMAIN),
				'default'     => $this->getDefaultDescription(),
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function process_payment($orderId) {
		if (!$this->apiHelper->configured) {
			Logger::debug('BTCPay Server API connection not configured, aborting. Please go to BTCPay Server settings and set it up.');
			// todo: show error notice/make sure it fails
			throw new \Exception(__("Can't process order. Please contact us if the problem persists.", BTCPAYSERVER_TEXTDOMAIN));
		}

		// Load the order and check it.
		$order = new \WC_Order( $orderId );
		if ( $order->get_id() === 0 ) {
			$message = 'Could not load order id ' . $orderId . ', aborting.';
			Logger::debug( $message, true );
			throw new \Exception( $message );
		}

		// Check for existing invoice and redirect instead.
		if ($this->validInvoiceExists($orderId)) {
			$existingInvoiceId = get_post_meta($orderId, 'BTCPay_id', true);
			Logger::debug('Found existing BTCPay Server invoice and redirecting to it. Invoice id: ' . $existingInvoiceId);
			return array(
				'result'   => 'success',
				'redirect' => $this->apiHelper->getInvoiceRedirectUrl($existingInvoiceId),
			);
		}

		// Create an invoice.
		Logger::debug('Creating invoice on BTCPay Server');
		if ($invoice = $this->createInvoice($order)) {

			// Todo: update order status and BTCPay meta data.

			Logger::debug('Invoice creation successful, redirecting user.');
			return array(
				'result'   => 'success',
				'redirect' => $invoice->getData()['checkoutLink'],
			);
		}
	}

	public function processWebhook() {
		echo "process webhook btcpay";
		var_dump(new \WC_Order());
		die('works');
	}

	/**
	 * Checks if the order has already a BTCPay invoice set and checks if it is still
	 * valid to avoid creating multiple invoices for the same order on BTCPay Server end.
	 *
	 * @param int $orderId
	 *
	 * @return mixed Returns false if no valid invoice found or the invoice id.
	 */
	protected function validInvoiceExists(int $orderId): bool {
		// Check order metadata for BTCPay_id.
		if ($invoiceId = get_post_meta($orderId, 'BTCPay_id', true)) {
			// Validate the order status on BTCPay server.
			$client = new Invoice($this->apiHelper->url, $this->apiHelper->apiKey);
			try {
				Logger::debug('Trying to fetch existing invoice from BTCPay Server.');
				$invoice = $client->getInvoice($this->apiHelper->storeId, $invoiceId);
				$invalidStates = ['Expired', 'Invalid'];
				if (in_array($invoice->getData(), $invalidStates)) {
					return false;
				} else {
					return true;
				}
			} catch (\Throwable $e) {
				Logger::debug($e->getMessage());
			}
		}

		return false;
	}

	/**
	 * Create an invoice on BTCPay Server.
	 *
	 * @param \WC_Order $order
	 *
	 * @return \BTCPayServer\Result\Invoice|void
	 */
	protected function createInvoice(\WC_Order $order) {
			// In case some plugins customizing the order number we need to pass that along, defaults to internal ID.
			$orderNumber = $order->get_order_number();
			Logger::debug('Got order number: ' . $orderNumber . ' and order ID: ' . $order->get_id());

			// Redirect URL.
			$redirectUrl = $this->get_return_url($order);
			$checkoutOptions = new InvoiceCheckoutOptions();
			$checkoutOptions->setRedirectURL($redirectUrl);
			Logger::debug('Setting redirect url to: ' . $redirectUrl);

			$metadata = [];

			// Send customer data only if option is set.
			if (get_option('btcpay_gf_send_customer_data') === 'yes') {
				$metadata += $this->prepareCustomerMetadata($order);
			}

			// Set included tax amount.
			$metadata['taxIncluded'] = $order->get_cart_tax();

			$metadata['posData'] = $this->preparePosMetadata($order);

			// todo: handle payment methods.

			// todo: transaction speed / checkout settings.

			// Create the invoice on BTCPay Server.
			$client = new Invoice($this->apiHelper->url, $this->apiHelper->apiKey);
			try {
				$invoice = $client->createInvoice(
					$this->apiHelper->storeId,
					$order->get_currency(),
					PreciseNumber::parseString($order->get_total()), // unlike method signature, it returns string.
					$orderNumber,
					null,
					$metadata
				);

				$this->updateOrderMetadata($order->get_id(), $invoice);

				return $invoice;

			} catch (\Throwable $e) {
				Logger::debug($e->getMessage(), true);
				// todo: should we throw exception here to make sure there is an visible error on the page and not silently failing?
			}
	}

	protected function prepareCustomerMetadata(\WC_Order $order): array {
		return [
			'buyerEmail' => $order->get_billing_email(),
			'buyerName' => $order->get_formatted_billing_full_name(),
			'buyerAddress1' => $order->get_billing_address_1(),
			'buyerAddress2' => $order->get_billing_address_2(),
			'buyerCity' => $order->get_billing_city(),
			'buyerState' => $order->get_billing_state(),
			'buyerZip' => $order->get_billing_postcode(),
			'buyerCountry' => $order->get_billing_country()
		];
	}

	protected function preparePosMetadata($order): string {
		$posData = [
			'WooCommerce' => [
				'Order ID' => $order->get_id(),
				'Order Number' => $order->get_order_number(),
				'Order URL' => $order->get_edit_order_url(),
				'Plugin Version' => constant('BTCPAYSERVER_VERSION')
			]
		];

		return json_encode($posData, JSON_THROW_ON_ERROR);
	}

	protected function updateOrderMetadata( int $orderId, \BTCPayServer\Result\Invoice $invoice ) {
		// Store relevant BTCPay invoice data.
		update_post_meta($orderId, 'BTCPay_redirect', $invoice->getData()['checkoutLink']);
		update_post_meta($orderId, 'BTCPay_id', $invoice->getData()['id']);

		// todo: discuss: below data taken from old plugin, not sure if this is needed; payment data needs to get fetched separately
		// by "Get invoice payment methods endpoint".
		/*
		update_post_meta($orderId, 'BTCPay_rate', $invoice->getRate());
		$formattedRate = number_format($invoice->getRate(), wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
		update_post_meta($orderId, 'BTCPay_formatted_rate', $formattedRate);
		update_post_meta($orderId, 'BTCPay_btcPrice', $responseData->data->btcPrice);
		update_post_meta($orderId, 'BTCPay_btcPaid', $responseData->data->btcPaid);
		update_post_meta($orderId, 'BTCPay_BTCaddress', $responseData->data->bitcoinAddress);
		*/
	}

	/**
	 * @return string
	 */
	abstract public function getDefaultTitle(): string;

	/**
	 * @return string
	 */
	abstract protected function getSettingsDescription(): string;

	/**
	 * @return string
	 */
	abstract protected function getDefaultDescription(): string;

}
