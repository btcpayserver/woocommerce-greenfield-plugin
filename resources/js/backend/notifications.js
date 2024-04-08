jQuery(document).ready(function($) {
	jQuery(document).on('click', '.btcpay-review-notice button.btcpay-review-dismiss', function(e) {
		e.preventDefault();
		$.ajax({
			url: BTCPayNotifications.ajax_url,
			type: 'post',
			data: {
				action: 'btcpaygf_notifications',
				nonce: BTCPayNotifications.nonce
			},
			success: function(data) {
				jQuery('.btcpay-review-notice').remove();
			}
		});
	});
	jQuery(document).on('click', '.btcpay-review-notice button.btcpay-review-dismiss-forever', function(e) {
		e.preventDefault();
		$.ajax({
			url: BTCPayNotifications.ajax_url,
			type: 'post',
			data: {
				action: 'btcpaygf_notifications',
				nonce: BTCPayNotifications.nonce,
				dismiss_forever: true
			},
			success: function(data) {
				jQuery('.btcpay-review-notice').remove();
			}
		});
	});
});
