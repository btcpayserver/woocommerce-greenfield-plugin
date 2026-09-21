<?php

namespace BTCPayServer\WC\Helper;

class SettingsHelper {
	public function gatewayFormFields(
		$defaultTitle,
		$defaultDescription
	) {
		$this->form_fields = [
			'title' => [
				'title'       => __('Title', 'btcpay-greenfield-for-woocommerce'),
				'type'        => 'text',
				'description' => __('Controls the name of this payment method as displayed to the customer during checkout.', 'btcpay-greenfield-for-woocommerce'),
				'default'     => __('BTCPay (Bitcoin, Lightning Network, ...)', 'btcpay-greenfield-for-woocommerce'),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __('Custom checkout text', 'btcpay-greenfield-for-woocommerce'),
				'type'        => 'textarea',
				'description' => __('Overrides the default checkout text from BTCPay settings. Leave empty to use the default.', 'btcpay-greenfield-for-woocommerce'),
				'default'     => '',
				'desc_tip'    => false,
			],
		];

		return $this->form_fields;
	}
}
