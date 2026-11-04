<?php
/**
 * The operator's answer to a status query.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Networks;

defined( 'ABSPATH' ) || exit;

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
