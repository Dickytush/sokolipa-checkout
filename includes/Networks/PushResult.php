<?php
/**
 * What an operator returns for an accepted push.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Networks;

defined( 'ABSPATH' ) || exit;

/**
 * What a successful push request returns.
 */
final class PushResult {

	/**
	 * The operator's reference for this push (CheckoutRequestID, X-Reference-Id, transaction id).
	 *
	 * @var string
	 */
	public $provider_ref;

	/**
	 * Message to show the buyer on the waiting screen.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Extra values worth keeping on the order (MerchantRequestID and the like).
	 *
	 * @var array
	 */
	public $meta;

	/**
	 * Wraps what the operator returned for an accepted push.
	 *
	 * @param string $provider_ref Operator reference.
	 * @param string $message      Buyer message.
	 * @param array  $meta         Extra meta.
	 */
	public function __construct( $provider_ref, $message = '', array $meta = array() ) {
		$this->provider_ref = (string) $provider_ref;
		$this->message      = (string) $message;
		$this->meta         = $meta;
	}
}
