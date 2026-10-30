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
		// The return handler may redirect to the host the customer is currently
		// using (for example a Tor .onion address), which is not the configured
		// home host. Allow it for wp_safe_redirect().
		add_filter( 'allowed_redirect_hosts', [ self::class, 'allowCurrentHostRedirect' ] );
	}

	/**
	 * Allow redirects to the host of the current request.
	 *
	 * @param string[] $hosts Allowed redirect hosts.
	 * @return string[]
	 */
	public static function allowCurrentHostRedirect( array $hosts ): array {
		$host = self::getCurrentHost();
		if ( $host === '' ) {
			return $hosts;
		}

		$parts    = wp_parse_url( 'http://' . $host );
		$hostName = isset( $parts['host'] ) ? $parts['host'] : '';
		if ( $hostName !== '' && ! in_array( $hostName, $hosts, true ) ) {
			$hosts[] = $hostName;
		}

		return $hosts;
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

		/**
		 * Filters the base URL used for the temporary BTCPay return link.
		 *
		 * The default keeps the host the customer is currently browsing, so
		 * stores reachable over both clearnet and Tor .onion addresses return
		 * shoppers to the same host and keep their WooCommerce session.
		 *
		 * @param string    $baseUrl Store URL on the current request host.
		 * @param \WC_Order $order   Order being returned to.
		 */
		$baseUrl = apply_filters( 'btcpay_gf_return_url', self::getStoreUrl(), $order );

		return add_query_arg( self::QUERY_ARG, $reference, $baseUrl );
	}

	/**
	 * Return the store URL on the host the customer is currently browsing.
	 *
	 * Stores reachable both on clearnet and on a Tor .onion address must send
	 * the customer back to the host they came from. The WooCommerce session
	 * cookie is scoped to that host, so using the configured clearnet URL would
	 * make the return link fail authorization for onion shoppers.
	 */
	private static function getStoreUrl(): string {
		$homeUrl     = home_url( '/' );
		$currentHost = self::getCurrentHost();

		if ( $currentHost === '' ) {
			return $homeUrl;
		}

		$homeParts     = wp_parse_url( $homeUrl );
		$homeHost      = isset( $homeParts['host'] ) ? strtolower( $homeParts['host'] ) : '';
		$homePort      = isset( $homeParts['port'] ) ? ':' . $homeParts['port'] : '';
		$canonicalHost = $homeHost . $homePort;

		// Already on the configured host: keep the canonical URL and its scheme.
		if ( $canonicalHost !== '' && strcasecmp( $currentHost, $canonicalHost ) === 0 ) {
			return $homeUrl;
		}

		// Keep the configured path (supports subdirectory installs) but use the
		// host and scheme the customer is currently browsing.
		$path   = isset( $homeParts['path'] ) && $homeParts['path'] !== '' ? $homeParts['path'] : '/';
		$scheme = is_ssl() ? 'https' : 'http';

		return $scheme . '://' . $currentHost . $path;
	}

	/**
	 * Return the host of the current request, or an empty string if unusable.
	 */
	private static function getCurrentHost(): string {
		if ( ! isset( $_SERVER['HTTP_HOST'] ) || ! is_string( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}

		$host = strtolower( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) );

		// DNS name, IPv4 or bracketed IPv6, optionally with a port. Reject
		// malformed values so a bad Host header falls back to the home URL.
		if ( ! preg_match( '/\A(?:[a-z0-9_](?:[a-z0-9_.-]*[a-z0-9_])?\.?|\[[0-9a-f:.]+\])(?::\d{1,5})?\z/i', $host ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * Rewrite a WooCommerce-generated URL to the host of the current request.
	 */
	public static function withCurrentHost( string $url ): string {
		$currentHost = self::getCurrentHost();
		if ( $currentHost === '' ) {
			return $url;
		}

		$homeParts = wp_parse_url( home_url( '/' ) );
		$homeHost  = isset( $homeParts['host'] ) ? strtolower( $homeParts['host'] ) : '';
		$homePort  = isset( $homeParts['port'] ) ? ':' . $homeParts['port'] : '';
		if ( $homeHost !== '' && strcasecmp( $currentHost, $homeHost . $homePort ) === 0 ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$rewritten = ( is_ssl() ? 'https' : 'http' ) . '://' . $currentHost;
		if ( isset( $parts['path'] ) ) {
			$rewritten .= $parts['path'];
		}
		if ( isset( $parts['query'] ) ) {
			$rewritten .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) ) {
			$rewritten .= '#' . $parts['fragment'];
		}

		return $rewritten;
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

		wp_safe_redirect( self::withCurrentHost( $order->get_checkout_order_received_url() ) );
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
			wp_safe_redirect( self::withCurrentHost( wc_get_account_endpoint_url( 'orders' ) ) );
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
				'link_url'  => self::withCurrentHost( wc_get_page_permalink( 'myaccount' ) ),
				'link_text' => esc_html__( 'Log in to your account', 'btcpay-greenfield-for-woocommerce' ),
			]
		);
		exit;
	}
}
