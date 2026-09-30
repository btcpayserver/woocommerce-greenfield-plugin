<?php

declare( strict_types=1 );

namespace BTCPayServer\WC\Gateway;

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\Subscriptions;
use BTCPayServer\WC\Helper\Logger;
use BTCPayServer\WC\Helper\OrderReturn;
use BTCPayServer\WC\Helper\SubscriptionPortalEmail;
use BTCPayServer\WC\Helper\SubscriptionLock;

/**
 * BTCPay owns billing periods; WooCommerce mirrors its verified subscriber state.
 */
trait SubscriptionGateway {
	protected $syncingSubscriptionFromBtcpay = false;
	private bool $removedSubscriptionExpirationHandler = false;

	protected function subscriptionsClient(): Subscriptions {
		return new Subscriptions( $this->apiHelper->url, $this->apiHelper->apiKey );
	}

	protected function initSubscriptionSupport(): void {
		if ( class_exists( 'WC_Subscriptions' ) && $this->getId() === 'btcpaygf_default' ) {
			$this->supports = array_merge(
				$this->supports,
				[
					'subscriptions',
					'subscription_cancellation',
					'subscription_suspension',
					'subscription_reactivation',
					// Prevent WooCommerce from creating a second renewal and suspending BTCPay.
					'gateway_scheduled_payments',
				]
			);

			add_action(
				'woocommerce_subscription_status_updated',
				[ $this, 'process_subscription_status_update' ],
				10,
				3
			);

			add_action(
				'woocommerce_scheduled_subscription_expiration',
				[ $this, 'process_scheduled_subscription_expiration' ],
				1,
				1
			);

			add_action(
				'woocommerce_scheduled_subscription_expiration',
				[ $this, 'restore_wc_subscription_expiration_handler' ],
				11,
				1
			);

			add_filter(
				'woocommerce_can_subscription_be_updated_to_new-payment-method',
				[ $this, 'prevent_subscription_payment_method_change' ],
				10,
				2
			);
		}
	}

	/**
	 * Mirror WooCommerce subscription status changes to the BTCPay subscriber.
	 */
	public function process_subscription_status_update( $subscription, string $new_status, string $old_status ): void {
		if ( ! $subscription instanceof \WC_Subscription ) {
			return;
		}

		if ( $this->syncingSubscriptionFromBtcpay || $new_status === $old_status || ! $this->subscriptionUsesThisGateway( $subscription ) ) {
			return;
		}

		switch ( $new_status ) {
			case 'on-hold':
				$this->suspendBtcpaySubscriberForSubscription(
					$subscription,
					__( 'WooCommerce subscription was suspended.', 'btcpay-greenfield-for-woocommerce' )
				);
				break;
			case 'pending-cancel':
				Logger::debug( __METHOD__ . ': WooCommerce subscription is pending cancellation; leaving BTCPay subscriber unchanged. Subscription ID: ' . $subscription->get_id() );
				break;
			case 'cancelled':
				$this->suspendBtcpaySubscriberForSubscription(
					$subscription,
					__( 'WooCommerce subscription was cancelled.', 'btcpay-greenfield-for-woocommerce' )
				);
				break;
			case 'expired':
				if ( $old_status === 'pending-cancel' ) {
					$this->suspendBtcpaySubscriberForSubscription( $subscription, 'WooCommerce cancellation period ended.' );
					break;
				}
				if ( $this->reconcileSubscriptionWithBtcpaySubscriber( $subscription, __( 'WooCommerce expiry check', 'btcpay-greenfield-for-woocommerce' ) ) ) {
					break;
				}

				$this->suspendBtcpaySubscriberForSubscription(
					$subscription,
					__( 'WooCommerce subscription expired.', 'btcpay-greenfield-for-woocommerce' )
				);
				break;
			case 'active':
				if ( in_array( $old_status, [ 'on-hold', 'pending-cancel', 'cancelled', 'expired' ], true ) ) {
					$this->unsuspendBtcpaySubscriberForSubscription( $subscription );
				}
				break;
		}
	}

	/**
	 * Let BTCPay's subscriber state win if Woo's local expiration job is stale.
	 */
	public function process_scheduled_subscription_expiration( $subscription_id ): void {
		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;
		if ( ! $subscription instanceof \WC_Subscription || ! $this->subscriptionUsesThisGateway( $subscription ) ) {
			return;
		}

		$subscriber = $this->getFreshBtcpaySubscriberForSubscription( $subscription );
		if ( ! $subscriber ) {
			// A temporary API outage is not evidence that a paid subscription expired.
			throw new \RuntimeException( 'Could not verify BTCPay subscriber before scheduled expiration.' );
		}

		$this->storeBtcpaySubscriberMetadata( $subscription, $subscriber );

		$periodEnd = $this->getBtcpaySubscriberExpirationTimestamp( $subscriber );
		if ( ! $subscription->has_status( [ 'pending-cancel', 'cancelled' ] ) && $this->btcpaySubscriberIsActive( $subscriber ) && $periodEnd && $periodEnd > time() && class_exists( 'WC_Subscriptions_Manager' ) ) {
			$this->removedSubscriptionExpirationHandler = remove_action(
				'woocommerce_scheduled_subscription_expiration',
				[ 'WC_Subscriptions_Manager', 'expire_subscription' ],
				10
			);
			$this->addSubscriptionNote(
				$subscription,
				__( 'Skipped WooCommerce scheduled expiration because BTCPay reports the subscriber is active.', 'btcpay-greenfield-for-woocommerce' )
			);
		}
	}

	public function restore_wc_subscription_expiration_handler( $subscription_id = null ): void {
		if ( ! $this->removedSubscriptionExpirationHandler || ! class_exists( 'WC_Subscriptions_Manager' ) ) {
			return;
		}

		$this->removedSubscriptionExpirationHandler = false;
		$handler = [ 'WC_Subscriptions_Manager', 'expire_subscription' ];
		if ( ! has_action( 'woocommerce_scheduled_subscription_expiration', $handler ) ) {
			add_action(
				'woocommerce_scheduled_subscription_expiration',
				$handler,
				10,
				1
			);
		}
	}

	public function prevent_subscription_payment_method_change( $canBeUpdated, $subscription ) {
		if ( $subscription instanceof \WC_Subscription && $this->subscriptionUsesThisGateway( $subscription ) ) {
			return false;
		}

		return $canBeUpdated;
	}

	protected function subscriptionUsesThisGateway( \WC_Subscription $subscription ): bool {
		return $subscription->get_payment_method() === $this->getId();
	}

	protected function suspendBtcpaySubscriberForSubscription( \WC_Subscription $subscription, string $reason ): void {
		$subscriberData = $this->getBtcpaySubscriberControlData( $subscription );
		if ( empty( $subscriberData['offering_id'] ) || empty( $subscriberData['customer_selector'] ) ) {
			$message = __( 'Could not suspend BTCPay subscriber because offering or subscriber metadata is missing.', 'btcpay-greenfield-for-woocommerce' );
			Logger::debug( __METHOD__ . ': ' . $message . ' Subscription ID: ' . $subscription->get_id() );
			$this->addSubscriptionNote( $subscription, $message );
			return;
		}

		try {
			$client = $this->subscriptionsClient();
			$client->suspendSubscriber(
				$this->apiHelper->storeId,
				$subscriberData['offering_id'],
				$subscriberData['customer_selector'],
				$reason
			);

			$this->addSubscriptionNote(
				$subscription,
				__( 'BTCPay subscriber suspended.', 'btcpay-greenfield-for-woocommerce' )
			);
		} catch ( \Throwable $e ) {
			$message = sprintf(
				/* translators: %s: API error message. */
				__( 'Failed to suspend BTCPay subscriber: %s', 'btcpay-greenfield-for-woocommerce' ),
				$e->getMessage()
			);
			Logger::debug( __METHOD__ . ': ' . $message );
			$this->addSubscriptionNote( $subscription, $message );
		}
	}

	protected function unsuspendBtcpaySubscriberForSubscription( \WC_Subscription $subscription ): void {
		$subscriberData = $this->getBtcpaySubscriberControlData( $subscription );
		if ( empty( $subscriberData['offering_id'] ) || empty( $subscriberData['customer_selector'] ) ) {
			$message = __( 'Could not reactivate BTCPay subscriber because offering or subscriber metadata is missing.', 'btcpay-greenfield-for-woocommerce' );
			Logger::debug( __METHOD__ . ': ' . $message . ' Subscription ID: ' . $subscription->get_id() );
			$this->addSubscriptionNote( $subscription, $message );
			return;
		}

		try {
			$client = $this->subscriptionsClient();
			$result = $client->unsuspendSubscriber(
				$this->apiHelper->storeId,
				$subscriberData['offering_id'],
				$subscriberData['customer_selector']
			);

			$subscriber = json_decode( json_encode( $result->getData(), JSON_THROW_ON_ERROR ), false, 512, JSON_THROW_ON_ERROR );
			if ( ! $this->btcpaySubscriberIsActive( $subscriber ) ) {
				// Removing a suspension does not buy a new billing period.
				$this->updateWooSubscriptionStatusFromBtcpay(
					$subscription,
					( $subscriber->phase ?? '' ) === 'Expired' ? 'expired' : 'on-hold',
					__( 'BTCPay subscriber is not active yet. Add credit through the subscription portal.', 'btcpay-greenfield-for-woocommerce' )
				);
			}

			$this->addSubscriptionNote(
				$subscription,
				__( 'BTCPay subscriber unsuspended.', 'btcpay-greenfield-for-woocommerce' )
			);
		} catch ( \Throwable $e ) {
			$message = sprintf(
				/* translators: %s: API error message. */
				__( 'Failed to unsuspend BTCPay subscriber: %s', 'btcpay-greenfield-for-woocommerce' ),
				$e->getMessage()
			);
			Logger::debug( __METHOD__ . ': ' . $message );
			$this->addSubscriptionNote( $subscription, $message );
		}
	}

	protected function getBtcpaySubscriberControlData( \WC_Subscription $subscription ): array {
		try {
			if ( ! $this->ensureBtcpaySubscriptionStore( $subscription ) ) {
				return [];
			}
		} catch ( \Throwable $e ) {
			Logger::debug( __METHOD__ . ': failed to verify legacy subscription checkout: ' . $e->getMessage() );
			return [];
		}
		return [
			'offering_id'       => $this->getBtcpaySubscriptionOfferingId( $subscription ),
			'customer_selector' => $this->getBtcpaySubscriptionCustomerSelector( $subscription ),
		];
	}

	/** Recover pre-store-binding subscriptions only through their saved checkout. */
	protected function ensureBtcpaySubscriptionStore( \WC_Subscription $subscription, ?\BTCPayServer\Result\PlanCheckout $checkout = null ): bool {
		$storeId = (string) $this->apiHelper->storeId;
		$savedStoreId = (string) $subscription->get_meta( 'BTCPay_store_id' );
		if ( $storeId === '' ) {
			return false;
		}
		if ( $savedStoreId !== '' && ( $savedStoreId !== $storeId || $checkout === null ) ) {
			return $savedStoreId === $storeId;
		}
		$order = wc_get_order( $subscription->get_parent_id() );
		if ( ! $order || ! $this->subscriptionUsesThisGateway( $subscription ) || $order->get_payment_method() !== $subscription->get_payment_method() ) {
			return false;
		}
		$checkoutId = (string) $order->get_meta( 'BTCPay_plan_checkout_id' );
		$orderStoreId = (string) $order->get_meta( 'BTCPay_store_id' );
		if ( $checkoutId === '' || ( $orderStoreId !== '' && $orderStoreId !== $storeId ) ) {
			return false;
		}
		foreach ( [ 'BTCPay_offering_id', 'BTCPay_plan_id' ] as $key ) {
			if ( $subscription->get_meta( $key ) === '' || $subscription->get_meta( $key ) !== $order->get_meta( $key ) ) {
				return false;
			}
		}

		// Use the API response to the locally saved checkout ID, never the webhook payload.
		// Let lookup failures propagate so webhook deliveries can be retried.
		$checkout = $checkout ?? $this->subscriptionsClient()->getPlanCheckout( $checkoutId );
		$subscriber = $checkout->getSubscriber();
		$data = $subscriber ? $subscriber->getData() : [];
		$customerId = (string) ( $data['customer']['id'] ?? '' );
		if ( $checkout->getId() !== $checkoutId || $customerId === ''
			|| ( $data['offering']['storeId'] ?? null ) !== $storeId
			|| ( $data['offering']['id'] ?? null ) !== $subscription->get_meta( 'BTCPay_offering_id' )
			|| ( $data['plan']['id'] ?? null ) !== $subscription->get_meta( 'BTCPay_plan_id' ) ) {
			return false;
		}
		foreach ( [ $order, $subscription ] as $object ) {
			$knownId = (string) $object->get_meta( 'BTCPay_subscriber_id' );
			if ( $knownId !== '' && ! hash_equals( $knownId, $customerId ) ) {
				return false;
			}
		}
		foreach ( [ $order, $subscription ] as $object ) {
			$object->update_meta_data( 'BTCPay_store_id', $storeId );
			$object->update_meta_data( 'BTCPay_subscriber_id', $customerId );
			$object->save();
		}
		Logger::debug( __METHOD__ . ': verified legacy subscription store binding. Subscription ID: ' . $subscription->get_id() );
		return true;
	}

	protected function getBtcpaySubscriptionOfferingId( \WC_Subscription $subscription ): ?string {
		$id = (string) $subscription->get_meta( 'BTCPay_offering_id' );
		return $id !== '' ? $id : null;
	}

	protected function getBtcpaySubscriptionCustomerSelector( \WC_Subscription $subscription ): ?string {
		$id = (string) $subscription->get_meta( 'BTCPay_subscriber_id' );
		return $id !== '' ? $id : null;
	}

	protected function addSubscriptionNote( \WC_Subscription $subscription, string $message ): void {
		if ( method_exists( $subscription, 'add_order_note' ) ) {
			$subscription->add_order_note( $message );
		}
	}

	protected function isSubscriptionWebhookEvent( string $eventType ): bool {
		return in_array(
			$eventType,
			[
				'SubscriberCreated',
				'SubscriberCredited',
				'SubscriberCharged',
				'SubscriberActivated',
				'SubscriberPhaseChanged',
				'SubscriberDisabled',
				'PaymentReminder',
				'PlanStarted',
				'SubscriberNeedUpgrade',
			],
			true
		);
	}

	protected function processSubscriptionWebhook( \stdClass $webhookData ): void {
		if ( ( $webhookData->storeId ?? null ) !== $this->apiHelper->storeId || ! ( ( $webhookData->subscriber ?? null ) instanceof \stdClass ) ) {
			return;
		}
		$subscription = $this->getWooSubscriptionFromBtcpaySubscriberPayload( $webhookData->subscriber );
		if ( ! $subscription ) {
			return;
		}
		// All BTCPay webhooks arrive at the default gateway, including separate gateways.
		if ( ! $this->subscriptionUsesThisGateway( $subscription ) ) {
			$gateway = WC()->payment_gateways()->payment_gateways()[ $subscription->get_payment_method() ] ?? null;
			if ( $gateway instanceof AbstractGateway && $gateway !== $this ) {
				$gateway->processSubscriptionWebhook( $webhookData );
			}
			return;
		}
		$lock = SubscriptionLock::acquire( 'subscription:' . $subscription->get_id() );
		try {
			$subscription->read_meta_data( true );
			if ( ! $this->subscriberMatchesSubscription( $subscription, $webhookData->subscriber ) ) {
				return;
			}
			// A signed but delayed event must never overwrite newer server state.
			$subscriber = $this->getFreshBtcpaySubscriberForSubscription( $subscription );
			if ( ! $subscriber ) {
				throw new \RuntimeException( 'Could not verify the BTCPay subscriber. Retry the webhook.' );
			}
			if ( ! $this->subscriberMatchesSubscription( $subscription, $subscriber ) ) {
				throw new \RuntimeException( 'The current BTCPay subscriber no longer matches the WooCommerce subscription.' );
			}
			$this->reconcileWooSubscriptionFromBtcpaySubscriber( $subscription, $subscriber, $webhookData->type );
			$this->maybeSendSubscriptionPortalEmailForWebhook( $subscription, $subscriber, $webhookData );
		} finally {
			SubscriptionLock::release( $lock );
		}
	}

	protected function subscriberMatchesSubscription( \WC_Subscription $subscription, \stdClass $subscriber ): bool {
		if ( ! $this->ensureBtcpaySubscriptionStore( $subscription )
			|| ( $subscriber->offering->storeId ?? null ) !== $this->apiHelper->storeId
			|| ( $subscriber->offering->id ?? null ) !== $subscription->get_meta( 'BTCPay_offering_id' )
			|| ( $subscriber->plan->id ?? null ) !== $subscription->get_meta( 'BTCPay_plan_id' ) ) {
			return false;
		}
		$customerId = (string) ( $subscriber->customer->id ?? '' );
		$knownId = (string) $subscription->get_meta( 'BTCPay_subscriber_id' );
		if ( $knownId !== '' ) {
			return $customerId !== '' && hash_equals( $knownId, $customerId );
		}
		$order = wc_get_order( $subscription->get_parent_id() );
		if ( ! $order || ! $order->get_meta( 'BTCPay_plan_checkout_id' ) ) {
			return false;
		}
		$checkout = $this->getPlanCheckoutForOrder( $order );
		if ( ! $checkout ) {
			throw new \RuntimeException( 'Could not verify the original BTCPay plan checkout.' );
		}
		$checkoutSubscriber = $checkout->getSubscriber();
		if ( ! $checkoutSubscriber || $customerId === '' || ( $checkoutSubscriber->getData()['customer']['id'] ?? null ) !== $customerId ) {
			return false;
		}
		$this->storePlanCheckoutSubscriberMetadata( $order, $subscription, $checkout );
		return true;
	}

	protected function maybeSendSubscriptionPortalEmailForWebhook( \WC_Subscription $subscription, \stdClass $subscriber, \stdClass $webhookData ): void {
		// Let exceptions reach the webhook handler so BTCPay can redeliver failed emails.
		$portalEmail = new SubscriptionPortalEmail( $this->apiHelper, $this->subscriptionsClient() );
		$portalEmail->maybeSendForWebhook( $subscription, $subscriber, $webhookData, $this->getBtcpaySubscriberControlData( $subscription ) );
	}

	protected function getWooSubscriptionFromBtcpaySubscriberPayload( \stdClass $subscriber ): ?\WC_Subscription {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}
		$metadata = $this->objectToArray( $subscriber->metadata ?? [] );
		$subscription = ! empty( $metadata['wc_subscription_id'] )
			? wcs_get_subscription( (int) $metadata['wc_subscription_id'] ) : null;
		if ( ! $subscription && ! empty( $metadata['wc_order_id'] ) ) {
			$order = wc_get_order( (int) $metadata['wc_order_id'] );
			$subscription = $order ? $this->getSubscriptionForOrder( $order ) : null;
		}
		// Metadata is only a lookup hint. The saved checkout is verified before use.
		return $subscription instanceof \WC_Subscription ? $subscription : null;
	}

	protected function getFreshBtcpaySubscriberForSubscription( \WC_Subscription $subscription ): ?\stdClass {
		$subscriberData = $this->getBtcpaySubscriberControlData( $subscription );
		if ( empty( $subscriberData['offering_id'] ) || empty( $subscriberData['customer_selector'] ) ) {
			return null;
		}

		try {
			$client = $this->subscriptionsClient();
			$subscriber = $client->getSubscriber(
				$this->apiHelper->storeId,
				$subscriberData['offering_id'],
				$subscriberData['customer_selector']
			);

			return json_decode( json_encode( $subscriber->getData(), JSON_THROW_ON_ERROR ), false, 512, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $e ) {
			Logger::debug( __METHOD__ . ': failed to refresh BTCPay subscriber: ' . $e->getMessage() );
			return null;
		}
	}

	protected function reconcileSubscriptionWithBtcpaySubscriber( \WC_Subscription $subscription, string $source ): bool {
		$subscriber = $this->getFreshBtcpaySubscriberForSubscription( $subscription );
		if ( ! $subscriber || ! $this->btcpaySubscriberIsActive( $subscriber ) ) {
			return false;
		}
		$this->storeBtcpaySubscriberMetadata( $subscription, $subscriber );
		$this->updateWooSubscriptionStatusFromBtcpay( $subscription, 'active', $source );
		return true;
	}

	protected function reconcileWooSubscriptionFromBtcpaySubscriber( \WC_Subscription $subscription, \stdClass $subscriber, string $source ): bool {
		$this->storeBtcpaySubscriberMetadata( $subscription, $subscriber );
		if ( $subscription->has_status( [ 'pending-cancel', 'cancelled' ] ) ) {
			Logger::debug(
				sprintf(
					'%s: skipped BTCPay subscriber status reconciliation because WooCommerce subscription is pending cancellation or cancelled. Source: %s. Subscription ID: %d.',
					__METHOD__,
					$source,
					$subscription->get_id()
				)
			);
			return true;
		}
		if ( $this->btcpaySubscriberIsActive( $subscriber ) ) {
			// Plan edits must not silently record renewals for a different amount or currency.
			if ( ! isset( $subscriber->plan->price, $subscriber->plan->currency )
				|| bccomp( (string) $subscription->get_total(), (string) $subscriber->plan->price, 8 ) !== 0
				|| strtoupper( $subscription->get_currency() ) !== strtoupper( (string) $subscriber->plan->currency ) ) {
				throw new \RuntimeException( 'The BTCPay plan price or currency changed. Reconcile the WooCommerce subscription before retrying.' );
			}
			$this->syncingSubscriptionFromBtcpay = true;
			try {
				$this->maybeCompleteInitialSubscriptionOrderFromBtcpaySubscriber( $subscription, $subscriber, $source );
				$this->maybeCreateBtcpayRenewalOrder( $subscription, $subscriber, $source );
				if ( ! $subscription->has_status( 'active' ) ) {
					$subscription->update_status( 'active', __( 'BTCPay subscription is active.', 'btcpay-greenfield-for-woocommerce' ) );
				}
				// WooCommerce payment callbacks may recalculate dates; apply BTCPay's date last.
				$periodEnd = $this->getBtcpaySubscriberExpirationTimestamp( $subscriber );
				if ( $periodEnd && $periodEnd > time() ) {
					$this->updateWooSubscriptionNextPaymentDate( $subscription, $periodEnd );
				}
			} finally {
				$this->syncingSubscriptionFromBtcpay = false;
			}
			return true;
		}
		if ( ! empty( $subscriber->isSuspended ) ) {
			$this->updateWooSubscriptionStatusFromBtcpay( $subscription, 'on-hold', __( 'BTCPay subscription is suspended.', 'btcpay-greenfield-for-woocommerce' ) );
			return true;
		}
		if ( ( $subscriber->phase ?? '' ) === 'Expired' ) {
			$this->updateWooSubscriptionStatusFromBtcpay( $subscription, 'expired', __( 'BTCPay subscription expired.', 'btcpay-greenfield-for-woocommerce' ) );
			return true;
		}
		return false;
	}

	protected function maybeCompleteInitialSubscriptionOrderFromBtcpaySubscriber( \WC_Subscription $subscription, \stdClass $subscriber, string $source ): void {
		$order = wc_get_order( $subscription->get_parent_id() );
		if ( ! $order instanceof \WC_Order || $order->get_payment_method() !== $this->getId() || $order->is_paid() ) {
			return;
		}
		$checkout = $this->getPlanCheckoutForOrder( $order );
		if ( ! $checkout || ! $checkout->isPlanStarted() ) {
			throw new \RuntimeException( 'The original BTCPay plan checkout has not started yet.' );
		}
		$this->storePlanCheckoutSubscriberMetadata( $order, $subscription, $checkout );
		$order->payment_complete( $checkout->getInvoiceId() ?? '' );
		if ( ! $order->is_paid() ) {
			throw new \RuntimeException( 'Could not complete the initial WooCommerce order.' );
		}
	}

	protected function updateWooSubscriptionStatusFromBtcpay( \WC_Subscription $subscription, string $status, string $message ): void {
		if ( $subscription->has_status( $status ) ) {
			return;
		}

		$this->syncingSubscriptionFromBtcpay = true;
		try {
			$subscription->update_status( $status, $message );
		} finally {
			$this->syncingSubscriptionFromBtcpay = false;
		}
	}

	protected function storeBtcpaySubscriberMetadata( \WC_Subscription $subscription, \stdClass $subscriber ): void {
		if ( ! empty( $subscriber->customer->id ) ) {
			$subscription->update_meta_data( 'BTCPay_subscriber_id', (string) $subscriber->customer->id );
		}

		if ( ! empty( $subscriber->offering->id ) ) {
			$subscription->update_meta_data( 'BTCPay_offering_id', (string) $subscriber->offering->id );
		}

		if ( ! empty( $subscriber->plan->id ) ) {
			$subscription->update_meta_data( 'BTCPay_plan_id', (string) $subscriber->plan->id );
		}

		foreach ( [ 'periodEnd', 'trialEnd', 'gracePeriodEnd', 'phase', 'isActive', 'isSuspended' ] as $field ) {
			if ( property_exists( $subscriber, $field ) ) {
				$subscription->update_meta_data( 'BTCPay_subscriber_' . $field, is_bool( $subscriber->{$field} ) ? (int) $subscriber->{$field} : $subscriber->{$field} );
			}
		}

		$subscription->save();
	}

	protected function updateWooSubscriptionNextPaymentDate( \WC_Subscription $subscription, int $timestamp ): void {
		if ( ! method_exists( $subscription, 'update_dates' ) ) {
			return;
		}

		$date = gmdate( 'Y-m-d H:i:s', $timestamp );
		try {
			$subscription->update_dates( [ 'next_payment' => $date ] );
			$subscription->save();
		} catch ( \Throwable $e ) {
			Logger::debug( __METHOD__ . ': failed to update Woo subscription next payment date: ' . $e->getMessage() );
		}
	}

	protected function maybeCreateBtcpayRenewalOrder( \WC_Subscription $subscription, \stdClass $subscriber, string $source ): void {
		if ( ! function_exists( 'wcs_create_renewal_order' ) || ( $subscriber->phase ?? '' ) !== 'Normal' || ! $this->btcpaySubscriberIsActive( $subscriber ) ) {
			return;
		}
		$periodEnd = (int) ( $subscriber->periodEnd ?? 0 );
		$lastPeriodEnd = (int) $subscription->get_meta( 'BTCPay_last_renewal_period_end' );
		if ( $periodEnd <= $lastPeriodEnd ) {
			return;
		}
		if ( $lastPeriodEnd === 0 && ! $subscription->get_meta( 'BTCPay_trial_started' ) ) {
			$subscription->update_meta_data( 'BTCPay_last_renewal_period_end', $periodEnd );
			$subscription->save();
			return;
		}
		// Resume a partially processed renewal instead of creating a duplicate on redelivery.
		$key = $subscription->get_id() . ':' . $periodEnd;
		$orders = wc_get_orders( [ 'limit' => 1, 'meta_key' => 'BTCPay_renewal_key', 'meta_value' => $key ] );
		$renewalOrder = $orders[0] ?? wcs_create_renewal_order( $subscription );
		if ( ! $renewalOrder instanceof \WC_Order ) {
			throw new \RuntimeException( 'Could not create the WooCommerce renewal order.' );
		}
		$renewalOrder->set_payment_method( $this->getId() );
		// A credit balance can fund renewals without a new invoice. Never copy the parent's invoice.
		foreach ( [ 'BTCPay_id', 'BTCPay_redirect', 'BTCPay_plan_checkout_id', '_btcpay_return_reference_hash', '_btcpay_return_reference_expires' ] as $metaKey ) {
			$renewalOrder->delete_meta_data( $metaKey );
		}
		$renewalOrder->update_meta_data( 'BTCPay_renewal_key', $key );
		$renewalOrder->update_meta_data( 'BTCPay_subscription_period_end', $periodEnd );
		$renewalOrder->save();
		if ( ! $renewalOrder->is_paid() ) {
			$renewalOrder->payment_complete();
			if ( ! $renewalOrder->is_paid() ) {
				throw new \RuntimeException( 'Could not complete the WooCommerce renewal order.' );
			}
		}
		$subscription->update_meta_data( 'BTCPay_last_renewal_period_end', $periodEnd );
		$subscription->save();
	}

	protected function btcpaySubscriberIsActive( \stdClass $subscriber ): bool {
		return ( $subscriber->isActive ?? false ) === true && empty( $subscriber->isSuspended );
	}

	protected function getBtcpaySubscriberExpirationTimestamp( \stdClass $subscriber ): ?int {
		$field = [ 'Trial' => 'trialEnd', 'Grace' => 'gracePeriodEnd' ][ $subscriber->phase ?? '' ] ?? 'periodEnd';
		return ! empty( $subscriber->{$field} ) ? (int) $subscriber->{$field} : null;
	}

	protected function objectToArray( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return json_decode( json_encode( $value ), true ) ?: [];
		}

		return [];
	}

	protected function getOrderByInvoiceMetadata( string $invoiceId ): ?\WC_Order {
		$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
		$metadata = $client->getInvoice( $this->apiHelper->storeId, $invoiceId )->getData()['metadata'] ?? [];
		$order = ! empty( $metadata['wc_order_id'] ) ? wc_get_order( (int) $metadata['wc_order_id'] ) : null;
		if ( ! $order || strpos( $order->get_payment_method(), 'btcpaygf_' ) !== 0 || ! $order->get_meta( 'BTCPay_plan_checkout_id' ) ) {
			return null;
		}
		$checkout = $this->getPlanCheckoutForOrder( $order );
		if ( ! $checkout || $checkout->getInvoiceId() !== $invoiceId ) {
			return null;
		}
		$order->update_meta_data( 'BTCPay_id', $invoiceId );
		$order->save();
		return $order;
	}

	protected function shouldDeferSubscriptionInvoiceWebhook( \WC_Order $order, \stdClass $webhookData ): bool {
		return $order->get_meta( 'BTCPay_plan_checkout_id' ) !== '';
	}

	protected function getPlanCheckoutForOrder( \WC_Order $order ): ?\BTCPayServer\Result\PlanCheckout {
		$planCheckoutId = $order->get_meta( 'BTCPay_plan_checkout_id' );
		if ( empty( $planCheckoutId ) ) {
			return null;
		}

		try {
			$client = $this->subscriptionsClient();
			return $client->getPlanCheckout( $planCheckoutId );
		} catch ( \Throwable $e ) {
			Logger::debug( __METHOD__ . ': failed to read plan checkout: ' . $e->getMessage() );
			return null;
		}
	}

	protected function storePlanCheckoutSubscriberMetadata(
		\WC_Order $order,
		?\WC_Subscription $subscription,
		\BTCPayServer\Result\PlanCheckout $checkout
	): ?\stdClass {
		if ( $checkout->getInvoiceId() && ! $order->get_meta( 'BTCPay_id' ) ) {
			$order->update_meta_data( 'BTCPay_id', $checkout->getInvoiceId() );
		}

		$subscriberResult = $checkout->getSubscriber();
		if ( ! $subscriberResult ) {
			$order->save();
			return null;
		}

		$subscriber = json_decode( json_encode( $subscriberResult->getData(), JSON_THROW_ON_ERROR ), false, 512, JSON_THROW_ON_ERROR );
		if ( ! empty( $subscriber->customer->id ) ) {
			$order->update_meta_data( 'BTCPay_subscriber_id', (string) $subscriber->customer->id );
		}
		$order->save();

		if ( $subscription ) {
			$this->storeBtcpaySubscriberMetadata( $subscription, $subscriber );
		}

		return $subscriber;
	}

	/**
	 * Check if the order contains a subscription.
	 */
	protected function isSubscriptionOrder( \WC_Order $order ): bool {
		if ( function_exists( 'wcs_order_contains_subscription' ) ) {
			return wcs_order_contains_subscription( $order );
		}

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			return \WC_Subscriptions_Order::order_contains_subscription( $order->get_id() );
		}

		return false;
	}

	/**
	 * Process subscription payment.
	 */
	protected function processSubscriptionPayment( \WC_Order $order, bool $isModal ): array {
		$lock = SubscriptionLock::acquire( 'checkout:' . $order->get_id() );
		try {
			$order->read_meta_data( true );
			if ( $order->is_paid() ) {
				return [ 'result' => 'success', 'subscription' => true, 'redirect' => $order->get_checkout_order_received_url() ];
			}
			$subscription = $this->getSubscriptionForOrder( $order );
			if ( ! $subscription || $this->getId() !== 'btcpaygf_default' ) {
				throw new \RuntimeException( 'A WooCommerce subscription and the default BTCPay gateway are required.' );
			}
			$planData = $this->getBtcpayPlanData( $order, $subscription );
			if ( empty( $planData['offering_id'] ) || empty( $planData['plan_id'] ) ) {
				throw new \RuntimeException( 'Subscription offering or plan ID not configured.' );
			}
			$plan = $this->subscriptionsClient()->getOfferingPlan( $this->apiHelper->storeId, $planData['offering_id'], $planData['plan_id'] );
			$this->validateSubscriptionPlan( $order, $subscription, $plan );
			$planCheckout = $this->getReusablePlanCheckout( $order );
			if ( ! $planCheckout ) {
				$planCheckout = $this->createPlanCheckout( $order, $planData['offering_id'], $planData['plan_id'], null, $order->get_billing_email() );
				$this->storeSubscriptionMetadata( $order, $planData['offering_id'], $planData['plan_id'], $planCheckout, $subscription );
			}
			// Both classic and Blocks checkout follow this redirect, even with modal mode enabled.
			return [
				'result' => 'success',
				'subscription' => true,
				'redirect' => $planCheckout->getUrl(),
			];
		} catch ( \Throwable $e ) {
			Logger::debug( 'Error processing subscription payment: ' . $e->getMessage() );
			throw new \Exception( __( 'Could not start the subscription. Please contact the store to check its BTCPay plan configuration.', 'btcpay-greenfield-for-woocommerce' ), 0, $e );
		} finally {
			SubscriptionLock::release( $lock );
		}
	}

	/** A plan charges its own price, not the WooCommerce order total. Refuse underpayment. */
	protected function validateSubscriptionPlan( \WC_Order $order, \WC_Subscription $subscription, \BTCPayServer\Result\OfferingPlan $plan ): void {
		$items = $order->get_items();
		$item = count( $items ) === 1 ? reset( $items ) : null;
		$product = $item ? $item->get_product() : null;
		if ( ! $product || ! $product->is_type( 'subscription' ) || (int) $item->get_quantity() !== 1 ) {
			throw new \RuntimeException( 'BTCPay requires exactly one simple subscription product with quantity one.' );
		}
		$schedule = [ 'Monthly' => [ 'month', 1 ], 'Quarterly' => [ 'month', 3 ], 'Yearly' => [ 'year', 1 ] ][ $plan->getRecurringType() ] ?? null;
		if ( ! $schedule || $schedule !== [ $subscription->get_billing_period(), (int) $subscription->get_billing_interval() ]
			|| $subscription->get_time( 'end' ) || ! $plan->isRenewable() || $plan->getStatus() !== 'Active' ) {
			throw new \RuntimeException( 'BTCPay and WooCommerce must use the same ongoing billing schedule and an active, renewable plan.' );
		}
		$trialLength = (int) \WC_Subscriptions_Product::get_trial_length( $product );
		$trialPeriod = \WC_Subscriptions_Product::get_trial_period( $product );
		$trialDays = $trialPeriod === 'week' ? $trialLength * 7 : $trialLength;
		if ( ( $trialLength && ! in_array( $trialPeriod, [ 'day', 'week' ], true ) ) || $trialDays !== $plan->getTrialDays() ) {
			throw new \RuntimeException( 'Trial periods must match and be expressed in days or weeks.' );
		}
		$initialAmount = $trialDays > 0 ? '0' : $plan->getPrice();
		if ( strtoupper( $order->get_currency() ) !== strtoupper( $plan->getCurrency() )
			|| strtoupper( $subscription->get_currency() ) !== strtoupper( $plan->getCurrency() )
			|| bccomp( (string) $order->get_total(), $initialAmount, 8 ) !== 0
			|| bccomp( (string) $subscription->get_total(), $plan->getPrice(), 8 ) !== 0
			|| bccomp( $plan->getPrice(), '0', 8 ) <= 0 ) {
			throw new \RuntimeException( 'The currency, initial total and recurring total must match the BTCPay plan, including tax, shipping, fees and discounts.' );
		}
	}

	protected function getReusablePlanCheckout( \WC_Order $order ): ?\BTCPayServer\Result\PlanCheckout {
		$id = (string) $order->get_meta( 'BTCPay_plan_checkout_id' );
		if ( $id === '' ) {
			return null;
		}
		$storeId = (string) $order->get_meta( 'BTCPay_store_id' );
		if ( $storeId !== '' && $storeId !== $this->apiHelper->storeId ) {
			throw new \RuntimeException( 'The saved checkout belongs to a different BTCPay store.' );
		}
		$checkout = $this->subscriptionsClient()->getPlanCheckout( $id );
		if ( $storeId === '' ) {
			$subscription = $this->getSubscriptionForOrder( $order );
			if ( ! $subscription || ! $this->ensureBtcpaySubscriptionStore( $subscription, $checkout ) ) {
				throw new \RuntimeException( 'Could not verify the store binding of the legacy BTCPay checkout.' );
			}
		}
		return $checkout->isPlanStarted() || ! $checkout->isExpired() ? $checkout : null;
	}

	protected function createPlanCheckout(
		\WC_Order $order,
		string $offeringId,
		string $planId,
		?string $customerSelector,
		?string $newSubscriberEmail
	): \BTCPayServer\Result\PlanCheckout {
		$client = $this->subscriptionsClient();
		$subscription = $this->getSubscriptionForOrder( $order );

		$invoiceMetadata = $this->buildSubscriptionInvoiceMetadata( $order, $subscription );
		$newSubscriberMetadata = $this->buildSubscriptionInvoiceMetadata( $order, $subscription );
		// Public checkout responses must not contain WooCommerce order access keys.
		$successRedirect = OrderReturn::createUrl( $order );

		return $client->createPlanCheckout(
			storeId: $this->apiHelper->storeId,
			offeringId: $offeringId,
			planId: $planId,
			customerSelector: $customerSelector,
			newSubscriberMetadata: $newSubscriberMetadata,
			invoiceMetadata: $invoiceMetadata,
			isTrial: $subscription && $subscription->get_time( 'trial_end' ) > 0,
			successRedirectLink: $successRedirect,
			newSubscriberEmail: $newSubscriberEmail
		);	}

	protected function buildSubscriptionInvoiceMetadata( \WC_Order $order, ?\WC_Subscription $subscription ): array {
		$metadata = [
			'wc_order_id' => (string) $order->get_id(),
			'wc_order_number' => (string) $order->get_order_number(),
		];

		if ( $subscription ) {
			$metadata['wc_subscription_id'] = (string) $subscription->get_id();
		}

		return $metadata;
	}

	protected function getSubscriptionForOrder( \WC_Order $order ): ?\WC_Subscription {
		if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
			if ( ! empty( $subscriptions ) ) {
				return array_shift( $subscriptions );
			}
		}

		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order->get_id(), [ 'order_type' => 'parent' ] );
			if ( ! empty( $subscriptions ) ) {
				return array_shift( $subscriptions );
			}
		}

		return null;
	}

	protected function getBtcpayPlanData( \WC_Order $order, ?\WC_Subscription $subscription ): array {
		$offeringId = $order->get_meta( 'BTCPay_offering_id' );
		$planId = $order->get_meta( 'BTCPay_plan_id' );

		if ( ( empty( $offeringId ) || empty( $planId ) ) && $subscription ) {
			$offeringId = $subscription->get_meta( 'BTCPay_offering_id' );
			$planId = $subscription->get_meta( 'BTCPay_plan_id' );
		}

		if ( empty( $offeringId ) || empty( $planId ) ) {
			$productMeta = $this->getBtcpayPlanDataFromProducts( $order );
			$offeringId = $productMeta['offering_id'] ?? $offeringId;
			$planId = $productMeta['plan_id'] ?? $planId;
		}

		return [
			'offering_id' => $offeringId,
			'plan_id' => $planId,
		];
	}

	protected function getBtcpayPlanDataFromProducts( \WC_Order $order ): array {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return [];
		}

		$mappings = get_option( 'btcpay_gf_subscription_mappings', [] );
		if ( empty( $mappings ) ) {
			return [];
		}

		$mappingsByProduct = [];
		foreach ( $mappings as $mapping ) {
			$mappingsByProduct[ (int) $mapping['product_id'] ] = $mapping;
		}

		$matches = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			if ( ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
				continue;
			}

			$productId = $product->get_id();
			if ( isset( $mappingsByProduct[ $productId ] ) ) {
				$matches[] = [
					'offering_id' => $mappingsByProduct[ $productId ]['offering_id'],
					'plan_id'     => $mappingsByProduct[ $productId ]['plan_id'],
					'product_id'  => $productId,
				];
			}
		}

		if ( empty( $matches ) ) {
			return [];
		}

		if ( count( $matches ) > 1 ) {
			throw new \Exception( __( 'Only one subscription product can be purchased per order for BTCPay subscriptions.', 'btcpay-greenfield-for-woocommerce' ) );
		}

		return $matches[0];
	}

	protected function storeSubscriptionMetadata(
		\WC_Order $order,
		string $offeringId,
		string $planId,
		\BTCPayServer\Result\PlanCheckout $checkout,
		?\WC_Subscription $subscription
	): void {
		$order->update_meta_data( 'BTCPay_store_id', $this->apiHelper->storeId );
		$order->update_meta_data( 'BTCPay_offering_id', $offeringId );
		$order->update_meta_data( 'BTCPay_plan_id', $planId );
		$order->update_meta_data( 'BTCPay_plan_checkout_id', $checkout->getId() );

		$order->update_meta_data( 'BTCPay_id', $checkout->getInvoiceId() ?? '' );

		$order->save();

		if ( $subscription ) {
			$subscription->update_meta_data( 'BTCPay_trial_started', $checkout->isTrial() ? 1 : 0 );
			$subscription->update_meta_data( 'BTCPay_store_id', $this->apiHelper->storeId );
			$subscription->update_meta_data( 'BTCPay_offering_id', $offeringId );
			$subscription->update_meta_data( 'BTCPay_plan_id', $planId );
			$subscription->save();
		}
	}

}
