jQuery(function ($) {
	// Open media library and get the selected image.
	$('.btcpay-gf-icon-button').click(function (e) {
		e.preventDefault();

		let button = $(this),
			custom_uploader = wp.media({
				title: btcpaygfGatewayData.titleText,
				library: {
					type: 'image'
				},
				button: {
					text: btcpaygfGatewayData.buttonText
				},
				multiple: false
			}).on('select', function () { // it also has "open" and "close" events
				let attachment = custom_uploader.state().get('selection').first().toJSON();
				let url = '';
				if (attachment.sizes.thumbnail !== undefined) {
					url = attachment.sizes.thumbnail.url;
				} else {
					url = attachment.url;
				}
				$('.btcpay-gf-icon-image').attr('src', url).show();
				$('.btcpay-gf-icon-remove').show();
				$('.btcpay-gf-icon-value').val(attachment.id);
				button.hide();
			}).open();
	});

	// Handle removal of media image.
	$('.btcpay-gf-icon-remove').click(function (e) {
		e.preventDefault();

		$('.btcpay-gf-icon-value').val('');
		$('.btcpay-gf-icon-image').hide();
		$(this).hide();
		$('.btcpay-gf-icon-button').show();
	});
});
