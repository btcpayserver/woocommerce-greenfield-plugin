<?php

namespace BTCPayServer\WC\Gateway;

// todo extract common things to abstract class
// todo think about global config for api connection, order states and
// keep payment method config focused on the payment method itself



class DefaultGateway extends AbstractGateway {
// initialze

// setup config

// load additional classes / payment methods

// todo fix why settings are not saved and loaded properly.

	public function __construct() {
		// todo maybe move to bottom and clean duplicates up
		parent::__construct();
		// General
		$this->id                 = 'btcpaygf_default';
		$this->order_button_text  = __('Proceed to BTCPay', BTCPAYSERVER_TEXTDOMAIN);
		$this->method_title       = 'BTCPay NEWWW';
		$this->method_description = 'BTCPay allows you to accept bitcoin payments on your WooCommerce store.';

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title              = $this->getDefaultTitle();
		$this->description        = $this->getDefaultDescription(); //$this->get_option('description');

		// Actions
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
		add_action('woocommerce_api_btcpaygf_default', [$this, 'processWebhook']);


		/*
		var_dump($this->id);
		var_dump($this->description);
		var_dump($this->get_option('description'));
		*/

	}

	public function getDefaultTitle(): string {
		return $this->get_option('title', 'BTCPay (Bitcoin, Lightning Network, ...)');
	}

	protected function getDefaultDescription(): string {
		return $this->get_option('description', 'You will be redirected to BTCPay to complete your purchase 3434.');
	}

	protected function getSettingsDescription(): string {
		return "d: setSettingsDescription()";
	}

	public function init_form_fields(): void {
		parent::init_form_fields();
		$this->form_fields += [
			'enforce_payment_tokens' => [
				'title'       => __( 'Enforce payment tokens', BTCPAYSERVER_TEXTDOMAIN ),
				'type'        => 'checkbox',
				'label'       => __( 'Limit default payment methods to listed "payment" tokens.', BTCPAYSERVER_TEXTDOMAIN ),
				'default'     => 'no',
				'value'       => 'yes',
				'desc' => __( 'This will override the default btcpay payment method (defaults to all supported by BTCPay Server) and enforce to tokens of type "payment". This is useful if you want full control on what is available on BTCPay Server payment page.', BTCPAYSERVER_TEXTDOMAIN ),
				'desc_tip'    => true,
			],
		];
	}




}
