<?php
/**
 * Optimized unsigned integer RLE encoder.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/encoding.js UintOptRleEncoder.
 */
class UintOptRleEncoder {
	/**
	 * @var Encoder
	 */
	public Encoder $encoder;

	/**
	 * @var int
	 */
	public int $s = 0;

	/**
	 * @var int
	 */
	public int $count = 0;

	public function __construct() {
		$this->encoder = Encoding::createEncoder();
	}

	/**
	 * @param int $value Value.
	 * @return void
	 */
	public function write( int $value ): void {
		if ( $this->s === $value ) {
			++$this->count;
			return;
		}
		$this->flush();
		$this->count = 1;
		$this->s     = $value;
	}

	/**
	 * @return Buffer
	 */
	public function toUint8Array(): Buffer {
		$this->flush();
		return Encoding::toUint8Array( $this->encoder );
	}

	/**
	 * @return void
	 */
	private function flush(): void {
		if ( $this->count <= 0 ) {
			return;
		}
		if ( 1 === $this->count ) {
			Encoding::writeVarInt( $this->encoder, $this->s );
		} else {
			Encoding::writeVarInt( $this->encoder, 0 === $this->s ? -0.0 : -$this->s );
			Encoding::writeVarUint( $this->encoder, $this->count - 2 );
		}
	}
}
