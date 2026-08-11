<?php
/**
 * Xoroshiro128plus PRNG.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/prng/Xoroshiro128plus.js.
 */
final class Xoroshiro128plus {
	/**
	 * Original seed.
	 *
	 * @var int
	 */
	public int $seed;

	/**
	 * Four uint32 state words.
	 *
	 * @var array<int,int>
	 */
	public array $state = array();

	/**
	 * Whether the next output is the fresh half of the current state.
	 *
	 * @var bool
	 */
	private bool $fresh = true;

	/**
	 * @param int $seed Unsigned 32-bit seed.
	 */
	public function __construct( int $seed ) {
		$this->seed = Binary::toUint32( $seed );
		$source     = new Xorshift32( $this->seed );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->state[ $i ] = Binary::toUint32( floor( $source->next() * Binary::BITS32 ) );
		}
	}

	/**
	 * @return float Random float in [0,1).
	 */
	public function next(): float {
		if ( $this->fresh ) {
			$this->fresh = false;
			return Binary::toUint32( $this->state[0] + $this->state[2] ) / ( Binary::BITS32 + 1 );
		}

		$this->fresh = true;
		$s0          = $this->state[0];
		$s1          = $this->state[1];
		$s2          = Binary::xor32( $this->state[2], $s0 );
		$s3          = Binary::xor32( $this->state[3], $s1 );

		$this->state[0] = Binary::toUint32(
			Binary::xor32(
				Binary::xor32(
					Binary::or32( Binary::shiftLeft32( $s1, 23 ), Binary::unsignedRightShift( $s0, 9 ) ),
					$s2
				),
				Binary::or32( Binary::shiftLeft32( $s2, 14 ), Binary::unsignedRightShift( $s3, 18 ) )
			)
		);
		$this->state[1] = Binary::toUint32(
			Binary::xor32(
				Binary::xor32(
					Binary::or32( Binary::shiftLeft32( $s0, 23 ), Binary::unsignedRightShift( $s1, 9 ) ),
					$s3
				),
				Binary::shiftLeft32( $s3, 14 )
			)
		);
		$this->state[2] = Binary::toUint32(
			Binary::or32( Binary::shiftLeft32( $s3, 4 ), Binary::unsignedRightShift( $s2, 28 ) )
		);
		$this->state[3] = Binary::toUint32(
			Binary::or32( Binary::shiftLeft32( $s2, 4 ), Binary::unsignedRightShift( $s3, 28 ) )
		);

		return Binary::toUint32( $this->state[1] + $this->state[3] ) / ( Binary::BITS32 + 1 );
	}
}
