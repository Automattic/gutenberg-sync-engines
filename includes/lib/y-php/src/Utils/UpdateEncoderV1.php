<?php
/**
 * Update encoder V1.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

use Yjs\Lib0\Arr;
use Yjs\Lib0\BigInt64;
use Yjs\Lib0\Buffer;
use Yjs\Lib0\Encoding;
use Yjs\Lib0\Error;
use Yjs\Lib0\Math;
use Yjs\Lib0\Obj;
use Yjs\Lib0\UndefinedValue;

/**
 * Port of UpdateEncoderV1 from yjs/src/utils/UpdateEncoder.js.
 */
class UpdateEncoderV1 extends DSEncoderV1 {
	/**
	 * @param ID $id ID.
	 * @return void
	 */
	public function writeLeftID( ID $id ): void {
		Encoding::writeVarUint( $this->restEncoder, $id->client );
		Encoding::writeVarUint( $this->restEncoder, $id->clock );
	}

	/**
	 * @param ID $id ID.
	 * @return void
	 */
	public function writeRightID( ID $id ): void {
		Encoding::writeVarUint( $this->restEncoder, $id->client );
		Encoding::writeVarUint( $this->restEncoder, $id->clock );
	}

	/**
	 * Use writeClient and writeClock instead of writeID if possible.
	 *
	 * @param int $client Client id.
	 * @return void
	 */
	public function writeClient( int $client ): void {
		Encoding::writeVarUint( $this->restEncoder, $client );
	}

	/**
	 * @param int $info Unsigned 8-bit integer.
	 * @return void
	 */
	public function writeInfo( int $info ): void {
		Encoding::writeUint8( $this->restEncoder, $info );
	}

	/**
	 * @param string $s String.
	 * @return void
	 */
	public function writeString( string $s ): void {
		Encoding::writeVarString( $this->restEncoder, $s );
	}

	/**
	 * @param bool $isYKey Whether the parent info is a Y key.
	 * @return void
	 */
	public function writeParentInfo( bool $isYKey ): void {
		Encoding::writeVarUint( $this->restEncoder, $isYKey ? 1 : 0 );
	}

	/**
	 * @param int $info Type ref.
	 * @return void
	 */
	public function writeTypeRef( int $info ): void {
		Encoding::writeVarUint( $this->restEncoder, $info );
	}

	/**
	 * Write len of a struct - well suited for Opt RLE encoder.
	 *
	 * @param int $len Length.
	 * @return void
	 */
	public function writeLen( int $len ): void {
		Encoding::writeVarUint( $this->restEncoder, $len );
	}

	/**
	 * @param mixed $any Value.
	 * @return void
	 */
	public function writeAny( $any ): void {
		Encoding::writeAny( $this->restEncoder, $any );
	}

	/**
	 * @param Buffer $buf Buffer.
	 * @return void
	 */
	public function writeBuf( Buffer $buf ): void {
		Encoding::writeVarUint8Array( $this->restEncoder, $buf );
	}

	/**
	 * @param mixed $embed JSON-serializable embed.
	 * @return void
	 */
	public function writeJSON( $embed ): void {
		Encoding::writeVarString( $this->restEncoder, self::jsonStringify( $embed ) );
	}

	/**
	 * @param string $key Key.
	 * @return void
	 */
	public function writeKey( string $key ): void {
		Encoding::writeVarString( $this->restEncoder, $key );
	}

	/**
	 * @param mixed $value JSON value.
	 * @return string
	 */
	private static function jsonStringify( $value ): string {
		if ( $value instanceof UndefinedValue ) {
			return 'undefined';
		}

		$normalized = self::normalizeJsonValue( $value );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$json = json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			throw Error::create( 'Unexpected JSON encode failure.' );
		}
		return $json;
	}

	/**
	 * @param mixed $value JSON value.
	 * @return mixed
	 */
	private static function normalizeJsonValue( $value ) {
		if ( $value instanceof BigInt64 ) {
			throw Error::create( 'Do not know how to serialize a BigInt.' );
		}
		if ( $value instanceof UndefinedValue ) {
			return null;
		}
		if ( is_float( $value ) ) {
			if ( is_nan( $value ) || is_infinite( $value ) ) {
				return null;
			}
			if ( 0.0 === $value && Math::isNegativeZero( $value ) ) {
				return 0;
			}
		}
		if ( is_array( $value ) ) {
			return Arr::isList( $value ) ? self::normalizeJsonArray( $value ) : self::normalizeJsonObject( $value );
		}
		if ( $value instanceof \stdClass ) {
			return (object) self::normalizeJsonObject( Obj::toArray( $value ) );
		}
		return $value;
	}

	/**
	 * @param array<int,mixed> $value JSON array.
	 * @return array<int,mixed>
	 */
	private static function normalizeJsonArray( array $value ): array {
		$normalized = array();
		foreach ( $value as $item ) {
			$normalized[] = self::normalizeJsonValue( $item );
		}
		return $normalized;
	}

	/**
	 * @param array<int|string,mixed> $value JSON object.
	 * @return array<string,mixed>
	 */
	private static function normalizeJsonObject( array $value ): array {
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( $item instanceof UndefinedValue ) {
				continue;
			}
			$normalized[ (string) $key ] = self::normalizeJsonValue( $item );
		}
		return $normalized;
	}
}
