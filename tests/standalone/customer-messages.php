<?php

declare(strict_types=1);

/**
 * Run: php tests/standalone/customer-messages.php
 *
 * Uses the real WooCommerce settings/gateway classes and both Blocks integrations.
 * Requires Composer dependencies and a sibling WooCommerce plugin checkout, or
 * WC_PLUGIN_DIR pointing to one. WordPress storage, hooks and sanitization are
 * stubbed: no database, WordPress bootstrap or network access is used.
 */

use BTCPayServer\WC\Blocks\DefaultGatewayBlocks;
use BTCPayServer\WC\Blocks\SeparateGatewayBlocks;
use BTCPayServer\WC\Gateway\AbstractGateway;
use BTCPayServer\WC\Gateway\DefaultGateway;

if (PHP_SAPI !== 'cli') {
	exit;
}

$pluginDir = dirname(__DIR__, 2);
$woocommerceDir = getenv('WC_PLUGIN_DIR') ?: dirname($pluginDir) . '/woocommerce';
if (!is_file($woocommerceDir . '/includes/abstracts/abstract-wc-settings-api.php')) {
	fwrite(STDERR, "WooCommerce not found. Set WC_PLUGIN_DIR to its plugin directory.\n");
	exit(1);
}

define('ABSPATH', $pluginDir . '/');
define('BTCPAYSERVER_VERSION', 'test');
define('BTCPAYSERVER_PLUGIN_URL', 'https://shop.example/plugins/btcpay/');

class CustomerMessageTestState {
	public static array $options = [];
	public static $wc;
}

function get_option($key, $default = false) { return CustomerMessageTestState::$options[$key] ?? $default; }
function update_option($key, $value, ...$args) {
	$changed = get_option($key) !== $value;
	CustomerMessageTestState::$options[$key] = $value;
	return $changed;
}
function apply_filters($name, $value, ...$args) { return $value; }
function add_action(...$args) {}
function do_action(...$args) {}
function __($text, ...$args) { return $text; }
function _x($text, ...$args) { return $text; }
function wp_kses_post($text) { return $text; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($text) { return esc_attr($text); }
function checked($value, $expected, $echo = true) {
	$result = (string) $value === (string) $expected ? 'checked="checked"' : '';
	if ($echo) { echo $result; }
	return $result;
}
function disabled($value, $expected, $echo = true) {
	$result = (string) $value === (string) $expected ? 'disabled="disabled"' : '';
	if ($echo) { echo $result; }
	return $result;
}
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
function wp_list_pluck($items, $field) { return array_map(static fn($item) => $item[$field] ?? null, $items); }
function WC() { return CustomerMessageTestState::$wc; }

require $woocommerceDir . '/includes/abstracts/abstract-wc-settings-api.php';
require $woocommerceDir . '/includes/abstracts/abstract-wc-payment-gateway.php';
require $woocommerceDir . '/src/Blocks/Integrations/IntegrationInterface.php';
require $woocommerceDir . '/src/Blocks/Payments/PaymentMethodTypeInterface.php';
require $woocommerceDir . '/src/Blocks/Payments/Integrations/AbstractPaymentMethodType.php';
require $pluginDir . '/vendor/autoload.php';

// Generated separate gateways inherit message handling from AbstractGateway.
class CustomerMessageSeparateGateway extends AbstractGateway {
	public function __construct() {
		$this->id = 'btcpaygf_btc';
		parent::__construct();
	}

	public function getPaymentMethods(): array { return ['BTC']; }
}

set_error_handler(static function ($level, $message, $file, $line) {
	if (error_reporting() & $level) {
		throw new ErrorException($message, 0, $level, $file, $line);
	}
	return false;
});

function checkSame($actual, $expected, string $label): void {
	if ($actual !== $expected) {
		throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function gatewayFor(string $kind): AbstractGateway {
	return $kind === 'default' ? new DefaultGateway() : new CustomerMessageSeparateGateway();
}

function fixture(string $kind, ?array $settings, ?string $global): AbstractGateway {
	$id = $kind === 'default' ? 'btcpaygf_default' : 'btcpaygf_btc';
	CustomerMessageTestState::$options = [];
	if ($settings !== null) {
		CustomerMessageTestState::$options['woocommerce_' . $id . '_settings'] = $settings;
	}
	if ($global !== null) {
		CustomerMessageTestState::$options['btcpay_gf_default_description'] = $global;
	}
	$_POST = [];
	$before = CustomerMessageTestState::$options;
	$gateway = gatewayFor($kind);
	checkSame(CustomerMessageTestState::$options, $before, 'Loading settings must not write options');
	return $gateway;
}

function checkCheckoutMessages(AbstractGateway $gateway, string $expected): void {
	CustomerMessageTestState::$wc = (object) [
		'payment_gateways' => new class($gateway) {
			private AbstractGateway $gateway;
			public function __construct(AbstractGateway $gateway) { $this->gateway = $gateway; }
			public function payment_gateways() { return [$this->gateway->getId() => $this->gateway]; }
		},
	];
	$blocks = $gateway instanceof DefaultGateway
		? new DefaultGatewayBlocks()
		: new SeparateGatewayBlocks($gateway->getId());
	$blocks->initialize();
	checkSame($gateway->getDescription(), $expected, 'Resolved message');
	checkSame($gateway->get_description(), $expected, 'Classic checkout message');
	checkSame($blocks->get_payment_method_data()['description'], $expected, 'Blocks checkout message');
}

function saveGateway(AbstractGateway $gateway, array $changes = []): void {
	// Submit the values actually shown in the form, not the resolved checkout text.
	$_POST = [$gateway->get_field_key(AbstractGateway::ICON_MEDIA_OPTION) => ''];
	foreach ($gateway->get_form_fields() as $key => $field) {
		$value = $changes[$key] ?? $gateway->get_option($key);
		if ($field['type'] === 'checkbox') {
			if ($value === 'yes') {
				$_POST[$gateway->get_field_key($key)] = '1';
			}
		} elseif ($field['type'] !== 'icon_upload') {
			$_POST[$gateway->get_field_key($key)] = addslashes((string) $value);
		}
	}
	$gateway->process_admin_options();
	checkSame($gateway->get_errors(), [], 'Settings save errors');
}

$stock = AbstractGateway::DEFAULT_DESCRIPTION;
$cases = [
	'built-in fallback' => [null, null, $stock, 'yes'],
	'new gateway inherits global default' => [null, 'Global message', 'Global message', 'yes'],
	'empty settings inherit' => [[], 'Global message', 'Global message', 'yes'],
	'missing legacy description inherits' => [['enabled' => 'yes'], 'Global message', 'Global message', 'yes'],
	'empty legacy description inherits' => [['description' => ''], 'Global message', 'Global message', 'yes'],
	'legacy stock description inherits' => [['description' => $stock], 'Global message', 'Global message', 'yes'],
	'legacy custom description survives' => [['description' => 'Custom message'], 'Global message', 'Custom message', 'no'],
	'legacy zero is a custom description' => [['description' => '0'], 'Global message', '0', 'no'],
	'explicit inheritance ignores dormant custom text' => [['use_default_description' => 'yes', 'description' => 'Custom message'], 'Global message', 'Global message', 'yes'],
	'explicit custom description wins' => [['use_default_description' => 'no', 'description' => 'Custom message'], 'Global message', 'Custom message', 'no'],
	'explicit stock description stays an override' => [['use_default_description' => 'no', 'description' => $stock], 'Global message', $stock, 'no'],
	'explicit blank custom description hides message' => [['use_default_description' => 'no', 'description' => ''], 'Global message', '', 'no'],
	'blank global description hides message' => [null, '', '', 'yes'],
];

$tests = [];
foreach (['default', 'separate'] as $kind) {
	foreach ($cases as $label => [$settings, $global, $expected, $mode]) {
		$tests[$kind . ': ' . $label] = static function () use ($kind, $settings, $global, $expected, $mode) {
			$gateway = fixture($kind, $settings, $global);
			checkCheckoutMessages($gateway, $expected);
			checkSame($gateway->get_option('use_default_description'), $mode, 'Inheritance checkbox');
			checkSame($gateway->form_fields['description']['default'], '', 'Do not prefill inherited text as an override');
		};
	}

	$tests[$kind . ': normal save continues following global changes'] = static function () use ($kind) {
		$gateway = fixture($kind, null, 'First global message');
		saveGateway($gateway);
		$stored = get_option($gateway->get_option_key());
		checkSame($stored['description'], '', 'No saved global snapshot');
		checkSame($stored['use_default_description'], 'yes', 'Saved inheritance');
		update_option('btcpay_gf_default_description', 'Updated global message');
		checkCheckoutMessages(gatewayFor($kind), 'Updated global message');
	};

	$tests[$kind . ': gateway form renders the correct inheritance mode'] = static function () use ($kind, $stock) {
		foreach ([$stock => true, 'Legacy custom message' => false] as $description => $inherits) {
			$gateway = fixture($kind, ['description' => $description], 'Global message');
			$checkbox = $gateway->generate_checkbox_html('use_default_description', $gateway->form_fields['use_default_description']);
			$textarea = $gateway->generate_textarea_html('description', $gateway->form_fields['description']);
			checkSame(strpos($checkbox, 'checked="checked"') !== false, $inherits, 'Rendered inheritance checkbox');
			checkSame(strpos($textarea, '>Legacy custom message</textarea>') !== false, !$inherits, 'Rendered custom message');
			checkSame(strpos($textarea, 'Global message'), false, 'Do not render inherited text as the override');
		}
	};

	$tests[$kind . ': override, blank message, and return to inheritance'] = static function () use ($kind) {
		$gateway = fixture($kind, null, 'Global message');
		saveGateway($gateway, ['use_default_description' => 'no', 'description' => "Customer's custom message"]);
		update_option('btcpay_gf_default_description', 'Updated global message');
		$gateway = gatewayFor($kind);
		checkCheckoutMessages($gateway, "Customer's custom message");
		saveGateway($gateway, ['description' => '']);
		$gateway = gatewayFor($kind);
		checkCheckoutMessages($gateway, '');
		saveGateway($gateway, ['use_default_description' => 'yes']);
		checkCheckoutMessages(gatewayFor($kind), 'Updated global message');
	};

	$tests[$kind . ': saving legacy defaults keeps inheritance'] = static function () use ($kind, $stock) {
		$gateway = fixture($kind, ['description' => $stock], 'Global message');
		checkSame($gateway->get_option('description'), '', 'Legacy stock text is not an override');
		saveGateway($gateway);
		checkSame(get_option($gateway->get_option_key())['use_default_description'], 'yes', 'Persisted legacy inheritance');
		update_option('btcpay_gf_default_description', 'Updated global message');
		checkCheckoutMessages(gatewayFor($kind), 'Updated global message');
	};

	$tests[$kind . ': saving legacy custom text preserves the override'] = static function () use ($kind) {
		$gateway = fixture($kind, ['description' => 'Legacy custom message'], 'Global message');
		saveGateway($gateway);
		checkSame(get_option($gateway->get_option_key())['use_default_description'], 'no', 'Persisted legacy override');
		update_option('btcpay_gf_default_description', 'Updated global message');
		checkCheckoutMessages(gatewayFor($kind), 'Legacy custom message');
	};

	$tests[$kind . ': explicitly saving stock text remains an override'] = static function () use ($kind, $stock) {
		$gateway = fixture($kind, null, 'Global message');
		saveGateway($gateway, ['use_default_description' => 'no', 'description' => $stock]);
		checkCheckoutMessages(gatewayFor($kind), $stock);
	};

	$tests[$kind . ': inheritance does not erase a dormant override'] = static function () use ($kind) {
		$gateway = fixture($kind, ['use_default_description' => 'no', 'description' => 'Custom message'], 'Global message');
		saveGateway($gateway, ['use_default_description' => 'yes']);
		$gateway = gatewayFor($kind);
		checkCheckoutMessages($gateway, 'Global message');
		saveGateway($gateway, ['use_default_description' => 'no']);
		checkCheckoutMessages(gatewayFor($kind), 'Custom message');
	};
}

$failures = 0;
foreach ($tests as $label => $test) {
	try {
		$test();
		echo 'PASS ' . $label . PHP_EOL;
	} catch (Throwable $e) {
		$failures++;
		fwrite(STDERR, 'FAIL ' . $label . ': ' . $e->getMessage() . PHP_EOL);
	}
}
echo count($tests) . ' tests, ' . $failures . ' failures.' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
