<?php

declare( strict_types=1 );

namespace BTCPayServer\WC\Helper;

class Logger {

	public static function debug( string $message, $force = false): void {
		if ( get_option( 'btcpay_gf_debug' ) === 'yes' || $force ) {
			// Convert message to string
			if ( ! is_string( $message ) ) {
				$message = wc_print_r( $message, true );
			}

			$logger = new \WC_Logger();
			$context = array( 'source' => BTCPAYSERVER_PLUGIN_ID );
			$logger->debug( $message, $context );
		}
	}

	/**
	 * Returns a print_r representation of an array with sensitive values redacted.
	 *
	 * Use this to debug request data (e.g. $_POST) without writing personal
	 * information (names, addresses, emails, phone numbers) or credentials to
	 * the debug log.
	 */
	public static function redactArray( array $data ): string {
		$sensitiveKeys = '/billing|shipping|address|email|phone|postcode|zip|city|state|country|company|name|comment|password|nonce|card|cvv|cvc|exp|token|account|auth/i';

		$redacted = [];
		foreach ( $data as $key => $value ) {
			$redacted[ $key ] = preg_match( $sensitiveKeys, (string) $key ) ? '[redacted]' : $value;
		}

		return print_r( $redacted, true );
	}

	public static function getLogFileUrl(): string {
		$log_file = BTCPAYSERVER_PLUGIN_ID . '-' . date('Y-m-d') . '-' . sanitize_file_name( wp_hash( BTCPAYSERVER_PLUGIN_ID ) ) . '-log';
		return esc_url(admin_url('admin.php?page=wc-status&tab=logs&log_file=' . $log_file));
	}

}
