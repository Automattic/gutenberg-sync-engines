<?php
/**
 * Optimized integer diff RLE decoder.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/decoding.js IntDiffOptRleDecoder.
 */
class IntDiffOptRleDecoder {
	/**
	 * @var Decoder
	 */
	public Decoder $decoder;

	/**
	 * @var int
	 */
	private int $s = 0;

	/**
	 * @var int
	 */
	private int $count = 0;

	/**
	 * @var int
	 */
	private int $diff = 0;

	/**
	 * @param Buffer $buffer Encoded data.
	 */
	public function __construct( Buffer $buffer ) {
		$this->decoder = Decoding::createDecoder( $buffer );
	}

	/**
	 * @return int
	 */
	public function read(): int {
		if ( 0 === $this->count ) {
			$diff        = Decoding::readVarInt( $this->decoder );
			$hasCount    = ( (int) $diff & 1 ) !== 0;
			$this->diff  = Math::floor( $diff / 2 );
			$this->count = 1;
			if ( $hasCount ) {
				$this->count = Decoding::readVarUint( $this->decoder ) + 2;
			}
		}
		$this->s += $this->diff;
		--$this->count;
		return $this->s;
	}
}
