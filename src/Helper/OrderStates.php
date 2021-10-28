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

	public function getDefaultOrderStateMappings(): array {
		return [
			self::NEW => 'wc-pending',
			self::PROCESSING => 'wc-on-hold',
			self::SETTLED => 'wc-processing',
			self::SETTLED_PAID_OVER => 'wc-processing',
			self::INVALID => 'wc-failed',
			self::EXPIRED => 'wc-cancelled',
			self::EXPIRED_PAID_PARTIAL => 'wc-failed'
		];
	}

	public function getOrderStateLabels(): array {
		return [
			self::NEW => __('New', BTCPAYSERVER_TEXTDOMAIN),
			self::PROCESSING => __('Paid', BTCPAYSERVER_TEXTDOMAIN),
			self::SETTLED => __('Settled', BTCPAYSERVER_TEXTDOMAIN),
			self::SETTLED_PAID_OVER => __('Settled (paid over)', BTCPAYSERVER_TEXTDOMAIN),
			self::INVALID => __('Invalid', BTCPAYSERVER_TEXTDOMAIN),
			self::EXPIRED => __('Expired', BTCPAYSERVER_TEXTDOMAIN),
			self::EXPIRED_PAID_PARTIAL => __('Expired with partial payment', BTCPAYSERVER_TEXTDOMAIN)
		];
	}

	public function renderOrderStatesHtml($value) {
		// Todo: mabye refactor to be done in a separate template file.
		$btcpayStates = $this->getOrderStateLabels();
		$defaultStates = $this->getDefaultOrderStateMappings();

		$wcStates = wc_get_order_statuses();
		$wcStates = ['BTCPAY_IGNORE' => __('- do nothing -', 'BTCPAYSERVER_TEXTDOMAIN')] + $wcStates;
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
							<th><?php echo $btcpayName; ?></th>
							<td>
								<select name="<?php echo esc_attr($value['id']) ?>[<?php echo $btcpayState; ?>]">
									<?php

									foreach ($wcStates as $wcState => $wcName) {
										$selectedOption = $orderStates[$btcpayState];

										if (true === empty($selectedOption)) {
											$selectedOption = $defaultStates[$btcpayState];
										}

										if ($selectedOption === $wcState) {
											echo "<option value=\"$wcState\" selected>$wcName</option>" . PHP_EOL;
										} else {
											echo "<option value=\"$wcState\">$wcName</option>" . PHP_EOL;
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
			</td>
		</tr>
		<?php
	}
}

