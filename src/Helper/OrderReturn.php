<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Helper;

/**
 * Creates and resolves short-lived references for BTCPay checkout redirects.
 *
 * The reference only identifies an order. Access to the order is authorized
 * separately against the logged-in customer or the WooCommerce guest session.
 */
final class OrderReturn {
	private const QUERY_ARG = 'btcpaygf-return';
	private const REFERENCE_HASH_META_KEY = '_btcpay_return_reference_hash';
	private const REFERENCE_EXPIRY_META_KEY = '_btcpay_return_reference_expires';
	private const GUEST_ORDERS_SESSION_KEY = 'btcpaygf_return_orders';
	private const REFERENCE_LIFETIME = 24 * 60 * 60;

	/**
	 * Register the frontend return handler before canonical redirects run.
	 */
	public static function register(): void {
		add_action( 'template_redirect', [ self::class, 'handle' ], 0 );
	}

	/**
	 * Create a temporary BTCPay redirect URL for an order.
	 */
	public static function createUrl( \WC_Order $order ): string {
		$reference = bin2hex( random_bytes( 32 ) );

		$order->update_meta_data( self::REFERENCE_HASH_META_KEY, hash( 'sha256', $reference ) );
		$order->update_meta_data( self::REFERENCE_EXPIRY_META_KEY, time() + self::REFERENCE_LIFETIME );
		$order->save();
		self::rememberGuestOrder( $order );

		return add_query_arg( self::QUERY_ARG, $reference, home_url( '/' ) );
	}

	/**
	 * Resolve an authorized return reference to WooCommerce's order received URL.
	 */
	public static function handle(): void {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) {
			return;
		}

		nocache_headers();

		$reference = is_string( $_GET[ self::QUERY_ARG ] )
			? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) )
			: '';

		if ( ! preg_match( '/\A[a-f0-9]{64}\z/D', $reference ) ) {
			self::unavailable();
		}

		$referenceHash = hash( 'sha256', $reference );
		$orders        = wc_get_orders(
			[
				'limit'      => 1,
				'return'     => 'objects',
				'meta_key'   => self::REFERENCE_HASH_META_KEY,
				'meta_value' => $referenceHash,
			]
		);
		$order         = $orders[0] ?? null;

		if (
			! $order instanceof \WC_Order
			|| ! hash_equals( (string) $order->get_meta( self::REFERENCE_HASH_META_KEY ), $referenceHash )
			|| strpos( $order->get_payment_method(), 'btcpaygf_' ) !== 0
		) {
			self::unavailable();
		}

		if ( (int) $order->get_meta( self::REFERENCE_EXPIRY_META_KEY ) <= time() ) {
			// Expiry is enforced on every request; remove expired metadata when revisited.
			$order->delete_meta_data( self::REFERENCE_HASH_META_KEY );
			$order->delete_meta_data( self::REFERENCE_EXPIRY_META_KEY );
			$order->save();
			self::unavailable();
		}

		if ( ! self::currentCustomerOwnsOrder( $order ) ) {
			self::unavailable();
		}

		// Keep guest access after WooCommerce clears the checkout session markers.
		// This also covers unexpired references created before the plugin update.
		self::rememberGuestOrder( $order );

		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	/**
	 * Check whether an order belongs to the current customer or guest session.
	 */
	public static function currentCustomerOwnsOrder( \WC_Order $order ): bool {
		$customerId = (int) $order->get_customer_id();
		if ( $customerId > 0 ) {
			return get_current_user_id() === $customerId;
		}

		$session = WC()->session;
		if ( ! $session ) {
			return false;
		}

		$orderId = $order->get_id();
		$guestOrders = (array) $session->get( self::GUEST_ORDERS_SESSION_KEY, [] );

		return (int) ( $guestOrders[ $orderId ] ?? 0 ) > time() || in_array(
			$orderId,
			[
				absint( $session->get( 'store_api_draft_order', 0 ) ),
				absint( $session->get( 'order_awaiting_payment', 0 ) ),
			],
			true
		);
	}

	/**
	 * Remember only verified guest orders, until their original reference expiry.
	 */
	private static function rememberGuestOrder( \WC_Order $order ): void {
		if ( (int) $order->get_customer_id() !== 0 || ! self::currentCustomerOwnsOrder( $order ) ) {
			return;
		}

		$session = WC()->session;
		$guestOrders = array_filter(
			(array) $session->get( self::GUEST_ORDERS_SESSION_KEY, [] ),
			static fn( $expires ) => (int) $expires > time()
		);
		$guestOrders[ $order->get_id() ] = (int) $order->get_meta( self::REFERENCE_EXPIRY_META_KEY );
		$session->set( self::GUEST_ORDERS_SESSION_KEY, $guestOrders );
	}

	/**
	 * Give the same fallback for invalid, expired, and unauthorized references.
	 */
	private static function unavailable(): void {
		if ( is_user_logged_in() ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'orders' ) );
			exit;
		}

		$title = esc_html__( 'Order information', 'btcpay-greenfield-for-woocommerce' );
		wp_die(
			'<h1>' . $title . '</h1><p>'
			. esc_html__( 'This link has expired or cannot be opened in this browser. If you have an account, please log in to view your orders. If you checked out as a guest, please refer to your order confirmation email or contact the store for help.', 'btcpay-greenfield-for-woocommerce' )
			. '</p>',
			$title,
			[
				'response'  => 200,
				'link_url'  => wc_get_page_permalink( 'myaccount' ),
				'link_text' => esc_html__( 'Log in to your account', 'btcpay-greenfield-for-woocommerce' ),
			]
		);
		exit;
	}
}
