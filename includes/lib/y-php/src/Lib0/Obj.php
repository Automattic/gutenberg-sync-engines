<?php
/**
 * Object helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/object.js helpers with stdClass as the empty object carrier.
 */
final class Obj {
	/**
	 * @return \stdClass
	 */
	public static function create(): \stdClass {
		return new \stdClass();
	}

	/**
	 * @param array<string,mixed>|\stdClass $object Object-like value.
	 * @return array<int,string>
	 */
	public static function keys( $object ): array {
		return array_keys( self::toArray( $object ) );
	}

	/**
	 * @param array<string,mixed>|\stdClass $object Object-like value.
	 * @param callable                      $fn     Callback.
	 * @return void
	 */
	public static function forEach( $object, callable $fn ): void {
		foreach ( self::toArray( $object ) as $key => $value ) {
			$fn( $value, $key );
		}
	}

	/**
	 * @param array<string,mixed>|\stdClass $object Object-like value.
	 * @param callable                      $fn     Mapper.
	 * @return array<int,mixed>
	 */
	public static function map( $object, callable $fn ): array {
		$result = array();
		foreach ( self::toArray( $object ) as $key => $value ) {
			$result[] = $fn( $value, $key );
		}
		return $result;
	}

	/**
	 * @param array<string,mixed>|\stdClass $object Object-like value.
	 * @return int
	 */
	public static function size( $object ): int {
		return count( self::toArray( $object ) );
	}

	/**
	 * @param array<string,mixed>|\stdClass|null $object Object-like value.
	 * @return bool
	 */
	public static function isEmpty( $object ): bool {
		return null === $object || 0 === count( self::toArray( $object ) );
	}

	/**
	 * @param array<string,mixed>|\stdClass $object Object-like value.
	 * @param string                        $key    Key.
	 * @return bool
	 */
	public static function hasProperty( $object, string $key ): bool {
		if ( is_array( $object ) ) {
			return array_key_exists( $key, $object );
		}
		return property_exists( $object, $key );
	}

	/**
	 * @param array<string,mixed>|\stdClass $object Object-like value.
	 * @return array<string,mixed>
	 */
	public static function toArray( $object ): array {
		if ( $object instanceof \stdClass ) {
			return get_object_vars( $object );
		}
		return $object;
	}
}
