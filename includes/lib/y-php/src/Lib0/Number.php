<?php
/**
 * Number helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/number.js constants and helpers.
 */
final class Number {
	public const MAX_SAFE_INTEGER = 9007199254740991;
	public const MIN_SAFE_INTEGER = -9007199254740991;
	public const LOWEST_INT32     = Binary::BIT32;
	public const HIGHEST_INT32    = Binary::BITS31;
	public const HIGHEST_UINT32   = Binary::BITS32;

	/**
	 * @param int|float $value Numeric value.
	 * @return bool
	 */
	public static function isInteger( $value ): bool {
		if ( is_int( $value ) ) {
			return true;
		}
		return is_float( $value ) && is_finite( $value ) && floor( $value ) === $value;
	}

	/**
	 * @param int $value Unsigned 32-bit value.
	 * @return int
	 */
	public static function countBits( int $value ): int {
		$value = Binary::toUint32( $value );
		$count = 0;
		while ( 0 !== $value ) {
			$value &= ( $value - 1 );
			++$count;
		}
		return $count;
	}
}
