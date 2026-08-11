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
 */
class StringEncoder {
	/**
	 * @var array<int,string>
	 */
	private array $sarr = array();

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
		if ( Str::utf16Length( $this->s ) > 19 ) {
			$this->sarr[] = $this->s;
			$this->s      = '';
		}
		$this->lensE->write( Str::utf16Length( $string ) );
	}

	/**
	 * @return Buffer
	 */
	public function toUint8Array(): Buffer {
		$encoder      = Encoding::createEncoder();
		$this->sarr[] = $this->s;
		$this->s      = '';
		Encoding::writeVarString( $encoder, implode( '', $this->sarr ) );
		Encoding::writeUint8Array( $encoder, $this->lensE->toUint8Array() );
		return Encoding::toUint8Array( $encoder );
	}
}
