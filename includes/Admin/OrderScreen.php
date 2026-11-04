<?php
/**
 * What the merchant sees on the order screen.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Admin;

use Sokolipa\Networks\Registry;
use Sokolipa\Orders;
use Sokolipa\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Payment details and delivery place under the billing address, plus a "check with the network" order action.
 */
final class OrderScreen {

	/**
	 * Hooks in.
	 */
	public function register() {
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'details' ) );
		add_filter( 'woocommerce_order_actions', array( $this, 'actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_sokolipa_check', array( $this, 'check' ) );
	}

	/**
	 * Delivery place and mobile-money details.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function details( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$place = Orders::delivery_place_to_print( $order );
		if ( '' !== $place ) {
			echo '<p class="sokolipa-admin-delivery"><strong>' . esc_html__( 'Delivery place:', 'sokolipa-checkout' ) . '</strong><br>' . nl2br( esc_html( $place ) ) . '</p>';
		}

		if ( Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		$network = Registry::get( (string) $order->get_meta( Orders::META_NETWORK ) );
		$rows    = array(
			__( 'Network', 'sokolipa-checkout' )   => $network ? $network->label() : '—',
			__( 'Phone', 'sokolipa-checkout' )     => (string) $order->get_meta( Orders::META_MSISDN ),
			__( 'Status', 'sokolipa-checkout' )    => (string) $order->get_meta( Orders::META_STATUS ),
			__( 'Receipt', 'sokolipa-checkout' )   => (string) $order->get_meta( Orders::META_RECEIPT ),
			__( 'Reference', 'sokolipa-checkout' ) => (string) $order->get_meta( Orders::META_REF ),
			__( 'Attempts', 'sokolipa-checkout' )  => (string) (int) $order->get_meta( Orders::META_ATTEMPTS ),
		);
		$reason = (string) $order->get_meta( Orders::META_REASON );
		if ( '' !== $reason ) {
			$rows[ __( 'Last reason', 'sokolipa-checkout' ) ] = $reason;
		}

		echo '<div class="sokolipa-admin-details"><h3>' . esc_html__( 'Mobile money', 'sokolipa-checkout' ) . '</h3><table class="widefat striped" style="max-width:480px">';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:40%">' . esc_html( $label ) . '</th><td>' . esc_html( '' !== $value ? $value : '—' ) . '</td></tr>';
		}
		echo '</table></div>';
	}

	/**
	 * Adds "Check payment with the network" to the order actions box.
	 *
	 * @param array     $actions Actions.
	 * @param \WC_Order $order   Order.
	 * @return array
	 */
	public function actions( $actions, $order = null ) {
		if ( $order instanceof \WC_Order && Plugin::GATEWAY_ID === $order->get_payment_method() && ! $order->is_paid() ) {
			$actions['sokolipa_check'] = __( 'Check payment with the network', 'sokolipa-checkout' );
		}
		return $actions;
	}

	/**
	 * Runs the check.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function check( $order ) {
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$result = Orders::confirm( $order, true );
		$order->add_order_note(
			sprintf(
				/* translators: 1: status, 2: reason */
				__( 'Sokolipa: manual check returned "%1$s". %2$s', 'sokolipa-checkout' ),
				$result->status,
				$result->reason
			)
		);
		$order->save();
	}
}
