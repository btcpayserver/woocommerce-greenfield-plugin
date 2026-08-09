( function () {
	let activeInvoice = null;
	let invoicePaid = false;
	let redirectTimer = null;
	let messageHandlerRegistered = false;

	const isObject = ( value ) => {
		return Object.prototype.toString.call( value ) === '[object Object]';
	};

	const resetCheckout = () => {
		window.wp.data
			.dispatch( window.wc.wcBlocksData.CHECKOUT_STORE_KEY )
			.__internalSetIdle();
	};

	const submitError = ( message ) => {
		window.wp.data.dispatch( 'core/notices' ).createErrorNotice( message, {
			context: 'wc/checkout',
		} );
		resetCheckout();
	};

	const clearActiveInvoice = () => {
		activeInvoice = null;
		invoicePaid = false;
		if ( redirectTimer ) {
			window.clearTimeout( redirectTimer );
			redirectTimer = null;
		}
	};

	const redirectToOrder = ( orderCompleteLink ) => {
		window.location.assign( orderCompleteLink );
	};

	const failInvoice = ( message ) => {
		clearActiveInvoice();
		window.btcpay.hideFrame();
		submitError( message );
	};

	const handleModalMessage = ( event ) => {
		if ( ! activeInvoice ) {
			return;
		}

		if ( isObject( event.data ) && event.data.status ) {
			// Ignore stale messages from a previously opened invoice.
			if (
				event.data.invoiceId &&
				event.data.invoiceId !== activeInvoice.invoiceId
			) {
				return;
			}

			switch ( event.data.status.toLowerCase() ) {
				case 'complete':
				case 'paid':
				case 'processing':
				case 'settled':
					invoicePaid = true;
					if ( ! redirectTimer ) {
						redirectTimer = window.setTimeout( () => {
							redirectToOrder( activeInvoice.orderCompleteLink );
						}, 3000 );
					}
					break;
				case 'expired':
					failInvoice( window.BTCPayWP.textInvoiceExpired );
					break;
				case 'invalid':
					failInvoice( window.BTCPayWP.textInvoiceInvalid );
					break;
			}
			return;
		}

		if ( event.data === 'close' ) {
			const completedInvoice = activeInvoice;
			const wasPaid = invoicePaid;
			clearActiveInvoice();

			if ( wasPaid ) {
				redirectToOrder( completedInvoice.orderCompleteLink );
				return;
			}

			submitError( window.BTCPayWP.textModalClosed );
		}
	};

	const showInvoice = ( paymentDetails ) => {
		if (
			! window.btcpay ||
			! isObject( paymentDetails ) ||
			typeof paymentDetails.invoiceId !== 'string' ||
			! paymentDetails.invoiceId ||
			typeof paymentDetails.orderCompleteLink !== 'string' ||
			! paymentDetails.orderCompleteLink
		) {
			return false;
		}

		clearActiveInvoice();
		activeInvoice = {
			invoiceId: paymentDetails.invoiceId,
			orderCompleteLink: paymentDetails.orderCompleteLink,
		};

		if ( ! messageHandlerRegistered ) {
			window.btcpay.onModalReceiveMessage( handleModalMessage );
			messageHandlerRegistered = true;
		}

		window.btcpay.setApiUrlPrefix( window.BTCPayWP.apiUrl );
		window.btcpay.showInvoice( activeInvoice.invoiceId );
		return true;
	};

	window.btcpayGreenfieldBlocks = { showInvoice };
} )();
