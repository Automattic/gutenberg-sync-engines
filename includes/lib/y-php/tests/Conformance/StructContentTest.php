<?php
/**
 * Struct/content conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\BigInt64;
use Yjs\Lib0\Buffer;
use Yjs\Lib0\Decoding;
use Yjs\Lib0\Math;
use Yjs\Lib0\UndefinedValue;
use Yjs\Structs\ContentAny;
use Yjs\Structs\ContentBinary;
use Yjs\Structs\ContentDeleted;
use Yjs\Structs\ContentDoc;
use Yjs\Structs\ContentEmbed;
use Yjs\Structs\ContentFormat;
use Yjs\Structs\ContentJSON;
use Yjs\Structs\ContentString;
use Yjs\Structs\ContentType;
use Yjs\Structs\GC;
use Yjs\Structs\Skip;
use Yjs\Types\YArray;
use Yjs\Types\YMap;
use Yjs\Types\YText;
use Yjs\Types\YXmlElement;
use Yjs\Types\YXmlFragment;
use Yjs\Types\YXmlHook;
use Yjs\Types\YXmlText;
use Yjs\Utils\Doc;
use Yjs\Utils\ID;
use Yjs\Utils\UpdateDecoderV1;
use Yjs\Utils\UpdateEncoderV1;

/**
 * Verifies struct/content byte parity with fixtures captured from real yjs.
 */
final class StructContentTest extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function contentCaseProvider(): array {
		$fixture = $this->fixture( 'struct-content.json' );
		return array_map(
			static fn ( array $case ): array => array( $case ),
			$fixture['contentCases']
		);
	}

	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function structCaseProvider(): array {
		$fixture = $this->fixture( 'struct-content.json' );
		return array_map(
			static fn ( array $case ): array => array( $case ),
			$fixture['structCases']
		);
	}

	/**
	 * @dataProvider contentCaseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testContentWriterMatchesJsBytesAndRoundTrips( array $case ): void {
		$content = $this->materializeContent( $case['input'] );
		self::assertSame( $case['ref'], $content->getRef(), $case['name'] . ' ref' );
		self::assertSame( $case['length'], $content->getLength(), $case['name'] . ' length' );

		$encoder = new UpdateEncoderV1();
		$content->write( $encoder, $case['offset'] );
		self::assertSame( $case['hex'], $encoder->toUint8Array()->toHexString(), $case['name'] );

		$decoder = new UpdateDecoderV1( Decoding::createDecoder( Buffer::fromHexString( $case['hex'] ) ) );
		$decoded = $this->readContent( $case['input']['kind'], $decoder );

		self::assertSame( $case['decoded'], $this->normalizeContent( $decoded ), $case['name'] . ' decoded' );
		self::assertFalse( Decoding::hasContent( $decoder->restDecoder ), $case['name'] . ' consumes all bytes' );
	}

	/**
	 * @dataProvider structCaseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testGcAndSkipWritersMatchJsBytes( array $case ): void {
		$struct  = $this->materializeStruct( $case );
		$encoder = new UpdateEncoderV1();
		$struct->write( $encoder, $case['offset'] );

		self::assertSame( $case['hex'], $encoder->toUint8Array()->toHexString(), $case['name'] );
	}

	/**
	 * @return void
	 */
	public function testContentStringUsesUtf16OffsetsAndReplacementOnSplit(): void {
		$content = new ContentString( 'a😊b' );
		self::assertSame( 4, $content->getLength() );

		$right = $content->splice( 2 );
		self::assertSame( 'a�', $content->str );
		self::assertSame( '�b', $right->str );
		self::assertSame( 2, $content->getLength() );
		self::assertSame( 2, $right->getLength() );
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
	 * @param array<string,mixed> $desc Content descriptor.
	 * @return object
	 */
	private function materializeContent( array $desc ): object {
		switch ( $desc['kind'] ) {
			case 'ContentString':
				return new ContentString( $desc['str'] );
			case 'ContentAny':
				return new ContentAny( $this->materializeArray( $desc['arr'] ) );
			case 'ContentJSON':
				return new ContentJSON( $this->materializeArray( $desc['arr'] ) );
			case 'ContentBinary':
				return new ContentBinary( $this->materialize( $desc['content'] ) );
			case 'ContentEmbed':
				return new ContentEmbed( $this->materialize( $desc['embed'] ) );
			case 'ContentFormat':
				return new ContentFormat( $desc['key'], $this->materialize( $desc['value'] ) );
			case 'ContentDeleted':
				return new ContentDeleted( $desc['len'] );
			case 'ContentType':
				return new ContentType( $this->materializeType( $desc['type'] ) );
			case 'ContentDoc':
				return new ContentDoc( new Doc( $this->materializeDocOptions( $desc ) ) );
		}
		self::fail( 'Unknown content kind ' . $desc['kind'] );
	}

	/**
	 * @param array<string,mixed> $case Struct fixture case.
	 * @return GC|Skip
	 */
	private function materializeStruct( array $case ) {
		$id = $this->materialize( $case['id'] );
		if ( ! $id instanceof ID ) {
			self::fail( 'Struct fixture id did not materialize to ID.' );
		}
		if ( 'GC' === $case['kind'] ) {
			return new GC( $id, $case['length'] );
		}
		if ( 'Skip' === $case['kind'] ) {
			return new Skip( $id, $case['length'] );
		}
		self::fail( 'Unknown struct kind ' . $case['kind'] );
	}

	/**
	 * @param string          $kind    Content kind.
	 * @param UpdateDecoderV1 $decoder Decoder.
	 * @return object
	 */
	private function readContent( string $kind, UpdateDecoderV1 $decoder ): object {
		switch ( $kind ) {
			case 'ContentString':
				return \Yjs\readContentString( $decoder );
			case 'ContentAny':
				return \Yjs\readContentAny( $decoder );
			case 'ContentJSON':
				return \Yjs\readContentJSON( $decoder );
			case 'ContentBinary':
				return \Yjs\readContentBinary( $decoder );
			case 'ContentEmbed':
				return \Yjs\readContentEmbed( $decoder );
			case 'ContentFormat':
				return \Yjs\readContentFormat( $decoder );
			case 'ContentDeleted':
				return \Yjs\readContentDeleted( $decoder );
			case 'ContentType':
				return \Yjs\readContentType( $decoder );
			case 'ContentDoc':
				return \Yjs\readContentDoc( $decoder );
		}
		self::fail( 'Unknown content reader ' . $kind );
	}

	/**
	 * @param array<int,array<string,mixed>> $descs Value descriptors.
	 * @return array<int,mixed>
	 */
	private function materializeArray( array $descs ): array {
		return array_map(
			fn ( array $desc ) => $this->materialize( $desc ),
			$descs
		);
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
				return $this->materializeArray( $desc['value'] );
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
	 * @param array<string,mixed> $desc Type descriptor.
	 * @return object
	 */
	private function materializeType( array $desc ): object {
		switch ( $desc['name'] ) {
			case 'YArray':
				return new YArray();
			case 'YMap':
				return new YMap();
			case 'YText':
				return new YText();
			case 'YXmlElement':
				return new YXmlElement( $desc['nodeName'] );
			case 'YXmlFragment':
				return new YXmlFragment();
			case 'YXmlHook':
				return new YXmlHook( $desc['hookName'] );
			case 'YXmlText':
				return new YXmlText();
		}
		self::fail( 'Unknown type descriptor ' . $desc['name'] );
	}

	/**
	 * @param array<string,mixed> $desc ContentDoc descriptor.
	 * @return array<string,mixed>
	 */
	private function materializeDocOptions( array $desc ): array {
		$options = array( 'guid' => $desc['guid'] );
		foreach ( $desc['opts'] as $pair ) {
			$options[ $pair[0] ] = $this->materialize( $pair[1] );
		}
		return $options;
	}

	/**
	 * @param object $content Content.
	 * @return array<string,mixed>
	 */
	private function normalizeContent( object $content ): array {
		if ( $content instanceof ContentString ) {
			return array(
				'kind' => 'ContentString',
				'str'  => $content->str,
			);
		}
		if ( $content instanceof ContentAny ) {
			return array(
				'kind' => 'ContentAny',
				'arr'  => array_map( fn ( $item ) => $this->normalize( $item ), $content->arr ),
			);
		}
		if ( $content instanceof ContentJSON ) {
			return array(
				'kind' => 'ContentJSON',
				'arr'  => array_map( fn ( $item ) => $this->normalize( $item ), $content->arr ),
			);
		}
		if ( $content instanceof ContentBinary ) {
			return array(
				'kind'    => 'ContentBinary',
				'content' => $this->normalize( $content->content ),
			);
		}
		if ( $content instanceof ContentEmbed ) {
			return array(
				'kind'  => 'ContentEmbed',
				'embed' => $this->normalize( $content->embed ),
			);
		}
		if ( $content instanceof ContentFormat ) {
			return array(
				'kind'  => 'ContentFormat',
				'key'   => $content->key,
				'value' => $this->normalize( $content->value ),
			);
		}
		if ( $content instanceof ContentDeleted ) {
			return array(
				'kind' => 'ContentDeleted',
				'len'  => $content->len,
			);
		}
		if ( $content instanceof ContentType ) {
			return array(
				'kind' => 'ContentType',
				'type' => $this->normalizeType( $content->type ),
			);
		}
		if ( $content instanceof ContentDoc ) {
			return array(
				'kind' => 'ContentDoc',
				'guid' => $content->doc->guid,
				'opts' => $this->normalizeObjectPairs( $content->opts ),
			);
		}
		self::fail( 'Unable to normalize content.' );
	}

	/**
	 * @param object $type Type.
	 * @return array<string,mixed>
	 */
	private function normalizeType( object $type ): array {
		if ( $type instanceof YXmlElement ) {
			return array(
				'name'     => 'YXmlElement',
				'nodeName' => $type->nodeName,
			);
		}
		if ( $type instanceof YXmlHook ) {
			return array(
				'name'     => 'YXmlHook',
				'hookName' => $type->hookName,
			);
		}
		if ( $type instanceof YXmlText ) {
			return array( 'name' => 'YXmlText' );
		}
		if ( $type instanceof YXmlFragment ) {
			return array( 'name' => 'YXmlFragment' );
		}
		if ( $type instanceof YArray ) {
			return array( 'name' => 'YArray' );
		}
		if ( $type instanceof YMap ) {
			return array( 'name' => 'YMap' );
		}
		if ( $type instanceof YText ) {
			return array( 'name' => 'YText' );
		}
		self::fail( 'Unable to normalize type.' );
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
				'value' => array_map( fn ( $item ) => $this->normalize( $item ), $value ),
			);
		}
		if ( $value instanceof \stdClass ) {
			return array(
				'type'  => 'object',
				'value' => $this->normalizeObjectPairs( $value ),
			);
		}
		self::fail( 'Unable to normalize value.' );
	}

	/**
	 * @param \stdClass $object Object.
	 * @return array<int,array{0:string,1:array<string,mixed>}>
	 */
	private function normalizeObjectPairs( \stdClass $object ): array {
		$pairs = array();
		foreach ( get_object_vars( $object ) as $key => $item ) {
			$pairs[] = array( $key, $this->normalize( $item ) );
		}
		return $pairs;
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
