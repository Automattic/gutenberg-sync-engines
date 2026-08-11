<?php
/**
 * Common math helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of small lib0/math.js helpers.
 */
final class Math {
	/**
	 * @param int|float $value Numeric value.
	 * @return int
	 */
	public static function floor( $value ): int {
		return (int) floor( $value );
	}

	/**
	 * @param int|float $value Numeric value.
	 * @return int
	 */
	public static function ceil( $value ): int {
		return (int) ceil( $value );
	}

	/**
	 * @param int|float $value Numeric value.
	 * @return int|float
	 */
	public static function abs( $value ) {
		return abs( $value );
	}

	/**
	 * @param int|float $left  Left operand.
	 * @param int|float $right Right operand.
	 * @return int|float
	 */
	public static function min( $left, $right ) {
		return $left < $right ? $left : $right;
	}

	/**
	 * @param int|float $left  Left operand.
	 * @param int|float $right Right operand.
	 * @return int|float
	 */
	public static function max( $left, $right ) {
		return $left > $right ? $left : $right;
	}

	/**
	 * @param int|float $left  Left operand.
	 * @param int|float $right Right operand.
	 * @return int|float
	 */
	public static function add( $left, $right ) {
		return $left + $right;
	}

	/**
	 * @param int|float $value Numeric value.
	 * @return int
	 */
	public static function round( $value ): int {
		return (int) round( $value );
	}

	/**
	 * @param float $value Numeric value.
	 * @return bool
	 */
	public static function isNaN( float $value ): bool {
		return is_nan( $value );
	}

	/**
	 * Mirrors lib0's isNegativeZero: true for negative numbers and negative zero.
	 *
	 * @param int|float $value Numeric value.
	 * @return bool
	 */
	public static function isNegativeZero( $value ): bool {
		if ( $value < 0 ) {
			return true;
		}
		if ( 0.0 !== $value || ! is_float( $value ) ) {
			return false;
		}
		return 0 !== ( ord( pack( 'E', $value )[0] ) & Binary::BIT8 );
	}
}
