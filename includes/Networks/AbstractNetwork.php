<?php
/**
 * Shared plumbing for network adapters: settings, HTTP, tokens, logging.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Networks;

use Sokolipa\Logger;
use Sokolipa\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for adapters.
 */
abstract class AbstractNetwork implements Network {

	/**
	 * Gateway settings, loaded once.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Reads one of this network's settings (the key is prefixed with the network id).
	 *
	 * @param string $key      Unprefixed key, e.g. 'consumer_key'.
	 * @param string $fallback Value when the setting is missing.
	 * @return string
	 */
	protected function setting( $key, $fallback = '' ) {
		if ( null === self::$settings ) {
			$stored         = get_option( 'woocommerce_' . Plugin::GATEWAY_ID . '_settings', array() );
			self::$settings = is_array( $stored ) ? $stored : array();
		}
		$full = $this->id() . '_' . $key;
		return isset( self::$settings[ $full ] ) ? trim( (string) self::$settings[ $full ] ) : $fallback;
	}

	/**
	 * Forget the cached settings (after a save).
	 */
	public static function reset_settings_cache() {
		self::$settings = null;
	}

	/**
	 * True when the network is switched on in settings.
	 *
	 * @return bool
	 */
	protected function enabled() {
		return 'yes' === $this->setting( 'enabled', 'no' );
	}

	/**
	 * 'live' or 'sandbox'.
	 *
	 * @return string
	 */
	protected function env() {
		return 'live' === $this->setting( 'env', 'sandbox' ) ? 'live' : 'sandbox';
	}

	/**
	 * Whether this adapter runs against the live operator host.
	 *
	 * @return bool
	 */
	public function is_live() {
		return 'live' === $this->env();
	}

	/**
	 * Callback URL for this network.
	 *
	 * @return string
	 */
	public function callback_url() {
		return rest_url( 'sokolipa/v1/callback/' . $this->id() );
	}

	/**
	 * Default acknowledgement body.
	 *
	 * @return array
	 */
	public function callback_ack() {
		return array( 'received' => true );
	}

	/**
	 * Order meta key for a network-specific value.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	protected function meta_key( $key ) {
		return '_sokolipa_' . $this->id() . '_' . $key;
	}

	/**
	 * JSON request with logging. Returns the decoded body; throws on transport failure.
	 *
	 * @param string $method  GET or POST.
	 * @param string $url     Full URL.
	 * @param array  $headers Headers.
	 * @param mixed  $body    Array to JSON-encode, a raw string, or null.
	 * @param int    $timeout Seconds.
	 * @return array{status:int, body:array, raw:string}
	 * @throws NetworkException On transport failure.
	 */
	protected function http( $method, $url, array $headers = array(), $body = null, $timeout = 30 ) {
		$args = array(
			'method'    => $method,
			'timeout'   => $timeout,
			'sslverify' => true,
			'headers'   => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => 'SokolipaCheckout/' . SOKOLIPA_VERSION . ' (WordPress; ' . home_url() . ')',
				),
				$headers
			),
		);

		if ( null !== $body ) {
			if ( is_array( $body ) ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $body );
			} else {
				$args['body'] = (string) $body;
			}
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$message   = $response->get_error_message();
			$ambiguous = 'POST' === $method && false !== stripos( $message, 'timed out' );
			Logger::error(
				$this->id() . ': request failed',
				array(
					'url'   => $this->path_of( $url ),
					'error' => $message,
				)
			);
			/* translators: %s: network name */
			$unreachable            = new NetworkException( esc_html( sprintf( __( 'Could not reach %s. Please try again in a moment.', 'sokolipa-checkout' ), $this->label() ) ) );
			$unreachable->ambiguous = $ambiguous;
			throw $unreachable;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		Logger::debug( $this->id() . ': ' . $method . ' ' . $this->path_of( $url ) . ' -> ' . $status, array( 'body' => self::redact( mb_substr( $raw, 0, 600 ) ) ) );

		return array(
			'status' => $status,
			'body'   => is_array( $decoded ) ? $decoded : array(),
			'raw'    => $raw,
		);
	}

	/**
	 * Blanks tokens and phone numbers before a response body reaches the log.
	 *
	 * @param string $text Body excerpt.
	 * @return string
	 */
	public static function redact( $text ) {
		$text = preg_replace( '/("(?:access_token|token|refresh_token|partyId|msisdn|PhoneNumber|PartyA)"\s*:\s*")[^"]*(")/i', '$1[redacted]$2', $text );
		return (string) preg_replace( '/\b\d{9,15}\b/', '[number]', $text );
	}

	/**
	 * Caches an access token in a transient keyed by network + environment + credential fingerprint.
	 *
	 * @param string   $fingerprint Something that changes when credentials change.
	 * @param callable $fetch       Returns array{token:string, ttl:int}.
	 * @return string
	 * @throws NetworkException When the token cannot be obtained.
	 */
	protected function cached_token( $fingerprint, callable $fetch ) {
		$key   = 'sokolipa_tok_' . md5( $this->id() . $this->env() . $fingerprint );
		$token = get_transient( $key );
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}
		$result = $fetch();
		if ( empty( $result['token'] ) ) {
			throw new NetworkException(
				/* translators: %s: network name */
				esc_html( sprintf( __( '%s rejected the API credentials. Check the keys in Sokolipa Checkout settings.', 'sokolipa-checkout' ), $this->label() ) )
			);
		}
		$ttl = isset( $result['ttl'] ) ? max( 30, (int) $result['ttl'] - 30 ) : 1800;
		set_transient( $key, $result['token'], $ttl );
		return $result['token'];
	}

	/**
	 * Path part of a URL for logs.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function path_of( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Amount as the operator wants it: whole units for zero-decimal currencies, two decimals otherwise.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function amount_for( \WC_Order $order ) {
		$total = (float) $order->get_total();
		if ( in_array( strtoupper( $order->get_currency() ), self::zero_decimal_currencies(), true ) ) {
			return (string) (int) ceil( $total );
		}
		return number_format( $total, 2, '.', '' );
	}

	/**
	 * Currencies the operators treat as whole units.
	 *
	 * @return string[]
	 */
	public static function zero_decimal_currencies() {
		return array( 'KES', 'UGX', 'TZS', 'RWF', 'XAF', 'XOF', 'GNF', 'MWK', 'MGA', 'CDF', 'SSP', 'LRD' );
	}

	/**
	 * Standard settings block header for this network.
	 *
	 * @param string $title       Section title.
	 * @param string $description Section description.
	 * @return array
	 */
	protected function section( $title, $description ) {
		return array(
			$this->id() . '_section' => array(
				'title'       => $title,
				'type'        => 'title',
				'description' => $description,
			),
			$this->id() . '_enabled' => array(
				'title'   => __( 'Enable', 'sokolipa-checkout' ),
				'type'    => 'checkbox',
				/* translators: %s: network name */
				'label'   => sprintf( __( 'Offer %s at checkout', 'sokolipa-checkout' ), $this->label() ),
				'default' => 'no',
			),
			$this->id() . '_env'     => array(
				'title'       => __( 'Environment', 'sokolipa-checkout' ),
				'type'        => 'select',
				'default'     => 'sandbox',
				'options'     => array(
					'sandbox' => __( 'Sandbox / test', 'sokolipa-checkout' ),
					'live'    => __( 'Live (real money)', 'sokolipa-checkout' ),
				),
				'description' => __( 'Start in sandbox, run one prompt to your own phone, then switch to live. While a network is in sandbox only logged-in store managers see it at checkout, so real buyers never pay a test prompt.', 'sokolipa-checkout' ),
				'desc_tip'    => true,
			),
		);
	}
}
