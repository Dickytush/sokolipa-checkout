<?php
/**
 * What an inbound operator callback claims, before verification.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Networks;

defined( 'ABSPATH' ) || exit;

/**
 * What a callback claims, before verification.
 */
final class CallbackHint {

	/**
	 * Operator reference the callback refers to.
	 *
	 * @var string
	 */
	public $provider_ref;

	/**
	 * Order id the callback names, when the operator echoes one back (0 when unknown).
	 *
	 * @var int
	 */
	public $order_id;

	/**
	 * Receipt the callback carries, when any.
	 *
	 * @var string
	 */
	public $receipt;

	/**
	 * Claimed status (StatusResult constant) or '' when the callback does not say.
	 *
	 * @var string
	 */
	public $claimed;

	/**
	 * Wraps what an inbound callback claims.
	 *
	 * @param string $provider_ref Operator reference.
	 * @param int    $order_id     Order id.
	 * @param string $receipt      Receipt.
	 * @param string $claimed      Claimed status.
	 */
	public function __construct( $provider_ref, $order_id = 0, $receipt = '', $claimed = '' ) {
		$this->provider_ref = (string) $provider_ref;
		$this->order_id     = (int) $order_id;
		$this->receipt      = (string) $receipt;
		$this->claimed      = (string) $claimed;
	}
}
