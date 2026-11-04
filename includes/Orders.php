<?php
/**
 * Order-side state: starting a push, confirming it, recording the outcome.
 *
 * @package Sokolipa
 */

namespace Sokolipa;

use Sokolipa\Networks\CallbackHint;
use Sokolipa\Networks\Network;
use Sokolipa\Networks\NetworkException;
use Sokolipa\Networks\Registry;
use Sokolipa\Networks\StatusResult;

defined( 'ABSPATH' ) || exit;

/**
 * All writes to order meta go through here, so the rules live in one place:
 * an order is marked paid only by confirm(), and confirm() only trusts query_status().
 */
final class Orders {

	const META_NETWORK      = '_sokolipa_network';
	const META_MSISDN       = '_sokolipa_msisdn';
	const META_REF          = '_sokolipa_provider_ref';
	const META_STATUS       = '_sokolipa_status';
	const META_RECEIPT      = '_sokolipa_receipt';
	const META_RECEIPT_HINT = '_sokolipa_receipt_hint';
	const META_REASON       = '_sokolipa_reason';
	const META_STARTED      = '_sokolipa_started';
	const META_ATTEMPTS     = '_sokolipa_attempts';
	const META_LAST_QUERY   = '_sokolipa_last_query';
	const META_DELIVERY     = '_sokolipa_delivery_place';

	const PENDING_OPTION = 'sokolipa_pending';
	const MAX_ATTEMPTS   = 4;
	const EXPIRE_AFTER   = 20 * MINUTE_IN_SECONDS;

	/*
	 * Status-API pacing for the waiting screen's poll. Operators rate-limit their query
	 * endpoints (Daraja's sandbox answers 429 "spike arrest" beyond a short burst), and a
	 * buyer cannot approve a prompt in under ten seconds anyway. Callbacks and the cron
	 * poller pass $force and are not held back by these.
	 */
	const FIRST_QUERY_AFTER = 10;
	const QUERY_GAP         = 8;

	/**
	 * The delivery place to print for an order, or '' when there is none or when
	 * WooCommerce prints it itself (orders from the block checkout keep the field as
	 * an "additional field" that Woo shows on the order page, in emails and in admin).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function delivery_place_to_print( \WC_Order $order ) {
		if ( '' !== (string) $order->get_meta( '_wc_other/' . SimpleCheckout::BLOCK_FIELD ) ) {
			return '';
		}
		return (string) $order->get_meta( self::META_DELIVERY );
	}

	/**
	 * Sends the prompt and records the attempt on the order.
	 *
	 * @param \WC_Order $order   Order.
	 * @param Network   $network Network.
	 * @param string    $msisdn  International number.
	 * @return string Buyer message.
	 * @throws NetworkException When the operator rejects the push.
	 */
	public static function start_push( \WC_Order $order, Network $network, $msisdn ) {
		$attempts = (int) $order->get_meta( self::META_ATTEMPTS );
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			throw new NetworkException( esc_html__( 'Too many payment attempts on this order. Please contact the store.', 'sokolipa-checkout' ) );
		}

		try {
			$result = $network->request_push( $order, $msisdn );
		} catch ( NetworkException $e ) {
			if ( $e->ambiguous ) {
				// The request timed out: the prompt may still have gone out. Record the attempt so
				// the waiting screen, the callback route and the poller can still resolve it.
				self::record_attempt( $order, $network, $msisdn, (string) $e->provider_ref, $attempts );
				$order->add_order_note(
					sprintf(
						/* translators: %s: network */
						__( 'Sokolipa: the %s push request timed out; the prompt may still arrive. Checking with the network.', 'sokolipa-checkout' ),
						$network->label()
					)
				);
				$order->save();
				self::pending_add( $order->get_id() );
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: 1: network, 2: reason */
						__( 'Sokolipa: %1$s did not accept the payment request. %2$s', 'sokolipa-checkout' ),
						$network->label(),
						$e->getMessage()
					)
				);
			}
			throw $e;
		}

		self::record_attempt( $order, $network, $msisdn, $result->provider_ref, $attempts );
		foreach ( $result->meta as $key => $value ) {
			$order->update_meta_data( '_sokolipa_' . $network->id() . '_' . sanitize_key( $key ), $value );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: network, 2: masked phone, 3: operator reference */
				__( 'Sokolipa: %1$s prompt sent to %2$s (reference %3$s). Waiting for the buyer to approve.', 'sokolipa-checkout' ),
				$network->label(),
				Phone::mask( $msisdn ),
				$result->provider_ref
			)
		);
		$order->save();

		self::remember_ref( $network->id(), $result->provider_ref, $order->get_id() );
		self::pending_add( $order->get_id() );

		Logger::info(
			'push sent',
			array(
				'order'   => $order->get_id(),
				'network' => $network->id(),
				'ref'     => $result->provider_ref,
			)
		);

		return $result->message;
	}

	/**
	 * Writes the attempt onto the order (shared by the confirmed and the timed-out paths).
	 *
	 * @param \WC_Order $order    Order.
	 * @param Network   $network  Network.
	 * @param string    $msisdn   International number.
	 * @param string    $ref      Operator reference, '' when the operator never answered.
	 * @param int       $attempts Attempts before this one.
	 */
	private static function record_attempt( \WC_Order $order, Network $network, $msisdn, $ref, $attempts ) {
		$order->update_meta_data( self::META_NETWORK, $network->id() );
		$order->update_meta_data( self::META_MSISDN, $msisdn );
		$order->update_meta_data( self::META_REF, $ref );
		$order->update_meta_data( self::META_STATUS, StatusResult::PENDING );
		$order->update_meta_data( self::META_STARTED, time() );
		$order->update_meta_data( self::META_ATTEMPTS, $attempts + 1 );
		$order->update_meta_data( self::META_LAST_QUERY, 0 );
		$order->delete_meta_data( self::META_REASON );
		$order->set_payment_method( Plugin::GATEWAY_ID );
		$order->set_payment_method_title( $network->label() );

		if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			$order->set_status( 'pending' );
		}
		if ( '' !== $ref ) {
			self::remember_ref( $network->id(), $ref, $order->get_id() );
		}
	}

	/**
	 * Asks the network for the truth and applies it. Safe to call often.
	 *
	 * @param \WC_Order $order Order.
	 * @param bool      $force Skip the few-seconds gap between queries.
	 * @return StatusResult
	 */
	public static function confirm( \WC_Order $order, $force = false ) {
		if ( $order->is_paid() ) {
			return new StatusResult( StatusResult::PAID, (string) $order->get_meta( self::META_RECEIPT ) );
		}

		$status = (string) $order->get_meta( self::META_STATUS );
		if ( ! $force && StatusResult::FAILED === $status ) {
			return new StatusResult( StatusResult::FAILED, '', (string) $order->get_meta( self::META_REASON ) );
		}

		$network = Registry::get( (string) $order->get_meta( self::META_NETWORK ) );
		if ( ! $network ) {
			return new StatusResult( StatusResult::PENDING, '', __( 'No payment request on this order yet.', 'sokolipa-checkout' ) );
		}

		$last    = (int) $order->get_meta( self::META_LAST_QUERY );
		$started = (int) $order->get_meta( self::META_STARTED );
		if ( ! $force && ( ( time() - $last ) < self::QUERY_GAP || ( time() - $started ) < self::FIRST_QUERY_AFTER ) ) {
			return new StatusResult( StatusResult::PENDING, '', __( 'Waiting for the buyer to approve.', 'sokolipa-checkout' ) );
		}

		// One check at a time per order: the browser poll, a callback and cron can all arrive together.
		$lock = 'sokolipa_lock_' . $order->get_id();
		if ( false !== get_transient( $lock ) ) {
			return new StatusResult( StatusResult::PENDING, '', __( 'Still checking with the network.', 'sokolipa-checkout' ) );
		}
		set_transient( $lock, 1, 15 );

		$order->update_meta_data( self::META_LAST_QUERY, time() );
		$order->save_meta_data();

		try {
			$result = $network->query_status( $order );
		} catch ( NetworkException $e ) {
			delete_transient( $lock );
			Logger::error(
				'status query failed',
				array(
					'order' => $order->get_id(),
					'error' => $e->getMessage(),
				)
			);
			return new StatusResult( StatusResult::PENDING, '', __( 'Still checking with the network.', 'sokolipa-checkout' ) );
		}

		// Re-read: another request may have completed the order while the network was answering.
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh instanceof \WC_Order ) {
			$order = $fresh;
		}

		if ( $order->is_paid() ) {
			delete_transient( $lock );
			return new StatusResult( StatusResult::PAID, (string) $order->get_meta( self::META_RECEIPT ) );
		}

		if ( $result->paid() ) {
			self::mark_paid( $order, $network, $result->receipt );
		} elseif ( $result->failed() ) {
			self::mark_failed( $order, $network, $result->reason );
		}

		delete_transient( $lock );

		return $result;
	}

	/**
	 * A callback arrived: remember what it claims, then verify with the network.
	 *
	 * @param Network      $network Network.
	 * @param CallbackHint $hint    Parsed callback.
	 * @return bool Whether an order was found.
	 */
	public static function apply_callback( Network $network, CallbackHint $hint ) {
		$order = null;
		if ( '' !== $hint->provider_ref ) {
			$order = self::find_by_ref( $network->id(), $hint->provider_ref );
		}
		if ( ! $order && $hint->order_id > 0 ) {
			$candidate = wc_get_order( $hint->order_id );
			if ( $candidate instanceof \WC_Order && $network->id() === (string) $candidate->get_meta( self::META_NETWORK ) ) {
				$order = $candidate;
			}
		}
		if ( ! $order ) {
			Logger::info(
				'callback for unknown reference ignored',
				array(
					'network' => $network->id(),
					'ref'     => $hint->provider_ref,
				)
			);
			return false;
		}

		if ( '' !== $hint->receipt ) {
			$order->update_meta_data( self::META_RECEIPT_HINT, $hint->receipt );
			if ( $order->is_paid() && '' === (string) $order->get_meta( self::META_RECEIPT ) ) {
				$order->update_meta_data( self::META_RECEIPT, $hint->receipt );
				$order->set_transaction_id( $hint->receipt );
				/* translators: %s: receipt number */
				$order->add_order_note( sprintf( __( 'Sokolipa: receipt %s confirmed by the network callback.', 'sokolipa-checkout' ), $hint->receipt ) );
			}
			$order->save();
		}

		Logger::info(
			'callback received',
			array(
				'order'   => $order->get_id(),
				'network' => $network->id(),
				'claimed' => $hint->claimed,
			)
		);

		self::confirm( $order, true );
		return true;
	}

	/**
	 * Marks pushes older than 20 minutes as failed after one last query.
	 */
	public static function expire_stale() {
		foreach ( self::pending_list() as $order_id => $started ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				self::pending_remove( $order_id );
				continue;
			}
			$result = self::confirm( $order, true );
			if ( StatusResult::PENDING === $result->status && ( time() - (int) $started ) > self::EXPIRE_AFTER ) {
				$network = Registry::get( (string) $order->get_meta( self::META_NETWORK ) );
				self::mark_failed( $order, $network, __( 'The payment prompt was not approved in time.', 'sokolipa-checkout' ) );
			}
		}
	}

	/**
	 * Records the confirmed payment and completes the order.
	 *
	 * @param \WC_Order    $order   Order.
	 * @param Network|null $network Network.
	 * @param string       $receipt Receipt.
	 */
	private static function mark_paid( \WC_Order $order, $network, $receipt ) {
		if ( '' === $receipt ) {
			$receipt = (string) $order->get_meta( self::META_RECEIPT_HINT );
		}
		$order->update_meta_data( self::META_STATUS, StatusResult::PAID );
		$order->update_meta_data( self::META_RECEIPT, $receipt );
		$order->delete_meta_data( self::META_REASON );
		$order->add_order_note(
			sprintf(
				/* translators: 1: network, 2: receipt or dash */
				__( 'Sokolipa: %1$s payment confirmed by the network. Receipt %2$s.', 'sokolipa-checkout' ),
				$network ? $network->label() : 'Mobile money',
				'' !== $receipt ? $receipt : '—'
			)
		);
		$order->payment_complete( $receipt );
		$order->save();
		self::pending_remove( $order->get_id() );
		Logger::info(
			'order paid',
			array(
				'order'   => $order->get_id(),
				'receipt' => $receipt,
			)
		);
	}

	/**
	 * Records a final failure and fails the order if it was still pending.
	 *
	 * @param \WC_Order    $order   Order.
	 * @param Network|null $network Network.
	 * @param string       $reason  Reason.
	 */
	private static function mark_failed( \WC_Order $order, $network, $reason ) {
		$order->update_meta_data( self::META_STATUS, StatusResult::FAILED );
		$order->update_meta_data( self::META_REASON, $reason );
		$order->add_order_note(
			sprintf(
				/* translators: 1: network, 2: reason */
				__( 'Sokolipa: %1$s payment not completed. %2$s', 'sokolipa-checkout' ),
				$network ? $network->label() : 'Mobile money',
				$reason
			)
		);
		if ( $order->has_status( 'pending' ) ) {
			$order->set_status( 'failed' );
		}
		$order->save();
		self::pending_remove( $order->get_id() );
	}

	/**
	 * Lookup by operator reference: a transient first, then order meta.
	 *
	 * @param string $network_id Network id.
	 * @param string $ref        Operator reference.
	 * @return \WC_Order|null
	 */
	public static function find_by_ref( $network_id, $ref ) {
		$order_id = (int) get_transient( self::ref_key( $network_id, $ref ) );
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order && $ref === (string) $order->get_meta( self::META_REF ) ) {
				return $order;
			}
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per callback, by indexed reference.
					array(
						'key'   => self::META_REF,
						'value' => $ref,
					),
				),
			)
		);

		return ! empty( $orders ) && $orders[0] instanceof \WC_Order ? $orders[0] : null;
	}

	/**
	 * Caches which order an operator reference belongs to, for fast callback lookups.
	 *
	 * @param string $network_id Network id.
	 * @param string $ref        Operator reference.
	 * @param int    $order_id   Order id.
	 */
	private static function remember_ref( $network_id, $ref, $order_id ) {
		set_transient( self::ref_key( $network_id, $ref ), (int) $order_id, DAY_IN_SECONDS );
	}

	/**
	 * Transient key for an operator reference.
	 *
	 * @param string $network_id Network id.
	 * @param string $ref        Operator reference.
	 * @return string
	 */
	private static function ref_key( $network_id, $ref ) {
		return 'sokolipa_ref_' . md5( $network_id . '|' . $ref );
	}

	/**
	 * Orders still waiting for the buyer to approve a prompt.
	 *
	 * @return array<int, int> order id => started timestamp
	 */
	public static function pending_list() {
		$list = get_option( self::PENDING_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Adds an order to the poller's list and makes sure the poller is scheduled.
	 *
	 * @param int $order_id Order id.
	 */
	private static function pending_add( $order_id ) {
		$list              = self::pending_list();
		$list[ $order_id ] = time();
		update_option( self::PENDING_OPTION, $list, false );
		Poller::schedule();
	}

	/**
	 * Takes an order off the poller's list.
	 *
	 * @param int $order_id Order id.
	 */
	private static function pending_remove( $order_id ) {
		$list = self::pending_list();
		if ( isset( $list[ $order_id ] ) ) {
			unset( $list[ $order_id ] );
			update_option( self::PENDING_OPTION, $list, false );
		}
	}
}
