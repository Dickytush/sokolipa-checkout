<?php
/**
 * REST routes: operator callbacks, the waiting screen's status poll, and "send the prompt again".
 *
 * @package Sokolipa
 */

namespace Sokolipa;

use Sokolipa\Networks\NetworkException;
use Sokolipa\Networks\Registry;
use Sokolipa\Networks\StatusResult;

defined( 'ABSPATH' ) || exit;

/**
 * /wp-json/sokolipa/v1/...
 */
final class Callbacks {

	const NS = 'sokolipa/v1';

	/**
	 * Hooks in.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public function routes() {
		register_rest_route(
			self::NS,
			'/callback/(?P<network>[a-z0-9_-]+)',
			array(
				'methods'             => array( 'POST', 'PUT' ), // MTN delivers callbacks with PUT.
				'callback'            => array( $this, 'callback' ),
				// Public by design: the operators have no WordPress login. A callback is
				// only ever a hint; Orders::apply_callback() re-checks with the operator's
				// status API before any order is marked paid.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/status/(?P<order_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => '__return_true', // The order key in the request is the credential.
				'args'                => array(
					'key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/resend/(?P<order_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resend' ),
				'permission_callback' => '__return_true', // The order key in the request is the credential.
			)
		);
	}

	/**
	 * Operator callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function callback( \WP_REST_Request $request ) {
		$slug    = sanitize_key( $request['network'] );
		$aliases = array( 'stk' => 'mpesa' ); // Safaricom rejects callback URLs containing operator names.
		$network = Registry::get( isset( $aliases[ $slug ] ) ? $aliases[ $slug ] : $slug );
		if ( ! $network ) {
			return new \WP_REST_Response( array( 'error' => 'unknown network' ), 404 );
		}

		$hint = $network->parse_callback( $request );
		if ( null === $hint ) {
			Logger::info( 'callback body not understood', array( 'network' => $network->id() ) );
			return new \WP_REST_Response( $network->callback_ack(), 200 );
		}

		Orders::apply_callback( $network, $hint );

		// Always acknowledge so the operator stops retrying; the outcome lives on the order.
		return new \WP_REST_Response( $network->callback_ack(), 200 );
	}

	/**
	 * Waiting-screen poll. Confirms with the network when it has been a few seconds since the last check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function status( \WP_REST_Request $request ) {
		$order = $this->order_from( $request );
		if ( ! $order ) {
			return new \WP_REST_Response( array( 'error' => 'not found' ), 404 );
		}

		$result = Orders::confirm( $order );

		return new \WP_REST_Response(
			array(
				'status'   => $result->status,
				'reason'   => $result->reason,
				'receipt'  => $result->paid() ? (string) $order->get_meta( Orders::META_RECEIPT ) : '',
				'attempts' => (int) $order->get_meta( Orders::META_ATTEMPTS ),
				'canRetry' => (int) $order->get_meta( Orders::META_ATTEMPTS ) < Orders::MAX_ATTEMPTS,
			),
			200
		);
	}

	/**
	 * Sends the prompt again, optionally to a corrected number.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function resend( \WP_REST_Request $request ) {
		$order = $this->order_from( $request );
		if ( ! $order ) {
			return new \WP_REST_Response( array( 'error' => 'not found' ), 404 );
		}
		if ( $order->is_paid() ) {
			return new \WP_REST_Response( array( 'status' => StatusResult::PAID ), 200 );
		}

		$status = (string) $order->get_meta( Orders::META_STATUS );
		$age    = time() - (int) $order->get_meta( Orders::META_STARTED );
		if ( StatusResult::PENDING === $status && $age < 45 ) {
			return new \WP_REST_Response( array( 'error' => __( 'A prompt was sent less than a minute ago. Check the phone first.', 'sokolipa-checkout' ) ), 429 );
		}

		$network_id = sanitize_key( (string) $request->get_param( 'network' ) );
		if ( '' === $network_id ) {
			$network_id = (string) $order->get_meta( Orders::META_NETWORK );
		}
		$available = Registry::available( $order->get_currency() );
		$network   = isset( $available[ $network_id ] ) ? $available[ $network_id ] : null;
		if ( ! $network ) {
			return new \WP_REST_Response( array( 'error' => __( 'That network is not available for this order.', 'sokolipa-checkout' ) ), 400 );
		}

		$raw    = (string) $request->get_param( 'phone' );
		$msisdn = '' !== trim( $raw ) ? Phone::normalize( $raw, $network->country() ) : (string) $order->get_meta( Orders::META_MSISDN );
		if ( '' === $msisdn ) {
			return new \WP_REST_Response( array( 'error' => __( 'That phone number does not look right.', 'sokolipa-checkout' ) ), 400 );
		}

		try {
			$message = Orders::start_push( $order, $network, $msisdn );
		} catch ( NetworkException $e ) {
			// Messages are escaped for HTML where they are raised; this is JSON that the waiting screen shows as plain text.
			return new \WP_REST_Response( array( 'error' => wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) ), 400 );
		}

		return new \WP_REST_Response(
			array(
				'status'  => StatusResult::PENDING,
				'message' => $message,
				'phone'   => Phone::mask( $msisdn ),
			),
			200
		);
	}

	/**
	 * The order named in the route, only when the order key matches.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WC_Order|null
	 */
	private function order_from( \WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['order_id'] );
		$key   = (string) $request->get_param( 'key' );
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}
		if ( Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return null;
		}
		return $order;
	}
}
