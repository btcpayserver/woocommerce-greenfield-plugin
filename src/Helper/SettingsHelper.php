<?php

namespace BTCPayServer\WC\Helper;

class SettingsHelper {
	public function gatewayFormFields(
		$defaultTitle,
		$defaultDescription
	) {
		//$this->log('    [Info] Entered init_form_fields()...');
		$log_file = 'btcpay-' . sanitize_file_name( wp_hash( 'btcpay' ) ) . '-log';
		$logs_href = get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-status&tab=logs&log_file=' . $log_file;

		$this->form_fields = [
			'title' => [
				'title'       => __('Title', BTCPAYSERVER_TEXTDOMAIN),
				'type'        => 'text',
				'description' => __('Controls the name of this payment method as displayed to the customer during checkout.', BTCPAYSERVER_TEXTDOMAIN),
				'default'     => __('BTCPay (Bitcoin, Lightning Network, ...)', BTCPAYSERVER_TEXTDOMAIN),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __('Customer Message', BTCPAYSERVER_TEXTDOMAIN),
				'type'        => 'textarea',
				'description' => __('Message to explain how the customer will be paying for the purchase.', BTCPAYSERVER_TEXTDOMAIN),
				'default'     => 'You will be redirected to BTCPay to complete your purchase.',
				'desc_tip'    => true,
			],
		];

		//$this->log('    [Info] Initialized form fields: ' . var_export($this->form_fields, true));
		//$this->log('    [Info] Leaving init_form_fields()...');


		return $this->form_fields;
	}
}
