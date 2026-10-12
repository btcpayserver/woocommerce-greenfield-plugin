<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	exit;
}

// Load the actual callback without booting WordPress or the plugin singleton.
define('ABSPATH', dirname(__DIR__, 4) . '/');
$actions = [];
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'https://shop.example/plugins/btcpay/'; }
function add_action($name, $callback, ...$args) { $GLOBALS['actions'][$name][] = $callback; }
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
require dirname(__DIR__, 2) . '/btcpay-greenfield-for-woocommerce.php';
require __DIR__ . '/bootstrap.php';

use BTCPayServer\Http\CurlClient;
use BTCPayServer\WC\Helper\GreenfieldApiAuthorization;
use BTCPayServer\WC\Helper\GreenfieldApiHelper;

class ApiSetupRedirect extends RuntimeException {}
function admin_url($path) { return 'https://shop.example/wp-admin/' . $path; }
function wp_safe_redirect($url) { throw new ApiSetupRedirect($url); }
function wp_generate_password($length, ...$args) { return bin2hex(random_bytes($length / 2)); }
function set_transient($key, $value, $ttl) { WebhookTestState::$transients[$key] = [$value, $GLOBALS['setup_now'] + $ttl]; return true; }
function get_transient($key) {
	$entry = WebhookTestState::$transients[$key] ?? null;
	return $entry && $entry[1] > $GLOBALS['setup_now'] ? $entry[0] : false;
}
function wp_verify_nonce($nonce, $action) { return $nonce === 'test-nonce'; }
function current_user_can($capability) { return $capability === 'manage_options'; }
function home_url($path) { return 'https://shop.example' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_send_json_success($data) {
	echo json_encode(['data' => $data, 'options' => WebhookTestState::$options, 'pending' => get_transient('btcpay_gf_pending_api_setup')], JSON_THROW_ON_ERROR);
	exit;
}
function wp_send_json_error($message) { throw new RuntimeException($message); }

function check($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message);
	}
}

function fixture(): void {
	WebhookTestState::$options = [
		'btcpay_gf_url' => 'https://existing.example',
		'btcpay_gf_api_key' => 'existing-key',
		'btcpay_gf_store_id' => 'existing-store',
		'btcpay_gf_webhook' => ['id' => 'old-hook', 'secret' => 'existing-secret'],
	];
	WebhookTestState::$transients = WebhookTestState::$notices = [];
	$GLOBALS['setup_now'] = 1000;
	CurlClient::$requests = CurlClient::$unexpectedRequests = [];
	CurlClient::$apiKeyData = ['permissions' => array_map(static fn($p) => $p . ':verified-store', GreenfieldApiAuthorization::REQUIRED_PERMISSIONS)];
	CurlClient::$apiKeyStatus = CurlClient::$createStatus = 200;
	CurlClient::$apiKeyTimeout = false;
	CurlClient::$createdWebhook = ['id' => 'new-hook', 'secret' => 'verified-secret', 'url' => 'https://shop.example/wc-api/btcpaygf_default/'];
	$_GET = ['state' => GreenfieldApiHelper::createApiSetupState('https://selected.example')];
	$_POST = [
		'apiKey' => 'submitted-key',
		'permissions' => array_map(static fn($p) => $p . ':forged-store', GreenfieldApiAuthorization::REQUIRED_PERMISSIONS),
		'btcpay_gf_url' => 'https://attacker.example',
	];
	$GLOBALS['wp_query'] = (object) ['query_vars' => ['btcpay-settings-callback' => true]];
}

function runCallback(): void {
	try {
		($GLOBALS['actions']['template_redirect'][0])();
		throw new RuntimeException('Callback did not redirect.');
	} catch (ApiSetupRedirect $e) {
		check(admin_url('admin.php?page=wc-settings&tab=btcpay_settings'), $e->getMessage(), 'Redirect to settings');
	}
}

function checkRejected(): void {
	$before = WebhookTestState::$options;
	runCallback();
	check($before, WebhookTestState::$options, 'Preserve existing configuration');
	check(true, count(array_filter(WebhookTestState::$notices, static fn($notice) => $notice[0] === 'error')) > 0, 'Report setup failure');
}

// A child process lets the AJAX handler use its real exit-on-success behavior.
if (($argv[1] ?? '') === '--start-wizard') {
	fixture();
	$_POST = ['apiNonce' => 'test-nonce', 'host' => 'https://selected.example'];
	(new ReflectionClass(BTCPayServerWCPlugin::class))->newInstanceWithoutConstructor()->processAjaxApiUrl();
	exit(1);
}

$tests = [];
$tests['starting the wizard keeps the active connection intact'] = static function () {
	$before = WebhookTestState::$options;
	$process = proc_open([PHP_BINARY, __FILE__, '--start-wizard'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$response = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	check(0, proc_close($process), 'Wizard response: ' . $error);
	$response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
	check($before, $response['options'], 'Keep live credentials until setup succeeds');
	check('https://selected.example', $response['pending']['url'], 'Store the selected host only in pending state');
	parse_str(parse_url($response['data']['url'], PHP_URL_QUERY), $authorization);
	parse_str(parse_url($authorization['redirect'], PHP_URL_QUERY), $callback);
	check($response['pending']['state'], $callback['state'], 'Bind the authorization URL to pending state');
};
$tests['callback uses server permissions and ignores forged store and URL'] = static function () {
	runCallback();
	check('submitted-key', get_option('btcpay_gf_api_key'), 'Retain the verified submitted key even if introspection omits it');
	check('verified-store', get_option('btcpay_gf_store_id'), 'Use server-reported store scope');
	check('https://selected.example', get_option('btcpay_gf_url'), 'Use state-bound host');
	check(CurlClient::$createdWebhook, get_option('btcpay_gf_webhook'), 'Save new webhook');
	check(['https://selected.example/api/v1/api-keys/current', 'https://selected.example/api/v1/stores/verified-store/webhooks'], array_column(CurlClient::$requests, 'url'), 'Verify and register against the selected server');
	CurlClient::$requests = [];
	checkRejected();
	check([], CurlClient::$requests, 'Reject replay before any HTTP calls');
};
$tests['callback does not require POSTed permissions'] = static function () {
	unset($_POST['permissions']);
	runCallback();
	check('verified-store', get_option('btcpay_gf_store_id'), 'Use introspection without callback permission claims');
};
foreach ([
	'missing permissions' => [],
	'insufficient permissions' => ['btcpay.store.canviewstoresettings:verified-store'],
	'unscoped permissions' => GreenfieldApiAuthorization::REQUIRED_PERMISSIONS,
	'empty store scope' => array_map(static fn($p) => $p . ':', GreenfieldApiAuthorization::REQUIRED_PERMISSIONS),
	'multiple stores' => ['btcpay.store.canviewinvoices:store-a', 'btcpay.store.cancreateinvoice:store-b'],
	'malformed permissions' => [123],
	'non-array permissions' => 'invalid',
] as $name => $permissions) {
	$tests['callback rejects ' . $name] = static function () use ($permissions) {
		CurlClient::$apiKeyData = ['permissions' => $permissions];
		checkRejected();
		check(1, count(CurlClient::$requests), 'Reject scope before webhook registration');
	};
}
foreach ([403, 500] as $status) {
	$tests['callback preserves configuration on key lookup error ' . $status] = static function () use ($status) {
		CurlClient::$apiKeyStatus = $status;
		checkRejected();
	};
	$tests['callback preserves configuration on webhook error ' . $status] = static function () use ($status) {
		CurlClient::$createStatus = $status;
		checkRejected();
	};
}
$tests['callback preserves configuration on key lookup timeout'] = static function () {
	CurlClient::$apiKeyTimeout = true;
	checkRejected();
};
foreach (['missing', 'wrong', 'expired', 'malformed'] as $state) {
	$tests['callback rejects ' . $state . ' state'] = static function () use ($state) {
		if ($state === 'missing') { unset($_GET['state']); }
		if ($state === 'wrong') { $_GET['state'] = 'incorrect'; }
		if ($state === 'expired') { $GLOBALS['setup_now'] += 901; }
		if ($state === 'malformed') { $_GET['state'] = [$_GET['state']]; }
		checkRejected();
		check([], CurlClient::$requests, 'Reject invalid state before HTTP calls');
	};
}

$failures = 0;
foreach ($tests as $name => $test) {
	try {
		fixture();
		$test();
		check([], CurlClient::$unexpectedRequests, 'Only mocked HTTP calls');
		echo 'PASS ' . $name . PHP_EOL;
	} catch (Throwable $e) {
		$failures++;
		fwrite(STDERR, 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL);
	}
}
echo count($tests) . ' tests, ' . $failures . ' failures.' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
