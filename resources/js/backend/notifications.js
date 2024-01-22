jQuery(document).ready(function($) {
	jQuery(document).on('click', '.btcpay-review-notice button.notice-dismiss', function() {
		$.ajax({
			url: BTCPayNotifications.ajax_url,
			type: 'post',
			data: {
				action: 'btcpaygf_notifications',
				nonce: BTCPayNotifications.nonce
			}
		});
	});
});
