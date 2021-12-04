<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Gateway;

/**
 * Handles and initializes separate gateways.
 */
class SeparateGateways {

	public static function generateClasses() {
		// Load payment methods from BTCPS as separate gateways.
		if (get_option('btcpay_gf_separate_gateways') === 'yes') {
			// Call init separate payment gateways here.
			if ( $separateGateways = \BTCPayServer\WC\Helper\GreenfieldApiHelper::supportedPaymentMethods() ) {
				self::initSeparatePaymentGateways( $separateGateways );
			}
		}
	}

	public static function initSeparatePaymentGateways(array $gateways) {
		foreach ( $gateways as $gw ) {
			$className = $gw['className'];
			$symbol = $gw['symbol'];
			$id = 'btcpaygf_' . strtolower($symbol);
			// todo: mode (payment, promotion)
			// todo: icon upload
			if ( ! class_exists( $className ) ) {
				// Build the class structure.
				$classcode = "use BTCPayServer\WC\Gateway\AbstractGateway;
				              class {$className} extends AbstractGateway {
				              	public function __construct() {
				                  \$this->id = '{$id}';
				                  parent::__construct();
				                  \$this->method_title = 'BTCPay Gateway: {$symbol}';
				                  \$this->method_description = 'This is separate payment gateway managed by BTCPay.';
				                  \$this->tokenType = \$this->getTokenType();
				                  \$this->primaryPaymentMethod = '{$symbol}';
								  \$this->icon = ''; // todo get from config
				                }
								public function getPaymentMethods(): array {
									return ['{$symbol}']; // todo: add feature to add other pm
			                    }

								public function init_form_fields() {
									parent::init_form_fields();
									\$this->form_fields += [
										'token_type' => [
											'title'       => __( 'Token type', BTCPAYSERVER_TEXTDOMAIN ),
											'type'        => 'select',
											'options'     => [
												'payment'    => 'Payment',
												'promotion'       => 'Promotion'
											],
											'default'     => 'payment',
											'description' => __( 'Tokens of type promotion will not have a FIAT (USD, EUR, ..) exchange rate but counted as 1 per item quantity. See <a target=\"_blank\" href=\"https://docs.btcpayserver.org/FAQ/Integrations/#token-types\">here</a> for more details.', BTCPAYSERVER_TEXTDOMAIN ),
											'desc_tip'    => false,
										],
									];
								}
							}
							";

				// Initialize it on the fly.
				eval( $classcode );

			}
		}
	}
}

