<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Helper;

use BTCPayServer\Client\Webhook;
use BTCPayServer\Result\Webhook as WebhookResult;

class GreenfieldApiWebhook {
	public const WEBHOOK_EVENTS = [
		'InvoiceReceivedPayment',
		'InvoicePaymentSettled',
		'InvoiceProcessing',
		'InvoiceExpired',
		'InvoiceSettled',
		'InvoiceInvalid'
	];

	/**
	 * Get locally stored webhook data and check if it exists on the store.
	 */
	public static function webhookExists(string $apiUrl, string $apiKey, string $storeId, $manualWebhookSecret = null): bool {

		if ( $storedWebhook = get_option( 'btcpay_gf_webhook' ) ) {
			// Handle case of manually entered webhook (secret). We can't query webhooks endpoint at all without permission.
			if ($storedWebhook['id'] === 'manual' && $storedWebhook['secret'] === $manualWebhookSecret) {
				Logger::debug('Detected existing and manually set webhook.');
				return true;
			}

			// Check automatically created webhook.
			try {
				$whClient = new Webhook( $apiUrl, $apiKey );
				$existingWebhook = $whClient->getWebhook( $storeId, $storedWebhook['id'] );
				// Check for the url here as it could have been changed on BTCPay Server making the webhook not work for WooCommerce anymore.
				if (
					$existingWebhook->getData()['id'] === $storedWebhook['id'] &&
					strpos( $existingWebhook->getData()['url'], $storedWebhook['url'] ) !== false
				) {
					Logger::debug('Detected existing automatically set webhook.');
					return true;
				}
			} catch (\Throwable $e) {
				Logger::debug('Error fetching existing Webhook from BTCPay Server. Message: ' . $e->getMessage());
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
			Logger::debug('Error creating a new webhook on BTCPay Server instance: ' . $e->getMessage());
		}

		return null;
	}

	/**
	 * Update an existing webhook on BTCPay Server.
	 */
	public static function updateWebhook(
		string $webhookId,
		string $webhookUrl,
		string $secret,
		bool $enabled,
		bool $automaticRedelivery,
		?array $events
	): ?WebhookResult {

		if ($config = GreenfieldApiHelper::getConfig()) {
			try {
				$whClient = new Webhook( $config['url'], $config['api_key'] );
				$webhook = $whClient->updateWebhook(
					$config['store_id'],
					$webhookUrl,
					$webhookId,
					$events ?? self::WEBHOOK_EVENTS,
					$enabled,
					$automaticRedelivery,
					$secret
				);

				return $webhook;
			} catch (\Throwable $e) {
				Logger::debug('Error updating existing Webhook from BTCPay Server: ' . $e->getMessage());
				return null;
			}
		} else {
			Logger::debug('Plugin not configured, aborting updating webhook.');
		}

		return null;
	}

	/**
	 * Load existing webhook data from BTCPay Server, defaults to locally stored webhook.
	 */
	public static function getWebhook(?string $webhookId): ?WebhookResult {
		$existingWebhook = get_option('btcpay_gf_webhook');
		$config = GreenfieldApiHelper::getConfig();

		try {
			$whClient = new Webhook( $config['url'], $config['api_key'] );
			$webhook = $whClient->getWebhook(
				$config['store_id'],
				$webhookId ?? $existingWebhook['id'],
				);

			return $webhook;
		} catch (\Throwable $e) {
			Logger::debug('Error fetching existing Webhook from BTCPay Server: ' . $e->getMessage());
		}

		return null;
	}
}
