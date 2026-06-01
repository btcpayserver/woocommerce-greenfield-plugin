# BTCPay Greenfield for WooCommerce - Subscription Support

## Overview

Subscription support maps WooCommerce subscription products to existing BTCPay subscription offerings/plans, sends customers through BTCPay plan checkout, and keeps WooCommerce subscription state in sync from BTCPay subscriber webhooks and WooCommerce status changes.

BTCPay remains the source of truth for subscriber periods, credit balance, reminders, and subscription phase changes. WooCommerce records the order/subscription state, mirrors admin cancellation/suspension to BTCPay, and sends subscriber-facing reminder/recovery emails when a BTCPay webhook requires a portal link.

## Supported Flows

### Product Mapping

Merchants configure mappings under WooCommerce > Settings > BTCPay Settings > Subscription Products:

- BTCPay offerings/plans are loaded from the Greenfield Subscriptions API.
- WooCommerce subscription products can be mapped one-to-one to BTCPay plans.
- The mapping UI warns when the WooCommerce product and BTCPay plan differ on currency, price, billing period/interval, or trial length.
- One WooCommerce subscription product can only be mapped once.

### Customer Checkout

When an order contains a mapped subscription product, the gateway:

- Resolves the BTCPay offering/plan from order/subscription metadata or the saved product mapping.
- Creates a BTCPay plan checkout.
- Reuses an existing non-expired, not-yet-started plan checkout for the order instead of creating duplicates.
- Stores BTCPay metadata on the order and subscription.
- Redirects the customer to BTCPay checkout.

BTCPay subscriptions currently use redirect checkout. If modal checkout is enabled and subscription mappings exist, the admin settings page shows a warning. Regular non-subscription purchases can still use modal checkout.

### Webhooks and Renewal Recording

The registered webhook includes invoice events plus BTCPay subscription events:

- `SubscriberCreated`
- `SubscriberCredited`
- `SubscriberCharged`
- `SubscriberActivated`
- `SubscriberPhaseChanged`
- `SubscriberDisabled`
- `PaymentReminder`
- `PlanStarted`
- `SubscriberNeedUpgrade`

Subscription webhooks locate the WooCommerce subscription through BTCPay subscriber metadata, order metadata, or stored subscriber id. The gateway stores subscriber metadata, updates WooCommerce next payment dates from BTCPay period/trial/grace dates, and records WooCommerce renewal orders when BTCPay advances the subscriber period.

`PaymentReminder` is treated as a pre-renewal signal from BTCPay. BTCPay only sends it when the subscriber is missing credit for the upcoming renewal, so WooCommerce should not generate a separate checkout for that case. Instead, WooCommerce creates a subscriber portal session where the subscriber can add credit to the existing BTCPay subscription.

### Portal Reminder Emails

BTCPay subscription email rules do not currently expose a built-in subscriber portal URL placeholder. For WooCommerce-backed subscriptions, BTCPay email rules for subscription reminders should be disabled and the webhook events should remain enabled.

Operational setup:

- Keep the BTCPay Greenfield webhook enabled for subscription events.
- Disable BTCPay subscription email rules that would email the subscriber directly, especially `PaymentReminder`.
- Let the WooCommerce plugin send the subscriber-facing email because it can create a fresh portal session and include the returned URL.

The gateway sends WooCommerce-styled emails with a fresh BTCPay subscriber portal link for:

- `PaymentReminder`
- `SubscriberNeedUpgrade`
- `SubscriberDisabled` when the BTCPay reason is `Expired`

The gateway does not send a portal payment email for `SubscriberDisabled` with reason `Suspension`, because that usually reflects admin/customer cancellation or manual suspension rather than a recoverable payment issue.

For each email, the gateway creates a new BTCPay subscriber portal session through `POST /api/v1/subscriber-portal`. The request uses:

- `storeId` from the configured BTCPay store.
- `offeringId` from the subscriber payload or stored subscription metadata.
- `customerSelector` from the BTCPay customer id when available, falling back to the stored selector.
- `durationMinutes` defaulting to `10080` minutes, which is seven days.

The email includes the returned portal URL so the subscriber can add credit or recover the subscription. The recipient is the WooCommerce subscription billing email, with a fallback to the BTCPay subscriber customer identity `Email`/`email`.

The portal email content is intentionally minimal:

- The email explains why action is needed.
- It shows the current BTCPay subscription date when BTCPay provides `periodEnd`, `trialEnd`, or `gracePeriodEnd`.
- It includes a button and plaintext fallback link for the subscriber portal URL.

Available filters:

- `btcpay_gf_subscription_portal_session_duration_minutes` changes the portal session lifetime. Arguments: default minutes, subscriber payload, subscriber control data.
- `btcpay_gf_subscription_portal_email_subject` changes the email subject. Arguments: subject, context, Woo subscription, subscriber payload, portal URL.
- `btcpay_gf_subscription_portal_email_body` changes the email HTML body before WooCommerce wraps/styles it. Arguments: body, context, Woo subscription, subscriber payload, portal URL.

Duplicate webhook deliveries are guarded per subscription, event type, phase/reason, and BTCPay period/trial/grace date.

The duplicate guard stores one key per email kind:

- `payment_reminder`
- `need_upgrade`
- `expired`

### Expiration Handling

WooCommerce remains responsible for scheduled expiration checks. Before WooCommerce expires a BTCPay-backed subscription, the gateway refreshes the BTCPay subscriber:

- If BTCPay reports the subscriber is active with a future period end, WooCommerce expiry is skipped and the date is updated.
- If BTCPay reports the subscriber is suspended, WooCommerce is moved to on-hold.
- If BTCPay reports the subscriber is expired or no active BTCPay state is found, WooCommerce can expire the subscription.

When BTCPay emits `SubscriberDisabled` with reason `Expired`, the gateway also sends the expired subscription portal email described above. This is a recovery email, not a new WooCommerce checkout. The subscriber should add credit through the BTCPay portal.

### Cancellation, Suspension, and Reactivation

WooCommerce status changes are mirrored to BTCPay:

- WooCommerce `on-hold` suspends the BTCPay subscriber.
- WooCommerce `cancelled` suspends the BTCPay subscriber.
- WooCommerce `expired` reconciles with BTCPay first, then suspends when no active BTCPay renewal exists.
- WooCommerce reactivation from on-hold/cancelled/expired unsuspends the BTCPay subscriber.

The gateway intentionally does not delete BTCPay subscribers when WooCommerce cancels or expires a subscription.

### Payment Method Changes

Changing a subscription away from or through BTCPay is not declared as supported. BTCPay subscriptions are tied to BTCPay subscriber state, so the gateway supports subscription cancellation, suspension, and reactivation, but not amount changes, date changes, payment method changes, or WooCommerce gateway-scheduled renewal payments.

## Stored Metadata

Orders and subscriptions may store:

- `BTCPay_offering_id`
- `BTCPay_plan_id`
- `BTCPay_plan_checkout_id`
- `BTCPay_id`
- `BTCPay_subscriber_id`
- `BTCPay_subscriber_periodEnd`
- `BTCPay_subscriber_trialEnd`
- `BTCPay_subscriber_gracePeriodEnd`
- `BTCPay_subscriber_phase`
- `BTCPay_subscriber_isActive`
- `BTCPay_subscriber_isSuspended`
- `BTCPay_last_renewal_period_end`
- `BTCPay_subscription_portal_email_*`
- `BTCPay_subscription_portal_url_*`
- `BTCPay_subscription_portal_expires_*`

Portal email metadata is stored on the WooCommerce subscription for diagnostics and duplicate protection. The latest portal URL is not intended to be permanent because BTCPay portal sessions expire.

## Testing Notes

For end-to-end reminder testing:

- Disable BTCPay subscription email rules for the relevant subscription events.
- Confirm the WooCommerce BTCPay webhook includes `PaymentReminder`, `SubscriberDisabled`, and `SubscriberNeedUpgrade`.
- Use a test subscriber with insufficient credit before renewal.
- Trigger BTCPay's reminder timing, for example with BTCPay's subscription test-account time controls when available.
- Confirm the WooCommerce subscription receives an order note saying the portal email was sent.
- Confirm the received email contains a fresh `/subscriber-portal/ps_...` URL.
- Redeliver the same webhook and confirm a duplicate email is not sent for the same event/date key.

For expiry testing:

- Let or move the BTCPay subscriber to expired.
- Confirm WooCommerce moves the subscription to expired through the webhook/expiry reconciliation.
- Confirm the expired subscription email contains a fresh portal URL.
- Confirm admin/customer suspension does not send a portal recovery email.

## Current Constraints

- Only one mapped BTCPay subscription product is supported per order.
- Variable subscription products are not supported by the current mapping UI.
- The plugin sends simple WooCommerce-styled portal emails directly through the WooCommerce mailer. It does not currently register a separate configurable WooCommerce email class.
- End-to-end behavior should be verified against a live BTCPay Server subscription app and WooCommerce Subscriptions install because renewal timing depends on BTCPay webhook timing and WooCommerce scheduled actions.
