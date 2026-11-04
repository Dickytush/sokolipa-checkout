<?php
/**
 * The list of network adapters.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Networks;

defined( 'ABSPATH' ) || exit;

/**
 * Add a network: implement Network (extend AbstractNetwork) and list the class here,
 * or hook the 'sokolipa_networks' filter from another plugin.
 */
final class Registry {

	/**
	 * Built instances.
	 *
	 * @var Network[]|null
	 */
	private static $instances = null;

	/**
	 * Adapter classes shipped with the plugin.
	 *
	 * @return string[]
	 */
	public static function classes() {
		return array( Mpesa::class, Mtn::class, Airtel::class );
	}

	/**
	 * Every adapter, keyed by id.
	 *
	 * @return Network[]
	 */
	public static function all() {
		if ( null === self::$instances ) {
			$list = array();
			foreach ( (array) apply_filters( 'sokolipa_networks', self::classes() ) as $class ) {
				if ( class_exists( $class ) ) {
					$network = new $class();
					if ( $network instanceof Network ) {
						$list[ $network->id() ] = $network;
					}
				}
			}
			self::$instances = $list;
		}
		return self::$instances;
	}

	/**
	 * One adapter by id.
	 *
	 * @param string $id Network id.
	 * @return Network|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Adapters the merchant has configured whose currency matches the store's.
	 *
	 * @param string $currency Store currency.
	 * @return Network[]
	 */
	public static function available( $currency ) {
		$out = array();
		foreach ( self::all() as $id => $network ) {
			if ( $network->is_configured() && strtoupper( $network->currency() ) === strtoupper( $currency ) ) {
				$out[ $id ] = $network;
			}
		}
		return $out;
	}

	/**
	 * What a visitor can pick at checkout: the available adapters, except that a network
	 * still in sandbox is offered only to logged-in store managers, so a real buyer can
	 * never "pay" an order with a test prompt that moves no money.
	 *
	 * @param string $currency Store currency.
	 * @return Network[]
	 */
	public static function offered( $currency ) {
		$manager = function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
		return array_filter(
			self::available( $currency ),
			static function ( Network $network ) use ( $manager ) {
				return $manager || $network->is_live();
			}
		);
	}

	/**
	 * Configured adapters regardless of currency (for settings warnings).
	 *
	 * @return Network[]
	 */
	public static function configured() {
		return array_filter(
			self::all(),
			static function ( Network $network ) {
				return $network->is_configured();
			}
		);
	}
}
