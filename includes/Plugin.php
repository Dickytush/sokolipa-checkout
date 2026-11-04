<?php
/**
 * Plugin bootstrap.
 *
 * @package Sokolipa
 */

namespace Sokolipa;

use Sokolipa\Networks\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the gateway, the checkout changes, the REST routes and the poller together.
 */
final class Plugin {

	const GATEWAY_ID = 'sokolipa';

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boot once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Registers everything, or an admin notice when WooCommerce is missing.
	 */
	private function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
			return;
		}

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SOKOLIPA_FILE ), array( $this, 'action_links' ) );

		( new Callbacks() )->register();
		( new Poller() )->register();
		( new SimpleCheckout() )->register();
		( new Admin\OrderScreen() )->register();
		( new Blocks\Integration() )->register();
		( new ThankYou() )->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the gateway to WooCommerce's list.
	 *
	 * @param array $gateways Gateway class names.
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = Gateway::class;
		return $gateways;
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . self::GATEWAY_ID );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'sokolipa-checkout' ) . '</a>' );
		return $links;
	}

	/**
	 * Front-end assets on the checkout page (the order-received page loads its own in ThankYou).
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$gateway = self::gateway();
		if ( ! $gateway || ! $gateway->is_available() ) {
			return; // Not offered to this visitor: load nothing.
		}

		wp_enqueue_style( 'sokolipa-checkout', SOKOLIPA_URL . 'assets/css/checkout.css', array(), SOKOLIPA_VERSION );

		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			return; // The block checkout renders its own fields through assets/js/blocks.js.
		}

		$data = array(
			'networks' => $gateway->networks_for_js(),
			'restUrl'  => esc_url_raw( rest_url( 'sokolipa/v1/' ) ),
			'i18n'     => array(
				/* translators: 1: network name, 2: example phone number */
				'phoneHint' => __( 'Enter the number registered on %1$s, for example %2$s', 'sokolipa-checkout' ),
			),
		);

		wp_enqueue_script( 'sokolipa-checkout', SOKOLIPA_URL . 'assets/js/checkout.js', array( 'jquery' ), SOKOLIPA_VERSION, true );
		wp_localize_script( 'sokolipa-checkout', 'sokolipaData', $data );
	}

	/**
	 * The gateway instance WooCommerce holds, when loaded.
	 *
	 * @return Gateway|null
	 */
	public static function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ self::GATEWAY_ID ] ) && $gateways[ self::GATEWAY_ID ] instanceof Gateway ? $gateways[ self::GATEWAY_ID ] : null;
	}

	/**
	 * Notice when WooCommerce is not active.
	 */
	public function notice_missing_woocommerce() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Sokolipa Checkout needs WooCommerce to be installed and active.', 'sokolipa-checkout' ) . '</p></div>';
	}

	/**
	 * Activation: schedule the status poller.
	 */
	public static function activate() {
		Poller::schedule();
	}

	/**
	 * Deactivation: clear the poller. Nothing else is touched; orders keep their data.
	 */
	public static function deactivate() {
		Poller::unschedule();
	}
}
