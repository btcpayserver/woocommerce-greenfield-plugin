
// Add debouncing to avoid infinite loop.
let isProcessingOrder = false;
let lastExecutionTime = 0;
const debounceInterval = 1000;
let invoiceCreatedForOrder = null;

let originalButtonText = null;

const setButtonProcessing = function (processing) {
	const textEl = document.querySelector('.wc-block-components-checkout-place-order-button__text');
	if (!textEl) return;
	if (processing) {
		originalButtonText = textEl.textContent;
		textEl.textContent = BTCPayWP.textProcessingButton || 'Processing…';
	} else if (originalButtonText) {
		textEl.textContent = originalButtonText;
		originalButtonText = null;
	}
};

/**
 * Subscribe to the checkout store and listen to place order button event,
 * which changes emits the isBeforeProcessing() event.
 */
wp.data.subscribe(() => {
	const now = Date.now();

	// Check if the function was executed recently.
	if (now - lastExecutionTime < debounceInterval) {
		return;
	}

	const isBeforeProcessing = wp.data.select(wc.wcBlocksData.CHECKOUT_STORE_KEY).isBeforeProcessing();

	// Check the payment method on placing the order.
	if (isBeforeProcessing && !isProcessingOrder) {
		//console.log('Checkout is before processing. Run your custom code here.');

		isProcessingOrder = true; // Set the flag to avoid re-triggering.
		lastExecutionTime = now; // Update the last execution time.

		const store = wp.data.select(wc.wcBlocksData.PAYMENT_STORE_KEY);
		const currentState = store.getState();
		const activePM = currentState.activePaymentMethod;

		//console.log('current payment method:');
		//console.log(activePM);

		if (activePM.startsWith('btcpaygf_')) {
			//console.log('BTCPay is selected');
			setButtonProcessing(true);

			// Check for validation errors before proceeding.
			// This ensures required fields (address, email, etc.) are filled in.
			const validationStore = wp.data.select(wc.wcBlocksData.VALIDATION_STORE_KEY);
			if (validationStore && validationStore.hasValidationErrors()) {
				// Make all validation errors visible to the user.
				wp.data.dispatch(wc.wcBlocksData.VALIDATION_STORE_KEY).showAllValidationErrors();
				isProcessingOrder = false;
				return;
			}

			// Make sure the order exists and invoice is created.
			let responseData = blocksProcessOrder(activePM);
			//console.log(responseData);
			if (responseData) {
				//console.log('got response: ');
				//console.log(responseData);
				// Reset checkout to idle to prevent WooCommerce blocks from also
				// sending its own REST checkout request (which would call
				// process_payment a second time and create a duplicate invoice).
				resetCheckout();
				blocksShowBTCPayModal(responseData);
				// Keep isProcessingOrder = true while modal is open to prevent duplicate invoices.
				return false;
			} else {
				blocksSubmitError(BTCPayWP.textProcessingError);
				isProcessingOrder = false;
				return false;
			}
		}

		return true;
	}


});

/**
 * Trigger ajax request to create order object and assign an invoice id.
 */
const blocksProcessOrder = function (paymentGateway) {
	//console.log('Triggered processOrderBlocks()');
	let responseData = null;

	// Block the UI.
	//blockElement('.woocommerce-checkout-payment');
	const checkout = wp.data.select(wc.wcBlocksData.CHECKOUT_STORE_KEY);
	const orderId = checkout.getOrderId();

	// Prevent duplicate invoice creation for the same order.
	if (orderId && orderId === invoiceCreatedForOrder) {
		return null;
	}

	// Prepare form data.
	let data = {
		'action': 'btcpaygf_modal_blocks_checkout',
		'orderId': orderId,
		'paymentGateway': paymentGateway,
		'apiNonce': BTCPayWP.apiNonce,
	};

	//console.log(data);
	// We need to make sure the order processing worked before returning from this function.
	jQuery.ajaxSetup({async: false});

	jQuery.post(wc_add_to_cart_params.ajax_url, data, function (response) {
		//console.log('Received response when processing order: ');
		//console.log(response);

		if (response.data.invoiceId) {
			invoiceCreatedForOrder = orderId;
			responseData = response.data;
		} else {
			///unblockElement('.woocommerce-checkout-payment');
			// Show errors.
			if (response.data) {
				blocksSubmitError(response.data);
			} else {
				blocksSubmitError(BTCPayWP.textProcessingError); // eslint-disable-line max-len
			}
		}
	}).fail(function () {
		///unblockElement('.woocommerce-checkout-payment');
		blocksSubmitError(BTCPayWP.textProcessingError);
		console.error('Error on ajax request 2');
	});

	// Reenable async.
	jQuery.ajaxSetup({async: true});

	return responseData;
};

/**
 * Show the BTCPay modal and listen to events sent by BTCPay server.
 */
const blocksShowBTCPayModal = function (data) {
	//console.log('Triggered blocksShowBTCPModal()');

	if (data.invoiceId !== undefined) {
		window.btcpay.setApiUrlPrefix(BTCPayWP.apiUrl);
		window.btcpay.onModalWillEnter(function () {
			setButtonProcessing(false);
		});
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
						isProcessingOrder = false;
						invoiceCreatedForOrder = null;
						blocksSubmitError(BTCPayWP.textInvoiceExpired);
						break;
					case 'invalid':
						window.btcpay.hideFrame();
						isProcessingOrder = false;
						invoiceCreatedForOrder = null;
						blocksSubmitError(BTCPayWP.textInvoiceInvalid);
						break;
				}
			}
		} else { // handle event.data "loaded" "closed"
			if (event.data === 'close') {
				if (invoice_paid === true) {
					window.location = data.orderCompleteLink;
				}
				isProcessingOrder = false;
				invoiceCreatedForOrder = null;
				blocksSubmitError(BTCPayWP.textModalClosed);
			}
		}
	});
	const isObject = obj => {
		return Object.prototype.toString.call(obj) === '[object Object]'
	}
}

/**
 * Show errors on the checkout page.
 *
 * @param error_message
 */
const blocksSubmitError = function (error_message) {
	setButtonProcessing(false);
	window.wp.data.dispatch( 'core/notices' )
		.createErrorNotice(
			error_message,
			{ context: 'wc/checkout' }
		);
	resetCheckout();
};

/**
 * Reset the checkout store to reenable the place order button.
 */
const resetCheckout = function () {
	wp.data.dispatch( wc.wcBlocksData.CHECKOUT_STORE_KEY ).__internalSetIdle();
}
