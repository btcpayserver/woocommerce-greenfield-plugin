import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
// Supplied by WordPress and extracted to a script dependency at build time.
// eslint-disable-next-line import/no-extraneous-dependencies
import { useEffect } from '@wordpress/element';

const makeContent = ( settings, name ) => {
	const Content = ( props ) => {
		const { activePaymentMethod, emitResponse, eventRegistration } = props;
		const onCheckoutSuccess =
			eventRegistration.onCheckoutSuccess ||
			eventRegistration.onCheckoutAfterProcessingWithSuccess;

		useEffect( () => {
			if ( typeof onCheckoutSuccess !== 'function' ) {
				return undefined;
			}

			return onCheckoutSuccess( ( checkoutData ) => {
				if ( activePaymentMethod !== name ) {
					return true;
				}

				// WooCommerce renamed this value while retaining the legacy event.
				// Support both shapes across the plugin's supported WC versions.
				const paymentResult =
					checkoutData.processingResponse ||
					checkoutData.paymentResult;

				// Without modal checkout, WooCommerce should follow the gateway's
				// regular redirect and this observer must not interfere.
				if (
					checkoutData.redirectUrl ||
					paymentResult?.redirectUrl ||
					window.BTCPayWP?.modalEnabled === false
				) {
					return true;
				}

				const paymentDetails = paymentResult?.paymentDetails || {};
				const modal = window.btcpayGreenfieldBlocks;

				if ( modal?.showInvoice?.( paymentDetails ) ) {
					return true;
				}

				return {
					type: emitResponse.responseTypes.ERROR,
					message:
						window.BTCPayWP?.textProcessingError ||
						'Error processing checkout. Please try again or choose another payment option.',
					retry: true,
				};
			} );
		}, [
			activePaymentMethod,
			emitResponse.responseTypes.ERROR,
			onCheckoutSuccess,
		] );

		return decodeEntities( settings.description || '' );
	};

	return Content;
};

const makeLabel = ( settings, labelText ) => ( props ) => {
	const { PaymentMethodLabel } = props.components;
	const icon = settings.icon || '';
	return (
		<div className="btcpay-payment-method-label">
			{ icon && (
				<img
					src={ icon }
					alt="BTCPay Bitcoin payment icon"
					className="btcpay-payment-icon"
					style={ {
						width: '50px',
						marginRight: '10px',
						verticalAlign: 'middle'
					} }
				/>
			) }
			<PaymentMethodLabel text={ labelText } />
		</div>
	);
};

const registerBTCPayGateway = ( name, defaultTitle ) => {
	const settings = getSetting( `${ name }_data`, {} );

	if ( ! settings || Object.keys( settings ).length === 0 ) {
		return;
	}

	const label = decodeEntities( settings.title ) || defaultTitle;
	const Content = makeContent( settings, name );
	const Label = makeLabel( settings, label );

	registerPaymentMethod( {
		name: name,
		label: <Label />,
		content: <Content />,
		edit: <Content />,
		canMakePayment: () => true,
		ariaLabel: label,
		supports: {
			features: settings.supports,
		},
	} );
};

registerBTCPayGateway(
	'btcpaygf_default',
	__( 'Bitcoin / Lightning Network over BTCPay Server', 'btcpay-greenfield-for-woocommerce' )
);

const paymentMethodData = getSetting( 'paymentMethodData', {} );
Object.keys( paymentMethodData ).forEach( ( name ) => {
	if ( name.startsWith( 'btcpaygf_' ) && name !== 'btcpaygf_default' ) {
		registerBTCPayGateway( name, name );
	}
} );
