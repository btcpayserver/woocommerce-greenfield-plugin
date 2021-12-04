<?php

namespace BTCPayServer\WC\Gateway;

/**
 * Default Gateway that provides all available payment methods of BTCPay Server store configuration.
 */
class DefaultGateway extends AbstractGateway {

	public function __construct() {
		// Set the id first.
		$this->id                 = 'btcpaygf_default';

		// Call parent constructor.
		parent::__construct();

		// todo: maybe make the button text configurable via settings.
		// General gateway setup.
		$this->order_button_text  = __('Proceed to BTCPay', BTCPAYSERVER_TEXTDOMAIN);
		// Admin facing title and description.
		$this->method_title       = 'BTCPay (default)';
		$this->method_description = __('BTCPay default gateway supporting all available tokens on your BTCPay store.', BTCPAYSERVER_TEXTDOMAIN);

		// Actions.
		add_action('woocommerce_api_btcpaygf_default', [$this, 'processWebhook']);
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		return $this->get_option('title', 'BTCPay (Bitcoin, Lightning Network, ...)');
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		return $this->get_option('description', 'You will be redirected to BTCPay to complete your purchase.');
	}

	/**
	 * @inheritDoc
	 */
	public function init_form_fields(): void {
		parent::init_form_fields();
		$this->form_fields += [
			'enforce_payment_tokens' => [
				'title'       => __( 'Enforce payment tokens', BTCPAYSERVER_TEXTDOMAIN ),
				'type'        => 'checkbox',
				'label'       => __( 'Enforce payment methods "payment". This way tokens of type promotion will be excluded for this gateway.', BTCPAYSERVER_TEXTDOMAIN ),
				'default'     => 'yes',
				'value'       => 'yes',
				'description' => __( 'This will override the default btcpay payment method (defaults to all supported by BTCPay Server) and enforce to tokens of type "payment". This is useful if you have enabled separate payment gateways and want full control on what is available on BTCPay Server payment page.', BTCPAYSERVER_TEXTDOMAIN ),
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getPaymentMethods(): array {
		if ($this->get_option('enforce_payment_tokens') === 'yes') {
			$gateways = WC()->payment_gateways->get_available_payment_gateways();
			$btcPayPaymentGW = [];
			/** @var  $gateway AbstractGateway */
			foreach ($gateways as $id => $gateway) {
				if (
					$gateway->enabled === 'yes' &&
					strpos($id, 'btcpaygf') !== FALSE
					&& (isset($gateway->tokenType) && $gateway->tokenType === 'payment')
				) {
					$btcPayPaymentGW[] = $gateway->primaryPaymentMethod;
				}
			}
			return $btcPayPaymentGW;
		}

		return [];
	}

}
