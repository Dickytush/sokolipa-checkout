<?php
/**
 * Removes the plugin's own options and transients. Order meta is left in place so
 * past orders keep their receipt numbers and delivery places.
 *
 * @package Sokolipa
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_sokolipa_settings' );
delete_option( 'sokolipa_pending' );

$sokolipa_timestamp = wp_next_scheduled( 'sokolipa_poll' );
while ( $sokolipa_timestamp ) {
	wp_unschedule_event( $sokolipa_timestamp, 'sokolipa_poll' );
	$sokolipa_timestamp = wp_next_scheduled( 'sokolipa_poll' );
}

global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_sokolipa_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_sokolipa_' ) . '%'
	)
);
