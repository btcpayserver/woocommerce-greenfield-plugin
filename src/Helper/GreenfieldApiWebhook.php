<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Helper;

use BTCPayServer\Client\Webhook;
use BTCPayServer\Result\Webhook as WebhookResult;

class GreenfieldApiWebhook {
	public const WEBHOOK_EVENTS = [
		'InvoiceReceivedPayment',
		'InvoiceProcessing',
		'InvoiceExpired',
		'InvoiceSettled',
		'InvoiceInvalid'
	];

	/**
	 * Get locally stored webhook data and check if it exists on the store.
	 */
	public static function webhookExists(string $apiUrl, string $apiKey, string $storeId): bool {
		if ( $storedWebhook = get_option( 'btcpay_gf_webhook' ) ) {
			try {
				$whClient = new Webhook( $apiUrl, $apiKey );
				$existingWebhook = $whClient->getWebhook( $storeId, $storedWebhook['id'] );
				// Check for the url here as it could have been changed on BTCPay Server making the webhook not work for WooCommerce anymore.
				if ( strpos( $existingWebhook->getData()['url'], $storedWebhook['url'] ) !== false ) {
					return true;
				}
			} catch (\Throwable $e) {
				Logger::debug('Error fetching existing Webhook from BTCPay Server.');
			}
		}

		return false;
	}

	/**
	 * Register a webhook on BTCPay Server and store it locally.
	 */
	public static function registerWebhook(string $apiUrl, $apiKey, $storeId): ?WebhookResult {
		try {
			$whClient = new Webhook( $apiUrl, $apiKey );
			$webhook = $whClient->createWebhook(
				$storeId,
				WC()->api_request_url( 'btcpaygf_default' ),
				self::WEBHOOK_EVENTS,
				null
			);

			// Store in option table.
			update_option(
				'btcpay_gf_webhook',
				[
					'id' => $webhook->getData()['id'],
					'secret' => $webhook->getData()['secret'],
					'url' => $webhook->getData()['url']
				]
			);

			return $webhook;
		} catch (\Throwable $e) {
			Logger::debug('Error fetching existing Webhook from BTCPay Server.');
		}

		return null;
	}
}
