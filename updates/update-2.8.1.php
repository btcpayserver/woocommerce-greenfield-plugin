<?php

/**
 * Update 2.8.1
 */

/**
 * Remove the retired option that enabled sending customer data to BTCPay Server.
 */
\BTCPayServer\WC\Helper\Logger::debug('Update 2.8.1: Starting ...', true);
delete_option('btcpay_gf_send_customer_data');
\BTCPayServer\WC\Helper\Logger::debug('Update 2.8.1: Finished.', true);
