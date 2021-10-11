<?php

declare( strict_types=1 );

namespace BTCPayServer\WC\Helper;

class Logger {

	public static function debug( $message, $force = false) {
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

}
