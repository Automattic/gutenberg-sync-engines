<?php
/**
 * V2 update codec conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\BigInt64;
use Yjs\Lib0\Buffer;
use Yjs\Lib0\Decoder;
use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoder;
use Yjs\Lib0\Encoding;
use Yjs\Lib0\UndefinedValue;
use Yjs\Utils\ID;
use Yjs\Utils\UpdateDecoderV2;
use Yjs\Utils\UpdateEncoderV2;

use function Yjs\readID;
use function Yjs\writeID;

/**
 * Verifies V2 update-codec byte parity with fixtures captured from real yjs.
 */
final class UpdateCodecV2Test extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function codecCaseProvider(): array {
		$fixture = $this->fixture( 'update-codecs-v2.json' );
		return array_map(
			static fn ( array $case ): array => array( $case ),
			$fixture['cases']
		);
	}

	/**
	 * @dataProvider codecCaseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testV2CodecPrimitiveMatchesJsBytesAndRoundTrips( array $case ): void {
		$input  = $this->materialize( $case['input'] );
		$actual = $this->writeCase( $case['method'], $input );

		self::assertSame( $case['hex'], $actual->toHexString(), $case['name'] );

		$decoder = Decoding::createDecoder( Buffer::fromHexString( $case['hex'] ) );
		$decoded = $this->readCase( $decoder, $case['method'] );

		self::assertSame( $case['decoded'], $this->normalize( $decoded ), $case['name'] . ' decoded' );
		self::assertFalse( Decoding::hasContent( $decoder ), $case['name'] . ' consumes all bytes' );
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
	 * @param string $method Writer method.
	 * @param mixed  $input  Input value.
	 * @return Buffer
	 */
	private function writeCase( string $method, $input ): Buffer {
		if ( 'writeID' === $method ) {
			$encoder = Encoding::createEncoder();
			writeID( $encoder, $input );
			return Encoding::toUint8Array( $encoder );
		}

		$encoder = new UpdateEncoderV2();
		$this->writeUpdateCase( $encoder, $method, $input );
		return $encoder->toUint8Array();
	}

	/**
	 * @param UpdateEncoderV2 $encoder Encoder.
	 * @param string          $method  Writer method.
	 * @param mixed           $input   Input value.
	 * @return void
	 */
	private function writeUpdateCase( UpdateEncoderV2 $encoder, string $method, $input ): void {
		switch ( $method ) {
			case 'writeLeftID':
				$encoder->writeLeftID( $input );
				return;
			case 'writeRightID':
				$encoder->writeRightID( $input );
				return;
			case 'writeClient':
				$encoder->writeClient( $input );
				return;
			case 'writeInfo':
				$encoder->writeInfo( $input );
				return;
			case 'writeString':
				$encoder->writeString( $input );
				return;
			case 'writeParentInfo':
				$encoder->writeParentInfo( $input );
				return;
			case 'writeTypeRef':
				$encoder->writeTypeRef( $input );
				return;
			case 'writeLen':
				$encoder->writeLen( $input );
				return;
			case 'writeAny':
				$encoder->writeAny( $input );
				return;
			case 'writeBuf':
				$encoder->writeBuf( $input );
				return;
			case 'writeJSON':
				$encoder->writeJSON( $input );
				return;
			case 'writeKey':
				$encoder->writeKey( $input );
				return;
			case 'writeDsClock':
				$encoder->writeDsClock( $input );
				return;
			case 'writeDsLen':
				$encoder->writeDsLen( $input );
				return;
		}
		self::fail( 'Unknown update codec writer ' . $method );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @param string  $method  Writer method.
	 * @return mixed
	 */
	private function readCase( Decoder $decoder, string $method ) {
		if ( 'writeID' === $method ) {
			return readID( $decoder );
		}

		$updateDecoder = new UpdateDecoderV2( $decoder );
		switch ( $method ) {
			case 'writeLeftID':
				return $updateDecoder->readLeftID();
			case 'writeRightID':
				return $updateDecoder->readRightID();
			case 'writeClient':
				return $updateDecoder->readClient();
			case 'writeInfo':
				return $updateDecoder->readInfo();
			case 'writeString':
				return $updateDecoder->readString();
			case 'writeParentInfo':
				return $updateDecoder->readParentInfo();
			case 'writeTypeRef':
				return $updateDecoder->readTypeRef();
			case 'writeLen':
				return $updateDecoder->readLen();
			case 'writeAny':
				return $updateDecoder->readAny();
			case 'writeBuf':
				return $updateDecoder->readBuf();
			case 'writeJSON':
				return $updateDecoder->readJSON();
			case 'writeKey':
				return $updateDecoder->readKey();
			case 'writeDsClock':
				return $updateDecoder->readDsClock();
			case 'writeDsLen':
				return $updateDecoder->readDsLen();
		}
		self::fail( 'Unknown update codec reader ' . $method );
	}

	/**
	 * @param array<string,mixed> $desc Descriptor.
	 * @return mixed
	 */
	private function materialize( array $desc ) {
		switch ( $desc['type'] ) {
			case 'id':
				return \Yjs\createID( $desc['client'], $desc['clock'] );
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
		if ( $value instanceof ID ) {
			return array(
				'type'   => 'id',
				'client' => $value->client,
				'clock'  => $value->clock,
			);
		}
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
		if ( is_float( $value ) && 0.0 === $value && 0 !== ( ord( pack( 'E', $value )[0] ) & 0x80 ) ) {
			return array(
				'type'  => 'number',
				'value' => '-0',
			);
		}
		return array(
			'type'  => 'number',
			'value' => $value,
		);
	}
}
