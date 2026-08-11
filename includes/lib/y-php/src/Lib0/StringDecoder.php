<?php
/**
 * Optimized string decoder.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/decoding.js StringDecoder.
 */
class StringDecoder {
	/**
	 * @var UintOptRleDecoder
	 */
	private UintOptRleDecoder $decoder;

	/**
	 * @var string
	 */
	private string $str;

	/**
	 * @var int
	 */
	private int $spos = 0;

	/**
	 * @param Buffer $buffer Encoded data.
	 */
	public function __construct( Buffer $buffer ) {
		$this->decoder = new UintOptRleDecoder( $buffer );
		$this->str     = Decoding::readVarString( $this->decoder->decoder );
	}

	/**
	 * @return string
	 */
	public function read(): string {
		$end        = $this->spos + $this->decoder->read();
		$result     = Str::sliceUtf16( $this->str, $this->spos, $end );
		$this->spos = $end;
		return $result;
	}
}
