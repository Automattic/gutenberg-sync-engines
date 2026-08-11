<?php
/**
 * Uint8Array-like binary string wrapper.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Carries binary data as a PHP string while exposing byte-oriented helpers.
 */
final class Buffer {
	/**
	 * Binary data.
	 *
	 * @var string
	 */
	private string $data;

	/**
	 * @param string $data Binary string.
	 */
	public function __construct( string $data = '' ) {
		$this->data = $data;
	}

	/**
	 * @param int $len Byte length.
	 * @return self
	 */
	public static function createUint8ArrayFromLen( int $len ): self {
		return new self( str_repeat( "\0", $len ) );
	}

	/**
	 * @param string $data Binary string.
	 * @return self
	 */
	public static function fromBinaryString( string $data ): self {
		return new self( $data );
	}

	/**
	 * @param array<int,int> $bytes Byte values.
	 * @return self
	 */
	public static function fromByteArray( array $bytes ): self {
		$data = '';
		foreach ( $bytes as $byte ) {
			$data .= chr( $byte & Binary::BITS8 );
		}
		return new self( $data );
	}

	/**
	 * @param string $base64 Base64 text.
	 * @return self
	 */
	public static function fromBase64( string $base64 ): self {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$data = base64_decode( $base64, true );
		if ( false === $data ) {
			throw new \InvalidArgumentException( 'Invalid base64 buffer.' );
		}
		return new self( $data );
	}

	/**
	 * @param string $base64 Base64url text.
	 * @return self
	 */
	public static function fromBase64UrlEncoded( string $base64 ): self {
		return self::fromBase64( strtr( $base64, '-_', '+/' ) );
	}

	/**
	 * @param string $hex Hex string.
	 * @return self
	 */
	public static function fromHexString( string $hex ): self {
		$hlen  = strlen( $hex );
		$bytes = array_fill( 0, (int) ceil( $hlen / 2 ), 0 );
		$count = count( $bytes );
		for ( $i = 0; $i < $hlen; $i += 2 ) {
			$start                                  = max( 0, $hlen - $i - 2 );
			$bytes[ $count - (int) ( $i / 2 ) - 1 ] = hexdec( substr( $hex, $start, min( 2, $hlen - $start ) ) );
		}
		return self::fromByteArray( $bytes );
	}

	/**
	 * @return int
	 */
	public function byteLength(): int {
		return strlen( $this->data );
	}

	/**
	 * @return string
	 */
	public function toBinaryString(): string {
		return $this->data;
	}

	/**
	 * @return array<int,int>
	 */
	public function toByteArray(): array {
		if ( '' === $this->data ) {
			return array();
		}
		return array_values( unpack( 'C*', $this->data ) );
	}

	/**
	 * @param int $index Byte offset.
	 * @return int
	 */
	public function get( int $index ): int {
		return ord( $this->data[ $index ] );
	}

	/**
	 * @param int $index Byte offset.
	 * @param int $value Byte value.
	 * @return void
	 */
	public function set( int $index, int $value ): void {
		$this->data[ $index ] = chr( $value & Binary::BITS8 );
	}

	/**
	 * @param int      $offset Byte offset.
	 * @param int|null $length Byte length.
	 * @return self
	 */
	public function slice( int $offset, ?int $length = null ): self {
		if ( null === $length ) {
			return new self( substr( $this->data, $offset ) );
		}
		return new self( substr( $this->data, $offset, $length ) );
	}

	/**
	 * @param self $other Buffer to append.
	 * @return void
	 */
	public function append( self $other ): void {
		$this->data .= $other->toBinaryString();
	}

	/**
	 * @return self
	 */
	public function copyUint8Array(): self {
		return new self( $this->data );
	}

	/**
	 * @return string
	 */
	public function toBase64(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $this->data );
	}

	/**
	 * @return string
	 */
	public function toBase64UrlEncoded(): string {
		return rtrim( strtr( $this->toBase64(), '+/', '-_' ), '=' );
	}

	/**
	 * @return string
	 */
	public function toHexString(): string {
		return bin2hex( $this->data );
	}

	/**
	 * @param self $other Other buffer.
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return hash_equals( $this->data, $other->toBinaryString() );
	}

	/**
	 * Shift byte array bits to the left without expanding the byte array.
	 *
	 * @param int $bits Number of bits in the range 0-7.
	 * @return self
	 */
	public function shiftNBitsLeft( int $bits ): self {
		if ( 0 === $bits ) {
			return $this->copyUint8Array();
		}

		$bytes = $this->toByteArray();
		$count = count( $bytes );
		if ( 0 === $count ) {
			return new self();
		}

		$bytes[0] = ( $bytes[0] << $bits ) & Binary::BITS8;
		for ( $i = 1; $i < $count; $i++ ) {
			$bytes[ $i - 1 ] |= Binary::unsignedRightShift( $bytes[ $i ], 8 - $bits );
			$bytes[ $i ]      = ( $bytes[ $i ] << $bits ) & Binary::BITS8;
		}
		return self::fromByteArray( $bytes );
	}

	/**
	 * @return string
	 */
	public function __toString(): string {
		return $this->data;
	}
}
