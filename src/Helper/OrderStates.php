<?php

declare( strict_types=1 );

namespace BTCPayServer\WC\Helper;

/**
 * Helper class to render the order_states as a custom field in global settings form.
 */
class OrderStates {
	const NEW = 'New';
	const PROCESSING = 'Processing';
	const SETTLED = 'Settled';
	const SETTLED_PAID_OVER = 'SettledPaidOver';
	const INVALID = 'Invalid';
	const EXPIRED = 'Expired';
	const EXPIRED_PAID_PARTIAL = 'ExpiredPaidPartial';
	const EXPIRED_PAID_LATE = 'ExpiredPaidLate';
	const IGNORE = 'BTCPAY_IGNORE';

	public function getDefaultOrderStateMappings(): array {
		return [
			self::NEW                  => 'wc-pending',
			self::PROCESSING           => 'wc-on-hold',
			self::SETTLED              => self::IGNORE,
			self::SETTLED_PAID_OVER    => 'wc-processing',
			self::INVALID              => 'wc-failed',
			self::EXPIRED              => 'wc-cancelled',
			self::EXPIRED_PAID_PARTIAL => 'wc-failed',
			self::EXPIRED_PAID_LATE    => 'wc-processing'
		];
	}

	public function getOrderStateLabels(): array {
		return [
			self::NEW                  => _x('New', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
			self::PROCESSING           => _x('Paid', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
			self::SETTLED              => _x('Settled', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
			self::SETTLED_PAID_OVER    => _x('Settled (paid over)', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
			self::INVALID              => _x('Invalid', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
			self::EXPIRED              => _x('Expired', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
			self::EXPIRED_PAID_PARTIAL => _x('Expired with partial payment', 'global_settings', 'btcpay-greenfield-for-woocommerce'),
			self::EXPIRED_PAID_LATE    => _x('Expired (paid late)', 'global_settings', 'btcpay-greenfield-for-woocommerce')
		];
	}

	public function renderOrderStatesHtml($value) {
		// Todo: mabye refactor to be done in a separate template file.
		$btcpayStates = $this->getOrderStateLabels();
		$defaultStates = $this->getDefaultOrderStateMappings();

		$wcStates = wc_get_order_statuses();
		$wcStates = [self::IGNORE => _x('- no mapping / defaults -', 'global_settings', 'btcpay-greenfield-for-woocommerce')] + $wcStates;
		$orderStates = get_option($value['id']);
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">Order States:</th>
			<td class="forminp" id="<?php echo esc_attr($value['id']) ?>">
				<table cellspacing="0">
					<?php

					foreach ($btcpayStates as $btcpayState => $btcpayName) {
						?>
						<tr>
							<th><?php echo esc_html($btcpayName); ?></th>
							<td>
								<select name="<?php echo esc_attr($value['id']) ?>[<?php echo esc_html($btcpayState); ?>]">
									<?php

									foreach ($wcStates as $wcState => $wcName) {
										$selectedOption = $orderStates[$btcpayState];

										if (true === empty($selectedOption)) {
											$selectedOption = $defaultStates[$btcpayState];
										}

										if ($selectedOption === $wcState) {
											echo '<option value="' . esc_attr($wcState) . '" selected>' . esc_html($wcName) . '</option>' . PHP_EOL;
										} else {
											echo '<option value="' . esc_attr($wcState) . '">' . esc_html($wcName) . '</option>' . PHP_EOL;
										}
									}
									?>
								</select>
							</td>
						</tr>
						<?php
					}

					?>
				</table>
				<p class="description">
					<?php echo _x( 'By keeping default behavior for the "Settled" status you make sure that WooCommerce handles orders of virtual and downloadable products only properly and set those orders to "complete" instead of "processing" like for orders containing physical products.', 'global_settings', 'btcpay-greenfield-for-woocommerce' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}
}

