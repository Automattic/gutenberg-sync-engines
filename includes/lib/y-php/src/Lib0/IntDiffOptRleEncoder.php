<?php
/**
 * Optimized integer diff RLE encoder.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/encoding.js IntDiffOptRleEncoder.
 */
class IntDiffOptRleEncoder {
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

	/**
	 * @var int
	 */
	public int $diff = 0;

	public function __construct() {
		$this->encoder = Encoding::createEncoder();
	}

	/**
	 * @param int $value Value.
	 * @return void
	 */
	public function write( int $value ): void {
		if ( $this->diff === $value - $this->s ) {
			$this->s = $value;
			++$this->count;
			return;
		}
		$this->flush();
		$this->count = 1;
		$this->diff  = $value - $this->s;
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
		$encodedDiff = $this->diff * 2 + ( 1 === $this->count ? 0 : 1 );
		Encoding::writeVarInt( $this->encoder, $encodedDiff );
		if ( $this->count > 1 ) {
			Encoding::writeVarUint( $this->encoder, $this->count - 2 );
		}
	}
}
