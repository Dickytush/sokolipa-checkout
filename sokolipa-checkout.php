<?php
/**
 * Plugin Name:       Sokolipa Checkout
 * Plugin URI:        https://github.com/Dickytush/sokolipa-checkout
 * Description:       Free, open-source mobile-money checkout for African stores. Buyers enter their name, phone, network and delivery place, approve a payment prompt on their phone, and the order is paid. M-Pesa, MTN MoMo and Airtel Money with your own keys. Developed by Snap Pay Solutions.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Snap Pay Solutions
 * Author URI:        https://snapaysolutions.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sokolipa-checkout
 * WC requires at least: 8.2
 * WC tested up to:   10.5
 *
 * @package Sokolipa
 */

defined( 'ABSPATH' ) || exit;

define( 'SOKOLIPA_VERSION', '0.1.0' );
define( 'SOKOLIPA_FILE', __FILE__ );
define( 'SOKOLIPA_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOKOLIPA_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for the Sokolipa\ namespace: Sokolipa\Networks\Mpesa -> includes/Networks/Mpesa.php.
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Sokolipa\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class_name, strlen( 'Sokolipa\\' ) ) );
		$path     = SOKOLIPA_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * WooCommerce feature compatibility: custom order tables (HPOS) and the block checkout.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SOKOLIPA_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SOKOLIPA_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( \Sokolipa\Plugin::class, 'instance' ), 11 );

register_activation_hook( SOKOLIPA_FILE, array( \Sokolipa\Plugin::class, 'activate' ) );
register_deactivation_hook( SOKOLIPA_FILE, array( \Sokolipa\Plugin::class, 'deactivate' ) );
