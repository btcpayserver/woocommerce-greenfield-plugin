<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Helper;

use BTCPayServer\Client\Store;
use BTCPayServer\Client\StorePaymentMethod;
use BTCPayServer\Result\AbstractStorePaymentMethodResult;

class GreenfieldApiHelper {
	const PM_CACHE_KEY = 'btcpay_payment_methods';
	public $configured = false;
	public $url;
	public $apiKey;
	public $storeId;

	// todo: perf static instance
	public function __construct() {
		if ($config = self::getConfig()) {
			$this->url = $config['url'];
			$this->apiKey = $config['api_key'];
			$this->storeId = $config['store_id'];
			$this->configured = true;
		}
	}

	// todo: maybe remove static class and make GFConfig object or similar
	public static function getConfig(): array {
		$url = get_option('btcpay_gf_url');
		$key = get_option('btcpay_gf_api_key');
		if ($url && $key) {
			return [
				'url' => $url,
				'api_key' => $key,
				'store_id' => get_option('btcpay_gf_store_id', NULL)
			];
		}
		else {
			return [];
		}
	}

	public static function checkApiConnection(): bool {
		if ($config = self::getConfig()) {
			// todo: replace with server info endpoint.
			$client = new Store($config['url'], $config['api_key']);
			if (!empty($stores = $client->getStores())) {
				return true;
			}
		}
		return false;
	}

	public static function supportedPaymentMethods(): array {
		$paymentMethods = [];

		// Use transients API to cache pm for a few minutes to avoid too many requests to BTCPay Server.
		if ($cachedPaymentMethods = get_transient(self::PM_CACHE_KEY)) {
			return $cachedPaymentMethods;
		}

		if ($config = self::getConfig()) {
			$client = new StorePaymentMethod($config['url'], $config['api_key']);
			if ($storeId = get_option('btcpay_gf_store_id')) {
				$pmResult = $client->getPaymentMethods($storeId);
				/** @var AbstractStorePaymentMethodResult $pm */
				foreach ($pmResult as $pm) {
					if ($pm->isEnabled() && $pmName = $pm->getData()['paymentMethod'] )  {
						// Convert - to _ for later use in gateway class generator.
						$symbol = str_replace('-', '_', $pmName);
						$paymentMethods[] = [
							'symbol' => $symbol,
							'className' => "BTCPay_GF_{$symbol}"
						];
					}
				}
			}
		}

		// Store payment methods into cache.
		set_transient( self::PM_CACHE_KEY, $paymentMethods,5 * MINUTE_IN_SECONDS );

		return $paymentMethods;
	}

	public function getInvoiceRedirectUrl($invoiceId) {
		if ($this->configured) {
			return $this->url . '/i/' . urlencode($invoiceId);
		}
	}

	public function validWebhookRequest(string $signature, string $requestData): bool {
		if ($this->configured) {
			if ($signature === "sha256=" . hash_hmac('sha256', $requestData, $this->apiKey)) {
				return true;
			}
		}
		return false;
	}
}
