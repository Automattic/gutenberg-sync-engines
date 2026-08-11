<?php
/**
 * Optimized unsigned integer RLE decoder.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/decoding.js UintOptRleDecoder.
 */
class UintOptRleDecoder {
	/**
	 * @var Decoder
	 */
	public Decoder $decoder;

	/**
	 * @var int|float
	 */
	private $s = 0;

	/**
	 * @var int
	 */
	private int $count = 0;

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
			$this->s     = Decoding::readVarInt( $this->decoder );
			$isNegative  = Math::isNegativeZero( $this->s );
			$this->count = 1;
			if ( $isNegative ) {
				$this->s     = (int) -$this->s;
				$this->count = Decoding::readVarUint( $this->decoder ) + 2;
			}
		}
		--$this->count;
		return (int) $this->s;
	}
}
