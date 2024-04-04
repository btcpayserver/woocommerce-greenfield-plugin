jQuery(document).ready(function($) {
	jQuery(document).on('click', '.btcpay-review-notice button.btcpay-review-dismiss', function() {
		$.ajax({
			url: BTCPayNotifications.ajax_url,
			type: 'post',
			data: {
				action: 'btcpaygf_notifications',
				nonce: BTCPayNotifications.nonce
			},
			success : function(data) {
				window.location.reload(true);
			}
		});
	});
	jQuery(document).on('click', '.btcpay-review-notice button.btcpay-review-dismiss-forever', function() {
		$.ajax({
			url: BTCPayNotifications.ajax_url,
			type: 'post',
			data: {
				action: 'btcpaygf_notifications',
				nonce: BTCPayNotifications.nonce,
				dismiss_forever: true
			},
			success : function(data) {
				window.location.reload(true);
			}
		});
	});
});
