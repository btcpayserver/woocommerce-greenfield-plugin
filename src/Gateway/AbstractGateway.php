<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Gateway;

abstract class AbstractGateway extends \WC_Payment_Gateway {
// initialze

// setup config


	public function __construct() {
		// General
		$this->id                = strtolower( get_class( $this ) );
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
		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->order_states = $this->get_option( 'order_states' );
		$this->debug        = 'yes' === $this->get_option( 'debug', 'no' );

		// BTCPay Server settings.
		$this->btcpay_url      = get_option( 'btcpayserver_url' );
		$this->btcpay_api_key  = get_option( 'btcpayserver_api_key' );
		$this->btcpay_store_id = get_option( 'btcpayserver_store_id' );


		// Debugging & informational settings.
		$this->debug_php_version    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$this->debug_plugin_version = BTCPAYSERVER_VERSION;
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields(): void {
		// todo: maybe remove settingshelper as we have this abstract class as boilerplate anyway
		$settingsHelper    = \BTCPayServerWCPlugin::getSettingsHelper();
		$this->form_fields = $settingsHelper->gatewayFormFields(
			$this->getDefaultTitle(),
			$this->getDefaultDescription(),
		);
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
