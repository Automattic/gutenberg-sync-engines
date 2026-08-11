<?php
/**
 * Efficient schema-less binary encoding.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Static module port of lib0/encoding.js.
 */
final class Encoding {
	/**
	 * @return Encoder
	 */
	public static function createEncoder(): Encoder {
		return new Encoder();
	}

	/**
	 * @param callable $fn Encoder callback.
	 * @return Buffer
	 */
	public static function encode( callable $fn ): Buffer {
		$encoder = self::createEncoder();
		$fn( $encoder );
		return self::toUint8Array( $encoder );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @return int
	 */
	public static function length( Encoder $encoder ): int {
		return strlen( $encoder->data );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @return bool
	 */
	public static function hasContent( Encoder $encoder ): bool {
		return '' !== $encoder->data;
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @return Buffer
	 */
	public static function toUint8Array( Encoder $encoder ): Buffer {
		return Buffer::fromBinaryString( $encoder->data );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param int     $len     Required length.
	 * @return void
	 */
	public static function verifyLen( Encoder $encoder, int $len ): void {}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param int     $num     Byte value.
	 * @return void
	 */
	public static function write( Encoder $encoder, int $num ): void {
		$encoder->data .= chr( $num & Binary::BITS8 );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param int     $pos     Position.
	 * @param int     $num     Byte value.
	 * @return void
	 */
	public static function set( Encoder $encoder, int $pos, int $num ): void {
		$encoder->data[ $pos ] = chr( $num & Binary::BITS8 );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param int     $num     Byte value.
	 * @return void
	 */
	public static function writeUint8( Encoder $encoder, int $num ): void {
		self::write( $encoder, $num );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param int     $pos     Position.
	 * @param int     $num     Byte value.
	 * @return void
	 */
	public static function setUint8( Encoder $encoder, int $pos, int $num ): void {
		self::set( $encoder, $pos, $num );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param int     $num     Uint16 value.
	 * @return void
	 */
	public static function writeUint16( Encoder $encoder, int $num ): void {
		self::write( $encoder, $num & Binary::BITS8 );
		self::write( $encoder, Binary::unsignedRightShift( $num, 8 ) & Binary::BITS8 );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param int     $pos     Position.
	 * @param int     $num     Uint16 value.
	 * @return void
	 */
	public static function setUint16( Encoder $encoder, int $pos, int $num ): void {
		self::set( $encoder, $pos, $num & Binary::BITS8 );
		self::set( $encoder, $pos + 1, Binary::unsignedRightShift( $num, 8 ) & Binary::BITS8 );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param int     $num     Uint32 value.
	 * @return void
	 */
	public static function writeUint32( Encoder $encoder, int $num ): void {
		$num = Binary::toUint32( $num );
		for ( $i = 0; $i < 4; $i++ ) {
			self::write( $encoder, $num & Binary::BITS8 );
			$num = Binary::unsignedRightShift( $num, 8 );
		}
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param int     $num     Uint32 value.
	 * @return void
	 */
	public static function writeUint32BigEndian( Encoder $encoder, int $num ): void {
		for ( $i = 3; $i >= 0; $i-- ) {
			self::write( $encoder, Binary::unsignedRightShift( $num, 8 * $i ) & Binary::BITS8 );
		}
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param int     $pos     Position.
	 * @param int     $num     Uint32 value.
	 * @return void
	 */
	public static function setUint32( Encoder $encoder, int $pos, int $num ): void {
		$num = Binary::toUint32( $num );
		for ( $i = 0; $i < 4; $i++ ) {
			self::set( $encoder, $pos + $i, $num & Binary::BITS8 );
			$num = Binary::unsignedRightShift( $num, 8 );
		}
	}

	/**
	 * @param Encoder   $encoder Encoder.
	 * @param int|float $num     Unsigned integer up to Number.MAX_SAFE_INTEGER.
	 * @return void
	 */
	public static function writeVarUint( Encoder $encoder, $num ): void {
		while ( $num > Binary::BITS7 ) {
			self::write( $encoder, Binary::BIT8 | ( Binary::BITS7 & (int) $num ) );
			$num = floor( $num / 128 );
		}
		self::write( $encoder, Binary::BITS7 & (int) $num );
	}

	/**
	 * @param Encoder   $encoder Encoder.
	 * @param int|float $num     Signed integer.
	 * @return void
	 */
	public static function writeVarInt( Encoder $encoder, $num ): void {
		$is_negative = Math::isNegativeZero( $num );
		if ( $is_negative ) {
			$num = -$num;
		}
		self::write(
			$encoder,
			( $num > Binary::BITS6 ? Binary::BIT8 : 0 ) |
			( $is_negative ? Binary::BIT7 : 0 ) |
			( Binary::BITS6 & (int) $num )
		);
		$num = floor( $num / 64 );
		while ( $num > 0 ) {
			self::write(
				$encoder,
				( $num > Binary::BITS7 ? Binary::BIT8 : 0 ) |
				( Binary::BITS7 & (int) $num )
			);
			$num = floor( $num / 128 );
		}
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param string  $str     UTF-8 string.
	 * @return void
	 */
	public static function writeVarString( Encoder $encoder, string $str ): void {
		self::writeVarUint( $encoder, strlen( $str ) );
		self::writeUint8Array( $encoder, Buffer::fromBinaryString( $str ) );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param string  $str     UTF-8 string.
	 * @return void
	 */
	public static function writeTerminatedString( Encoder $encoder, string $str ): void {
		self::writeTerminatedUint8Array( $encoder, Buffer::fromBinaryString( $str ) );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param Buffer  $buf     Buffer.
	 * @return void
	 */
	public static function writeTerminatedUint8Array( Encoder $encoder, Buffer $buf ): void {
		foreach ( $buf->toByteArray() as $byte ) {
			if ( 0 === $byte || 1 === $byte ) {
				self::write( $encoder, 1 );
			}
			self::write( $encoder, $byte );
		}
		self::write( $encoder, 0 );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param Encoder $append  Encoder to append.
	 * @return void
	 */
	public static function writeBinaryEncoder( Encoder $encoder, Encoder $append ): void {
		self::writeUint8Array( $encoder, self::toUint8Array( $append ) );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param Buffer  $buffer  Buffer.
	 * @return void
	 */
	public static function writeUint8Array( Encoder $encoder, Buffer $buffer ): void {
		$encoder->data .= $buffer->toBinaryString();
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param Buffer  $buffer  Buffer.
	 * @return void
	 */
	public static function writeVarUint8Array( Encoder $encoder, Buffer $buffer ): void {
		self::writeVarUint( $encoder, $buffer->byteLength() );
		self::writeUint8Array( $encoder, $buffer );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param float   $num     Float value.
	 * @return void
	 */
	public static function writeFloat32( Encoder $encoder, float $num ): void {
		$encoder->data .= pack( 'G', $num );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param float   $num     Float value.
	 * @return void
	 */
	public static function writeFloat64( Encoder $encoder, float $num ): void {
		$encoder->data .= pack( 'E', $num );
	}

	/**
	 * @param Encoder             $encoder Encoder.
	 * @param int|string|BigInt64 $num     Bigint value.
	 * @return void
	 */
	public static function writeBigInt64( Encoder $encoder, $num ): void {
		if ( $num instanceof BigInt64 ) {
			$value = $num;
		} elseif ( is_int( $num ) ) {
			$value = BigInt64::fromInt( $num );
		} else {
			$value = BigInt64::fromString( (string) $num );
		}
		self::writeUint8Array( $encoder, $value->toBytes() );
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param mixed   $data    Data to encode.
	 * @return void
	 */
	public static function writeAny( Encoder $encoder, $data ): void {
		if ( $data instanceof UndefinedValue ) {
			self::write( $encoder, 127 );
			return;
		}
		if ( $data instanceof BigInt64 ) {
			self::write( $encoder, 122 );
			self::writeBigInt64( $encoder, $data );
			return;
		}
		if ( $data instanceof Buffer ) {
			self::write( $encoder, 116 );
			self::writeVarUint8Array( $encoder, $data );
			return;
		}

		if ( is_string( $data ) ) {
			self::write( $encoder, 119 );
			self::writeVarString( $encoder, $data );
			return;
		}

		if ( is_int( $data ) || is_float( $data ) ) {
			self::writeAnyNumber( $encoder, $data );
			return;
		}

		if ( null === $data ) {
			self::write( $encoder, 126 );
			return;
		}

		if ( is_array( $data ) ) {
			if ( Arr::isList( $data ) ) {
				self::writeAnyArray( $encoder, $data );
			} else {
				self::writeAnyObject( $encoder, $data );
			}
			return;
		}

		if ( $data instanceof \stdClass ) {
			self::writeAnyObject( $encoder, Obj::toArray( $data ) );
			return;
		}

		if ( is_bool( $data ) ) {
			self::write( $encoder, $data ? 120 : 121 );
			return;
		}

		self::write( $encoder, 127 );
	}

	/**
	 * @param Encoder   $encoder Encoder.
	 * @param int|float $num     Number.
	 * @return void
	 */
	private static function writeAnyNumber( Encoder $encoder, $num ): void {
		if ( Number::isInteger( $num ) && Math::abs( $num ) <= Binary::BITS31 ) {
			self::write( $encoder, 125 );
			self::writeVarInt( $encoder, $num );
		} elseif ( self::isFloat32( (float) $num ) ) {
			self::write( $encoder, 124 );
			self::writeFloat32( $encoder, (float) $num );
		} else {
			self::write( $encoder, 123 );
			self::writeFloat64( $encoder, (float) $num );
		}
	}

	/**
	 * @param float $num Number.
	 * @return bool
	 */
	private static function isFloat32( float $num ): bool {
		if ( is_nan( $num ) ) {
			return false;
		}
		$roundtrip = unpack( 'G', pack( 'G', $num ) );
		return $roundtrip[1] === $num;
	}

	/**
	 * @param Encoder          $encoder Encoder.
	 * @param array<int,mixed> $array   Array value.
	 * @return void
	 */
	private static function writeAnyArray( Encoder $encoder, array $array ): void {
		self::write( $encoder, 117 );
		self::writeVarUint( $encoder, count( $array ) );
		foreach ( $array as $value ) {
			self::writeAny( $encoder, $value );
		}
	}

	/**
	 * @param Encoder             $encoder Encoder.
	 * @param array<string,mixed> $object  Object value.
	 * @return void
	 */
	private static function writeAnyObject( Encoder $encoder, array $object ): void {
		self::write( $encoder, 118 );
		self::writeVarUint( $encoder, count( $object ) );
		foreach ( $object as $key => $value ) {
			self::writeVarString( $encoder, (string) $key );
			self::writeAny( $encoder, $value );
		}
	}
}
