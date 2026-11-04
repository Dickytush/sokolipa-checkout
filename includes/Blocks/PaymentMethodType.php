<?php
/**
 * Block checkout payment method.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Sokolipa\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the block checkout how to render Sokolipa and what to send back.
 */
final class PaymentMethodType extends AbstractPaymentMethodType {

	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = Plugin::GATEWAY_ID;

	/**
	 * Loads settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Plugin::GATEWAY_ID . '_settings', array() );
	}

	/**
	 * Active when the gateway is.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = Plugin::gateway();
		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Registers the script.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'sokolipa-blocks',
			SOKOLIPA_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			SOKOLIPA_VERSION,
			true
		);
		return array( 'sokolipa-blocks' );
	}

	/**
	 * Data the script reads through wc.wcSettings.getSetting( 'sokolipa_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = Plugin::gateway();
		return array(
			'title'       => $gateway ? $gateway->get_title() : __( 'Mobile money', 'sokolipa-checkout' ),
			'description' => $gateway ? $gateway->get_description() : '',
			'networks'    => $gateway ? $gateway->networks_for_js() : array(),
			'icon'        => $gateway ? $gateway->icon : '',
			'supports'    => array( 'products' ),
			'i18n'        => array(
				'network' => __( 'Network', 'sokolipa-checkout' ),
				'phone'   => __( 'Mobile money number', 'sokolipa-checkout' ),
				'note'    => __( 'After you place the order, a payment prompt opens on this phone. Enter your PIN to pay.', 'sokolipa-checkout' ),
				'invalid' => __( 'Enter the mobile money number to charge.', 'sokolipa-checkout' ),
			),
		);
	}
}
