<?php
/**
 * Xorshift32 PRNG.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/prng/Xorshift32.js.
 */
final class Xorshift32 {
	/**
	 * Original seed.
	 *
	 * @var int
	 */
	public int $seed;

	/**
	 * Current state.
	 *
	 * @var int
	 */
	private int $stateValue;

	/**
	 * @param int $seed Unsigned 32-bit seed.
	 */
	public function __construct( int $seed ) {
		$this->seed       = Binary::toUint32( $seed );
		$this->stateValue = Binary::toInt32( $this->seed );
	}

	/**
	 * @return float Random float in [0,1).
	 */
	public function next(): float {
		$x                = $this->stateValue;
		$x                = Binary::xor32( $x, Binary::shiftLeft32( $x, 13 ) );
		$x                = Binary::xor32( $x, $x >> 17 );
		$x                = Binary::xor32( $x, Binary::shiftLeft32( $x, 5 ) );
		$this->stateValue = Binary::toInt32( $x );
		return Binary::toUint32( $x ) / ( Binary::BITS32 + 1 );
	}
}
