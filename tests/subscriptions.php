<?php
/**
 * Isolated subscription regressions: php tests/subscriptions.php
 * Uses the real PHP API client with an in-memory HTTP transport. No network, mail or database writes.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/subscription-fixtures.php';

use BTCPayServer\Client\Subscriptions;
use BTCPayServer\Http\ClientInterface;
use BTCPayServer\Http\Response;
use BTCPayServer\Http\ResponseInterface;
use BTCPayServer\Result\OfferingPlan;
use BTCPayServer\WC\Gateway\AbstractGateway;
use BTCPayServer\WC\Helper\GreenfieldApiHelper;
use BTCPayServer\WC\Helper\SubscriptionPortalEmail;

final class TestHttp implements ClientInterface {
	public array $requests = [];
	public array $responses = [];
	public function request(string $method, string $url, array $headers = [], string $body = ''): ResponseInterface {
		$this->requests[] = compact('method', 'url', 'headers', 'body');
		if (!$this->responses) {
			throw new RuntimeException('Unexpected HTTP request: ' . $method . ' ' . $url);
		}
		[$status, $data] = array_shift($this->responses);
		return new Response($status, json_encode($data, JSON_THROW_ON_ERROR), []);
	}
}

final class SubscriptionTestGateway extends AbstractGateway {
	public TestHttp $http;
	public function __construct() {
		$this->id = 'btcpaygf_default';
		$this->apiHelper = new GreenfieldApiHelper();
		$this->http = new TestHttp();
	}
	protected function subscriptionsClient(): Subscriptions {
		return new Subscriptions($this->apiHelper->url, $this->apiHelper->apiKey, $this->http);
	}
	public function getPaymentMethods(): array { return []; }
	public function call(string $method, ...$args) { return $this->$method(...$args); }
}

function expect($actual, $expected, string $message): void {
	if ($actual !== $expected) {
		throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}
function expectThrows(callable $action, string $message): void {
	try { $action(); } catch (Throwable $e) { return; }
	throw new RuntimeException($message);
}
function fixture(): array {
	$GLOBALS['orders'] = [];
	$GLOBALS['renewal_count'] = 0;
	$GLOBALS['renewal_failure'] = false;
	$GLOBALS['wpdb']->locked = false;
	$GLOBALS['test_woocommerce']->sendResult = true;
	$GLOBALS['test_woocommerce']->sent = 0;
	$GLOBALS['options']['btcpay_gf_subscription_mappings'] = [['product_id' => 5, 'offering_id' => 'off', 'plan_id' => 'plan']];
	$order = new WC_Order(1);
	$subscription = new WC_Subscription(2);
	$subscription->parent = 1;
	foreach ([$order, $subscription] as $item) {
		$item->meta = ['BTCPay_store_id' => 'store', 'BTCPay_offering_id' => 'off', 'BTCPay_plan_id' => 'plan'];
		$item->items = [new SubscriptionTestItem()];
		$GLOBALS['orders'][$item->get_id()] = $item;
	}
	$plan = ['id' => 'plan', 'name' => 'Monthly', 'price' => '10.00', 'currency' => 'USD', 'recurringType' => 'Monthly', 'trialDays' => 0, 'status' => 'Active', 'renewable' => true];
	$subscriber = (object) [
		'customer' => (object) ['id' => 'customer'],
		'offering' => (object) ['id' => 'off', 'storeId' => 'store'],
		'plan' => (object) $plan,
		'metadata' => (object) ['wc_subscription_id' => '2', 'wc_order_id' => '1'],
		'isActive' => true, 'isSuspended' => false, 'phase' => 'Normal', 'periodEnd' => time() + 86400,
	];
	$checkout = ['id' => 'checkout', 'invoiceId' => 'invoice', 'subscriber' => json_decode(json_encode($subscriber), true), 'planStarted' => true, 'isExpired' => false, 'isTrial' => false, 'url' => 'https://btcpay.test/plan-checkout/checkout'];
	return [new SubscriptionTestGateway(), $order, $subscription, $plan, $subscriber, $checkout];
}

$tests = [];
$tests['BTCPay owns the renewal schedule'] = function () {
	[$gateway] = fixture();
	$gateway->call('initSubscriptionSupport');
	expect(in_array('gateway_scheduled_payments', $gateway->supports, true), true, 'External billing must disable WooCommerce renewal charging');
	expect(in_array('subscription_amount_changes', $gateway->supports, true), false, 'Amount changes are unsupported');
};
$tests['normal and trial plans must match the WooCommerce totals'] = function () {
	[$gateway, $order, $subscription, $plan] = fixture();
	$gateway->call('validateSubscriptionPlan', $order, $subscription, new OfferingPlan($plan));
	$plan['trialDays'] = 7;
	$order->items[0]->product->trialLength = 1;
	$order->items[0]->product->trialPeriod = 'week';
	$order->total = '0';
	$gateway->call('validateSubscriptionPlan', $order, $subscription, new OfferingPlan($plan));
};
$tests['mixed carts, quantity, price, currency and schedules cannot undercharge'] = function () {
	$changes = [
		fn($o, $s) => $o->items[] = new SubscriptionTestItem(),
		fn($o, $s) => $o->items[0]->quantity = 2,
		fn($o, $s) => $o->items[0]->product->type = 'simple',
		fn($o, $s) => $o->total = '11',
		fn($o, $s) => $s->total = '9',
		fn($o, $s) => $o->currency = 'EUR',
		fn($o, $s) => $s->interval = 2,
		fn($o, $s) => $s->dates['end'] = time() + 86400,
	];
	foreach ($changes as $change) {
		[$gateway, $order, $subscription, $plan] = fixture();
		$change($order, $subscription);
		expectThrows(fn() => $gateway->call('validateSubscriptionPlan', $order, $subscription, new OfferingPlan($plan)), 'Unsupported checkout accepted');
	}
};
$tests['checkout payload uses token authentication and a protected return URL'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber, $checkout] = fixture();
	$gateway->http->responses = [[200, $checkout]];
	$subscription->dates['trial_end'] = time() + 604800;
	$gateway->call('createPlanCheckout', $order, 'off', 'plan', null, 'buyer@example.test');
	$request = $gateway->http->requests[0];
	$data = json_decode($request['body'], true);
	expect($request['url'], 'https://btcpay.test/api/v1/plan-checkout', 'Checkout route');
	expect($request['headers']['Authorization'], 'token test-key', 'Token auth');
	expect($data['isTrial'], true, 'Trials must be explicit');
	expect(str_contains($data['successRedirectLink'], 'btcpaygf-return='), true, 'Protected return');
	expect(str_contains($request['body'], 'wc_order_secret'), false, 'Order key must stay local');
	expect($data['invoiceMetadata']['wc_subscription_id'], '2', 'Subscription association');
	expect(isset($data['invoiceMetadata']['buyerEmail']), false, 'No email in invoice metadata');
};
$tests['modal subscription checkout redirects and persists its binding'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber, $checkout] = fixture();
	$checkout['planStarted'] = false;
	$checkout['invoiceId'] = null;
	$gateway->http->responses = [[200, $plan], [200, $checkout]];
	$response = $gateway->call('processSubscriptionPayment', $order, true);
	expect($response['redirect'], $checkout['url'], 'Modal subscription redirect');
	expect($response['subscription'], true, 'Classic checkout redirect marker');
	expect($order->get_meta('BTCPay_plan_checkout_id'), 'checkout', 'Stored checkout');
	expect($subscription->get_meta('BTCPay_store_id'), 'store', 'Stored store');
	expect($GLOBALS['wpdb']->locked, false, 'Checkout lock released');
};
$tests['checkout retry reuses the original and never duplicates after API failure'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber, $checkout] = fixture();
	$order->meta['BTCPay_plan_checkout_id'] = 'checkout';
	$gateway->http->responses = [[200, $checkout]];
	expect($gateway->call('getReusablePlanCheckout', $order)->getId(), 'checkout', 'Started plan is reused');
	$gateway->http->responses = [[503, ['code' => 'unavailable', 'message' => 'Retry']]];
	expectThrows(fn() => $gateway->call('getReusablePlanCheckout', $order), 'API failure must not create another checkout');
	$order->meta['BTCPay_store_id'] = 'other';
	expectThrows(fn() => $gateway->call('getReusablePlanCheckout', $order), 'Cross-store reuse');
};
$tests['subscription webhook must match the saved customer, offering, plan and store'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber, $checkout] = fixture();
	$subscription->meta['BTCPay_subscriber_id'] = 'customer';
	expect($gateway->call('subscriberMatchesSubscription', $subscription, $subscriber), true, 'Valid binding');
	foreach ([['customer', 'id'], ['offering', 'id'], ['offering', 'storeId'], ['plan', 'id']] as [$part, $field]) {
		$bad = unserialize(serialize($subscriber));
		$bad->$part->$field = 'unrelated';
		expect($gateway->call('subscriberMatchesSubscription', $subscription, $bad), false, 'Foreign subscriber rejected');
	}
};
$tests['first webhook binds only to the locally-created checkout'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber, $checkout] = fixture();
	$order->meta['BTCPay_plan_checkout_id'] = 'checkout';
	$wrong = $checkout;
	$wrong['subscriber']['customer']['id'] = 'other';
	$gateway->http->responses = [[200, $wrong], [200, $checkout]];
	expect($gateway->call('subscriberMatchesSubscription', $subscription, $subscriber), false, 'Forged metadata cannot bind a customer');
	expect($gateway->call('subscriberMatchesSubscription', $subscription, $subscriber), true, 'Original checkout binding');
	expect($subscription->get_meta('BTCPay_subscriber_id'), 'customer', 'Customer persisted');
};
$tests['grace and trial dates never create paid renewals'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$subscription->meta['BTCPay_last_renewal_period_end'] = 10;
	foreach (['Trial' => 'trialEnd', 'Grace' => 'gracePeriodEnd'] as $phase => $field) {
		$subscriber->phase = $phase;
		$subscriber->$field = time() + 172800;
		expect($gateway->call('getBtcpaySubscriberExpirationTimestamp', $subscriber), $subscriber->$field, 'Phase date');
		$gateway->call('maybeCreateBtcpayRenewalOrder', $subscription, $subscriber, 'test');
	}
	expect($GLOBALS['renewal_count'], 0, 'Grace or trial is not a paid renewal');
};
$tests['paid renewal redelivery is idempotent and strips old invoice credentials'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$subscription->meta += ['BTCPay_last_renewal_period_end' => 10, 'BTCPay_id' => 'parent-invoice', 'BTCPay_plan_checkout_id' => 'parent-checkout', '_btcpay_return_reference_hash' => 'old-reference'];
	$gateway->call('maybeCreateBtcpayRenewalOrder', $subscription, $subscriber, 'PlanStarted');
	$gateway->call('maybeCreateBtcpayRenewalOrder', $subscription, $subscriber, 'SubscriberActivated');
	expect($GLOBALS['renewal_count'], 1, 'One renewal per period');
	$renewal = $GLOBALS['orders'][101];
	expect($renewal->is_paid(), true, 'Renewal completed');
	expect($renewal->get_meta('BTCPay_id'), '', 'Parent invoice is not a renewal invoice');
	expect($renewal->get_meta('_btcpay_return_reference_hash'), '', 'Return credential removed');
};
$tests['renewal creation failure does not advance the recorded period'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$subscription->meta['BTCPay_last_renewal_period_end'] = 10;
	$GLOBALS['renewal_failure'] = true;
	expectThrows(fn() => $gateway->call('maybeCreateBtcpayRenewalOrder', $subscription, $subscriber, 'test'), 'Creation failure must retry');
	expect($subscription->get_meta('BTCPay_last_renewal_period_end'), 10, 'Period marker retained');
};
$tests['a trial creates its first paid renewal'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$subscription->meta['BTCPay_trial_started'] = 1;
	$gateway->call('maybeCreateBtcpayRenewalOrder', $subscription, $subscriber, 'test');
	expect($GLOBALS['renewal_count'], 1, 'First paid period after trial');
};
$tests['stale disabled webhook follows fresh active server state'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$order->paid = true;
	$subscription->status = 'active';
	$subscription->meta['BTCPay_subscriber_id'] = 'customer';
	$stale = clone $subscriber;
	$stale->isActive = false;
	$stale->phase = 'Expired';
	$gateway->http->responses = [[200, $subscriber]];
	$gateway->call('processSubscriptionWebhook', (object) ['storeId' => 'store', 'type' => 'SubscriberDisabled', 'reason' => 'Expired', 'subscriber' => $stale]);
	expect($subscription->status, 'active', 'Stale event cannot expire an active subscriber');
	expect($GLOBALS['wpdb']->locked, false, 'Webhook lock released');
};
$tests['failed subscriber verification never trusts the webhook fallback'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$subscription->meta['BTCPay_subscriber_id'] = 'customer';
	$gateway->http->responses = [[503, ['code' => 'unavailable', 'message' => 'Retry']]];
	expectThrows(fn() => $gateway->call('processSubscriptionWebhook', (object) ['storeId' => 'store', 'type' => 'SubscriberActivated', 'subscriber' => $subscriber]), 'Failure must be retryable');
	expect($order->paid, false, 'No unverified payment');
	expect($subscription->status, 'pending', 'No unverified activation');
	expect($GLOBALS['wpdb']->locked, false, 'Failure releases lock');
};
$tests['cancelled subscriptions stay cancelled on delayed activation'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$subscription->status = 'cancelled';
	$gateway->call('reconcileWooSubscriptionFromBtcpaySubscriber', $subscription, $subscriber, 'test');
	expect($subscription->status, 'cancelled', 'Cancellation preserved');
	expect($order->paid, false, 'Cancelled parent not completed');
};
$tests['new activation waits until the original plan checkout has started'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber, $checkout] = fixture();
	$order->meta['BTCPay_plan_checkout_id'] = 'checkout';
	$checkout['planStarted'] = false;
	$gateway->http->responses = [[200, $checkout]];
	expectThrows(fn() => $gateway->call('reconcileWooSubscriptionFromBtcpaySubscriber', $subscription, $subscriber, 'test'), 'Activation must wait');
	expect($subscription->status, 'pending', 'No premature activation');
	expect($order->paid, false, 'No premature payment');
};
$tests['suspension, reactivation and portal API contracts use current routes'] = function () {
	[$gateway] = fixture();
	$http = $gateway->http;
	$http->responses = [[200, []], [200, []], [200, ['url' => 'https://btcpay.test/subscriber-portal/ps_test']]];
	$client = new Subscriptions('https://btcpay.test', 'test-key', $http);
	$client->suspendSubscriber('store', 'off', 'customer', 'test');
	$client->unsuspendSubscriber('store', 'off', 'customer');
	$client->createPortalSession('store', 'off', 'customer', 10080);
	expect($http->requests[0]['url'], 'https://btcpay.test/api/v1/stores/store/offerings/off/subscribers/customer/suspend', 'Suspend route');
	expect($http->requests[1]['method'], 'POST', 'Unsuspend method');
	expect($http->requests[2]['url'], 'https://btcpay.test/api/v1/subscriber-portal', 'Portal route');
	expect(json_decode($http->requests[2]['body'], true)['durationMinutes'], 10080, 'Portal duration');
};

$tests['portal reminders send once and retain no reusable access URL'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$http = $gateway->http;
	$http->responses = [[200, ['url' => 'https://btcpay.test/subscriber-portal/ps_test', 'expiration' => time() + 604800]]];
	$email = new SubscriptionPortalEmail(new GreenfieldApiHelper(), new Subscriptions('https://btcpay.test', 'test-key', $http));
	$event = (object) ['type' => 'PaymentReminder', 'subscriber' => $subscriber];
	expect($email->maybeSendForWebhook($subscription, $subscriber, $event, []), true, 'Reminder sent');
	expect($email->maybeSendForWebhook($subscription, $subscriber, $event, []), false, 'Duplicate suppressed');
	expect($GLOBALS['test_woocommerce']->sent, 1, 'Only one email');
	expect(count($http->requests), 1, 'Only one portal session');
	expect($subscription->get_meta('BTCPay_subscription_portal_url_payment_reminder'), '', 'No access URL persisted');
};
$tests['failed portal mail remains retryable'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$gateway->http->responses = [[200, ['url' => 'https://btcpay.test/subscriber-portal/ps_test', 'expiration' => time() + 604800]]];
	$GLOBALS['test_woocommerce']->sendResult = false;
	$event = (object) ['type' => 'PaymentReminder', 'subscriber' => $subscriber];
	expectThrows(fn() => $gateway->call('maybeSendSubscriptionPortalEmailForWebhook', $subscription, $subscriber, $event), 'Mail failure must propagate to the webhook handler');
	expect($subscription->get_meta('BTCPay_subscription_portal_email_payment_reminder'), '', 'Failed delivery not deduplicated');
};
$tests['old-period and cancelled reminders do not create portal sessions'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$email = new SubscriptionPortalEmail(new GreenfieldApiHelper(), new Subscriptions('https://btcpay.test', 'test-key', $gateway->http));
	$old = clone $subscriber;
	$old->periodEnd -= 86400;
	expect($email->maybeSendForWebhook($subscription, $subscriber, (object) ['type' => 'PaymentReminder', 'subscriber' => $old], []), false, 'Old reminder ignored');
	$subscription->status = 'cancelled';
	expect($email->maybeSendForWebhook($subscription, $subscriber, (object) ['type' => 'PaymentReminder', 'subscriber' => $subscriber], []), false, 'Cancelled reminder ignored');
	expect(count($gateway->http->requests), 0, 'No portal sessions');
};
$tests['duplicate concurrent processing exits before calling BTCPay'] = function () {
	[$gateway, $order] = fixture();
	$GLOBALS['wpdb']->locked = true;
	expectThrows(fn() => $gateway->call('processSubscriptionPayment', $order, true), 'Contended lock must stop duplicate checkout');
	expect(count($gateway->http->requests), 0, 'No request during concurrent processing');
};

$tests['edited plan prices cannot silently record a different renewal amount'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$subscriber->plan->price = '20.00';
	expectThrows(fn() => $gateway->call('reconcileWooSubscriptionFromBtcpaySubscriber', $subscription, $subscriber, 'test'), 'Changed recurring price must be reconciled');
	expect($order->paid, false, 'No payment recorded at the wrong amount');
};
$tests['unsuspending an expired subscriber does not grant a paid period'] = function () {
	[$gateway, $order, $subscription, $plan, $subscriber] = fixture();
	$subscription->status = 'active';
	$subscription->meta['BTCPay_subscriber_id'] = 'customer';
	$subscriber->isActive = false;
	$subscriber->phase = 'Expired';
	$gateway->http->responses = [[200, $subscriber]];
	$gateway->call('unsuspendBtcpaySubscriberForSubscription', $subscription);
	expect($subscription->status, 'expired', 'Credit is still needed');
};

$tests['scheduled expiration defers when the subscriber cannot be verified'] = function () {
	[$gateway, $order, $subscription] = fixture();
	$subscription->meta['BTCPay_subscriber_id'] = 'customer';
	$subscription->status = 'active';
	$gateway->http->responses = [[503, ['code' => 'unavailable', 'message' => 'Retry']]];
	expectThrows(fn() => $gateway->call('process_scheduled_subscription_expiration', 2), 'API failure must stop automatic expiration');
	expect($subscription->status, 'active', 'Subscription must retain its status during an API outage');
};

$count = 0;
foreach ($tests as $name => $test) {
	try { $test(); ++$count; echo "PASS $name\n"; }
	catch (Throwable $e) { fwrite(STDERR, "FAIL $name: {$e->getMessage()}\n{$e->getTraceAsString()}\n"); exit(1); }
}
echo "$count subscription regression tests passed.\n";
