<?php
/**
 * Efficient schema-less binary decoding.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Static module port of lib0/decoding.js.
 */
final class Decoding {
	/**
	 * @param Buffer|string $buffer Binary data.
	 * @return Decoder
	 */
	public static function createDecoder( $buffer ): Decoder {
		if ( ! $buffer instanceof Buffer ) {
			$buffer = Buffer::fromBinaryString( (string) $buffer );
		}
		return new Decoder( $buffer );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return bool
	 */
	public static function hasContent( Decoder $decoder ): bool {
		return $decoder->pos !== $decoder->arr->byteLength();
	}

	/**
	 * @param Decoder  $decoder Decoder.
	 * @param int|null $newPos  New position.
	 * @return Decoder
	 */
	public static function clone( Decoder $decoder, ?int $newPos = null ): Decoder {
		$copy      = self::createDecoder( $decoder->arr );
		$copy->pos = null === $newPos ? $decoder->pos : $newPos;
		return $copy;
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @param int     $len     Length.
	 * @return Buffer
	 */
	public static function readUint8Array( Decoder $decoder, int $len ): Buffer {
		$view          = $decoder->arr->slice( $decoder->pos, $len );
		$decoder->pos += $len;
		return $view;
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return Buffer
	 */
	public static function readVarUint8Array( Decoder $decoder ): Buffer {
		return self::readUint8Array( $decoder, self::readVarUint( $decoder ) );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return Buffer
	 */
	public static function readTailAsUint8Array( Decoder $decoder ): Buffer {
		return self::readUint8Array( $decoder, $decoder->arr->byteLength() - $decoder->pos );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int
	 */
	public static function skip8( Decoder $decoder ): int {
		return $decoder->pos++;
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int
	 */
	public static function readUint8( Decoder $decoder ): int {
		return $decoder->arr->get( $decoder->pos++ );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int
	 */
	public static function readUint16( Decoder $decoder ): int {
		$uint          = $decoder->arr->get( $decoder->pos ) +
			( $decoder->arr->get( $decoder->pos + 1 ) << 8 );
		$decoder->pos += 2;
		return $uint;
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int
	 */
	public static function readUint32( Decoder $decoder ): int {
		$uint          = $decoder->arr->get( $decoder->pos ) +
			( $decoder->arr->get( $decoder->pos + 1 ) << 8 ) +
			( $decoder->arr->get( $decoder->pos + 2 ) << 16 ) +
			( $decoder->arr->get( $decoder->pos + 3 ) << 24 );
		$decoder->pos += 4;
		return Binary::unsignedRightShift( $uint, 0 );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int
	 */
	public static function readUint32BigEndian( Decoder $decoder ): int {
		$uint          = $decoder->arr->get( $decoder->pos + 3 ) +
			( $decoder->arr->get( $decoder->pos + 2 ) << 8 ) +
			( $decoder->arr->get( $decoder->pos + 1 ) << 16 ) +
			( $decoder->arr->get( $decoder->pos ) << 24 );
		$decoder->pos += 4;
		return Binary::unsignedRightShift( $uint, 0 );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int
	 */
	public static function peekUint8( Decoder $decoder ): int {
		return $decoder->arr->get( $decoder->pos );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int
	 */
	public static function peekUint16( Decoder $decoder ): int {
		return $decoder->arr->get( $decoder->pos ) +
			( $decoder->arr->get( $decoder->pos + 1 ) << 8 );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int
	 */
	public static function peekUint32( Decoder $decoder ): int {
		return Binary::unsignedRightShift(
			$decoder->arr->get( $decoder->pos ) +
			( $decoder->arr->get( $decoder->pos + 1 ) << 8 ) +
			( $decoder->arr->get( $decoder->pos + 2 ) << 16 ) +
			( $decoder->arr->get( $decoder->pos + 3 ) << 24 ),
			0
		);
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int
	 */
	public static function readVarUint( Decoder $decoder ): int {
		$num  = 0;
		$mult = 1;
		$len  = $decoder->arr->byteLength();
		while ( $decoder->pos < $len ) {
			$r     = $decoder->arr->get( $decoder->pos++ );
			$num  += ( $r & Binary::BITS7 ) * $mult;
			$mult *= 128;
			if ( $r < Binary::BIT8 ) {
				return $num;
			}
			if ( $num > Number::MAX_SAFE_INTEGER ) {
				throw Error::create( 'Integer out of Range' );
			}
		}
		throw Error::create( 'Unexpected end of array' );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int|float
	 */
	public static function readVarInt( Decoder $decoder ) {
		$r    = $decoder->arr->get( $decoder->pos++ );
		$num  = $r & Binary::BITS6;
		$mult = 64;
		$sign = ( $r & Binary::BIT7 ) > 0 ? -1 : 1;
		if ( 0 === ( $r & Binary::BIT8 ) ) {
			if ( -1 === $sign && 0 === $num ) {
				return -0.0;
			}
			return $sign * $num;
		}

		$len = $decoder->arr->byteLength();
		while ( $decoder->pos < $len ) {
			$r     = $decoder->arr->get( $decoder->pos++ );
			$num  += ( $r & Binary::BITS7 ) * $mult;
			$mult *= 128;
			if ( $r < Binary::BIT8 ) {
				return $sign * $num;
			}
			if ( $num > Number::MAX_SAFE_INTEGER ) {
				throw Error::create( 'Integer out of Range' );
			}
		}
		throw Error::create( 'Unexpected end of array' );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int
	 */
	public static function peekVarUint( Decoder $decoder ): int {
		$pos          = $decoder->pos;
		$num          = self::readVarUint( $decoder );
		$decoder->pos = $pos;
		return $num;
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return int|float
	 */
	public static function peekVarInt( Decoder $decoder ) {
		$pos          = $decoder->pos;
		$num          = self::readVarInt( $decoder );
		$decoder->pos = $pos;
		return $num;
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return string
	 */
	public static function readVarString( Decoder $decoder ): string {
		return self::readVarUint8Array( $decoder )->toBinaryString();
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return Buffer
	 */
	public static function readTerminatedUint8Array( Decoder $decoder ): Buffer {
		$encoder = Encoding::createEncoder();
		while ( true ) {
			$byte = self::readUint8( $decoder );
			if ( 0 === $byte ) {
				return Encoding::toUint8Array( $encoder );
			}
			if ( 1 === $byte ) {
				$byte = self::readUint8( $decoder );
			}
			Encoding::write( $encoder, $byte );
		}
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return string
	 */
	public static function readTerminatedString( Decoder $decoder ): string {
		return self::readTerminatedUint8Array( $decoder )->toBinaryString();
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return string
	 */
	public static function peekVarString( Decoder $decoder ): string {
		$pos          = $decoder->pos;
		$str          = self::readVarString( $decoder );
		$decoder->pos = $pos;
		return $str;
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @param int     $len     Length.
	 * @return Buffer
	 */
	public static function readFromDataView( Decoder $decoder, int $len ): Buffer {
		return self::readUint8Array( $decoder, $len );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return float
	 */
	public static function readFloat32( Decoder $decoder ): float {
		$data     = self::readFromDataView( $decoder, 4 )->toBinaryString();
		$unpacked = unpack( 'G', $data );
		return $unpacked[1];
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return float
	 */
	public static function readFloat64( Decoder $decoder ): float {
		$data     = self::readFromDataView( $decoder, 8 )->toBinaryString();
		$unpacked = unpack( 'E', $data );
		return $unpacked[1];
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return BigInt64
	 */
	public static function readBigInt64( Decoder $decoder ): BigInt64 {
		return BigInt64::fromBytes( self::readFromDataView( $decoder, 8 ) );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return mixed
	 */
	public static function readAny( Decoder $decoder ) {
		$tag = self::readUint8( $decoder );
		switch ( $tag ) {
			case 127:
				return UndefinedValue::getInstance();
			case 126:
				return null;
			case 125:
				return self::readVarInt( $decoder );
			case 124:
				return self::readFloat32( $decoder );
			case 123:
				return self::readFloat64( $decoder );
			case 122:
				return self::readBigInt64( $decoder );
			case 121:
				return false;
			case 120:
				return true;
			case 119:
				return self::readVarString( $decoder );
			case 118:
				return self::readAnyObject( $decoder );
			case 117:
				return self::readAnyArray( $decoder );
			case 116:
				return self::readVarUint8Array( $decoder );
			default:
				throw Error::create( 'Unexpected any type tag.' );
		}
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return \stdClass
	 */
	private static function readAnyObject( Decoder $decoder ): \stdClass {
		$len = self::readVarUint( $decoder );
		$obj = new \stdClass();
		for ( $i = 0; $i < $len; $i++ ) {
			$key         = self::readVarString( $decoder );
			$obj->{$key} = self::readAny( $decoder );
		}
		return $obj;
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @return array<int,mixed>
	 */
	private static function readAnyArray( Decoder $decoder ): array {
		$len = self::readVarUint( $decoder );
		$arr = array();
		for ( $i = 0; $i < $len; $i++ ) {
			$arr[] = self::readAny( $decoder );
		}
		return $arr;
	}
}
