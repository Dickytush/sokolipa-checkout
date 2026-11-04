<?php
/**
 * Phone number normalisation for the countries the networks serve.
 *
 * @package Sokolipa
 */

namespace Sokolipa;

defined( 'ABSPATH' ) || exit;

/**
 * Turns what a buyer types (0712 345 678, +254712345678, 254712345678) into the
 * international form the operators want (254712345678), and back to national for Airtel.
 */
final class Phone {

	/**
	 * Dial codes, accepted national-number lengths, and whether the national number keeps
	 * its leading zero after the dial code (Côte d'Ivoire and Benin do since their renumbering).
	 *
	 * @return array<string, array{0:string,1:int[],2?:bool}>
	 */
	public static function countries() {
		return array(
			'KE' => array( '254', array( 9 ) ),
			'UG' => array( '256', array( 9 ) ),
			'TZ' => array( '255', array( 9 ) ),
			'RW' => array( '250', array( 9 ) ),
			'GH' => array( '233', array( 9 ) ),
			'ZM' => array( '260', array( 9 ) ),
			'MW' => array( '265', array( 9, 10 ) ),
			'NG' => array( '234', array( 10 ) ),
			'CM' => array( '237', array( 9 ) ),
			'CI' => array( '225', array( 10 ), true ),
			'BJ' => array( '229', array( 8, 10 ), true ),
			'CG' => array( '242', array( 9 ) ),
			'CD' => array( '243', array( 9 ) ),
			'GA' => array( '241', array( 8, 9 ) ),
			'TD' => array( '235', array( 8 ) ),
			'NE' => array( '227', array( 8 ) ),
			'LR' => array( '231', array( 7, 8, 9 ) ),
			'SS' => array( '211', array( 9 ) ),
			'SZ' => array( '268', array( 8 ) ),
			'GN' => array( '224', array( 9 ) ),
			'MG' => array( '261', array( 9 ) ),
			'SC' => array( '248', array( 7 ) ),
		);
	}

	/**
	 * Dial code for a country, '' when unknown.
	 *
	 * @param string $country ISO2.
	 * @return string
	 */
	public static function dial_code( $country ) {
		$all = self::countries();
		return isset( $all[ strtoupper( $country ) ] ) ? $all[ strtoupper( $country ) ][0] : '';
	}

	/**
	 * International number without '+', or '' when the input cannot be a number in that country.
	 *
	 * @param string $raw     What the buyer typed.
	 * @param string $country ISO2 of the network's country.
	 * @return string
	 */
	public static function normalize( $raw, $country ) {
		$country = strtoupper( $country );
		$all     = self::countries();
		if ( ! isset( $all[ $country ] ) ) {
			return '';
		}
		$dial      = $all[ $country ][0];
		$lengths   = $all[ $country ][1];
		$keep_zero = ! empty( $all[ $country ][2] );

		$digits = preg_replace( '/\D+/', '', (string) $raw );
		if ( '' === $digits ) {
			return '';
		}

		if ( 0 === strpos( $digits, '00' . $dial ) ) {
			$digits = substr( $digits, 2 );
		}

		if ( 0 === strpos( $digits, $dial ) && strlen( $digits ) > strlen( $dial ) + 6 ) {
			$national = substr( $digits, strlen( $dial ) );
		} else {
			$national = $digits;
		}

		if ( ! $keep_zero ) {
			$national = ltrim( $national, '0' );
		} elseif ( ! in_array( strlen( $national ), $lengths, true ) && '0' !== $national[0] ) {
			$national = '0' . $national; // Typed without the leading zero the network expects.
		}

		if ( ! in_array( strlen( $national ), $lengths, true ) ) {
			return '';
		}

		return $dial . $national;
	}

	/**
	 * National part (no dial code, no leading zero) of an international number.
	 *
	 * @param string $msisdn  International number without '+'.
	 * @param string $country ISO2.
	 * @return string
	 */
	public static function national( $msisdn, $country ) {
		$dial = self::dial_code( $country );
		if ( '' !== $dial && 0 === strpos( $msisdn, $dial ) ) {
			return substr( $msisdn, strlen( $dial ) );
		}
		$all = self::countries();
		if ( isset( $all[ strtoupper( $country ) ][2] ) && $all[ strtoupper( $country ) ][2] ) {
			return $msisdn;
		}
		return ltrim( $msisdn, '0' );
	}

	/**
	 * A masked version for screens and notes: 2547****678.
	 *
	 * @param string $msisdn International number.
	 * @return string
	 */
	public static function mask( $msisdn ) {
		$len = strlen( $msisdn );
		if ( $len < 7 ) {
			return $msisdn;
		}
		return substr( $msisdn, 0, 4 ) . str_repeat( '*', $len - 7 ) . substr( $msisdn, -3 );
	}

	/**
	 * Example national number for a placeholder.
	 *
	 * @param string $country ISO2.
	 * @return string
	 */
	public static function example( $country ) {
		$examples = array(
			'KE' => '0712 345 678',
			'UG' => '0772 123 456',
			'TZ' => '0754 123 456',
			'RW' => '0788 123 456',
			'GH' => '0244 123 456',
			'ZM' => '0977 123 456',
			'NG' => '0803 123 4567',
			'CM' => '6 71 23 45 67',
		);
		$country = strtoupper( $country );
		return isset( $examples[ $country ] ) ? $examples[ $country ] : '0' . str_repeat( '7', 2 ) . ' 123 4567';
	}
}
