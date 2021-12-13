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
				'title'       => __('Customer Message', 'btcpay-greenfield-for-woocommerce'),
				'type'        => 'textarea',
				'description' => __('Message to explain how the customer will be paying for the purchase.', 'btcpay-greenfield-for-woocommerce'),
				'default'     => 'You will be redirected to BTCPay to complete your purchase.',
				'desc_tip'    => true,
			],
		];

		return $this->form_fields;
	}
}
