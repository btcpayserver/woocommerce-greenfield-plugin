<?php

namespace BTCPayServer\WC\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class SeparateGatewayBlocks extends AbstractPaymentMethodType {

	private $gateway;
	private string $gatewayId;

	public function __construct( string $gatewayId ) {
		$this->gatewayId = $gatewayId;
		$this->name = $gatewayId;
	}

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . $this->gatewayId . '_settings', [] );
		$gateways = \WC()->payment_gateways->payment_gateways();
		$this->gateway = $gateways[ $this->gatewayId ] ?? null;
	}

	public function is_active(): bool {
		return $this->gateway && $this->gateway->is_available();
	}

	public function get_payment_method_script_handles(): array {
		$script_url = BTCPAYSERVER_PLUGIN_URL . 'assets/js/frontend/blocks.js';
		$script_asset_path = BTCPAYSERVER_PLUGIN_FILE_PATH . 'assets/js/frontend/blocks.asset.php';
		$script_asset = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: array(
				'dependencies' => array(),
				'version' => BTCPAYSERVER_VERSION
			);

		wp_register_script(
			'btcpay-gateway-blocks',
			$script_url,
			$script_asset[ 'dependencies' ],
			$script_asset[ 'version' ],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'btcpay-gateway-blocks',
				'btcpay-greenfield-for-woocommerce',
				BTCPAYSERVER_PLUGIN_FILE_PATH . 'languages/'
			);
		}

		return [ 'btcpay-gateway-blocks' ];
	}

	public function get_payment_method_data(): array {
		return [
			'title' => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports' => $this->gateway
				? array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] )
				: [ 'products' ],
			'icon' => $this->gateway ? $this->gateway->getIcon() : '',
		];
	}
}
