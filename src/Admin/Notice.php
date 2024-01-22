<?php

declare(strict_types=1);

namespace BTCPayServer\WC\Admin;

class Notice {
	/**
	 * Adds notice to the admin UI.
	 */
	public static function addNotice( string $level, string $message, bool $dismissible = false, ?string $customClass = null): void {
		add_action(
			'admin_notices',
			function () use ( $level, $message, $dismissible, $customClass ) {
				$classes = $customClass ? ' ' . $customClass : '';
				$classes .= $dismissible ? ' is-dismissible' : '';
				?>
				<div class="notice notice-<?php echo esc_attr( $level ) . esc_attr( $classes ); ?>" style="padding:12px 12px">
					<?php echo '<strong>BTCPay Server:</strong> ' . wp_kses_post( $message ) ?>
				</div>
				<?php
			}
		);
	}
}
