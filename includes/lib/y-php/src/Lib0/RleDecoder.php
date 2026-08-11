<?php
/**
 * Basic run-length decoder.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/decoding.js RleDecoder.
 */
class RleDecoder {
	/**
	 * @var Decoder
	 */
	public Decoder $decoder;

	/**
	 * @var callable
	 */
	private $reader;

	/**
	 * @var mixed|null
	 */
	private $state = null;

	/**
	 * @var int
	 */
	private int $count = 0;

	/**
	 * @param Buffer   $buffer Encoded data.
	 * @param callable $reader Value reader.
	 */
	public function __construct( Buffer $buffer, callable $reader ) {
		$this->decoder = Decoding::createDecoder( $buffer );
		$this->reader  = $reader;
	}

	/**
	 * @return mixed
	 */
	public function read() {
		if ( 0 === $this->count ) {
			$reader      = $this->reader;
			$this->state = $reader( $this->decoder );
			$this->count = Decoding::hasContent( $this->decoder ) ? Decoding::readVarUint( $this->decoder ) + 1 : -1;
		}
		--$this->count;
		return $this->state;
	}
}
