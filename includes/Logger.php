<?php
/**
 * Logging through WooCommerce's logger (WooCommerce > Status > Logs, source "sokolipa").
 *
 * @package Sokolipa
 */

namespace Sokolipa;

defined( 'ABSPATH' ) || exit;

/**
 * Never logs keys, PINs or full phone numbers.
 */
final class Logger {

	/**
	 * Logs a routine event.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( $message, array $context = array() ) {
		self::write( 'info', $message, $context );
	}

	/**
	 * Logs a failure worth the merchant's attention.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * Debug lines are written only when "Debug log" is on in settings.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function debug( $message, array $context = array() ) {
		$settings = get_option( 'woocommerce_' . Plugin::GATEWAY_ID . '_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['debug'] ) || 'yes' !== $settings['debug'] ) {
			return;
		}
		self::write( 'debug', $message, $context );
	}

	/**
	 * Writes one line to the WooCommerce log under the "sokolipa" source.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private static function write( $level, $message, array $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$line = $message;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		wc_get_logger()->log( $level, $line, array( 'source' => 'sokolipa' ) );
	}
}
