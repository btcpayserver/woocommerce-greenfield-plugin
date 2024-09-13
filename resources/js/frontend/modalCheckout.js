jQuery(function ($) {
	/**
	 * Main entry point.
	 */
	// Listen on Update cart and change of payment methods.
	$('body').on('init_checkout updated_checkout payment_method_selected', function (event) {
		if (BTCPayWP.modalEnabled == 1) {
			btcpaySelected();
		}
	});

	/**
	 * Trigger ajax request to create order object and assign an invoice id.
	 */
	var processOrder = function () {

		let responseData = null;

		// Block the UI.
		blockElement('.woocommerce-checkout-payment');

		// Prepare form data and additional required data.
		let formData = $('form.checkout').serialize();
		let additionalData = {
			'action': 'btcpaygf_modal_checkout',
			'apiNonce': BTCPayWP.apiNonce,
		};

		let data = $.param(additionalData) + '&' + formData;

		// We need to make sure the order processing worked before returning from this function.
		$.ajaxSetup({async: false});

		$.post(wc_checkout_params.checkout_url, data, function (response) {
			//console.log('Received response when processing order: ');
			//console.log(response);

			if (response.invoiceId) {
				responseData = response;
			} else {
				unblockElement('.woocommerce-checkout-payment');
				// Show errors.
				if (response.messages) {
					submitError(response.messages);
				} else {
					submitError('<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>'); // eslint-disable-line max-len
				}
			}
		}).fail(function () {
			unblockElement('.woocommerce-checkout-payment');
			submitError('<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>');
		});

		// Reenable async.
		$.ajaxSetup({async: true});

		return responseData;
	};

	/**
	 * Show the BTCPay modal and listen to events sent by BTCPay server.
	 */
	var showBTCPayModal = function(data) {
		//console.log('Triggered showBTCPayModal()');

		if (data.invoiceId !== undefined) {
			window.btcpay.setApiUrlPrefix(BTCPayWP.apiUrl);
			window.btcpay.showInvoice(data.invoiceId);
		}
		let invoice_paid = false;
		window.btcpay.onModalReceiveMessage(function (event) {
			if (isObject(event.data)) {
				//console.log('BTCPay modal event: invoiceId: ' + event.data.invoiceId);
				//console.log('BTCPay modal event: status: ' + event.data.status);
				if (event.data.status) {
					switch (event.data.status.toLowerCase()) {
						case 'complete':
						case 'paid':
						case 'processing':
						case 'settled':
							invoice_paid = true;
							setTimeout(function() {
								window.location = data.orderCompleteLink;
							}, 3000);
							break;
						case 'expired':
							window.btcpay.hideFrame();
							submitError(BTCPayWP.textInvoiceExpired);
							break;
						case 'invalid':
							window.btcpay.hideFrame();
							submitError(BTCPayWP.textInvoiceInvalid);
							break;
					}
				}
			} else { // handle event.data "loaded" "closed"
				if (event.data === 'close') {
					if (invoice_paid === true) {
						window.location = data.orderCompleteLink;
					}
					submitError(BTCPayWP.textModalClosed);
				}
			}
		});
		const isObject = obj => {
			return Object.prototype.toString.call(obj) === '[object Object]'
		}
	}

	/**
	 * Block UI of a given element.
	 */
	var blockElement = function (cssClass) {
		//console.log('Triggered blockElement.');

		$(cssClass).block({
			message: null,
			overlayCSS: {
				background: '#fff',
				opacity: 0.6
			}
		});
	};

	/**
	 * Unblock UI of a given element.
	 */
	var unblockElement = function (cssClass) {
		//console.log('Triggered unblockElement.');
		$(cssClass).unblock();
	};

	/**
	 * Show errors, mostly copied from WC checkout.js
	 *
	 * @param error_message
	 */
	var submitError = function (error_message) {
		let $checkoutForm = $('form.checkout');
		$('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
		$checkoutForm.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout woocommerce-error">' + error_message + '</div>'); // eslint-disable-line max-len
		$checkoutForm.removeClass('processing').unblock();
		$checkoutForm.find('.input-text, select, input:checkbox').trigger('validate').trigger('blur');
		scrollToNotices();
		$(document.body).trigger('checkout_error', [error_message]);
		unblockElement('.woocommerce-checkout-payment');
	};

	/**
	 * Scroll to errors on top of form, copied from WC checkout.js.
	 */
	var scrollToNotices = function () {
		var scrollElement = $('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout');

		if (!scrollElement.length) {
			scrollElement = $('form.checkout');
		}

		$.scroll_to_notices(scrollElement);
	};

	/**
	 * Trigger payframe button submit.
	 */
	var submitOrder = function (e) {
		e.preventDefault();
		//console.log('Triggered submitOrder');

		let responseData = processOrder();
		if (responseData) {
			//console.log('Got invoice, opening modal.');
			blockElement('.woocommerce-checkout-payment');
			showBTCPayModal(responseData);
		}

		return false;
	};

	/**
	 * Makes sure to trigger on payment method changes and overriding the default button submit handler.
	 */
	var btcpaySelected = function () {
		var checkout_form = $('form.woocommerce-checkout');
		var selected_gateway = $('form[name="checkout"] input[name="payment_method"]:checked').val();
		unblockElement('.woocommerce-checkout-payment');

		if (!selected_gateway) {
			return;
		}

		if (selected_gateway.startsWith('btcpaygf_')) {
			// Bind our custom event handler to the checkout button.
			checkout_form.on('checkout_place_order', submitOrder);
		} else {
			// Unbind custom event handlers.
			checkout_form.off('checkout_place_order', submitOrder);
		}
	}

});
