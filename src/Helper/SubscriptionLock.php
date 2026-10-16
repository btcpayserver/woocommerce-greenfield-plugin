<?php

declare( strict_types=1 );

namespace BTCPayServer\WC\Helper;

/** Serialize checkout retries and webhook deliveries across PHP workers. */
final class SubscriptionLock {
	public static function acquire( string $resource ): string {
		global $wpdb;
		// MySQL releases the lock if a worker dies; no stale option needs cleanup.
		$name = 'btcpay_sub_' . md5( $wpdb->prefix . $resource );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) !== '1' ) {
			throw new \RuntimeException( 'BTCPay subscription is already being processed. Please retry.' );
		}
		return $name;
	}

	public static function release( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
