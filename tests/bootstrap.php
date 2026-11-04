<?php
/**
 * Minimal WordPress and WooCommerce stand-ins so the plugin's logic can be tested
 * with plain PHP: no WordPress install, no database, no network.
 *
 * The stubs only cover what the code under test calls. When a test needs a function
 * that is not here yet, add the smallest stand-in that behaves like WordPress.
 *
 * @package Sokolipa
 */

// phpcs:disable -- test scaffolding, not shipped with the plugin.

define( 'ABSPATH', '/' );
define( 'SOKOLIPA_VERSION', '0.0.0-test' );
define( 'SOKOLIPA_DIR', dirname( __DIR__ ) . '/' );
define( 'SOKOLIPA_URL', 'https://shop.example/wp-content/plugins/sokolipa-checkout/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['OPTS']    = array(); // Options.
$GLOBALS['TR']      = array(); // Transients.
$GLOBALS['ORDERS']  = array(); // Orders by id.
$GLOBALS['MANAGER'] = false;   // Whether the current user can manage WooCommerce.

/* Translation and escaping: identity or plain htmlspecialchars. */
function __( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function wp_specialchars_decode( $text, $quote = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $text, ENT_QUOTES ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }

/* Options, transients and hooks. */
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['OPTS'][ $key ] = $value; return true; }
function get_transient( $key ) { return isset( $GLOBALS['TR'][ $key ] ) ? $GLOBALS['TR'][ $key ] : false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['TR'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['TR'][ $key ] ); return true; }
function apply_filters( $tag, $value ) { return $value; }
function has_filter( $tag, $callback = false ) { return true; }
function add_filter() { return true; }
function wp_next_scheduled( $hook ) { return time() + 60; }
function wp_schedule_event() { return true; }
function current_user_can( $capability ) { return ! empty( $GLOBALS['MANAGER'] ); }

/* Misc helpers. */
function wp_json_encode( $value ) { return json_encode( $value ); }
function rest_url( $path = '' ) { return 'https://shop.example/wp-json/' . ltrim( $path, '/' ); }
function home_url( $path = '' ) { return 'https://shop.example' . $path; }
function wp_date( $format, $timestamp = null, $timezone = null ) { $d = new DateTime( 'now', $timezone ); return $d->format( $format ); }
function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) ); }
function wp_rand( $min = 0, $max = 0 ) { return mt_rand( $min, $max ); }
function get_bloginfo( $show = '' ) { return 'Test Shop'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wc_get_logger() { return new class() { public function log( $level, $message, $context = array() ) {} }; }
function wc_get_order( $id ) { return isset( $GLOBALS['ORDERS'][ (int) $id ] ) ? $GLOBALS['ORDERS'][ (int) $id ] : false; }
function wc_get_orders( $args ) {
	$found = array();
	foreach ( array_reverse( $GLOBALS['ORDERS'], true ) as $order ) {
		$match = true;
		foreach ( isset( $args['meta_query'] ) ? $args['meta_query'] : array() as $clause ) {
			if ( (string) $order->get_meta( $clause['key'] ) !== (string) $clause['value'] ) {
				$match = false;
			}
		}
		if ( $match ) {
			$found[] = $order;
		}
	}
	return isset( $args['limit'] ) && $args['limit'] > 0 ? array_slice( $found, 0, $args['limit'] ) : $found;
}

/** Just enough of WP_REST_Request for the callback parsers. */
class WP_REST_Request {
	private $json;
	public function __construct( $json = array() ) { $this->json = $json; }
	public function get_json_params() { return $this->json; }
}

/** An in-memory order with the WC_Order methods the plugin uses. */
class WC_Order {
	public $meta   = array();
	public $notes  = array();
	public $status = 'pending';
	public $paid   = false;
	public $transaction_id = '';
	public $payment_method = '';
	private $id;
	private $total;
	private $currency;

	public function __construct( $total = 1, $currency = 'KES', $id = 0 ) {
		$this->total    = $total;
		$this->currency = $currency;
		$this->id       = $id ? $id : mt_rand( 1000, 999999 );
		$GLOBALS['ORDERS'][ $this->id ] = $this;
	}
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_order_key() { return 'wc_order_test' . $this->id; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save_meta_data() {}
	public function save() { return $this->id; }
	public function add_order_note( $note ) { $this->notes[] = $note; return count( $this->notes ); }
	public function is_paid() { return $this->paid; }
	public function payment_complete( $transaction_id = '' ) { $this->paid = true; $this->status = 'processing'; $this->transaction_id = $transaction_id; return true; }
	public function set_transaction_id( $id ) { $this->transaction_id = $id; }
	public function has_status( $status ) { return in_array( $this->status, (array) $status, true ); }
	public function set_status( $status ) { $this->status = $status; }
	public function get_status() { return $this->status; }
	public function set_payment_method( $method ) { $this->payment_method = $method; }
	public function get_payment_method() { return $this->payment_method; }
	public function set_payment_method_title( $title ) {}
}

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Sokolipa\\' ) ) {
			return;
		}
		$path = SOKOLIPA_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, 9 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Replaces the gateway settings and clears every cache that reads them.
 *
 * @param array $settings Settings as stored in woocommerce_sokolipa_settings.
 */
function sokolipa_test_settings( array $settings ) {
	$GLOBALS['OPTS']['woocommerce_sokolipa_settings'] = $settings;
	\Sokolipa\Networks\AbstractNetwork::reset_settings_cache();
}

/**
 * Swaps the registry's adapters (for injecting fakes).
 *
 * @param array|null $networks Network instances keyed by id, or null to rebuild from Registry::classes().
 */
function sokolipa_test_networks( $networks ) {
	$property = new ReflectionProperty( \Sokolipa\Networks\Registry::class, 'instances' );
	if ( PHP_VERSION_ID < 80100 ) {
		$property->setAccessible( true ); // A no-op (and later deprecated) from PHP 8.1.
	}
	$property->setValue( null, $networks );
}

/**
 * Calls a private or protected method.
 *
 * @param object|string $target Object, or class name for static methods.
 * @param string        $method Method name.
 * @param mixed         ...$args Arguments.
 * @return mixed
 */
function sokolipa_test_call( $target, $method, ...$args ) {
	$reflection = new ReflectionMethod( is_object( $target ) ? get_class( $target ) : $target, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}
	return $reflection->invoke( is_object( $target ) ? $target : null, ...$args );
}
