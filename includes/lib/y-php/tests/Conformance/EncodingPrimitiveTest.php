<?php
/**
 * Primitive encoding conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\BigInt64;
use Yjs\Lib0\Buffer;
use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoder;
use Yjs\Lib0\Encoding;
use Yjs\Lib0\Math;
use Yjs\Lib0\UndefinedValue;

/**
 * Verifies byte parity with fixtures captured from real lib0.
 */
final class EncodingPrimitiveTest extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function primitiveCaseProvider(): array {
		$fixture = $this->fixture( 'encoding-primitives.json' );
		return array_map(
			static fn ( array $case ): array => array( $case ),
			$fixture['cases']
		);
	}

	/**
	 * @dataProvider primitiveCaseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testPrimitiveWriterMatchesJsBytesAndDecodes( array $case ): void {
		$encoder = Encoding::createEncoder();
		$input   = $this->materialize( $case['input'] );
		$this->writeCase( $encoder, $case['writer'], $input );

		$actual = Encoding::toUint8Array( $encoder );
		self::assertSame( $case['hex'], $actual->toHexString(), $case['name'] );

		$decoder = Decoding::createDecoder( Buffer::fromHexString( $case['hex'] ) );
		$decoded = $this->readCase( $decoder, $case['writer'], $input );
		self::assertSame( $case['decoded'], $this->normalize( $decoded ), $case['name'] . ' decoded' );
		self::assertFalse( Decoding::hasContent( $decoder ), $case['name'] . ' consumes all bytes' );

		if ( 'writeAny' === $case['writer'] ) {
			$roundtrip = Encoding::createEncoder();
			Encoding::writeAny( $roundtrip, $decoded );
			self::assertSame( $case['hex'], Encoding::toUint8Array( $roundtrip )->toHexString(), $case['name'] . ' writeAny roundtrip' );
		}
	}

	/**
	 * @param string $name Fixture file name.
	 * @return array<string,mixed>
	 */
	private function fixture( string $name ): array {
		$path = dirname( __DIR__ ) . '/fixtures/' . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			self::fail( 'Unable to read fixture ' . $name );
		}
		return $data;
	}

	/**
	 * @param Encoder $encoder Encoder.
	 * @param string  $writer  Writer name.
	 * @param mixed   $input   Input value.
	 * @return void
	 */
	private function writeCase( Encoder $encoder, string $writer, $input ): void {
		switch ( $writer ) {
			case 'writeVarUint':
				Encoding::writeVarUint( $encoder, $input );
				return;
			case 'writeVarInt':
				Encoding::writeVarInt( $encoder, $input );
				return;
			case 'writeVarString':
				Encoding::writeVarString( $encoder, $input );
				return;
			case 'writeFloat32':
				Encoding::writeFloat32( $encoder, (float) $input );
				return;
			case 'writeFloat64':
				Encoding::writeFloat64( $encoder, (float) $input );
				return;
			case 'writeBigInt64':
				Encoding::writeBigInt64( $encoder, $input );
				return;
			case 'writeUint8Array':
				Encoding::writeUint8Array( $encoder, $input );
				return;
			case 'writeVarUint8Array':
				Encoding::writeVarUint8Array( $encoder, $input );
				return;
			case 'writeAny':
				Encoding::writeAny( $encoder, $input );
				return;
		}
		self::fail( 'Unknown writer ' . $writer );
	}

	/**
	 * @param \Yjs\Lib0\Decoder $decoder Decoder.
	 * @param string            $writer  Writer name.
	 * @param mixed             $input   Input value.
	 * @return mixed
	 */
	private function readCase( \Yjs\Lib0\Decoder $decoder, string $writer, $input ) {
		switch ( $writer ) {
			case 'writeVarUint':
				return Decoding::readVarUint( $decoder );
			case 'writeVarInt':
				return Decoding::readVarInt( $decoder );
			case 'writeVarString':
				return Decoding::readVarString( $decoder );
			case 'writeFloat32':
				return Decoding::readFloat32( $decoder );
			case 'writeFloat64':
				return Decoding::readFloat64( $decoder );
			case 'writeBigInt64':
				return Decoding::readBigInt64( $decoder );
			case 'writeUint8Array':
				return Decoding::readUint8Array( $decoder, $input->byteLength() );
			case 'writeVarUint8Array':
				return Decoding::readVarUint8Array( $decoder );
			case 'writeAny':
				return Decoding::readAny( $decoder );
		}
		self::fail( 'Unknown writer ' . $writer );
	}

	/**
	 * @param array<string,mixed> $desc Descriptor.
	 * @return mixed
	 */
	private function materialize( array $desc ) {
		switch ( $desc['type'] ) {
			case 'undefined':
				return UndefinedValue::getInstance();
			case 'null':
				return null;
			case 'bigint':
				return BigInt64::fromString( $desc['value'] );
			case 'number':
				return $this->materializeNumber( $desc['value'] );
			case 'string':
				return $desc['value'];
			case 'boolean':
				return $desc['value'];
			case 'uint8array':
				return Buffer::fromByteArray( $desc['value'] );
			case 'array':
				return array_map(
					fn ( array $value ) => $this->materialize( $value ),
					$desc['value']
				);
			case 'object':
				$obj = new \stdClass();
				foreach ( $desc['value'] as $pair ) {
					$obj->{$pair[0]} = $this->materialize( $pair[1] );
				}
				return $obj;
		}
		self::fail( 'Unknown descriptor type ' . $desc['type'] );
	}

	/**
	 * @param mixed $value Number descriptor value.
	 * @return int|float
	 */
	private function materializeNumber( $value ) {
		if ( 'NaN' === $value ) {
			return NAN;
		}
		if ( 'Infinity' === $value ) {
			return INF;
		}
		if ( '-Infinity' === $value ) {
			return -INF;
		}
		if ( '-0' === $value ) {
			return -0.0;
		}
		return $value;
	}

	/**
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 */
	private function normalize( $value ): array {
		if ( $value instanceof UndefinedValue ) {
			return array( 'type' => 'undefined' );
		}
		if ( null === $value ) {
			return array( 'type' => 'null' );
		}
		if ( $value instanceof BigInt64 ) {
			return array(
				'type'  => 'bigint',
				'value' => $value->toString(),
			);
		}
		if ( $value instanceof Buffer ) {
			return array(
				'type'  => 'uint8array',
				'value' => $value->toByteArray(),
			);
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return $this->normalizeNumber( $value );
		}
		if ( is_string( $value ) ) {
			return array(
				'type'  => 'string',
				'value' => $value,
			);
		}
		if ( is_bool( $value ) ) {
			return array(
				'type'  => 'boolean',
				'value' => $value,
			);
		}
		if ( is_array( $value ) ) {
			return array(
				'type'  => 'array',
				'value' => array_map(
					fn ( $item ) => $this->normalize( $item ),
					$value
				),
			);
		}
		if ( $value instanceof \stdClass ) {
			$pairs = array();
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$pairs[] = array( $key, $this->normalize( $item ) );
			}
			return array(
				'type'  => 'object',
				'value' => $pairs,
			);
		}
		self::fail( 'Unable to normalize value.' );
	}

	/**
	 * @param int|float $value Number.
	 * @return array<string,mixed>
	 */
	private function normalizeNumber( $value ): array {
		if ( is_float( $value ) && is_nan( $value ) ) {
			return array(
				'type'  => 'number',
				'value' => 'NaN',
			);
		}
		if ( INF === $value ) {
			return array(
				'type'  => 'number',
				'value' => 'Infinity',
			);
		}
		if ( -INF === $value ) {
			return array(
				'type'  => 'number',
				'value' => '-Infinity',
			);
		}
		if ( Math::isNegativeZero( $value ) && ( 0.0 === $value || 0 === $value ) ) {
			return array(
				'type'  => 'number',
				'value' => '-0',
			);
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			$value = (int) $value;
		}
		return array(
			'type'  => 'number',
			'value' => $value,
		);
	}
}
