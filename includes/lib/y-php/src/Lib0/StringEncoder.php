<?php
/**
 * Optimized string encoder.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/encoding.js StringEncoder.
 *
 * The JS original flushes `s` into a `sarr` chunk list once its recomputed
 * UTF-16 length passes 19, which would cost an extra utf16Length() of the
 * accumulated chunk on every write here. That chunking exists in lib0 only
 * to avoid quadratic string concatenation in JS; the chunks are joined back
 * into ONE string in toUint8Array(), so chunk boundaries have zero wire
 * effect, and PHP string appends are already amortized O(1). This port
 * keeps a single running string instead: byte-identical output, one
 * utf16Length() per write (of the written piece only).
 */
class StringEncoder {
	/**
	 * @var string
	 */
	private string $s = '';

	/**
	 * @var UintOptRleEncoder
	 */
	private UintOptRleEncoder $lensE;

	public function __construct() {
		$this->lensE = new UintOptRleEncoder();
	}

	/**
	 * @param string $string String.
	 * @return void
	 */
	public function write( string $string ): void {
		$this->s .= $string;
		$this->lensE->write( Str::utf16Length( $string ) );
	}

	/**
	 * @return Buffer
	 */
	public function toUint8Array(): Buffer {
		$encoder = Encoding::createEncoder();
		Encoding::writeVarString( $encoder, $this->s );
		$this->s = '';
		Encoding::writeUint8Array( $encoder, $this->lensE->toUint8Array() );
		return Encoding::toUint8Array( $encoder );
	}
}
