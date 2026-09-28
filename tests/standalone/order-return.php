<?php

declare(strict_types=1);

/**
 * Run: php tests/standalone/order-return.php
 *
 * Exercises the real return handler and WooCommerce session/cart cleanup classes.
 * Requires a sibling WooCommerce checkout, or WC_PLUGIN_DIR pointing to one.
 * Orders, WordPress responses and time are isolated; no database or network is used.
 */

namespace BTCPayServer\WC\Helper {
	function time(): int { return \OrderReturnTestState::$now; }
}

namespace {
	use BTCPayServer\WC\Helper\OrderReturn;

	if (PHP_SAPI !== 'cli') {
		exit;
	}

	$pluginDir = dirname(__DIR__, 2);
	$woocommerceDir = getenv('WC_PLUGIN_DIR') ?: dirname($pluginDir) . '/woocommerce';
	if (!is_file($woocommerceDir . '/includes/abstracts/abstract-wc-session.php')) {
		fwrite(STDERR, "WooCommerce not found. Set WC_PLUGIN_DIR to its plugin directory.\n");
		exit(1);
	}

	define('ABSPATH', $pluginDir . '/');
	require $woocommerceDir . '/includes/abstracts/abstract-wc-session.php';
	require $woocommerceDir . '/includes/class-wc-cart-session.php';
	require $pluginDir . '/src/Helper/OrderReturn.php';

	class OrderReturnTestState {
		public static int $now = 1800000000;
		public static int $userId = 0;
		public static int $noCacheCalls = 0;
		public static array $orders = [];
		public static object $wc;
	}

	class OrderReturnResponse extends RuntimeException {
		public array $response;
		public function __construct(array $response) { parent::__construct(); $this->response = $response; }
	}

	class OrderReturnSession extends WC_Session {}
	class WC_Cart {}
	class WC_Order {
		public int $id;
		public int $customerId;
		public string $paymentMethod = 'btcpaygf_default';
		public array $meta = [];
		public int $saves = 0;
		public function __construct(int $id, int $customerId = 0) { $this->id = $id; $this->customerId = $customerId; }
		public function get_id() { return $this->id; }
		public function get_customer_id() { return $this->customerId; }
		public function get_payment_method() { return $this->paymentMethod; }
		public function get_meta($key) { return $this->meta[$key] ?? ''; }
		public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
		public function delete_meta_data($key) { unset($this->meta[$key]); }
		public function save() { $this->saves++; OrderReturnTestState::$orders[$this->id] = $this; }
		public function get_checkout_order_received_url() { return 'https://shop.example/order-received/' . $this->id . '/?key=private-order-key'; }
	}

	function WC() { return OrderReturnTestState::$wc; }
	function get_current_user_id() { return OrderReturnTestState::$userId; }
	function is_user_logged_in() { return get_current_user_id() > 0; }
	function nocache_headers() { OrderReturnTestState::$noCacheCalls++; }
	function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
	function maybe_serialize($value) { return is_array($value) ? serialize($value) : $value; }
	function maybe_unserialize($value) { return is_string($value) && str_starts_with($value, 'a:') ? unserialize($value, ['allowed_classes' => false]) : $value; }
	function sanitize_text_field($value) { return trim(strip_tags($value)); }
	function wp_unslash($value) { return stripslashes($value); }
	function absint($value) { return abs((int) $value); }
	function esc_html__($value, ...$args) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
	function home_url($path) { return 'https://shop.example' . $path; }
	function add_query_arg($key, $value, $url) { return $url . '?' . http_build_query([$key => $value]); }
	function wc_get_page_permalink($page) { return 'https://shop.example/' . $page . '/'; }
	function wc_get_account_endpoint_url($endpoint) { return wc_get_page_permalink('myaccount') . $endpoint . '/'; }
	function wc_get_orders($args) {
		return array_slice(array_values(array_filter(
			OrderReturnTestState::$orders,
			static fn($order) => $order->get_meta($args['meta_key']) === $args['meta_value']
		)), 0, $args['limit']);
	}
	function wp_safe_redirect($url) { throw new OrderReturnResponse(['redirect' => $url]); }
	function wp_die($message, $title, $args) { throw new OrderReturnResponse(['message' => $message, 'title' => $title, 'args' => $args]); }

	set_error_handler(static function ($level, $message, $file, $line) {
		if (error_reporting() & $level) {
			throw new ErrorException($message, 0, $level, $file, $line);
		}
		return false;
	});

	function check($expected, $actual, string $label): void {
		if ($expected !== $actual) {
			throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
		}
	}

	function fixture(int $customerId = 0, string $marker = 'order_awaiting_payment'): WC_Order {
		OrderReturnTestState::$now = 1800000000;
		OrderReturnTestState::$userId = $customerId;
		OrderReturnTestState::$noCacheCalls = 0;
		OrderReturnTestState::$orders = [];
		OrderReturnTestState::$wc = (object) ['session' => new OrderReturnSession()];
		$_GET = [];
		$order = new WC_Order(42, $customerId);
		WC()->session->set($marker, $order->get_id());
		return $order;
	}

	function createLink(WC_Order $order): string {
		$url = OrderReturn::createUrl($order);
		parse_str(parse_url($url, PHP_URL_QUERY), $query);
		return $query['btcpaygf-return'];
	}

	function visit($reference): array {
		$_GET = ['btcpaygf-return' => $reference];
		try {
			OrderReturn::handle();
		} catch (OrderReturnResponse $response) {
			return $response->response;
		}
		throw new RuntimeException('Expected a redirect or information page.');
	}

	function clearCart(): void {
		(new WC_Cart_Session(new WC_Cart()))->destroy_cart_session();
		check(null, WC()->session->get('order_awaiting_payment'), 'Classic checkout marker cleared');
		check(null, WC()->session->get('store_api_draft_order'), 'Blocks checkout marker cleared');
	}

	function checkOrderRedirect(WC_Order $order, $reference): void {
		check(['redirect' => $order->get_checkout_order_received_url()], visit($reference), 'Authorized order redirect');
	}

	$tests = [];
	$tests['new references expire exactly 24 hours after creation'] = static function () {
		$order = fixture(7);
		$reference = createLink($order);
		check(1, preg_match('/\A[a-f0-9]{64}\z/', $reference), 'Random reference format');
		check(hash('sha256', $reference), $order->get_meta('_btcpay_return_reference_hash'), 'Only the hash is stored');
		check(OrderReturnTestState::$now + 86400, $order->get_meta('_btcpay_return_reference_expires'), '24-hour lifetime');
	};
	$tests['owner can revisit without consuming or extending the reference'] = static function () {
		$order = fixture(7);
		$reference = createLink($order);
		$meta = $order->meta;
		foreach ([0, 18001, 86399] as $elapsed) {
			OrderReturnTestState::$now = 1800000000 + $elapsed;
			checkOrderRedirect($order, $reference);
			check($meta, $order->meta, 'Unchanged hash and expiry');
		}
		check(1, $order->saves, 'Visits do not save or consume the order reference');
		check(3, OrderReturnTestState::$noCacheCalls, 'Every visit disables caching');
	};
	foreach (['order_awaiting_payment', 'store_api_draft_order'] as $marker) {
		$tests[$marker . ': guest access survives cart cleanup and a second checkout'] = static function () use ($marker) {
			$order = fixture(0, $marker);
			$reference = createLink($order);
			clearCart();
			checkOrderRedirect($order, $reference);
			$second = new WC_Order(43);
			WC()->session->set($marker, 43);
			$secondReference = createLink($second);
			clearCart();
			OrderReturnTestState::$now += 86399;
			checkOrderRedirect($order, $reference);
			checkOrderRedirect($second, $secondReference);
		};
		$tests[$marker . ': legacy references remember the guest on first return'] = static function () use ($marker) {
			$order = fixture(0, $marker);
			$reference = createLink($order);
			WC()->session->set('btcpaygf_return_orders', null);
			$order->meta['_btcpay_return_reference_expires'] = OrderReturnTestState::$now + 18000;
			checkOrderRedirect($order, $reference);
			clearCart();
			checkOrderRedirect($order, $reference);
			check(1800018000, $order->get_meta('_btcpay_return_reference_expires'), 'Legacy expiry is not extended');
		};
	}
	foreach ([0, 7] as $customerId) {
		$tests['expiry and repeated expired visits, customer=' . $customerId] = static function () use ($customerId) {
			$order = fixture($customerId);
			$reference = createLink($order);
			clearCart();
			checkOrderRedirect($order, $reference);
			OrderReturnTestState::$now += 86400;
			$response = visit($reference);
			if ($customerId > 0) {
				check(['redirect' => wc_get_account_endpoint_url('orders')], $response, 'Expired link goes to own orders');
			} else {
				check(200, $response['args']['response'], 'Informational page, not a 404');
				check(wc_get_page_permalink('myaccount'), $response['args']['link_url'], 'Login link');
				check(true, str_contains($response['message'], 'confirmation email'), 'Guest guidance');
				check(false, str_contains($response['message'], 'private-order-key'), 'No order credentials');
				check(false, OrderReturn::currentCustomerOwnsOrder($order), 'Guest authorization expires too');
			}
			check('', $order->get_meta('_btcpay_return_reference_hash'), 'Expired hash removed on access');
			check('', $order->get_meta('_btcpay_return_reference_expires'), 'Expired timestamp removed on access');
			check($response, visit($reference), 'Same fallback after cleanup');
		};
	}
	$tests['a different browser cannot use or consume a guest reference'] = static function () {
		$order = fixture();
		$reference = createLink($order);
		$ownerSession = WC()->session;
		WC()->session = new OrderReturnSession();
		$meta = $order->meta;
		$response = visit($reference);
		check(200, $response['args']['response'], 'Unrelated guest sees information page');
		check($meta, $order->meta, 'Unauthorized visit preserves the reference');
		check(null, WC()->session->get('btcpaygf_return_orders'), 'Unauthorized visit grants no access');
		WC()->session = $ownerSession;
		clearCart();
		checkOrderRedirect($order, $reference);
	};
	$tests['other accounts and logged-out owners cannot resolve account orders'] = static function () {
		$order = fixture(7);
		$reference = createLink($order);
		$meta = $order->meta;
		OrderReturnTestState::$userId = 8;
		check(['redirect' => wc_get_account_endpoint_url('orders')], visit($reference), 'Other user sees only own orders');
		OrderReturnTestState::$userId = 0;
		check(200, visit($reference)['args']['response'], 'Logged-out owner sees login guidance');
		check($meta, $order->meta, 'Neither visit consumes the reference');
		OrderReturnTestState::$userId = 7;
		checkOrderRedirect($order, $reference);
	};
	$tests['creating a reference without guest ownership never grants session access'] = static function () {
		$order = fixture();
		clearCart();
		$reference = createLink($order);
		check(false, OrderReturn::currentCustomerOwnsOrder($order), 'No new authorization without ownership');
		check(200, visit($reference)['args']['response'], 'No order redirect');
		WC()->session = null;
		check(200, visit($reference)['args']['response'], 'Missing session fails safely');
	};
	$tests['guest authorization cannot bypass a registered owner'] = static function () {
		$order = fixture();
		$reference = createLink($order);
		$order->customerId = 7;
		clearCart();
		check(200, visit($reference)['args']['response'], 'Registered owner must log in');
		OrderReturnTestState::$userId = 7;
		checkOrderRedirect($order, $reference);
	};
	$tests['invalid, missing, unauthorized and expired references share a generic fallback'] = static function () {
		$order = fixture();
		$reference = createLink($order);
		WC()->session = new OrderReturnSession();
		$expected = visit($reference);
		foreach (['', 'invalid', str_repeat('a', 64), ['malformed']] as $invalid) {
			check($expected, visit($invalid), 'Same response for invalid references');
		}
		OrderReturnTestState::$now += 86400;
		check($expected, visit($reference), 'Same response for expired references');
	};
	$tests['replacing a reference invalidates the previous link'] = static function () {
		$order = fixture(7);
		$oldReference = createLink($order);
		$newReference = createLink($order);
		check(false, $oldReference === $newReference, 'Replacement is random');
		check(['redirect' => wc_get_account_endpoint_url('orders')], visit($oldReference), 'Old link cannot resolve order');
		checkOrderRedirect($order, $newReference);
	};
	$tests['only BTCPay orders resolve, including separate gateways'] = static function () {
		$order = fixture(7);
		$reference = createLink($order);
		$order->paymentMethod = 'btcpaygf_btc';
		checkOrderRedirect($order, $reference);
		$order->paymentMethod = 'bacs';
		check(['redirect' => wc_get_account_endpoint_url('orders')], visit($reference), 'Different payment method cannot resolve order');
	};
	$tests['expired guest authorizations are pruned when another order is remembered'] = static function () {
		$order = fixture();
		createLink($order);
		OrderReturnTestState::$now += 86400;
		$second = new WC_Order(43);
		WC()->session->set('order_awaiting_payment', 43);
		createLink($second);
		check([43 => OrderReturnTestState::$now + 86400], WC()->session->get('btcpaygf_return_orders'), 'Only unexpired guest authorization remains');
	};
	$tests['ordinary page requests are untouched'] = static function () {
		fixture();
		OrderReturn::handle();
		check(0, OrderReturnTestState::$noCacheCalls, 'No response changes without query argument');
	};

	$failures = 0;
	foreach ($tests as $name => $test) {
		try {
			$test();
			echo 'PASS ' . $name . PHP_EOL;
		} catch (Throwable $error) {
			$failures++;
			fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
		}
	}
	echo count($tests) . ' checks, ' . $failures . ' failures.' . PHP_EOL;
	exit($failures ? 1 : 0);
}
