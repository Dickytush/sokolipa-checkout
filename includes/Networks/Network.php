<?php
/**
 * The contract every mobile-money network adapter implements.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Networks;

defined( 'ABSPATH' ) || exit;

/**
 * One adapter per operator API. Adding a network means implementing this and
 * listing the class in Registry::classes().
 */
interface Network {

	/**
	 * Short machine id, e.g. 'mpesa'. Used in option keys, REST routes and order meta.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Buyer-facing name, e.g. 'M-Pesa'.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * ISO 3166-1 alpha-2 country the configured account serves, e.g. 'KE'.
	 *
	 * @return string
	 */
	public function country();

	/**
	 * ISO 4217 currency the configured account charges in, e.g. 'KES'.
	 *
	 * @return string
	 */
	public function currency();

	/**
	 * Whether the merchant has switched this network on and pasted every key it needs.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Whether the adapter talks to the live operator host (false in sandbox / test).
	 *
	 * @return bool
	 */
	public function is_live();

	/**
	 * Gateway settings fields for this network, keyed with the network id prefix.
	 *
	 * @return array
	 */
	public function form_fields();

	/**
	 * Sends the payment prompt to the buyer's phone.
	 *
	 * @param \WC_Order $order  The order being paid.
	 * @param string    $msisdn Phone number in international format without '+', e.g. 2547XXXXXXXX.
	 * @return PushResult
	 * @throws NetworkException When the operator rejects the request or cannot be reached.
	 */
	public function request_push( \WC_Order $order, $msisdn );

	/**
	 * Asks the operator for the authoritative state of the push recorded on the order.
	 *
	 * @param \WC_Order $order The order.
	 * @return StatusResult
	 * @throws NetworkException When the operator cannot be reached.
	 */
	public function query_status( \WC_Order $order );

	/**
	 * Reads an inbound callback and returns what it claims. The claim is only a
	 * hint: the plugin re-checks with query_status() before marking anything paid.
	 *
	 * @param \WP_REST_Request $request The callback request.
	 * @return CallbackHint|null Null when the body is not a callback this adapter understands.
	 */
	public function parse_callback( \WP_REST_Request $request );

	/**
	 * The body the operator expects back from a callback (acknowledgement).
	 *
	 * @return array
	 */
	public function callback_ack();
}
