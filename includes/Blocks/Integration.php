<?php
/**
 * WooCommerce block checkout support.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Blocks;

use Sokolipa\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the payment method with the block checkout and hands its fields to the gateway.
 */
final class Integration {

	/**
	 * Hooks in.
	 */
	public function register() {
		// Fires at init (priority 5); woocommerce_blocks_loaded has already fired by the time plugins_loaded 11 runs.
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_method' ) );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'payment_data' ) );
	}

	/**
	 * Registers the block payment method type.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registry.
	 */
	public function register_method( $registry ) {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		$registry->register( new PaymentMethodType() );
	}

	/**
	 * The block checkout posts the network and phone as payment data; the gateway reads $_POST.
	 *
	 * @param \Automattic\WooCommerce\StoreApi\Payments\PaymentContext $context Payment context.
	 */
	public function payment_data( $context ) {
		if ( Plugin::GATEWAY_ID !== $context->payment_method ) {
			return;
		}
		$data = is_array( $context->payment_data ) ? $context->payment_data : array();
		if ( isset( $data['sokolipa_network'] ) ) {
			$_POST['sokolipa_network'] = sanitize_key( $data['sokolipa_network'] );
		}
		if ( isset( $data['sokolipa_phone'] ) ) {
			$_POST['sokolipa_phone'] = sanitize_text_field( $data['sokolipa_phone'] );
		}
	}
}
