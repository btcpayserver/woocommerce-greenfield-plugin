<?php

/**
 * Update 1.0.3
*/

/**
 * Update order states and add default for the ExpiredPaidLate state.
 */
\BTCPayServer\WC\Helper\Logger::debug('Update 1.0.3: Starting updating order states.', true);
if ($orderStates = get_option('btcpay_gf_order_states')) {
	$orderStates['ExpiredPaidLate'] = 'wc-processing';
	update_option('btcpay_gf_order_states', $orderStates);
	\BTCPayServer\WC\Helper\Logger::debug('Update 1.0.3: Finished updating order states.', true);
}

/**
 * Also subscribe to InvoicePaymentSettled event for webhooks.
 */
\BTCPayServer\WC\Helper\Logger::debug('Update 1.0.3: Starting updating webhook.', true);
$storedWebhook = get_option('btcpay_gf_webhook');

if ($existingWebhook = \BTCPayServer\WC\Helper\GreenfieldApiWebhook::getWebhook($storedWebhook['id'])) {
	$updatedWebhook = \BTCPayServer\WC\Helper\GreenfieldApiWebhook::updateWebhook(
		$existingWebhook->getId(),
		$existingWebhook->getUrl(),
		$storedWebhook['secret'],
		$existingWebhook->getData()['enabled'],
		$existingWebhook->getData()['automaticRedelivery'],
		null
	);
	\BTCPayServer\WC\Helper\Logger::debug(print_r($updatedWebhook, true), true);
	\BTCPayServer\WC\Helper\Logger::debug('Update 1.0.3: Finished updating webhook.', true);
} else {
	\BTCPayServer\WC\Helper\Logger::debug('Error fetching existing webhook, aborting update.', true);
}
