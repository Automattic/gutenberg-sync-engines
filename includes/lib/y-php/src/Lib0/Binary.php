<?php
/**
 * Binary data constants and 32-bit helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Constants from lib0/binary.js plus helpers for JavaScript-style bit ops.
 */
final class Binary {
	public const BIT1  = 1;
	public const BIT2  = 2;
	public const BIT3  = 4;
	public const BIT4  = 8;
	public const BIT5  = 16;
	public const BIT6  = 32;
	public const BIT7  = 64;
	public const BIT8  = 128;
	public const BIT9  = 256;
	public const BIT10 = 512;
	public const BIT11 = 1024;
	public const BIT12 = 2048;
	public const BIT13 = 4096;
	public const BIT14 = 8192;
	public const BIT15 = 16384;
	public const BIT16 = 32768;
	public const BIT17 = 65536;
	public const BIT18 = 131072;
	public const BIT19 = 262144;
	public const BIT20 = 524288;
	public const BIT21 = 1048576;
	public const BIT22 = 2097152;
	public const BIT23 = 4194304;
	public const BIT24 = 8388608;
	public const BIT25 = 16777216;
	public const BIT26 = 33554432;
	public const BIT27 = 67108864;
	public const BIT28 = 134217728;
	public const BIT29 = 268435456;
	public const BIT30 = 536870912;
	public const BIT31 = 1073741824;
	public const BIT32 = -2147483648;

	public const BITS0  = 0;
	public const BITS1  = 1;
	public const BITS2  = 3;
	public const BITS3  = 7;
	public const BITS4  = 15;
	public const BITS5  = 31;
	public const BITS6  = 63;
	public const BITS7  = 127;
	public const BITS8  = 255;
	public const BITS9  = 511;
	public const BITS10 = 1023;
	public const BITS11 = 2047;
	public const BITS12 = 4095;
	public const BITS13 = 8191;
	public const BITS14 = 16383;
	public const BITS15 = 32767;
	public const BITS16 = 65535;
	public const BITS17 = self::BIT18 - 1;
	public const BITS18 = self::BIT19 - 1;
	public const BITS19 = self::BIT20 - 1;
	public const BITS20 = self::BIT21 - 1;
	public const BITS21 = self::BIT22 - 1;
	public const BITS22 = self::BIT23 - 1;
	public const BITS23 = self::BIT24 - 1;
	public const BITS24 = self::BIT25 - 1;
	public const BITS25 = self::BIT26 - 1;
	public const BITS26 = self::BIT27 - 1;
	public const BITS27 = self::BIT28 - 1;
	public const BITS28 = self::BIT29 - 1;
	public const BITS29 = self::BIT30 - 1;
	public const BITS30 = self::BIT31 - 1;
	public const BITS31 = 0x7FFFFFFF;
	public const BITS32 = 0xFFFFFFFF;

	/**
	 * Convert a number to JavaScript's unsigned 32-bit range.
	 *
	 * @param int|float $value Value to coerce.
	 * @return int
	 */
	public static function toUint32( $value ): int {
		return (int) $value & self::BITS32;
	}

	/**
	 * Convert a number to JavaScript's signed 32-bit range.
	 *
	 * @param int|float $value Value to coerce.
	 * @return int
	 */
	public static function toInt32( $value ): int {
		$value = self::toUint32( $value );
		if ( 0x80000000 <= $value ) {
			return $value - ( self::BITS32 + 1 );
		}
		return $value;
	}

	/**
	 * Emulate JavaScript's unsigned right shift operator (`>>>`).
	 *
	 * @param int $value Value to shift.
	 * @param int $bits  Number of bits.
	 * @return int
	 */
	public static function unsignedRightShift( int $value, int $bits ): int {
		$value = self::toUint32( $value );
		if ( 0 === $bits ) {
			return $value;
		}
		return $value >> $bits;
	}

	/**
	 * JavaScript-style 32-bit left shift.
	 *
	 * @param int $value Value to shift.
	 * @param int $bits  Number of bits.
	 * @return int
	 */
	public static function shiftLeft32( int $value, int $bits ): int {
		return self::toInt32( self::toInt32( $value ) << $bits );
	}

	/**
	 * JavaScript-style 32-bit bitwise OR.
	 *
	 * @param int $left  Left operand.
	 * @param int $right Right operand.
	 * @return int
	 */
	public static function or32( int $left, int $right ): int {
		return self::toInt32( self::toInt32( $left ) | self::toInt32( $right ) );
	}

	/**
	 * JavaScript-style 32-bit bitwise XOR.
	 *
	 * @param int $left  Left operand.
	 * @param int $right Right operand.
	 * @return int
	 */
	public static function xor32( int $left, int $right ): int {
		return self::toInt32( self::toInt32( $left ) ^ self::toInt32( $right ) );
	}
}
