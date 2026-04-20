import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';

const makeContent = ( settings ) => () => {
	return decodeEntities( settings.description || '' );
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
	const Content = makeContent( settings );
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
