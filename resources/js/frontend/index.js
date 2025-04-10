import { sprintf, __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';

const settings = getSetting( 'btcpaygf_default_data', {} );

const defaultLabel = __(
	'Bitcoin / Lightning Network over BTCPay Server',
	'woo-gutenberg-products-block'
);

const label = decodeEntities( settings.title ) || defaultLabel;

// Get the icon from the settings
const icon = settings.icon || '';
/**
 * Content component
 */
const Content = () => {
	return decodeEntities( settings.description || '' );
};
/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	return (
		<div className="btcpay-payment-method-label">
			{icon && (
				<img
					src={icon}
					alt="BTCPay Bitcoin payment icon"
					className="btcpay-payment-icon"
					style={{
						width: '50px',
						marginRight: '10px',
						verticalAlign: 'middle'
					}}
				/>
			)}
			<PaymentMethodLabel text={ label } />
		</div>
	);
};

/**
 * Payment method config object.
 */
const BTCPayDefault = {
	name: "btcpaygf_default",
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	iconUrl: icon,
	supports: {
		features: settings.supports,
	},
};

registerPaymentMethod( BTCPayDefault );
