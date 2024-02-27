jQuery(document).ready(function($) {
	function isValidUrl(serverUrl) {
		try {
			const url = new URL(serverUrl);
			if (url.protocol !== 'https:' && url.protocol !== 'http:') {
				return false;
			}
			if (url.hostname.endsWith('.local')) {
				alert('You entered a .local domain which only works on your local network. Please make sure your BTCPay Server is reachable on the internet if you want to use it in production. Check the tooltip for this field for more information. Aborting.');
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

	// Handle manual connection settings.
	const showDetails = $('#btcpay_gf_connection_details');
	const detailFields = $('#btcpay_gf_store_id, #btcpay_gf_whsecret, #btcpay_gf_api_key, #btcpay_gf_whstatus');

	toggleFields(showDetails.is(':checked'));

	showDetails.on('change', function() {
		toggleFields($(this).is(':checked'));
	});

	function toggleFields(isChecked) {
		if (isChecked) {
			detailFields.closest('tr').show();
		} else {
			detailFields.closest('tr').hide();
		}
	}

});
