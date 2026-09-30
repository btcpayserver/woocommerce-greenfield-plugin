<?php
/** Minimal WordPress/WooCommerce doubles for subscriptions.php; never load in WordPress. */
if (defined('ABSPATH')) { throw new RuntimeException('Run these isolated tests outside WordPress.'); }

$GLOBALS['options'] = ['btcpay_gf_url' => 'https://btcpay.test', 'btcpay_gf_api_key' => 'test-key', 'btcpay_gf_store_id' => 'store'];
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function __($text, $domain = '') { return $text; }
function add_action(...$args) {}
function add_filter(...$args) {}
function apply_filters($name, $value, ...$args) { return $value; }
function get_bloginfo($key) { return 'Test store'; }
function wp_specialchars_decode($text, $flags) { return html_entity_decode($text, $flags); }
function add_query_arg($key, $value, $url) { return $url . '?' . http_build_query([$key => $value]); }
function home_url($path) { return 'https://shop.test' . $path; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? null; }
function wcs_get_subscription($id) { $order = wc_get_order($id); return $order instanceof WC_Subscription ? $order : null; }
function wcs_get_subscriptions_for_renewal_order($order) { return []; }
function wcs_get_subscriptions_for_order($id, $args) { return array_filter($GLOBALS['orders'], fn($o) => $o instanceof WC_Subscription && $o->parent === $id); }
function wc_get_orders($args) { return array_values(array_filter($GLOBALS['orders'], fn($o) => $o->get_meta($args['meta_key']) === $args['meta_value'])); }
function wcs_create_renewal_order($subscription) {
	if ($GLOBALS['renewal_failure']) { return new stdClass(); }
	$order = new WC_Order(100 + ++$GLOBALS['renewal_count']);
	$order->meta = $subscription->meta;
	$GLOBALS['orders'][$order->get_id()] = $order;
	return $order;
}
class WC_Payment_Gateway { public string $id; public array $supports = ['products', 'refunds']; }
class WC_Subscriptions {}
class WC_Subscriptions_Product {
	public static function get_trial_length($product) { return $product->trialLength; }
	public static function get_trial_period($product) { return $product->trialPeriod; }
	public static function is_subscription($product) { return $product->is_type('subscription'); }
}
class SubscriptionTestProduct {
	public string $type = 'subscription';
	public int $trialLength = 0;
	public string $trialPeriod = 'day';
	public function is_type($type) { return $this->type === $type; }
	public function get_id() { return 5; }
}
class SubscriptionTestItem {
	public int $quantity = 1;
	public SubscriptionTestProduct $product;
	public function __construct() { $this->product = new SubscriptionTestProduct(); }
	public function get_product() { return $this->product; }
	public function get_quantity() { return $this->quantity; }
	public function get_name() { return 'Monthly plan'; }
}
class WC_Order {
	public array $meta = [];
	public array $items = [];
	public string $total = '10.00';
	public string $currency = 'USD';
	public string $method = 'btcpaygf_default';
	public bool $paid = false;
	public string $status = 'pending';
	public function __construct(public int $id) {}
	public function get_id() { return $this->id; }
	public function get_meta($key) { return $this->meta[$key] ?? ''; }
	public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
	public function delete_meta_data($key) { unset($this->meta[$key]); }
	public function read_meta_data($force = false) {}
	public function save() {}
	public function add_order_note($note) {}
	public function get_payment_method() { return $this->method; }
	public function set_payment_method($method) { $this->method = $method; }
	public function get_customer_id() { return 1; }
	public function get_order_number() { return (string) $this->id; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_items() { return $this->items; }
	public function is_paid() { return $this->paid; }
	public function payment_complete($tx = '') { $this->paid = true; }
	public function has_status($status) { return in_array($this->status, (array) $status, true); }
	public function update_status($status, $message = '') { $this->status = $status; }
	public function get_formatted_billing_full_name() { return 'Test Buyer'; }
	public function get_billing_email() { return 'buyer@example.test'; }
	public function get_checkout_order_received_url() { return 'https://shop.test/order?key=wc_order_secret'; }
}
class WC_Subscription extends WC_Order {
	public int $parent = 0;
	public int $interval = 1;
	public array $dates = [];
	public function get_parent_id() { return $this->parent; }
	public function get_billing_period() { return 'month'; }
	public function get_billing_interval() { return $this->interval; }
	public function get_time($type) { return $this->dates[$type] ?? 0; }
	public function update_dates($dates) { $this->dates = array_merge($this->dates, $dates); }
}
$GLOBALS['wpdb'] = new class {
	public string $prefix = 'test_';
	public bool $locked = false;
	public function prepare($query, $name) { return $query; }
	public function get_var($query) {
		if (str_contains($query, 'RELEASE_LOCK')) { $this->locked = false; return '1'; }
		if ($this->locked) { return '0'; }
		$this->locked = true;
		return '1';
	}
};

function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES); }
function wp_date($format, $timestamp) { return date($format, $timestamp); }
function wc_date_format() { return 'Y-m-d'; }
function WC() { return $GLOBALS['test_woocommerce']; }
$GLOBALS['test_woocommerce'] = new class {
	public bool $sendResult = true;
	public int $sent = 0;
	public function mailer() { return $this; }
	public function wrap_message($heading, $body) { return $heading . $body; }
	public function send(...$args) { ++$this->sent; return $this->sendResult; }
};
