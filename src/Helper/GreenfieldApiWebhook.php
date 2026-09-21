<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Helper;

use BTCPayServer\Client\Webhook;
use BTCPayServer\Exception\RequestException;
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
	 * Accept existing secrets without imposing new length requirements on working installations.
	 */
	public static function isUsableSecret($secret): bool {
		return is_string($secret) && trim($secret) !== '' && strcasecmp(trim($secret), 'manual') !== 0;
	}

	/**
	 * Get locally stored webhook data and check if it exists on the store.
	 *
	 * @throws \Throwable If the lookup fails for a reason other than a missing webhook.
	 */
	public static function webhookExists(string $apiUrl, string $apiKey, string $storeId, $manualWebhookSecret = null): bool {

		$storedWebhook = get_option('btcpay_gf_webhook', []);
		if (is_array($storedWebhook) && !empty($storedWebhook['id']) && self::isUsableSecret($storedWebhook['secret'] ?? null)) {
			// Handle case of manually entered webhook (secret). We can't query webhooks endpoint at all without permission.
			if ($storedWebhook['id'] === 'manual') {
				return $manualWebhookSecret === null || $storedWebhook['secret'] === $manualWebhookSecret;
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
				// A timeout, permission error or server error does not mean the webhook is missing.
				if (!$e instanceof RequestException || $e->getCode() !== 404) {
					throw $e;
				}
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

			if (!self::isUsableSecret($webhook->getData()['secret'] ?? null)) {
				self::deleteWebhook($apiUrl, $apiKey, $storeId, $webhook->getId());
				throw new \RuntimeException('Received an invalid webhook secret, aborting registration.');
			}

			// Store in option table.
			$webhookConfig = [
				'id' => $webhook->getId(),
				'secret' => $webhook->getData()['secret'],
				'url' => $webhook->getUrl()
			];
			if (!update_option('btcpay_gf_webhook', $webhookConfig) && get_option('btcpay_gf_webhook') !== $webhookConfig) {
				self::deleteWebhook($apiUrl, $apiKey, $storeId, $webhook->getId());
				throw new \RuntimeException('Could not store the new webhook configuration.');
			}

			return $webhook;
		} catch (\Throwable $e) {
			Logger::debug('Error creating a new webhook on BTCPay Server instance: ' . $e->getMessage());
		}

		return null;
	}

	/**
	 * Delete a webhook from BTCPay Server.
	 */
	public static function deleteWebhook(
		string $apiUrl,
		string $apiKey,
		string $storeId,
		string $webhookId
	): bool {
		try {
			$whClient = new Webhook($apiUrl, $apiKey);
			$whClient->deleteWebhook($storeId, $webhookId);
			Logger::debug('Deleted webhook from BTCPay Server: ' . $webhookId);
			return true;
		} catch (\Throwable $e) {
			Logger::debug('Failed to delete webhook from BTCPay Server: ' . $e->getMessage());
			return false;
		}
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
		if (!self::isUsableSecret($secret)) {
			Logger::debug('Invalid webhook secret, aborting webhook update.');
			return null;
		}

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
