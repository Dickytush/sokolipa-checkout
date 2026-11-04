<?php
/**
 * Raised when an operator rejects a request or cannot be reached.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Networks;

defined( 'ABSPATH' ) || exit;

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
