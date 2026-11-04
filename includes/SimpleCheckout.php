<?php
/**
 * The four-field checkout: full name, phone, network, delivery place.
 *
 * @package Sokolipa
 */

namespace Sokolipa;

use Sokolipa\Networks\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Trims WooCommerce's classic checkout down to what a mobile-money order needs and
 * adds a "where should we deliver?" box. The block checkout gets the delivery field
 * through WooCommerce's additional-checkout-fields API. Nothing here applies unless
 * this visitor is actually offered Sokolipa, so an unfinished setup never reshapes
 * the checkout for buyers paying another way.
 */
final class SimpleCheckout {

	const FIELD       = 'sokolipa_delivery_place';
	const BLOCK_FIELD = 'sokolipa/delivery-place';

	/**
	 * Hooks in.
	 */
	public function register() {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'fields' ), 50 );
		add_filter( 'woocommerce_enable_order_notes_field', array( $this, 'order_notes' ), 50 );
		add_filter( 'woocommerce_cart_needs_shipping_address', array( $this, 'needs_shipping_address' ), 50 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ) );
		add_action( 'woocommerce_email_customer_details', array( $this, 'email' ), 30, 3 );
		add_action( 'woocommerce_init', array( $this, 'block_field' ) );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'values' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save_block_field' ) );
	}

	/**
	 * Gateway settings read straight from the option, so nothing here instantiates the gateways at init.
	 *
	 * @param string $key      Setting key.
	 * @param string $fallback Value when the setting is missing.
	 * @return string
	 */
	private function setting( $key, $fallback = '' ) {
		static $settings = null;
		if ( null === $settings ) {
			$stored   = get_option( 'woocommerce_' . Plugin::GATEWAY_ID . '_settings', array() );
			$settings = is_array( $stored ) ? $stored : array();
		}
		return isset( $settings[ $key ] ) ? (string) $settings[ $key ] : $fallback;
	}

	/**
	 * Whether the gateway is enabled and this visitor can pick at least one network.
	 *
	 * @return bool
	 */
	private function enabled() {
		if ( 'yes' !== $this->setting( 'enabled', 'no' ) || ! function_exists( 'get_woocommerce_currency' ) ) {
			return false;
		}
		return count( Registry::offered( get_woocommerce_currency() ) ) > 0;
	}

	/**
	 * Whether the merchant switched simple checkout on.
	 *
	 * @return bool
	 */
	private function on() {
		return $this->enabled() && 'yes' === $this->setting( 'simple_checkout', 'yes' );
	}

	/**
	 * Delivery field label from settings.
	 *
	 * @return string
	 */
	private function label() {
		$label = $this->setting( 'delivery_label' );
		return '' !== $label ? $label : __( 'Where should we deliver? (town, estate or street, and a landmark)', 'sokolipa-checkout' );
	}

	/**
	 * Reshapes the classic checkout fields.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function fields( $fields ) {
		if ( ! $this->enabled() ) {
			return $fields;
		}
		if ( ! $this->on() ) {
			$fields['billing'][ self::FIELD ] = $this->delivery_field( 95, false );
			return $fields;
		}

		$billing = isset( $fields['billing'] ) ? $fields['billing'] : array();
		$base    = wc_get_base_location();
		$country = isset( $base['country'] ) ? $base['country'] : '';

		$billing['billing_first_name'] = array(
			'label'        => __( 'Full name', 'sokolipa-checkout' ),
			'placeholder'  => __( 'e.g. Amina Wanjiru Otieno', 'sokolipa-checkout' ),
			'required'     => true,
			'class'        => array( 'form-row-wide' ),
			'autocomplete' => 'name',
			'priority'     => 10,
		);
		$billing['billing_phone']      = array(
			'label'        => __( 'Phone number', 'sokolipa-checkout' ),
			'placeholder'  => Phone::example( $country ? $country : 'KE' ),
			'required'     => true,
			'type'         => 'tel',
			'class'        => array( 'form-row-wide' ),
			'autocomplete' => 'tel',
			'validate'     => array( 'phone' ),
			'priority'     => 20,
		);
		$billing['billing_country']    = array(
			'type'     => 'hidden',
			'default'  => $country,
			'required' => false,
			'class'    => array( 'sokolipa-hidden' ),
			'priority' => 30,
		);

		$registration_required = function_exists( 'WC' ) && WC()->checkout() && WC()->checkout()->is_registration_required();

		if ( $registration_required || 'yes' === $this->setting( 'ask_email', 'no' ) ) {
			$billing['billing_email'] = array(
				'label'        => $registration_required ? __( 'Email', 'sokolipa-checkout' ) : __( 'Email (optional, for your receipt)', 'sokolipa-checkout' ),
				'required'     => $registration_required,
				'type'         => 'email',
				'class'        => array( 'form-row-wide' ),
				'autocomplete' => 'email',
				'validate'     => array( 'email' ),
				'priority'     => 40,
			);
		} else {
			unset( $billing['billing_email'] );
		}

		foreach ( array( 'billing_last_name', 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode' ) as $drop ) {
			unset( $billing[ $drop ] );
		}

		$billing[ self::FIELD ] = $this->delivery_field( 50, true );

		$fields['billing']  = $billing;
		$fields['shipping'] = array();
		unset( $fields['order']['order_comments'] );

		return $fields;
	}

	/**
	 * The delivery place field definition.
	 *
	 * @param int  $priority Position.
	 * @param bool $required Whether the buyer must fill it.
	 * @return array
	 */
	private function delivery_field( $priority, $required ) {
		return array(
			'type'        => 'textarea',
			'label'       => $this->label(),
			'placeholder' => __( 'e.g. Kilimani, Nairobi. Rose Avenue, opposite the petrol station. Call when you arrive.', 'sokolipa-checkout' ),
			'required'    => (bool) $required,
			'class'       => array( 'form-row-wide', 'sokolipa-delivery' ),
			'priority'    => $priority,
		);
	}

	/**
	 * Values for the trimmed form: the full-name box shows first and last name together
	 * for returning buyers, and with the address fields hidden the billing country is
	 * always the store's own (not a geolocated or remembered one the buyer cannot see).
	 *
	 * @param mixed  $value Current value (null unless another plugin set one).
	 * @param string $input Field key.
	 * @return mixed
	 */
	public function values( $value, $input ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only check; WooCommerce verifies the checkout nonce.
		if ( ! $this->on() || ! empty( $_POST[ $input ] ) ) {
			return $value;
		}
		if ( 'billing_country' === $input ) {
			$base = wc_get_base_location();
			return isset( $base['country'] ) ? $base['country'] : $value;
		}
		if ( 'billing_first_name' === $input && function_exists( 'WC' ) && WC()->customer ) {
			$full = trim( WC()->customer->get_billing_first_name() . ' ' . WC()->customer->get_billing_last_name() );
			return '' !== $full ? $full : $value;
		}
		return $value;
	}

	/**
	 * No "order notes" box: the delivery field covers it.
	 *
	 * @param bool $enabled Default.
	 * @return bool
	 */
	public function order_notes( $enabled ) {
		return $this->on() ? false : $enabled;
	}

	/**
	 * No separate shipping address.
	 *
	 * @param bool $needs Default.
	 * @return bool
	 */
	public function needs_shipping_address( $needs ) {
		return $this->on() ? false : $needs;
	}

	/**
	 * Stores the delivery place and splits the full name.
	 *
	 * @param \WC_Order $order Order being created.
	 */
	public function save( $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		$place = isset( $_POST[ self::FIELD ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
		if ( '' !== $place ) {
			$order->update_meta_data( Orders::META_DELIVERY, mb_substr( $place, 0, 500 ) );
		}

		if ( $this->on() ) {
			$full = trim( (string) $order->get_billing_first_name() );
			if ( '' === trim( (string) $order->get_billing_last_name() ) && false !== strpos( $full, ' ' ) ) {
				$parts = preg_split( '/\s+/', $full );
				$first = array_shift( $parts );
				$order->set_billing_first_name( $first );
				$order->set_billing_last_name( implode( ' ', $parts ) );
			}
		}
	}

	/**
	 * Delivery place in order emails.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Plain text email.
	 */
	public function email( $order, $sent_to_admin, $plain_text ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$place = Orders::delivery_place_to_print( $order );
		if ( '' === $place ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Delivery place:', 'sokolipa-checkout' ) . ' ' . esc_html( $place ) . "\n";
			return;
		}
		echo '<h2>' . esc_html__( 'Delivery place', 'sokolipa-checkout' ) . '</h2><p>' . nl2br( esc_html( $place ) ) . '</p>';
	}

	/**
	 * The delivery field on the block checkout (WooCommerce 8.9+).
	 */
	public function block_field() {
		if ( ! $this->enabled() || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::BLOCK_FIELD,
				'label'    => $this->label(),
				'location' => 'order',
				'type'     => 'text',
				'required' => $this->on(),
			)
		);
	}

	/**
	 * Copies the block-checkout field into the same order meta the classic checkout uses.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function save_block_field( $order ) {
		if ( ! $order instanceof \WC_Order || ! function_exists( 'wc_get_container' ) ) {
			return;
		}
		$class = '\\Automattic\\WooCommerce\\Blocks\\Domain\\Services\\CheckoutFields';
		if ( ! class_exists( $class ) ) {
			return;
		}
		try {
			$fields = wc_get_container()->get( $class );
			$value  = $fields->get_field_from_object( self::BLOCK_FIELD, $order, 'other' );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$order->update_meta_data( Orders::META_DELIVERY, mb_substr( sanitize_textarea_field( $value ), 0, 500 ) );
		}
	}
}
