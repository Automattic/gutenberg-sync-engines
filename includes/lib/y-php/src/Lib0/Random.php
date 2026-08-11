<?php
/**
 * Random helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Isomorphic random helpers backed by PHP random_int.
 */
final class Random {
	/**
	 * @return float
	 */
	public static function rand(): float {
		return random_int( 0, Binary::BITS32 ) / ( Binary::BITS32 + 1 );
	}

	/**
	 * @return int
	 */
	public static function uint32(): int {
		return random_int( 0, Binary::BITS32 );
	}

	/**
	 * @param array<int,mixed> $array Non-empty array.
	 * @return mixed
	 */
	public static function oneOf( array $array ) {
		return $array[ Math::floor( self::rand() * count( $array ) ) ];
	}
}
