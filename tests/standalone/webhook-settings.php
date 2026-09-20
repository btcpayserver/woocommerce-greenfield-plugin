<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use BTCPayServer\Http\CurlClient;
use BTCPayServer\WC\Admin\GlobalSettings;
use BTCPayServer\WC\Helper\GreenfieldApiHelper;
use BTCPayServer\WC\Helper\GreenfieldApiWebhook;

const OLD_SECRET = 'J3qpYr5MkuZ8tNb2Af6HxV9sDc4L';
const NEW_SECRET = 'N6zsLb4QweD8rTh3Kj5VxY9aCp2M';
const CALLBACK_URL = 'https://shop.example/wc-api/btcpaygf_default/';

function check($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true));
	}
}

function fixture(): GlobalSettings {
	WebhookTestState::$options = [
		'btcpay_gf_url' => 'https://btcpay.example',
		'btcpay_gf_api_key' => 'test-key',
		'btcpay_gf_store_id' => 'store-a',
		'btcpay_gf_webhook' => ['id' => 'old-hook', 'secret' => OLD_SECRET, 'url' => CALLBACK_URL],
	];
	WebhookTestState::$notices = WebhookTestState::$errors = WebhookTestState::$logs = [];
	WebhookTestState::$saves = 0;
	WebhookTestState::$failOptionWrite = false;
	CurlClient::$requests = CurlClient::$unexpectedRequests = [];
	CurlClient::$remoteWebhook = ['id' => 'old-hook', 'url' => CALLBACK_URL];
	CurlClient::$createdWebhook = ['id' => 'new-hook', 'secret' => NEW_SECRET, 'url' => CALLBACK_URL];
	CurlClient::$lookupStatus = CurlClient::$createStatus = CurlClient::$deleteStatus = 200;
	CurlClient::$lookupTimeout = false;
	CurlClient::$webhookPermission = true;
	$_POST = [
		'btcpay_gf_url' => 'https://btcpay.example',
		'btcpay_gf_api_key' => 'test-key',
		'btcpay_gf_store_id' => 'store-a',
		'btcpay_gf_whsecret' => OLD_SECRET,
	];
	$reflection = new ReflectionClass(GlobalSettings::class);
	$settings = $reflection->newInstanceWithoutConstructor();
	$property = $reflection->getProperty('apiHelper');
	if (PHP_VERSION_ID < 80100) {
		$property->setAccessible(true);
	}
	$property->setValue($settings, new GreenfieldApiHelper());
	return $settings;
}

function webhookRequests(): array {
	return array_values(array_filter(CurlClient::$requests, static fn($r) => str_contains($r['url'], '/webhooks')));
}

function checkRequests(array $methods): void {
	check($methods, array_column(webhookRequests(), 'method'), 'Webhook HTTP methods');
}

function checkPreserved(array $before): void {
	check($before, WebhookTestState::$options, 'Previous settings must be preserved');
	check(0, WebhookTestState::$saves, 'Failed saves must not persist changed connection settings');
	check(true, count(WebhookTestState::$errors) > 0, 'Failed saves must show an error');
}

$tests = [];
$tests['unchanged automatic secret remains automatic'] = static function ($settings) {
	$before = get_option('btcpay_gf_webhook');
	$settings->save();
	check($before, get_option('btcpay_gf_webhook'), 'Existing webhook');
	checkRequests(['GET']);
	check(1, WebhookTestState::$saves, 'Normal settings save');
};
$tests['prefilled automatic secret recreates a missing webhook'] = static function ($settings) {
	CurlClient::$lookupStatus = 404;
	$settings->save();
	check(CurlClient::$createdWebhook, get_option('btcpay_gf_webhook'), 'Missing webhook must be recreated automatically');
	checkRequests(['GET', 'POST']);
	check(1, WebhookTestState::$saves, 'Settings save after recreation');
};
foreach ([403, 500, 503] as $status) {
	$tests['lookup error ' . $status . ' preserves automatic setup'] = static function ($settings) use ($status) {
		$before = WebhookTestState::$options;
		CurlClient::$lookupStatus = $status;
		$settings->save();
		checkPreserved($before);
		checkRequests(['GET']);
	};
}
$tests['lookup timeout preserves automatic setup'] = static function ($settings) {
	$before = WebhookTestState::$options;
	CurlClient::$lookupTimeout = true;
	$settings->save();
	checkPreserved($before);
	checkRequests(['GET']);
};
$tests['clearing a secret rotates then deletes the old webhook'] = static function ($settings) {
	$_POST['btcpay_gf_whsecret'] = '';
	$settings->save();
	check(CurlClient::$createdWebhook, get_option('btcpay_gf_webhook'), 'Rotated webhook');
	checkRequests(['POST', 'DELETE']);
	check(true, str_ends_with(webhookRequests()[1]['url'], '/old-hook'), 'Delete the old webhook');
};
$tests['rotation failure retains the working webhook'] = static function ($settings) {
	$before = WebhookTestState::$options;
	$_POST['btcpay_gf_whsecret'] = '';
	CurlClient::$createStatus = 500;
	$settings->save();
	checkPreserved($before);
	checkRequests(['POST']);
};
$tests['rotation followed by a normal save keeps the new webhook'] = static function ($settings) {
	$_POST['btcpay_gf_whsecret'] = '';
	$settings->save();
	$_POST['btcpay_gf_whsecret'] = $settings->getGlobalSettings()['whsecret']['value'];
	CurlClient::$remoteWebhook = CurlClient::$createdWebhook;
	$settings->save();
	check(CurlClient::$createdWebhook, get_option('btcpay_gf_webhook'), 'Keep the rotated automatic webhook');
	checkRequests(['POST', 'DELETE', 'GET']);
};
$tests['clearing a manual secret returns to automatic setup'] = static function ($settings) {
	WebhookTestState::$options['btcpay_gf_webhook']['id'] = 'manual';
	$_POST['btcpay_gf_whsecret'] = '';
	$settings->save();
	check(CurlClient::$createdWebhook, get_option('btcpay_gf_webhook'), 'Replace manual setup with a real automatic webhook');
	checkRequests(['POST']);
};
foreach (['', 'manual', ' Manual ', '   ', null, [], 123] as $index => $invalidSecret) {
	$tests['invalid generated secret ' . $index . ' is discarded'] = static function ($settings) use ($invalidSecret) {
		$before = WebhookTestState::$options;
		$_POST['btcpay_gf_whsecret'] = '';
		CurlClient::$createdWebhook['secret'] = $invalidSecret;
		$settings->save();
		checkPreserved($before);
		checkRequests(['POST', 'DELETE']);
		check(true, str_ends_with(webhookRequests()[1]['url'], '/new-hook'), 'Only discard the unusable new webhook');
	};
}
$tests['failed local persistence removes only the new webhook'] = static function ($settings) {
	$before = WebhookTestState::$options;
	$_POST['btcpay_gf_whsecret'] = '';
	WebhookTestState::$failOptionWrite = true;
	$settings->save();
	checkPreserved($before);
	checkRequests(['POST', 'DELETE']);
	check(true, str_ends_with(webhookRequests()[1]['url'], '/new-hook'), 'Never delete the working webhook if persistence fails');
};
foreach (['btcpay_gf_url' => 'https://other.example', 'btcpay_gf_store_id' => 'store-b'] as $key => $value) {
	$tests['rotation does not delete from a changed ' . $key] = static function ($settings) use ($key, $value) {
		$_POST[$key] = $value;
		$_POST['btcpay_gf_whsecret'] = '';
		$settings->save();
		check(CurlClient::$createdWebhook, get_option('btcpay_gf_webhook'), 'Webhook for new connection');
		checkRequests(['POST']);
	};
}
$tests['changing stores with the prefilled secret remains automatic'] = static function ($settings) {
	$_POST['btcpay_gf_store_id'] = 'store-b';
	CurlClient::$lookupStatus = 404;
	$settings->save();
	check(CurlClient::$createdWebhook, get_option('btcpay_gf_webhook'), 'Create webhook on new store');
	checkRequests(['GET', 'POST']);
	check(true, str_contains(webhookRequests()[1]['url'], '/stores/store-b/'), 'Use the new store');
};
foreach ([false, true] as $omitSecret) {
	$tests['manual setup without webhook permission, omitted=' . (int) $omitSecret] = static function ($settings) use ($omitSecret) {
		WebhookTestState::$options['btcpay_gf_webhook'] = ['id' => 'manual', 'secret' => OLD_SECRET, 'url' => 'manual'];
		$before = get_option('btcpay_gf_webhook');
		CurlClient::$webhookPermission = false;
		if ($omitSecret) {
			unset($_POST['btcpay_gf_whsecret']);
		}
		$settings->save();
		check($before, get_option('btcpay_gf_webhook'), 'Preserve manual setup');
		checkRequests([]);
		check(1, WebhookTestState::$saves, 'Manual settings save');
	};
}
$tests['new manual secret works without webhook permission'] = static function ($settings) {
	$_POST['btcpay_gf_whsecret'] = NEW_SECRET;
	CurlClient::$webhookPermission = false;
	$settings->save();
	check(['id' => 'manual', 'secret' => NEW_SECRET, 'url' => 'manual'], get_option('btcpay_gf_webhook'), 'Store actual manual secret');
	checkRequests([]);
};
$tests['manual secret persistence failure preserves the old settings'] = static function ($settings) {
	$before = WebhookTestState::$options;
	$_POST['btcpay_gf_whsecret'] = NEW_SECRET;
	WebhookTestState::$failOptionWrite = true;
	$settings->save();
	checkPreserved($before);
	checkRequests([]);
};
$tests['manual secrets preserve exact bytes through a form round trip'] = static function ($settings) {
	$secret = '  Ab3<keep>%ab"quote\'\\backslash-and-spaces  ';
	$_POST['btcpay_gf_whsecret'] = addslashes($secret);
	$settings->save();
	check($secret, get_option('btcpay_gf_webhook')['secret'], 'Do not sanitize or trim a signing secret');
	$body = '{"type":"InvoiceSettled"}';
	$signature = 'sha256=' . hash_hmac('sha256', $body, $secret);
	check(true, (new GreenfieldApiHelper())->validWebhookRequest($signature, $body), 'Signature must match original secret');
	checkRequests([]);
};
foreach (['manual', ' Manual ', 'short-secret', str_repeat('a', 15), str_repeat('é', 8), str_repeat(' ', 32), ['array'], null, false, 123] as $index => $invalidSecret) {
	$tests['invalid submitted secret ' . $index . ' cannot change settings'] = static function ($settings) use ($invalidSecret) {
		$before = WebhookTestState::$options;
		$_POST['btcpay_gf_whsecret'] = $invalidSecret;
		$settings->save();
		checkPreserved($before);
		checkRequests([]);
	};
}
$tests['16 character secret is accepted without composition rules'] = static function ($settings) {
	$_POST['btcpay_gf_whsecret'] = '82bf509a13dc76e4';
	$settings->save();
	check($_POST['btcpay_gf_whsecret'], get_option('btcpay_gf_webhook')['secret'], 'Accept hexadecimal secrets');
	check(1, WebhookTestState::$saves, 'Valid manual settings save');
};
foreach (['old-hook', 'manual'] as $id) {
	$tests['existing shorter secret remains usable for ' . $id] = static function ($settings) use ($id) {
		WebhookTestState::$options['btcpay_gf_webhook']['id'] = $id;
		WebhookTestState::$options['btcpay_gf_webhook']['secret'] = $_POST['btcpay_gf_whsecret'] = 'legacy-secret';
		$before = get_option('btcpay_gf_webhook');
		$settings->save();
		check($before, get_option('btcpay_gf_webhook'), 'Do not invalidate existing shorter secrets');
		check(1, WebhookTestState::$saves, 'Legacy secret save');
	};
}
$tests['first automatic setup creates a webhook'] = static function ($settings) {
	unset(WebhookTestState::$options['btcpay_gf_webhook'], $_POST['btcpay_gf_whsecret']);
	$settings->save();
	check(CurlClient::$createdWebhook, get_option('btcpay_gf_webhook'), 'Initial automatic webhook');
	checkRequests(['POST']);
};
$tests['field uses canonical secret instead of a stale separate option'] = static function ($settings) {
	WebhookTestState::$options['btcpay_gf_whsecret'] = 'stale-value';
	$fields = $settings->getGlobalSettings();
	check('password', $fields['api_key']['type'], 'API key masking');
	check('password', $fields['whsecret']['type'], 'Secret masking');
	check(OLD_SECRET, $fields['whsecret']['value'], 'Canonical secret');
	check(false, $fields['whsecret']['is_option'], 'Prevent separate option persistence');
};
$tests['invalid stored placeholder is not shown as configured'] = static function ($settings) {
	WebhookTestState::$options['btcpay_gf_webhook'] = ['id' => 'manual', 'secret' => 'manual', 'url' => 'manual'];
	$fields = $settings->getGlobalSettings();
	check('', $fields['whsecret']['value'], 'Do not prefill a placeholder secret');
	check(false, GreenfieldApiHelper::webhookIsSetup(), 'Invalid secret is not configured');
	check(false, GreenfieldApiHelper::webhookIsSetupManual(), 'Invalid secret is not a manual setup');
	$_POST['btcpay_gf_whsecret'] = $fields['whsecret']['value'];
	$settings->save();
	check(CurlClient::$createdWebhook, get_option('btcpay_gf_webhook'), 'Recover using a real generated secret');
	checkRequests(['POST']);
};
$tests['invalid secrets and signatures never authenticate'] = static function () {
	$body = '{"type":"InvoiceSettled"}';
	foreach ([null, [], ['secret' => ''], ['secret' => 'manual'], ['secret' => ' MANUAL '], ['secret' => []], ['secret' => 123]] as $webhook) {
		WebhookTestState::$options['btcpay_gf_webhook'] = $webhook;
		$secret = is_string($webhook['secret'] ?? null) ? $webhook['secret'] : '';
		check(false, (new GreenfieldApiHelper())->validWebhookRequest('sha256=' . hash_hmac('sha256', $body, $secret), $body), 'Reject invalid signing secrets');
	}
	WebhookTestState::$options['btcpay_gf_webhook'] = ['secret' => OLD_SECRET];
	$helper = new GreenfieldApiHelper();
	check(true, $helper->validWebhookRequest('sha256=' . hash_hmac('sha256', $body, OLD_SECRET), $body), 'Accept authentic delivery');
	check(false, $helper->validWebhookRequest('sha256=' . hash_hmac('sha256', $body, NEW_SECRET), $body), 'Reject wrong secret');
	check(false, $helper->validWebhookRequest('sha256=' . hash_hmac('sha256', $body, OLD_SECRET), $body . ' '), 'Reject modified body');
};
$tests['webhook updates cannot send a placeholder secret'] = static function () {
	check(null, GreenfieldApiWebhook::updateWebhook('old-hook', CALLBACK_URL, 'manual', true, true, null), 'Reject placeholder before sending an update');
	checkRequests([]);
};

$failures = 0;
foreach ($tests as $name => $test) {
	try {
		$test(fixture());
		check([], CurlClient::$unexpectedRequests, 'All requests must use the mocked transport');
		echo 'PASS ' . $name . PHP_EOL;
	} catch (Throwable $e) {
		$failures++;
		fwrite(STDERR, 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL);
	}
}
echo count($tests) . ' tests, ' . $failures . ' failures.' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
