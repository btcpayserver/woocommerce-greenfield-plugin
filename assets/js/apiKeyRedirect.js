jQuery(document).ready(function($) {
	function isValidUrl(serverUrl) {
		try {
			const url = new URL(serverUrl);
			if (url.protocol !== 'https:' && url.protocol !== 'http:') {
				return false;
			}
		} catch (e) {
			console.error(e);
			return false;
		}
		return true;
 	}

	$('.btcpay-api-key-link').click(function(e) {
		e.preventDefault();
		const host = $('#btcpay_gf_url').val();
		if (isValidUrl(host)) {
			let data = {
				'action': 'handle_ajax_api_url',
				'host': host,
				'apiNonce': BTCPayGlobalSettings.apiNonce
			};
			jQuery.post(BTCPayGlobalSettings.url, data, function(response) {
				if (response.data.url) {
					window.location = response.data.url;
				}
			}).fail( function() {
				alert('Error processing your request. Please make sure to enter a valid BTCPay Server instance URL.')
			});
		} else {
			alert('Please enter a valid url including https:// in the BTCPay Server URL input field.')
		}
	});
});
