<?php

namespace BTCPayServer\WC\Helper;

use BTCPayServer\WC\Admin\Notice;

class UpdateManager {

	private static $updates = [
		'1.0.3' => 'update-1.0.3.php',
		'2.4.1' => 'update-2.4.1.php'
	];

	/**
	 * Runs updates if available or just updates the stored version.
	 */
	public static function processUpdates() {

		// Check stored version to see if update is needed, will only run once.
		$runningVersion = get_option( BTCPAYSERVER_VERSION_KEY, '1.0.2' );

		if ( version_compare( $runningVersion, BTCPAYSERVER_VERSION, '<' ) ) {

			// Run update scripts if there are any.
			foreach ( self::$updates as $updateVersion => $filename ) {
				if ( version_compare( $runningVersion, $updateVersion, '<' ) ) {
					$file = BTCPAYSERVER_PLUGIN_FILE_PATH . 'updates/' . $filename;
					if ( file_exists( $file ) ) {
						include $file;
					}
					$runningVersion = $updateVersion;
					update_option( BTCPAYSERVER_VERSION_KEY, $updateVersion );
					Notice::addNotice('success', 'BTCPay Server: successfully ran updates to version ' . $runningVersion, true);
				}
			}

			update_option( BTCPAYSERVER_VERSION_KEY, BTCPAYSERVER_VERSION );
		}
	}

}
