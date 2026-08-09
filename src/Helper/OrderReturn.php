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
	private const REFERENCE_LIFETIME = 5 * 60 * 60;

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
			self::notFound();
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
			|| (int) $order->get_meta( self::REFERENCE_EXPIRY_META_KEY ) <= time()
			|| strpos( $order->get_payment_method(), 'btcpaygf_' ) !== 0
			|| ! self::currentCustomerOwnsOrder( $order )
		) {
			self::notFound();
		}

		// Consume the reference only after authorization so probes cannot invalidate it.
		$order->delete_meta_data( self::REFERENCE_HASH_META_KEY );
		$order->delete_meta_data( self::REFERENCE_EXPIRY_META_KEY );
		$order->save();

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

		return in_array(
			$orderId,
			[
				absint( $session->get( 'store_api_draft_order', 0 ) ),
				absint( $session->get( 'order_awaiting_payment', 0 ) ),
			],
			true
		);
	}

	/**
	 * Return the same response for invalid, expired, and unauthorized references.
	 */
	private static function notFound(): void {
		wp_die(
			esc_html__( 'Not found.', 'btcpay-greenfield-for-woocommerce' ),
			'',
			[ 'response' => 404 ]
		);
		exit;
	}
}
