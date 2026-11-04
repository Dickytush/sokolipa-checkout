<?php
/**
 * The WooCommerce payment gateway.
 *
 * @package Sokolipa
 */

namespace Sokolipa;

use Sokolipa\Networks\AbstractNetwork;
use Sokolipa\Networks\NetworkException;
use Sokolipa\Networks\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * One gateway, several networks: the buyer picks the network and types the phone number.
 */
final class Gateway extends \WC_Payment_Gateway {

	/**
	 * Sets up the gateway.
	 */
	public function __construct() {
		$this->id                 = Plugin::GATEWAY_ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'Sokolipa Checkout (mobile money)', 'sokolipa-checkout' );
		$this->method_description = __( 'M-Pesa, MTN MoMo and Airtel Money with your own operator keys. Buyers approve a prompt on their phone; the order is marked paid once the network confirms. Free and open source, developed by Snap Pay Solutions.', 'sokolipa-checkout' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Mobile money', 'sokolipa-checkout' ) );
		$this->description = $this->get_option( 'description', __( 'Pay with M-Pesa, MTN MoMo or Airtel Money. You will get a prompt on your phone.', 'sokolipa-checkout' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );
		$this->icon        = self::icon_data_uri();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( AbstractNetwork::class, 'reset_settings_cache' ), 20 );
	}

	/**
	 * Settings screen fields: general, then one block per network.
	 */
	public function init_form_fields() {
		$fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable', 'sokolipa-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Sokolipa Checkout', 'sokolipa-checkout' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title at checkout', 'sokolipa-checkout' ),
				'type'        => 'text',
				'default'     => __( 'Mobile money', 'sokolipa-checkout' ),
				'desc_tip'    => true,
				'description' => __( 'What buyers see as the payment method name.', 'sokolipa-checkout' ),
			),
			'description'     => array(
				'title'   => __( 'Description', 'sokolipa-checkout' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with M-Pesa, MTN MoMo or Airtel Money. You will get a prompt on your phone.', 'sokolipa-checkout' ),
			),
			'simple_checkout' => array(
				'title'       => __( 'Simple checkout', 'sokolipa-checkout' ),
				'type'        => 'checkbox',
				'label'       => __( 'Ask buyers only for full name, phone number, network and delivery place', 'sokolipa-checkout' ),
				'default'     => 'yes',
				'description' => __( 'Hides the address, company and email fields on the classic checkout and adds a "Where should we deliver?" box. Shipping zones still use your store country.', 'sokolipa-checkout' ),
			),
			'ask_email'       => array(
				'title'   => __( 'Email', 'sokolipa-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Still ask for an email address (optional) so buyers get order emails', 'sokolipa-checkout' ),
				'default' => 'no',
			),
			'delivery_label'  => array(
				'title'   => __( 'Delivery field label', 'sokolipa-checkout' ),
				'type'    => 'text',
				'default' => __( 'Where should we deliver? (town, estate or street, and a landmark)', 'sokolipa-checkout' ),
			),
			'debug'           => array(
				'title'   => __( 'Debug log', 'sokolipa-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Write every request and response to WooCommerce > Status > Logs (source: sokolipa). Keys are never logged.', 'sokolipa-checkout' ),
				'default' => 'no',
			),
		);

		foreach ( Registry::all() as $network ) {
			$fields = array_merge( $fields, $network->form_fields() );
		}

		$fields['about_section'] = array(
			'title'       => __( 'About', 'sokolipa-checkout' ),
			'type'        => 'title',
			'description' => sprintf(
				/* translators: 1: Snap Pay Solutions link, 2: GitHub link */
				__( 'Sokolipa Checkout is free and open source, developed by %1$s as a contribution to African e-commerce. It talks only to the networks you configure above and never sends your keys or your buyers\' data anywhere else. Report issues or add a network on %2$s.', 'sokolipa-checkout' ),
				'<a href="https://snapaysolutions.com" target="_blank" rel="noopener">Snap Pay Solutions</a>',
				'<a href="https://github.com/Dickytush/sokolipa-checkout" target="_blank" rel="noopener">GitHub</a>'
			),
		);

		$this->form_fields = $fields;
	}

	/**
	 * A small neutral phone-with-tick icon, inlined so no extra request is needed.
	 * The operators' own logos are trademarks and are deliberately not used.
	 *
	 * @return string
	 */
	public static function icon_data_uri() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="28" viewBox="0 0 40 28" role="img" aria-label="Mobile money">'
			. '<rect x="13" y="1" width="14" height="26" rx="3" fill="#fff" stroke="#1f2937" stroke-width="2"/>'
			. '<rect x="16" y="5" width="8" height="15" rx="1" fill="#1f2937" opacity=".12"/>'
			. '<circle cx="20" cy="23" r="1.4" fill="#1f2937"/>'
			. '<path d="M17 12.5l2.2 2.2 3.8-4.2" fill="none" stroke="#1f2937" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
			. '<path d="M7 9.5a9.5 9.5 0 0 1 0 9M33 9.5a9.5 9.5 0 0 0 0 9M3.5 6a14 14 0 0 1 0 16M36.5 6a14 14 0 0 0 0 16" fill="none" stroke="#1f2937" stroke-width="1.6" stroke-linecap="round" opacity=".7"/>'
			. '</svg>';
		return 'data:image/svg+xml;charset=utf-8,' . rawurlencode( $svg );
	}

	/**
	 * Offered only when at least one network is configured for the store currency.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		return count( $this->networks() ) > 0;
	}

	/**
	 * Networks this visitor can pick right now (sandbox networks only for store managers).
	 *
	 * @return \Sokolipa\Networks\Network[]
	 */
	public function networks() {
		return Registry::offered( get_woocommerce_currency() );
	}

	/**
	 * Data for the checkout script.
	 *
	 * @return array
	 */
	public function networks_for_js() {
		$out = array();
		foreach ( $this->networks() as $id => $network ) {
			$out[] = array(
				'id'      => $id,
				'label'   => $network->label(),
				'country' => $network->country(),
				'dial'    => Phone::dial_code( $network->country() ),
				'example' => Phone::example( $network->country() ),
			);
		}
		return $out;
	}

	/**
	 * Whether the simple four-field checkout is on.
	 *
	 * @return bool
	 */
	public function simple_checkout() {
		return 'yes' === $this->get_option( 'simple_checkout', 'yes' );
	}

	/**
	 * Network select and phone field on the classic checkout.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo '<p class="sokolipa-description">' . wp_kses_post( wpautop( $this->description ) ) . '</p>';
		}

		$networks = $this->networks();
		$default  = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_phone() : '';
		$chosen   = $this->posted_network();

		echo '<fieldset class="sokolipa-fields" id="sokolipa-fields">';

		echo '<p class="form-row form-row-wide sokolipa-network-row">';
		echo '<label for="sokolipa_network">' . esc_html__( 'Network', 'sokolipa-checkout' ) . ' <span class="required">*</span></label>';
		echo '<span class="sokolipa-networks">';
		$first = true;
		foreach ( $networks as $id => $network ) {
			$checked = ( '' === $chosen && $first ) || $chosen === $id;
			printf(
				'<label class="sokolipa-network sokolipa-network--%1$s"><input type="radio" name="sokolipa_network" value="%1$s" data-country="%2$s" data-dial="%3$s" data-example="%4$s" %5$s /> <span>%6$s</span></label>',
				esc_attr( $id ),
				esc_attr( $network->country() ),
				esc_attr( Phone::dial_code( $network->country() ) ),
				esc_attr( Phone::example( $network->country() ) ),
				checked( $checked, true, false ),
				esc_html( $network->label() )
			);
			$first = false;
		}
		echo '</span></p>';

		printf(
			'<p class="form-row form-row-wide sokolipa-phone-row"><label for="sokolipa_phone">%1$s <span class="required">*</span></label><input id="sokolipa_phone" name="sokolipa_phone" type="tel" inputmode="tel" autocomplete="tel" class="input-text" value="%2$s" placeholder="%3$s" /><span class="sokolipa-phone-hint" id="sokolipa-phone-hint"></span></p>',
			esc_html__( 'Mobile money number', 'sokolipa-checkout' ),
			esc_attr( $default ),
			esc_attr( Phone::example( 'KE' ) )
		);

		echo '<p class="sokolipa-note">' . esc_html__( 'After you place the order, a payment prompt opens on this phone. Enter your PIN to pay.', 'sokolipa-checkout' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Validates the network and the phone before the order is created.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$network = $this->chosen_network();
		if ( ! $network ) {
			wc_add_notice( __( 'Choose the mobile money network to pay with.', 'sokolipa-checkout' ), 'error' );
			return false;
		}

		$msisdn = Phone::normalize( $this->submitted_phone(), $network->country() );
		if ( '' === $msisdn ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: network, 2: example number */
					__( 'Enter the %1$s number to charge, for example %2$s.', 'sokolipa-checkout' ),
					$network->label(),
					Phone::example( $network->country() )
				),
				'error'
			);
			return false;
		}

		return true;
	}

	/**
	 * Sends the prompt and takes the buyer to the waiting screen.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 * @throws \Exception When the prompt cannot be sent; WooCommerce shows the message to the buyer.
	 */
	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$network = $this->chosen_network();

		if ( ! $order instanceof \WC_Order || ! $network ) {
			throw new \Exception( esc_html__( 'Something went wrong starting the payment. Please try again.', 'sokolipa-checkout' ) );
		}

		$msisdn = Phone::normalize( $this->submitted_phone(), $network->country() );
		if ( '' === $msisdn ) {
			throw new \Exception( esc_html__( 'That phone number does not look right for the network chosen.', 'sokolipa-checkout' ) );
		}

		try {
			Orders::start_push( $order, $network, $msisdn );
		} catch ( NetworkException $e ) {
			if ( $e->ambiguous ) {
				// The operator may have sent the prompt anyway: the attempt is recorded, so let the buyer wait and the poller decide.
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
			throw new \Exception( esc_html( $e->getMessage() ) );
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * The network the buyer picked, when it is one we offer.
	 *
	 * @return \Sokolipa\Networks\Network|null
	 */
	private function chosen_network() {
		$networks = $this->networks();
		$id       = $this->posted_network();
		return isset( $networks[ $id ] ) ? $networks[ $id ] : null;
	}

	/**
	 * The network id in the request, also during the checkout's "update order review"
	 * refresh where the form travels serialised in post_data.
	 *
	 * @return string
	 */
	private function posted_network() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		if ( isset( $_POST['sokolipa_network'] ) ) {
			return sanitize_key( wp_unslash( $_POST['sokolipa_network'] ) );
		}
		if ( isset( $_POST['post_data'] ) ) {
			$posted = array();
			wp_parse_str( sanitize_text_field( wp_unslash( $_POST['post_data'] ) ), $posted );
			if ( isset( $posted['sokolipa_network'] ) ) {
				return sanitize_key( $posted['sokolipa_network'] );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return '';
	}

	/**
	 * The phone the buyer typed in the payment box, falling back to the billing phone.
	 *
	 * @return string
	 */
	private function submitted_phone() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		$phone = isset( $_POST['sokolipa_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['sokolipa_phone'] ) ) : '';
		if ( '' !== trim( $phone ) ) {
			return $phone;
		}
		if ( isset( $_POST['billing_phone'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return '';
	}
}
