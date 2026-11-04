<?php
/**
 * The waiting screen on the order-received page.
 *
 * @package Sokolipa
 */

namespace Sokolipa;

use Sokolipa\Networks\Registry;
use Sokolipa\Networks\StatusResult;

defined( 'ABSPATH' ) || exit;

/**
 * While the buyer approves the prompt, the order-received page shows a live panel
 * that polls the status route and updates itself.
 */
final class ThankYou {

	/**
	 * Hooks in.
	 */
	public function register() {
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'heading' ), 10, 2 );
		add_action( 'woocommerce_before_thankyou', array( $this, 'panel' ), 5 );
		add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'delivery_place' ) );
	}

	/**
	 * Replaces "Thank you. Your order has been received." while payment is pending.
	 *
	 * @param string         $text  Default text.
	 * @param \WC_Order|null $order Order.
	 * @return string
	 */
	public function heading( $text, $order ) {
		if ( ! $this->waiting( $order ) ) {
			return $text;
		}
		return __( 'Almost there. Approve the payment on your phone to complete this order.', 'sokolipa-checkout' );
	}

	/**
	 * The live panel.
	 *
	 * @param int $order_id Order id.
	 */
	public function panel( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $this->waiting( $order ) ) {
			return;
		}

		$network = Registry::get( (string) $order->get_meta( Orders::META_NETWORK ) );
		$status  = (string) $order->get_meta( Orders::META_STATUS );
		$reason  = (string) $order->get_meta( Orders::META_REASON );
		$msisdn  = (string) $order->get_meta( Orders::META_MSISDN );

		wp_enqueue_style( 'sokolipa-checkout', SOKOLIPA_URL . 'assets/css/checkout.css', array(), SOKOLIPA_VERSION );
		wp_enqueue_script( 'sokolipa-waiting', SOKOLIPA_URL . 'assets/js/waiting.js', array(), SOKOLIPA_VERSION, true );
		wp_localize_script(
			'sokolipa-waiting',
			'sokolipaWaiting',
			array(
				'orderId' => $order->get_id(),
				'key'     => $order->get_order_key(),
				'restUrl' => esc_url_raw( rest_url( 'sokolipa/v1/' ) ),
				'status'  => $status,
				'i18n'    => array(
					'waiting' => __( 'Waiting for you to approve the prompt…', 'sokolipa-checkout' ),
					'paid'    => __( 'Payment received. Thank you!', 'sokolipa-checkout' ),
					'failed'  => __( 'The payment did not go through.', 'sokolipa-checkout' ),
					'sending' => __( 'Sending a new prompt…', 'sokolipa-checkout' ),
					'sent'    => __( 'A new prompt is on its way to your phone.', 'sokolipa-checkout' ),
					'slow'    => __( 'No prompt yet? Make sure the phone is on and has signal. You can send it again below.', 'sokolipa-checkout' ),
					'error'   => __( 'Something went wrong. Please try again.', 'sokolipa-checkout' ),
				),
			)
		);

		$networks = array();
		foreach ( Registry::available( $order->get_currency() ) as $id => $n ) {
			$networks[ $id ] = $n->label();
		}
		?>
		<div class="sokolipa-waiting" id="sokolipa-waiting" data-status="<?php echo esc_attr( $status ); ?>">
			<div class="sokolipa-waiting__spinner" aria-hidden="true"></div>
			<p class="sokolipa-waiting__title" id="sokolipa-waiting-title">
				<?php
				if ( StatusResult::FAILED === $status ) {
					echo esc_html( '' !== $reason ? $reason : __( 'The payment did not go through.', 'sokolipa-checkout' ) );
				} else {
					echo esc_html__( 'Check your phone', 'sokolipa-checkout' );
				}
				?>
			</p>
			<p class="sokolipa-waiting__text" id="sokolipa-waiting-text">
				<?php
				printf(
					/* translators: 1: network, 2: masked phone, 3: amount */
					esc_html__( 'A %1$s prompt was sent to %2$s for %3$s. Enter your PIN to pay.', 'sokolipa-checkout' ),
					esc_html( $network ? $network->label() : __( 'mobile money', 'sokolipa-checkout' ) ),
					esc_html( Phone::mask( $msisdn ) ),
					wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) )
				);
				?>
			</p>
			<form class="sokolipa-waiting__resend" id="sokolipa-resend" <?php echo StatusResult::FAILED === $status ? '' : 'hidden'; ?>>
				<label for="sokolipa_resend_phone"><?php esc_html_e( 'Send the prompt again (change the number if it was wrong):', 'sokolipa-checkout' ); ?></label>
				<div class="sokolipa-waiting__row">
					<?php if ( count( $networks ) > 1 ) : ?>
						<select id="sokolipa_resend_network" name="network">
							<?php foreach ( $networks as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $network && $network->id() === $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<input type="tel" id="sokolipa_resend_phone" name="phone" inputmode="tel" value="<?php echo esc_attr( $msisdn ); ?>" />
					<button type="submit" class="button alt"><?php esc_html_e( 'Send prompt again', 'sokolipa-checkout' ); ?></button>
				</div>
				<p class="sokolipa-waiting__error" id="sokolipa-resend-error" hidden></p>
			</form>
		</div>
		<?php
	}

	/**
	 * The delivery place on the order-received page and in My Account.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function delivery_place( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$place = Orders::delivery_place_to_print( $order );
		if ( '' === $place ) {
			return;
		}
		echo '<section class="woocommerce-customer-details--sokolipa"><h2 class="woocommerce-column__title">' . esc_html__( 'Delivery place', 'sokolipa-checkout' ) . '</h2><p>' . nl2br( esc_html( $place ) ) . '</p></section>';
	}

	/**
	 * Whether this order is a Sokolipa order still waiting for approval (or just failed).
	 *
	 * @param mixed $order Order.
	 * @return bool
	 */
	private function waiting( $order ) {
		return $order instanceof \WC_Order
			&& Plugin::GATEWAY_ID === $order->get_payment_method()
			&& ! $order->is_paid()
			&& '' !== (string) $order->get_meta( Orders::META_NETWORK );
	}
}
