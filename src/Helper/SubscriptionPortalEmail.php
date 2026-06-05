<?php

declare( strict_types=1 );

namespace BTCPayServer\WC\Helper;

use BTCPayServer\Client\Subscriptions;

class SubscriptionPortalEmail {
	private const DEFAULT_PORTAL_DURATION_MINUTES = 10080;
	private const META_LAST_SENT_PREFIX = 'BTCPay_subscription_portal_email_';
	private const META_PORTAL_URL_PREFIX = 'BTCPay_subscription_portal_url_';
	private const META_PORTAL_EXPIRATION_PREFIX = 'BTCPay_subscription_portal_expires_';

	private GreenfieldApiHelper $apiHelper;

	public function __construct( GreenfieldApiHelper $apiHelper ) {
		$this->apiHelper = $apiHelper;
	}

	public function maybeSendForWebhook(
		\WC_Subscription $subscription,
		\stdClass $subscriber,
		\stdClass $webhookData,
		array $subscriberData
	): bool {
		$context = $this->getEmailContext( $webhookData, $subscriber );
		if ( empty( $context ) ) {
			return false;
		}

		$dedupeKey = $this->buildDedupeKey( $context['slug'], $subscriber, $webhookData );
		$sentMetaKey = self::META_LAST_SENT_PREFIX . $context['slug'];
		if ( (string) $subscription->get_meta( $sentMetaKey ) === $dedupeKey ) {
			Logger::debug( __METHOD__ . ': duplicate subscription portal email skipped for subscription ' . $subscription->get_id() . ' and key ' . $dedupeKey );
			return false;
		}

		$recipient = $this->getRecipient( $subscription, $subscriber );
		if ( empty( $recipient ) || ! is_email( $recipient ) ) {
			$this->addSubscriptionNote(
				$subscription,
				__( 'Could not send BTCPay subscription portal email because no valid recipient email was found.', 'btcpay-greenfield-for-woocommerce' )
			);
			return false;
		}

		$portalSession = $this->createPortalSession( $subscriber, $subscriberData );
		if ( empty( $portalSession['url'] ) ) {
			$this->addSubscriptionNote(
				$subscription,
				__( 'Could not send BTCPay subscription portal email because no portal session URL was returned.', 'btcpay-greenfield-for-woocommerce' )
			);
			return false;
		}

		$sent = $this->sendEmail( $subscription, $subscriber, $recipient, $portalSession['url'], $context );
		if ( ! $sent ) {
			Logger::debug(
				sprintf(
					'%s: failed to send subscription portal email. Event: %s. Subscription ID: %d. Recipient: %s.',
					__METHOD__,
					(string) ( $webhookData->type ?? '' ),
					$subscription->get_id(),
					$recipient
				)
			);
			$this->addSubscriptionNote(
				$subscription,
				__( 'Failed to send BTCPay subscription portal email.', 'btcpay-greenfield-for-woocommerce' )
			);
			return false;
		}

		Logger::debug(
			sprintf(
				'%s: sent subscription portal email. Event: %s. Subscription ID: %d. Recipient: %s. Email type: %s. Dedupe key: %s.',
				__METHOD__,
				(string) ( $webhookData->type ?? '' ),
				$subscription->get_id(),
				$recipient,
				$context['slug'],
				$dedupeKey
			)
		);

		$subscription->update_meta_data( $sentMetaKey, $dedupeKey );
		$subscription->update_meta_data( self::META_PORTAL_URL_PREFIX . $context['slug'], $portalSession['url'] );
		if ( ! empty( $portalSession['expiration'] ) ) {
			$subscription->update_meta_data( self::META_PORTAL_EXPIRATION_PREFIX . $context['slug'], (string) $portalSession['expiration'] );
		}
		$subscription->save();

		$this->addSubscriptionNote(
			$subscription,
			sprintf(
				__( 'Sent BTCPay subscription %1$s email to %2$s with a fresh portal session link.', 'btcpay-greenfield-for-woocommerce' ),
				$context['note_label'],
				$recipient
			)
		);

		return true;
	}

	private function getEmailContext( \stdClass $webhookData, \stdClass $subscriber ): ?array {
		$eventType = (string) ( $webhookData->type ?? '' );
		$planName = $this->getPlanName( $subscriber );
		$siteName = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		if ( $eventType === 'PaymentReminder' ) {
			return [
				'slug'       => 'payment_reminder',
				'note_label' => __( 'payment reminder', 'btcpay-greenfield-for-woocommerce' ),
				'subject'    => sprintf(
					__( 'Payment reminder for your %s subscription', 'btcpay-greenfield-for-woocommerce' ),
					$siteName
				),
				'heading'    => __( 'Subscription payment reminder', 'btcpay-greenfield-for-woocommerce' ),
				'intro'      => sprintf(
					__( 'Your subscription to %s needs credit before the next renewal.', 'btcpay-greenfield-for-woocommerce' ),
					$planName
				),
				'action'     => __( 'Add credit in the subscription portal to keep the subscription active.', 'btcpay-greenfield-for-woocommerce' ),
			];
		}

		if ( $eventType === 'SubscriberNeedUpgrade' ) {
			return [
				'slug'       => 'need_upgrade',
				'note_label' => __( 'needs attention', 'btcpay-greenfield-for-woocommerce' ),
				'subject'    => sprintf(
					__( 'Action needed for your %s subscription', 'btcpay-greenfield-for-woocommerce' ),
					$siteName
				),
				'heading'    => __( 'Subscription action needed', 'btcpay-greenfield-for-woocommerce' ),
				'intro'      => sprintf(
					__( 'Your subscription to %s needs attention before it can continue.', 'btcpay-greenfield-for-woocommerce' ),
					$planName
				),
				'action'     => __( 'Open the subscription portal to review the subscription and add credit if needed.', 'btcpay-greenfield-for-woocommerce' ),
			];
		}

		if ( $eventType === 'SubscriberDisabled' && strtolower( (string) ( $webhookData->reason ?? '' ) ) === 'expired' ) {
			return [
				'slug'       => 'expired',
				'note_label' => __( 'expired subscription', 'btcpay-greenfield-for-woocommerce' ),
				'subject'    => sprintf(
					__( 'Your %s subscription has expired', 'btcpay-greenfield-for-woocommerce' ),
					$siteName
				),
				'heading'    => __( 'Subscription expired', 'btcpay-greenfield-for-woocommerce' ),
				'intro'      => sprintf(
					__( 'Your subscription to %s has expired because the renewal credit was not available.', 'btcpay-greenfield-for-woocommerce' ),
					$planName
				),
				'action'     => __( 'Open the subscription portal to add credit and reactivate the subscription.', 'btcpay-greenfield-for-woocommerce' ),
			];
		}

		return null;
	}

	private function createPortalSession( \stdClass $subscriber, array $subscriberData ): array {
		$offeringId = $subscriber->offering->id ?? $subscriberData['offering_id'] ?? null;
		$customerSelector = $subscriber->customer->id ?? $subscriberData['customer_selector'] ?? null;

		if ( empty( $offeringId ) || empty( $customerSelector ) ) {
			throw new \RuntimeException( 'Missing BTCPay offering id or customer selector for subscriber portal session.' );
		}

		$durationMinutes = (int) apply_filters(
			'btcpay_gf_subscription_portal_session_duration_minutes',
			self::DEFAULT_PORTAL_DURATION_MINUTES,
			$subscriber,
			$subscriberData
		);

		$client = new Subscriptions( $this->apiHelper->url, $this->apiHelper->apiKey );
		$session = $client->createPortalSession(
			$this->apiHelper->storeId,
			(string) $offeringId,
			(string) $customerSelector,
			$durationMinutes > 0 ? $durationMinutes : null
		);

		return [
			'url'        => $session->getUrl(),
			'expiration' => $session->getExpiration(),
		];
	}

	private function sendEmail(
		\WC_Subscription $subscription,
		\stdClass $subscriber,
		string $recipient,
		string $portalUrl,
		array $context
	): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return false;
		}

		$mailer = WC()->mailer();
		$subject = apply_filters(
			'btcpay_gf_subscription_portal_email_subject',
			$context['subject'],
			$context,
			$subscription,
			$subscriber,
			$portalUrl
		);

		$body = $this->buildEmailBody( $subscription, $subscriber, $portalUrl, $context );
		$body = apply_filters(
			'btcpay_gf_subscription_portal_email_body',
			$body,
			$context,
			$subscription,
			$subscriber,
			$portalUrl
		);

		$message = $mailer->wrap_message( $context['heading'], $body );

		return (bool) $mailer->send(
			$recipient,
			(string) $subject,
			$message,
			[ 'Content-Type: text/html; charset=UTF-8' ],
			[]
		);
	}

	private function buildEmailBody( \WC_Subscription $subscription, \stdClass $subscriber, string $portalUrl, array $context ): string {
		$nextPayment = $this->formatTimestamp( $this->getSubscriberDate( $subscriber ) );
		$buttonText = __( 'Open subscription portal', 'btcpay-greenfield-for-woocommerce' );
		$productName = $this->getSubscriptionProductName( $subscription ) ?? $this->getPlanName( $subscriber );
		$subscriptionUrl = $this->getSubscriptionAccountUrl( $subscription );

		$body = '<p>' . esc_html( $this->getCustomerGreetingName( $subscription ) ) . '</p>';
		$body .= '<p>' . esc_html( $context['intro'] ) . '</p>';

		if ( $nextPayment ) {
			$body .= '<p>' . esc_html(
				sprintf(
					__( 'Current subscription date for %1$s: %2$s', 'btcpay-greenfield-for-woocommerce' ),
					$productName,
					$nextPayment
				)
			) . '</p>';
		}

		if ( $subscriptionUrl ) {
			$body .= '<p><a href="' . esc_url( $subscriptionUrl ) . '">' . esc_html__( 'View your WooCommerce subscriptions', 'btcpay-greenfield-for-woocommerce' ) . '</a></p>';
		}

		$body .= '<p>' . esc_html( $context['action'] ) . '</p>';
		$body .= '<p><a class="button" href="' . esc_url( $portalUrl ) . '">' . esc_html( $buttonText ) . '</a></p>';
		$body .= '<p>' . esc_html__( 'If the button does not work, copy and paste this link into your browser:', 'btcpay-greenfield-for-woocommerce' ) . '<br>';
		$body .= '<a href="' . esc_url( $portalUrl ) . '">' . esc_html( $portalUrl ) . '</a></p>';

		return $body;
	}

	private function getRecipient( \WC_Subscription $subscription, \stdClass $subscriber ): ?string {
		$email = $subscription->get_billing_email();
		if ( ! empty( $email ) ) {
			return $email;
		}

		$identities = $this->objectToArray( $subscriber->customer->identities ?? [] );
		foreach ( [ 'Email', 'email' ] as $key ) {
			if ( ! empty( $identities[ $key ] ) ) {
				return (string) $identities[ $key ];
			}
		}

		return null;
	}

	private function buildDedupeKey( string $slug, \stdClass $subscriber, \stdClass $webhookData ): string {
		return implode(
			':',
			array_filter(
				[
					$slug,
					(string) ( $webhookData->type ?? '' ),
					(string) ( $webhookData->reason ?? '' ),
					(string) ( $webhookData->currentPhase ?? $subscriber->phase ?? '' ),
					(string) ( $this->getSubscriberDate( $subscriber ) ?? '' ),
				]
			)
		);
	}

	private function getSubscriberDate( \stdClass $subscriber ): ?int {
		foreach ( [ 'periodEnd', 'trialEnd', 'gracePeriodEnd' ] as $field ) {
			if ( ! empty( $subscriber->{$field} ) ) {
				return (int) $subscriber->{$field};
			}
		}

		return null;
	}

	private function formatTimestamp( ?int $timestamp ): ?string {
		if ( empty( $timestamp ) ) {
			return null;
		}

		return wp_date( wc_date_format(), $timestamp );
	}

	private function getPlanName( \stdClass $subscriber ): string {
		if ( ! empty( $subscriber->plan->name ) ) {
			return (string) $subscriber->plan->name;
		}

		return __( 'your subscription', 'btcpay-greenfield-for-woocommerce' );
	}

	private function getSubscriptionProductName( \WC_Subscription $subscription ): ?string {
		$productNames = [];
		foreach ( $subscription->get_items() as $item ) {
			$productName = $item->get_name();
			if ( empty( $productName ) ) {
				$product = $item->get_product();
				$productName = $product ? $product->get_name() : '';
			}

			if ( ! empty( $productName ) ) {
				$productNames[] = $productName;
			}
		}

		$productNames = array_unique( array_filter( array_map( 'strval', $productNames ) ) );
		if ( empty( $productNames ) ) {
			return null;
		}

		return implode( ', ', $productNames );
	}

	private function getSubscriptionAccountUrl( \WC_Subscription $subscription ): ?string {
		if ( function_exists( 'wc_get_endpoint_url' ) && function_exists( 'wc_get_page_permalink' ) ) {
			return wc_get_endpoint_url(
				get_option( 'woocommerce_myaccount_subscriptions_endpoint', 'subscriptions' ),
				'',
				wc_get_page_permalink( 'myaccount' )
			);
		}

		if ( method_exists( $subscription, 'get_view_order_url' ) ) {
			return $subscription->get_view_order_url();
		}

		return null;
	}

	private function getCustomerGreetingName( \WC_Subscription $subscription ): string {
		$name = trim( $subscription->get_formatted_billing_full_name() );
		if ( ! empty( $name ) ) {
			return sprintf(
				__( 'Hello %s,', 'btcpay-greenfield-for-woocommerce' ),
				$name
			);
		}

		return __( 'Hello,', 'btcpay-greenfield-for-woocommerce' );
	}

	private function addSubscriptionNote( \WC_Subscription $subscription, string $message ): void {
		if ( method_exists( $subscription, 'add_order_note' ) ) {
			$subscription->add_order_note( $message );
		}
	}

	private function objectToArray( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return json_decode( json_encode( $value ), true ) ?: [];
		}

		return [];
	}
}
