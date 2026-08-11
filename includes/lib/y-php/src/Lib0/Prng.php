<?php
/**
 * Pseudo-random number helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/prng.js.
 */
final class Prng {
	/**
	 * @param int $seed Positive 32-bit seed.
	 * @return Xoroshiro128plus
	 */
	public static function create( int $seed ): Xoroshiro128plus {
		return new Xoroshiro128plus( $seed );
	}

	/**
	 * @param Xoroshiro128plus $gen Generator.
	 * @return bool
	 */
	public static function bool( Xoroshiro128plus $gen ): bool {
		return $gen->next() >= 0.5;
	}

	/**
	 * @param Xoroshiro128plus $gen Generator.
	 * @param int              $min Inclusive lower bound.
	 * @param int              $max Inclusive upper bound.
	 * @return int
	 */
	public static function int53( Xoroshiro128plus $gen, int $min, int $max ): int {
		return Math::floor( $gen->next() * ( $max + 1 - $min ) + $min );
	}

	/**
	 * @param Xoroshiro128plus $gen Generator.
	 * @param int              $min Inclusive lower bound.
	 * @param int              $max Inclusive upper bound.
	 * @return int
	 */
	public static function uint53( Xoroshiro128plus $gen, int $min, int $max ): int {
		return Math::abs( self::int53( $gen, $min, $max ) );
	}

	/**
	 * @param Xoroshiro128plus $gen Generator.
	 * @param int              $min Inclusive lower bound.
	 * @param int              $max Inclusive upper bound.
	 * @return int
	 */
	public static function int32( Xoroshiro128plus $gen, int $min, int $max ): int {
		return Math::floor( $gen->next() * ( $max + 1 - $min ) + $min );
	}

	/**
	 * @param Xoroshiro128plus $gen Generator.
	 * @param int              $min Inclusive lower bound.
	 * @param int              $max Inclusive upper bound.
	 * @return int
	 */
	public static function uint32( Xoroshiro128plus $gen, int $min, int $max ): int {
		return Binary::unsignedRightShift( self::int32( $gen, $min, $max ), 0 );
	}

	/**
	 * @param Xoroshiro128plus $gen Generator.
	 * @param int              $min Inclusive lower bound.
	 * @param int              $max Inclusive upper bound.
	 * @return int
	 */
	public static function int31( Xoroshiro128plus $gen, int $min, int $max ): int {
		return self::int32( $gen, $min, $max );
	}

	/**
	 * @param Xoroshiro128plus $gen Generator.
	 * @return float
	 */
	public static function real53( Xoroshiro128plus $gen ): float {
		return $gen->next();
	}

	/**
	 * @param Xoroshiro128plus $gen Generator.
	 * @return string
	 */
	public static function char( Xoroshiro128plus $gen ): string {
		return Str::fromCharCode( self::int31( $gen, 32, 126 ) );
	}

	/**
	 * @param Xoroshiro128plus $gen Generator.
	 * @return string
	 */
	public static function letter( Xoroshiro128plus $gen ): string {
		return Str::fromCharCode( self::int31( $gen, 97, 122 ) );
	}

	/**
	 * @param Xoroshiro128plus $gen    Generator.
	 * @param int              $minLen Minimum length.
	 * @param int              $maxLen Maximum length.
	 * @return string
	 */
	public static function word( Xoroshiro128plus $gen, int $minLen = 0, int $maxLen = 20 ): string {
		$len = self::int31( $gen, $minLen, $maxLen );
		$str = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$str .= self::letter( $gen );
		}
		return $str;
	}

	/**
	 * @param Xoroshiro128plus $gen   Generator.
	 * @param array<int,mixed> $array Non-empty array.
	 * @return mixed
	 */
	public static function oneOf( Xoroshiro128plus $gen, array $array ) {
		return $array[ self::int31( $gen, 0, count( $array ) - 1 ) ];
	}

	/**
	 * @param Xoroshiro128plus $gen Generator.
	 * @param int              $len Byte length.
	 * @return Buffer
	 */
	public static function uint8Array( Xoroshiro128plus $gen, int $len ): Buffer {
		$bytes = array();
		for ( $i = 0; $i < $len; $i++ ) {
			$bytes[] = self::int32( $gen, 0, Binary::BITS8 );
		}
		return Buffer::fromByteArray( $bytes );
	}
}
