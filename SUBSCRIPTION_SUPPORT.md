# Subscription support

This integration maps a WooCommerce Subscriptions product to an existing BTCPay offering and plan. BTCPay owns the billing periods and credit balance. WooCommerce records verified activations and renewals and sends portal reminders.

## Setup

1. Install WooCommerce Subscriptions and use the default BTCPay payment gateway.
2. Update BTCPay Server to a current security release. The API review below used 2.4.4.
3. Run `composer install` for a source checkout. The minimum Greenfield PHP client is **2.9.1**; 2.8.1 has no subscription client.
4. Regenerate the API key with the normal checkout permissions plus `btcpay.store.canviewofferings` and `btcpay.store.canmanagesubscribers`, scoped to the selected store. The integration does not need permission to modify offerings or grant subscriber credit.
5. Save the global BTCPay settings. Automatically managed webhooks are extended to include the subscription events. For manually managed webhooks, add the events listed below yourself.
6. Under **WooCommerce → Settings → BTCPay Settings → Subscription Products**, map the product to an existing plan.
7. Disable duplicate BTCPay subscription reminder email rules if WooCommerce will send those emails. Leave webhook delivery and automatic redelivery enabled.

## Supported purchases

- Exactly one simple subscription product, quantity one, using the default BTCPay gateway. Variable subscriptions and mixed carts are rejected.
- An active, renewable BTCPay plan with an ongoing monthly, quarterly or yearly schedule that matches WooCommerce.
- Matching currency, initial order total and recurring subscription total. Tax, shipping, signup fees and discounts must not make WooCommerce charge a different amount from the BTCPay plan.
- Matching trials expressed in days or weeks. The first paid period after a trial creates a renewal order.
- Redirect checkout for both classic and Blocks checkout, including when modal mode is enabled. Ordinary purchases retain modal checkout.

Amount changes, payment method changes, finite subscriptions, lifetime plans and separate BTCPay gateways are not supported for subscription purchases. Configure plans before taking orders; changing a plan or the connected BTCPay store requires manual reconciliation of existing subscriptions.

## Code layout

- `src/Gateway/SubscriptionGateway.php`: checkout, identity verification, lifecycle synchronization and renewal recording. The main gateway uses this trait and dispatches subscription events to it.
- `src/Helper/SubscriptionPortalEmail.php`: reminder content, expiring portal sessions and successful-delivery deduplication.
- `src/Helper/SubscriptionLock.php`: database advisory locks for concurrent checkout retries and webhook deliveries. Locks are released when the database connection closes, including after a worker failure.
- `src/Admin/GlobalSettings.php`: product mappings and configuration warnings.

## Checkout and webhook behavior

Checkout saves the store, offering, plan and checkout IDs on the WooCommerce objects. It reuses an existing checkout until expiry, including a checkout whose plan has already started. An API failure while reading that checkout stops the retry instead of creating another one. The return link uses the same temporary, customer-authorized `OrderReturn` flow as regular invoices; WooCommerce order keys are not sent to BTCPay.

Subscriptions created by the earlier `subs` implementation have no saved store ID. On their next webhook, status action or checkout retry, the integration reads the original saved plan checkout and verifies its store, offering, plan and customer against the local records before adding the missing binding. Renewal cursors and reminder deduplication keys are retained. Conflicting identities or checkouts without a verifiable subscriber require manual reconciliation; the webhook payload alone cannot establish the binding. Temporary checkout lookup failures remain retryable.

The registered subscription events are:

```
SubscriberCreated
SubscriberCredited
SubscriberCharged
SubscriberActivated
SubscriberPhaseChanged
SubscriberDisabled
PaymentReminder
PlanStarted
SubscriberNeedUpgrade
```

A webhook must pass signature verification and belong to the configured store. Subscriber metadata locates a candidate WooCommerce subscription; its saved store, offering, plan and customer must match. The first customer association is verified against the checkout created for that order. Billing email is not used as a fallback identity.

Before changing status, the integration reads the current subscriber from BTCPay. A delayed disabled event cannot expire an active subscriber. Failed verification, renewal creation and mail delivery return a retryable HTTP error. Subscription invoice events do not complete orders independently of plan activation, and unrelated credit top-ups are not assigned to the last renewal order.

The gateway declares `gateway_scheduled_payments`, which tells WooCommerce that BTCPay manages billing. WooCommerce must not independently create a renewal and suspend the subscriber. Only an advance of a normal paid period creates a paid renewal; trial and grace dates do not. Renewal records have a subscription/period key, and duplicate deliveries resume or reuse the existing record. A renewal funded by existing credit need not have a new invoice ID.

WooCommerce cancellation and suspension are mirrored to BTCPay, and explicit reactivation unsuspends the subscriber. Pending cancellation remains pending until WooCommerce ends the prepaid term. Delayed webhooks do not reactivate cancelled subscriptions. API failures during a manual status change are recorded in subscription notes and require retrying the action after connectivity is restored.

## Portal reminders

WooCommerce creates a fresh portal session for `PaymentReminder`, `SubscriberNeedUpgrade`, and an expired `SubscriberDisabled` event. It ignores cancelled/suspended subscriptions, reminders for an older period and expired events when the subscriber has recovered.

The portal lifetime defaults to seven days. Successful delivery is deduplicated by event, reason, phase and period date. The subscription stores the deduplication key and portal expiration, but does not retain the portal access URL.

Filters:

- `btcpay_gf_subscription_portal_session_duration_minutes`: duration, subscriber payload, subscriber control data.
- `btcpay_gf_subscription_portal_email_subject`: subject, context, Woo subscription, subscriber payload, portal URL.
- `btcpay_gf_subscription_portal_email_body`: HTML body, context, Woo subscription, subscriber payload, portal URL.

These are WooCommerce-styled emails sent through its mailer, without a separate configurable WooCommerce email class.

With debug logging enabled, mail success and failure include the event, subscription ID and reminder type. Logs do not include recipient addresses or portal access URLs.

## API review — 2026-09-30

The [current subscription controller](https://github.com/btcpayserver/btcpayserver/blob/ae3abbb/BTCPayServer/Plugins/Subscriptions/Controllers/GreenfieldOfferingController.cs) retains the routes used here:

| Operation | Route | Permission |
| --- | --- | --- |
| Read offerings/plans | `GET /api/v1/stores/{storeId}/offerings[/…]` | View offerings |
| Read subscriber | `GET /api/v1/stores/{storeId}/offerings/{offeringId}/subscribers/{customerSelector}` | View offerings |
| Suspend/reactivate | `POST …/subscribers/{customerSelector}/suspend` or `/unsuspend` | View offerings + manage subscribers |
| Create plan checkout | `POST /api/v1/plan-checkout` | View offerings + manage subscribers for the store |
| Read plan checkout | `GET /api/v1/plan-checkout/{checkoutId}` | Checkout identifier |
| Create portal session | `POST /api/v1/subscriber-portal` | Manage subscribers for the store |

The [PHP client 2.9.1](https://github.com/btcpayserver/btcpayserver-greenfield-php/releases/tag/v2.9.1) implements these requests. No additional PHP library patch was identified for the routes used by this integration. The required upgrade is from the previously installed 2.8.1 to at least 2.9.1.

The [2.4.2 security release](https://github.com/btcpayserver/btcpayserver/releases/tag/v2.4.2) restricts Basic authentication; the PHP client uses `Authorization: token …`. The master branch's POST-based API-key callback and protected order return handling are retained. Updating this plugin or its PHP dependency does not replace updating BTCPay Server itself.

Read-only checks against the configured test host returned server version **2.4.4**, successful API-key introspection, invoice listing, invoice details, invoice payment methods and webhook listing, and **403** for offerings. Its current key lacks the two subscription permissions. WooCommerce Subscriptions was not installed in the local WordPress instance, so authenticated subscription mutations and a complete purchase/renewal cycle were not exercised there.

## Validation

Run the isolated regression suite and build:

```sh
composer install
composer test
npm ci
npm run build
```

The suite uses the real PHP client with an in-memory HTTP transport and minimal WooCommerce doubles. It makes no network requests, sends no mail and writes no database records. It covers request payloads, protected return links, supported baskets, checkout reuse, association checks, current-state verification, renewal/trial/grace handling, duplicate deliveries and reminder failures. The 24 regression tests pass on PHP 8.3 and 8.4. The JavaScript and translation build, PHP syntax checks and Composer security audit pass; the audit reports no known advisories. A local WordPress smoke check confirms that ordinary checkout still exposes only products/refunds without WooCommerce Subscriptions. A real two-connection database check confirms lock contention and release. These tests do not replace WooCommerce Subscriptions integration testing.

Before merging, test with WooCommerce Subscriptions and a disposable BTCPay store:

1. Purchase a matching plan through classic and Blocks checkout, with modal mode on and off. Repeat checkout and verify it reuses the plan checkout.
2. Complete the payment and verify one paid parent order, the expected subscriber association and the next payment date. Repeat with a trial.
3. Add credit through a reminder portal link, advance the billing period and verify exactly one renewal order. Redeliver the events, including a delayed disabled event.
4. Simulate insufficient credit, grace and expiry. Verify that grace creates no paid renewal, recovery sends the correct portal link and suspension sends no recovery email.
5. Cancel, suspend and reactivate from WooCommerce. Verify BTCPay state and the end of a pending cancellation.
6. Repeat with HPOS enabled and disabled. Simulate an API or mail failure and verify redelivery recovers without duplicate renewals.

Keep webhooks enabled: after a prolonged delivery outage, current subscriber state alone cannot reconstruct every historical credit-funded billing period. Reconcile missing historical renewals manually.
