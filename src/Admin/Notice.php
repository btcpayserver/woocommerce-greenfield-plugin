<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Admin;

class Notice {
	/**
	 * Adds notice to the admin UI.
	 */
	public static function addNotice( string $level, string $message ) {
		add_action(
			'admin_notices',
			function () use ( $level, $message ) {
				?>
				<div class="notice <?php echo esc_attr( $level ) ?>" style="padding:12px 12px">
					<?php echo '<strong>BTCPay Server:</strong> ' . wp_kses_post( $message ) ?>
				</div>
				<?php
			}
		);
	}
}
