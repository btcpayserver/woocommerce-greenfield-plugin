<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Helper;

use BTCPayServer\Client\ApiKey;
use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\Server;
use BTCPayServer\Client\Store;
use BTCPayServer\Client\StorePaymentMethod;
use BTCPayServer\Client\Webhook;
use BTCPayServer\Result\AbstractStorePaymentMethodResult;
use BTCPayServer\Result\ServerInfo;
use BTCPayServer\WC\Admin\Notice;

class GreenfieldApiHelper {
	const PM_CACHE_KEY = 'btcpay_payment_methods';
	const PM_CLASS_NAME_PREFIX = 'BTCPay_GF_';
	public $configured = false;
	public $url;
	public $apiKey;
	public $storeId;
	public $webhook;

	// todo: need to refactor as it loads cached options if form submitted by ajax
	public function __construct() {
		if ($config = self::getConfig()) {
			$this->url = $config['url'];
			$this->apiKey = $config['api_key'];
			$this->storeId = $config['store_id'];
			$this->webhook = $config['webhook'];
			$this->configured = true;
		}
	}

	// todo: maybe remove static class and make GFConfig object or similar
	public static function getConfig(): array {
		// todo: perf: maybe add caching
		$url = get_option('btcpay_gf_url');
		$key = get_option('btcpay_gf_api_key');
		if ($url && $key) {
			return [
				'url' => rtrim($url, '/'),
				'api_key' => $key,
				'store_id' => get_option('btcpay_gf_store_id', null),
				'webhook' => get_option('btcpay_gf_webhook', null)
			];
		}
		else {
			return [];
		}
	}

	public static function checkApiKeyWorks(string $url = null, string $apiKey = null): bool {
		$config = [];

		if ($url && $apiKey) {
			$config['url'] = $url;
			$config['api_key'] = $apiKey;
		} else {
			$config = self::getConfig();
		}

		if ($config) {
			$client = new Store($config['url'], $config['api_key']);
			if (!empty($client->getStores())) {
				return true;
			} else {
				Logger::debug('Could not fetch stores from BTCPay Server with the given API key.');
				return false;
			}
		}

		return false;
	}

	public static function getServerInfo(): ?ServerInfo {
		if ($config = self::getConfig()) {
			try {
				$client = new Server( $config['url'], $config['api_key'] );
				return $client->getInfo();
			} catch (\Throwable $e) {
				Logger::debug('Error fetching server info: ' . $e->getMessage(), true);
				return null;
			}
		}

		return null;
	}

	/**
	 * List supported payment methods by BTCPay Server.
	 */
	public static function supportedPaymentMethods(): array {
		$paymentMethods = [];

		// Use transients API to cache pm for a few minutes to avoid too many requests to BTCPay Server.
		if ($cachedPaymentMethods = get_transient(self::PM_CACHE_KEY)) {
			return $cachedPaymentMethods;
		}

		if ($config = self::getConfig()) {
			$client = new StorePaymentMethod($config['url'], $config['api_key']);
			if ($storeId = get_option('btcpay_gf_store_id')) {
				try {
					$pmResult = $client->getPaymentMethods($storeId);
					/** @var AbstractStorePaymentMethodResult $pm */
					foreach ($pmResult as $pm) {
						if ($pm->isEnabled() && $pmName = $pm->getData()['paymentMethod'] )  {
							// Convert - to _ and escape value for later use in gateway class generator.
							$symbol = sanitize_html_class(str_replace('-', '_', $pmName));
							$paymentMethods[] = [
								'symbol' => $symbol,
								'className' => self::PM_CLASS_NAME_PREFIX . $symbol
							];
						}
					}
				} catch (\Throwable $e) {
					$exceptionPM = 'Exception loading payment methods: ' . $e->getMessage();
					Logger::debug( $exceptionPM, true);
					Notice::addNotice('error', $exceptionPM);
				}
			}
		}

		// Store payment methods into cache.
		set_transient( self::PM_CACHE_KEY, $paymentMethods,5 * MINUTE_IN_SECONDS );

		return $paymentMethods;
	}

	/**
	 * Deletes local cache of supported payment methods.
	 */
	public static function clearSupportedPaymentMethodsCache(): void {
		delete_transient( self::PM_CACHE_KEY );
	}

	/**
	 * Returns BTCPay Server invoice url.
	 */
	public function getInvoiceRedirectUrl($invoiceId): ?string {
		if ($this->configured) {
			return $this->url . '/i/' . urlencode($invoiceId);
		}
		return null;
	}

	/**
	 * Check webhook signature to be a valid request.
	 */
	public function validWebhookRequest(string $signature, string $requestData): bool {
		if ($this->configured) {
			return Webhook::isIncomingWebhookRequestValid($requestData, $signature, $this->webhook['secret']);
		}
		return false;
	}

	/**
	 * Checks if the provided API config already exists in options table.
	 */
	public static function apiCredentialsExist(string $apiUrl, string $apiKey, string $storeId): bool {
		if ($config = self::getConfig()) {
			if (
				$config['url'] === $apiUrl &&
				$config['api_key'] === $apiKey &&
				$config['store_id'] === $storeId
			) {
				return true;
			}
		}

		return false;
	}

	public static function webhookIsSetup(): bool {
		if ($config = self::getConfig()) {
			return !empty($config['webhook']['secret']);
		}

		return false;
	}

	public static function webhookIsSetupManual(): bool {
		if ($config = self::getConfig()) {
			return !empty($config['webhook']['secret']) && $config['webhook']['id'] === 'manual';
		}

		return false;
	}



	/**
	 * Checks if a given invoice id has status of fully paid (settled) or paid late.
	 */
	public static function invoiceIsFullyPaid(string $invoiceId): bool {
		if ($config = self::getConfig()) {
			$client = new Invoice($config['url'], $config['api_key']);
			try {
				$invoice = $client->getInvoice($config['store_id'], $invoiceId);
				return $invoice->isSettled();
			} catch (\Throwable $e) {
				Logger::debug('Exception while checking if invoice settled '. $invoiceId . ': ' . $e->getMessage());
			}
		}

		return false;
	}

	public function apiKeyHasRefundPermission(): bool {
		if ($this->configured) {
			$client = new ApiKey($this->url, $this->apiKey);
			try {
				$apiKey = $client->getCurrent();
				$apiAuth = new GreenfieldApiAuthorization( $apiKey->getData() );
				return $apiAuth->hasRefundsPermission();
			} catch (\Throwable $e) {
				Logger::debug('Exception while checking current API key: ' . $e->getMessage());
			}
		}

		return false;
	}

	public function serverSupportsRefunds(): bool {
		if ($this->configured) {
			$client = new Server($this->url, $this->apiKey);
			try {
				$serverInfo = $client->getInfo();
				return !version_compare($serverInfo->getVersion(), '1.7.6', '<');
			} catch (\Throwable $e) {
				Logger::debug('Exception while checking current API key: ' . $e->getMessage());
			}
		}

		return false;
	}

}
