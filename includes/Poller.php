<?php
/**
 * Background status checks for pushes whose callback never arrived.
 *
 * @package Sokolipa
 */

namespace Sokolipa;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once a minute through WP-Cron while there are pending pushes.
 */
final class Poller {

	const HOOK     = 'sokolipa_poll';
	const INTERVAL = 'sokolipa_minute';

	/**
	 * Hooks in.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- one minute while payments are pending only.
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Checks every pending push; unschedules itself when nothing is left.
	 */
	public function run() {
		Orders::expire_stale();
		if ( empty( Orders::pending_list() ) ) {
			self::unschedule();
		}
	}

	/**
	 * Schedules the event when it is not already scheduled.
	 */
	public static function schedule() {
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- one minute while payments are pending only.
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * The one-minute interval, registered statically so activation can schedule too.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function add_interval( $schedules ) {
		$schedules[ self::INTERVAL ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Sokolipa payment checks)', 'sokolipa-checkout' ),
		);
		return $schedules;
	}

	/**
	 * Removes the event.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}
}
