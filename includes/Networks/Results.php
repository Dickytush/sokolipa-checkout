<?php
/**
 * Small value objects passed between the adapters and the rest of the plugin.
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

/**
 * The authoritative state of a push.
 */
final class StatusResult {

	const PAID    = 'paid';
	const PENDING = 'pending';
	const FAILED  = 'failed';

	/**
	 * One of the constants above.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Operator receipt (MpesaReceiptNumber, financialTransactionId, airtel_money_id) when paid.
	 *
	 * @var string
	 */
	public $receipt;

	/**
	 * Human reason when failed or pending.
	 *
	 * @var string
	 */
	public $reason;

	/**
	 * Wraps the operator's answer to a status query.
	 *
	 * @param string $status  Status constant.
	 * @param string $receipt Receipt.
	 * @param string $reason  Reason.
	 */
	public function __construct( $status, $receipt = '', $reason = '' ) {
		$this->status  = $status;
		$this->receipt = (string) $receipt;
		$this->reason  = (string) $reason;
	}

	/**
	 * Whether the operator confirmed the payment.
	 *
	 * @return bool
	 */
	public function paid() {
		return self::PAID === $this->status;
	}

	/**
	 * Whether the operator reported a final failure.
	 *
	 * @return bool
	 */
	public function failed() {
		return self::FAILED === $this->status;
	}
}

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

/**
 * Raised when an operator rejects a request or cannot be reached.
 */
class NetworkException extends \RuntimeException {

	/**
	 * Whether the push may still have gone through (timeouts).
	 *
	 * @var bool
	 */
	public $ambiguous = false;

	/**
	 * The reference the adapter generated before the call, when it has one, so a
	 * timed-out push can still be looked up with query_status().
	 *
	 * @var string
	 */
	public $provider_ref = '';

	/**
	 * Builds the exception; pass $ambiguous when a timeout means the prompt may still arrive.
	 *
	 * @param string $message      Human message.
	 * @param bool   $ambiguous    Whether the outcome is unknown.
	 * @param string $provider_ref Reference generated before the call.
	 */
	public function __construct( $message, $ambiguous = false, $provider_ref = '' ) {
		parent::__construct( $message );
		$this->ambiguous    = (bool) $ambiguous;
		$this->provider_ref = (string) $provider_ref;
	}
}
