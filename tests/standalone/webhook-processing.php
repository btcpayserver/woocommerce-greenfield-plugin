<?php

declare(strict_types=1);

// Replace only the request input/headers; exercise the real webhook handler and signature check.
namespace BTCPayServer\WC\Gateway {
	function file_get_contents($path) {
		\WebhookProcessingState::$inputReads++;
		return \WebhookProcessingState::$payload;
	}
	function getallheaders() {
		\WebhookProcessingState::$headerReads++;
		if (\WebhookProcessingState::$signatureMode === 'missing') {
			return [];
		}
		if (\WebhookProcessingState::$signatureMode === 'bad') {
			return ['Btcpay-Sig' => 'sha256=' . str_repeat('0', 64)];
		}
		return ['Btcpay-Sig' => 'sha256=' . hash_hmac('sha256', \WebhookProcessingState::$payload, \WebhookProcessingState::SECRET)];
	}
	function header($value) { \WebhookProcessingState::$responseHeaders[] = $value; }
}

namespace {
	require __DIR__ . '/bootstrap.php';

	use BTCPayServer\Http\CurlClient;
	use BTCPayServer\WC\Gateway\AbstractGateway;
	use BTCPayServer\WC\Helper\GreenfieldApiHelper;

	class WebhookProcessingState {
		const SECRET = 'J3qpYr5MkuZ8tNb2Af6HxV9sDc4L';
		public static string $payload;
		public static array $orders = [];
		public static WC_Order $order;
		public static string $signatureMode = 'valid';
		public static int $inputReads = 0;
		public static int $headerReads = 0;
		public static int $orderLookups = 0;
		public static array $responseHeaders = [];
	}

	class WC_Payment_Gateway {}
	class WC_Order {
		public string $status = 'on-hold';
		public string $paymentMethod = 'btcpaygf_default';
		public array $meta = ['BTCPay_id' => 'invoice-a'];
		public array $notes = [];
		public int $completions = 0;
		public function get_payment_method() { return $this->paymentMethod; }
		public function get_status() { return $this->status; }
		public function has_status($statuses) { return in_array($this->status, (array) $statuses, true); }
		public function add_order_note($note) { $this->notes[] = $note; }
		public function get_meta($key) { return $this->meta[$key] ?? ''; }
		public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
		public function save() {}
		public function update_status($status) { $this->status = str_replace('wc-', '', $status); }
		public function payment_complete() {
			if (in_array($this->status, ['pending', 'on-hold', 'failed', 'cancelled'], true)) {
				$this->completions++;
				$this->status = 'processing';
			}
		}
	}
	class WebhookProcessingGateway extends AbstractGateway {
		public function __construct() { $this->apiHelper = new GreenfieldApiHelper(); }
		public function getPaymentMethods(): array { return ['BTC']; }
	}

	function wc_get_orders($query) {
		WebhookProcessingState::$orderLookups++;
		return WebhookProcessingState::$orders;
	}
	function finishWebhook(int $status): void {
		echo json_encode([
			'response' => $status,
			'order' => WebhookProcessingState::$order,
			'requests' => CurlClient::$requests,
			'logs' => WebhookTestState::$logs,
			'unexpectedRequests' => CurlClient::$unexpectedRequests,
			'inputReads' => WebhookProcessingState::$inputReads,
			'headerReads' => WebhookProcessingState::$headerReads,
			'orderLookups' => WebhookProcessingState::$orderLookups,
			'responseHeaders' => WebhookProcessingState::$responseHeaders,
		], JSON_THROW_ON_ERROR);
		exit;
	}
	// Exit for real: throwing here would be swallowed by processWebhook's outer catch
	// and would not test WordPress's terminating response behavior.
	function wp_die($message = '', $title = '', $args = []) { finishWebhook($args['response'] ?? 500); }
	function wp_send_json_success() { finishWebhook(200); }

	if (($argv[1] ?? '') === '--request') {
		$fixture = json_decode(file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
		if (($fixture['method'] ?? null) === 'unset') {
			unset($_SERVER['REQUEST_METHOD']);
		} else {
			$_SERVER['REQUEST_METHOD'] = $fixture['method'] ?? 'POST';
		}
		WebhookProcessingState::$signatureMode = $fixture['signature'] ?? 'valid';
		WebhookTestState::$options = [
			'btcpay_gf_url' => 'https://btcpay.example',
			'btcpay_gf_api_key' => 'test-key',
			'btcpay_gf_store_id' => 'store-a',
			'btcpay_gf_webhook' => ['secret' => WebhookProcessingState::SECRET],
			'btcpay_gf_protect_order_status' => $fixture['protect'] ?? 'no',
		];
		$order = new WC_Order();
		foreach ($fixture['order'] ?? [] as $key => $value) {
			$order->$key = $value;
		}
		WebhookProcessingState::$order = $order;
		WebhookProcessingState::$orders = ($fixture['missingOrder'] ?? false) ? [] : [$order];
		WebhookProcessingState::$payload = json_encode($fixture['payload'] ?? [
			'type' => 'InvoiceSettled', 'invoiceId' => 'invoice-a', 'deliveryId' => 'delivery-a',
		], JSON_THROW_ON_ERROR);
		if (array_key_exists('rawBody', $fixture)) {
			WebhookProcessingState::$payload = $fixture['rawBody'];
		}
		CurlClient::$invoiceData = $fixture['invoice'] ?? ['status' => 'Settled', 'additionalStatus' => 'None'];
		CurlClient::$invoiceStatus = $fixture['apiStatus'] ?? 200;
		CurlClient::$invoiceTimeout = $fixture['timeout'] ?? false;
		(new WebhookProcessingGateway())->processWebhook();
		finishWebhook(200);
	}

	function requestWebhook(array $fixture = []): array {
		$process = proc_open([PHP_BINARY, __FILE__, '--request'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		fwrite($pipes[0], json_encode($fixture, JSON_THROW_ON_ERROR));
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (proc_close($process) !== 0 || $error !== '') {
			throw new RuntimeException('Request failed: ' . $error . $output);
		}
		$result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		checkSame([], $result['unexpectedRequests'], 'No unexpected API requests');
		return $result;
	}
	function checkSame($expected, $actual, string $message): void {
		if ($expected !== $actual) {
			throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
		}
	}

	foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'post', 'unset'] as $method) {
		$result = requestWebhook(['method' => $method]);
		checkSame(405, $result['response'], 'Non-POST method rejected');
		checkSame(['Allow: POST'], $result['responseHeaders'], 'Allowed method advertised');
		checkSame(0, $result['inputReads'], 'Method rejected before reading input');
		checkSame(0, $result['headerReads'], 'Method rejected before checking signature headers');
		checkSame(0, $result['orderLookups'], 'Method rejected before order lookup');
		checkSame([], $result['requests'], 'Non-POST request never contacts API');
		checkSame(0, $result['order']['completions'], 'Non-POST request cannot complete payment');
	}
	foreach (['missing', 'bad'] as $signature) {
		$result = requestWebhook(['signature' => $signature]);
		checkSame(400, $result['response'], 'Invalid signature is a client error');
		checkSame(0, $result['orderLookups'], 'Bad signature rejected before order lookup');
		checkSame([], $result['requests'], 'Bad signature never contacts API');
		checkSame(0, $result['order']['completions'], 'Bad signature cannot complete payment');
	}
	$result = requestWebhook(['rawBody' => 'wc-api=btcpaygf_default', 'signature' => 'missing']);
	checkSame(400, $result['response'], 'Unsigned form body is rejected');
	checkSame(0, $result['orderLookups'], 'Unsigned form body never looks up orders');
	checkSame([], $result['requests'], 'Unsigned form body never contacts API');
	$result = requestWebhook(['rawBody' => '']);
	checkSame(400, $result['response'], 'Empty POST is a client error');
	checkSame(0, $result['orderLookups'], 'Empty POST rejected before order lookup');
	checkSame([], $result['requests'], 'Empty POST never contacts API');

	foreach (['pending', 'on-hold'] as $status) {
		foreach ([['apiStatus' => 503], ['apiStatus' => 403], ['apiStatus' => 404], ['timeout' => true]] as $failure) {
			$failed = requestWebhook($failure + ['order' => ['status' => $status]]);
			checkSame(503, $failed['response'], 'Failed verification requests redelivery');
			checkSame($status, $failed['order']['status'], 'Failed verification preserves order status');
			checkSame(0, $failed['order']['completions'], 'Failed verification does not complete payment');
			checkSame(false, isset($failed['order']['meta']['BTCPay_processed_deliveries']), 'Failed delivery is not remembered');
			checkSame(1, count($failed['order']['notes']), 'Failed verification adds a note');
			$retry = requestWebhook(['order' => $failed['order'], 'payload' => [
				'type' => 'InvoiceSettled', 'invoiceId' => 'invoice-a', 'deliveryId' => 'retry-a', 'originalDeliveryId' => 'delivery-a',
			]]);
			checkSame(200, $retry['response'], 'Recovered verification succeeds');
			checkSame('processing', $retry['order']['status'], 'Redelivery completes payment');
			checkSame(1, $retry['order']['completions'], 'Payment completed once');
			checkSame(['delivery-a'], $retry['order']['meta']['BTCPay_processed_deliveries'], 'Original delivery remembered after success');
			$duplicate = requestWebhook(['order' => $retry['order'], 'apiStatus' => 503]);
			checkSame(200, $duplicate['response'], 'Duplicate acknowledged even during API outage');
			checkSame($retry['order'], $duplicate['order'], 'Duplicate has no order side effects');
			checkSame([], $duplicate['requests'], 'Duplicate does not contact API');
		}
	}

	foreach ([['missingOrder' => true], ['order' => ['paymentMethod' => 'other_gateway']]] as $ignored) {
		$result = requestWebhook($ignored + ['apiStatus' => 503]);
		checkSame(200, $result['response'], 'Unmatched orders and other gateways remain acknowledged');
		checkSame([], $result['requests'], 'Unmatched orders and other gateways never fetch invoice');
		checkSame(0, $result['order']['completions'], 'Ignored delivery does not complete payment');
	}
	foreach ([['status' => 'Processing', 'additionalStatus' => 'None'], ['status' => 'Settled', 'additionalStatus' => 'PaidLate']] as $invoice) {
		$result = requestWebhook(['invoice' => $invoice]);
		checkSame(200, $result['response'], 'Verified but disallowed invoice stays acknowledged');
		checkSame('on-hold', $result['order']['status'], 'Disallowed invoice does not complete payment');
		checkSame(false, isset($result['order']['meta']['BTCPay_processed_deliveries']), 'Deferred invoice remains retryable manually');
	}
	$result = requestWebhook(['invoice' => ['status' => 'Settled', 'additionalStatus' => 'PaidOver']]);
	checkSame(200, $result['response'], 'Overpaid settlement succeeds');
	checkSame(1, $result['order']['completions'], 'Overpaid settlement completes payment');
	foreach (['processing', 'completed'] as $status) {
		$result = requestWebhook(['order' => ['status' => $status], 'protect' => 'yes', 'apiStatus' => 503]);
		checkSame(200, $result['response'], 'Protected order stays acknowledged');
		checkSame([], $result['requests'], 'Protected order does not contact API');
		checkSame($status, $result['order']['status'], 'Protected order state preserved');
	}
	$result = requestWebhook(['payload' => ['type' => 'InvoiceProcessing', 'invoiceId' => 'invoice-a', 'deliveryId' => 'delivery-a'], 'apiStatus' => 503]);
	checkSame(200, $result['response'], 'Other events retain success response');
	checkSame([], $result['requests'], 'Processing event does not verify settlement');
	$result = requestWebhook(['payload' => ['type' => 'InvoiceSettled', 'invoiceId' => 'invoice-a']]);
	checkSame(200, $result['response'], 'Legacy payload without delivery ID succeeds');
	checkSame(1, $result['order']['completions'], 'Legacy payload completes payment');
	$result = requestWebhook(['payload' => ['type' => 'InvoiceSettled', 'invoiceId' => 'invoice-a'], 'apiStatus' => 503]);
	checkSame(503, $result['response'], 'Legacy payload also requests redelivery after verification failure');

	echo "PASS: method/signature rejection, verification failures, recovery, duplicates and legacy acknowledgement behavior\n";
}
