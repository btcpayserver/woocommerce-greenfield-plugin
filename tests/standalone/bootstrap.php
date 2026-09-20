<?php

declare(strict_types=1);

namespace {
	if (PHP_SAPI !== 'cli') {
		exit;
	}

	require dirname(__DIR__, 2) . '/vendor/autoload.php';

	// Exercise the real plugin and API client classes with in-memory WordPress and HTTP boundaries.
	class WebhookTestState {
		public static array $options = [];
		public static array $notices = [];
		public static array $errors = [];
		public static array $logs = [];
		public static int $saves = 0;
		public static bool $failOptionWrite = false;
	}

	class WC_Settings_Page {
		public function save() { WebhookTestState::$saves++; }
	}

	class WC_Admin_Settings {
		public static function add_error($message) { WebhookTestState::$errors[] = $message; }
	}

	function get_option($key, $default = false) { return WebhookTestState::$options[$key] ?? $default; }
	function update_option($key, $value) {
		if (WebhookTestState::$failOptionWrite || get_option($key) === $value) {
			return false;
		}
		WebhookTestState::$options[$key] = $value;
		return true;
	}
	function delete_option($key) { unset(WebhookTestState::$options[$key]); return true; }
	function delete_transient($key) { return true; }
	function wp_unslash($value) {
		if (is_array($value)) {
			return array_map('wp_unslash', $value);
		}
		return is_string($value) ? stripslashes($value) : $value;
	}
	function sanitize_text_field($value) { return is_string($value) ? trim(preg_replace('/%[a-f0-9]{2}/i', '', strip_tags($value))) : ''; }
	function esc_url_raw($value) { return $value; }
	function __($value, ...$args) { return $value; }
	function _x($value, ...$args) { return $value; }
	function esc_html_x($value, ...$args) { return $value; }
	function esc_attr_x($value, ...$args) { return $value; }
	function site_url() { return 'https://shop.example'; }
	function WC() {
		return new class {
			public function api_request_url($name) { return 'https://shop.example/wc-api/' . $name . '/'; }
		};
	}
	define('BTCPAYSERVER_VERSION', 'test');

	set_error_handler(static function ($level, $message, $file, $line) {
		if (error_reporting() & $level) {
			throw new \ErrorException($message, 0, $level, $file, $line);
		}
		return false;
	});
}

namespace BTCPayServer\WC\Helper {
	class Logger {
		public static function debug($message, ...$args) { \WebhookTestState::$logs[] = $message; }
		public static function getLogFileUrl() { return ''; }
	}
}

namespace BTCPayServer\WC\Admin {
	class Notice {
		public static function addNotice($type, $message, ...$args) { \WebhookTestState::$notices[] = [$type, $message]; }
	}
}

namespace BTCPayServer\WC\Gateway {
	class SeparateGateways {
		public static function cleanUpGeneratedFilesAndCache() {}
	}
}

namespace BTCPayServer\Http {
	use BTCPayServer\WC\Helper\GreenfieldApiAuthorization;

	// Replaces only the transport; real clients still parse responses and raise API exceptions.
	class CurlClient implements ClientInterface {
		public static array $requests = [];
		public static array $unexpectedRequests = [];
		public static array $remoteWebhook = [];
		public static array $createdWebhook = [];
		public static int $lookupStatus = 200;
		public static int $createStatus = 200;
		public static int $deleteStatus = 200;
		public static bool $lookupTimeout = false;
		public static bool $webhookPermission = true;

		public function request(string $method, string $url, array $headers = [], string $body = ''): ResponseInterface {
			self::$requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
			$status = 200;
			$data = [];
			if ($method === 'GET' && str_ends_with($url, '/api-keys/current')) {
				$permissions = GreenfieldApiAuthorization::REQUIRED_PERMISSIONS;
				if (self::$webhookPermission) {
					$permissions = array_merge($permissions, GreenfieldApiAuthorization::OPTIONAL_PERMISSIONS);
				}
				$data = ['permissions' => array_map(static fn($p) => $p . ':' . $_POST['btcpay_gf_store_id'], $permissions)];
			} elseif ($method === 'GET' && str_ends_with($url, '/server/info')) {
				$data = ['fullySynched' => true, 'version' => '2.3.5'];
			} elseif ($method === 'GET' && str_contains($url, '/webhooks/')) {
				if (self::$lookupTimeout) {
					throw new \RuntimeException('Simulated connection timeout.');
				}
				$status = self::$webhookPermission ? self::$lookupStatus : 403;
				$data = self::$remoteWebhook;
			} elseif ($method === 'POST' && str_ends_with($url, '/webhooks')) {
				$status = self::$webhookPermission ? self::$createStatus : 403;
				$data = self::$createdWebhook;
			} elseif ($method === 'DELETE' && str_contains($url, '/webhooks/')) {
				$status = self::$deleteStatus;
			} elseif ($method !== 'GET' || !str_ends_with($url, '/payment-methods')) {
				self::$unexpectedRequests[] = [$method, $url];
				throw new \LogicException('Unexpected HTTP request.');
			}
			return new Response($status, json_encode($status === 200 ? $data : ['message' => 'Simulated API failure'], JSON_THROW_ON_ERROR), []);
		}
	}
}
