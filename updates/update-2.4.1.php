<?php

/**
 * Update 2.4.1
 */

/**
 * Set new option to protect wc-processing and wc-completed orders by default.
 */
\BTCPayServer\WC\Helper\Logger::debug('Update 2.4.1: Starting ...', true);
update_option('btcpay_gf_protect_order_status', 'yes');
\BTCPayServer\WC\Helper\Logger::debug('Update 2.4.1: Finished.', true);
