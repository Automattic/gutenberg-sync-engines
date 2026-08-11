<?php
/**
 * YXml fixture-backed conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Buffer;
use Yjs\Types\YXmlElement;
use Yjs\Types\YXmlFragment;
use Yjs\Types\YXmlHook;
use Yjs\Types\YXmlText;
use Yjs\Utils\Doc;

/**
 * Replays deterministic JS YXml fixtures and compares final bytes.
 */
final class YXmlConformanceTest extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function scenarioProvider(): array {
		$fixture = $this->fixture( 'yxml-scenarios.json' );
		return array_map(
			static fn ( array $scenario ): array => array( $scenario ),
			$fixture['scenarios']
		);
	}

	/**
	 * @dataProvider scenarioProvider
	 *
	 * @param array<string,mixed> $scenario Scenario fixture.
	 * @return void
	 */
	public function testYXmlFixturesRoundTripByteIdentically( array $scenario ): void {
		$doc = new Doc();
		\Yjs\applyUpdate( $doc, Buffer::fromHexString( $scenario['updateHex'] ) );
		$root = $doc->get( $scenario['rootName'], $this->rootClass( $scenario['rootType'] ) );

		self::assertSame( $this->normalizeJsonValue( $scenario['descriptor'] ), $this->normalizeJsonValue( $this->xmlDescriptor( $root ) ), $scenario['name'] . ' descriptor' );
		self::assertSame( $scenario['stateVectorHex'], \Yjs\encodeStateVector( $doc )->toHexString(), $scenario['name'] . ' state vector' );
		self::assertSame( $scenario['updateHex'], \Yjs\encodeStateAsUpdate( $doc )->toHexString(), $scenario['name'] . ' update' );
		self::assertSame( $scenario['updateV2Hex'], \Yjs\encodeStateAsUpdateV2( $doc )->toHexString(), $scenario['name'] . ' update V2' );
		self::assertSame( $scenario['snapshotHex'], \Yjs\encodeSnapshot( \Yjs\snapshot( $doc ) )->toHexString(), $scenario['name'] . ' snapshot' );
		self::assertSame( $scenario['snapshotV2Hex'], \Yjs\encodeSnapshotV2( \Yjs\snapshot( $doc ) )->toHexString(), $scenario['name'] . ' snapshot V2' );
	}

	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function convergenceProvider(): array {
		$fixture = $this->fixture( 'yxml-convergence.json' );
		return array_map(
			static fn ( array $case ): array => array( $case ),
			$fixture['cases']
		);
	}

	/**
	 * @dataProvider convergenceProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testYXmlConvergenceMatchesJsBytes( array $case ): void {
		$docs = array();
		for ( $i = 0; $i < $case['users']; $i++ ) {
			$doc           = new Doc( array( 'guid' => 'php-yxml-' . $case['seed'] . '-' . $i ) );
			$doc->clientID = $i + 1;
			$docs[]        = $doc;
		}

		foreach ( $case['operations'] as $operation ) {
			$this->applyOperation( $docs, $operation );
		}

		$localUpdates = array_map(
			static fn ( Doc $doc ) => \Yjs\encodeStateAsUpdate( $doc ),
			$docs
		);
		foreach ( $docs as $docIndex => $doc ) {
			foreach ( $localUpdates as $updateIndex => $update ) {
				if ( $docIndex !== $updateIndex ) {
					\Yjs\applyUpdate( $doc, $update );
				}
			}
		}

		foreach ( $docs as $index => $doc ) {
			$xml = $doc->get( 'xml', YXmlElement::class );
			self::assertSame( $case['strings'][ $index ], $xml->toString(), $case['name'] . ' doc ' . $index . ' string' );
			self::assertSame( $this->normalizeJsonValue( $case['descriptors'][ $index ] ), $this->normalizeJsonValue( $this->xmlDescriptor( $xml ) ), $case['name'] . ' doc ' . $index . ' descriptor' );
			self::assertSame( $case['stateVectorHexes'][ $index ], \Yjs\encodeStateVector( $doc )->toHexString(), $case['name'] . ' doc ' . $index . ' state vector' );
			self::assertSame( $case['updateHexes'][ $index ], \Yjs\encodeStateAsUpdate( $doc )->toHexString(), $case['name'] . ' doc ' . $index . ' update' );
			self::assertSame( $case['updateV2Hexes'][ $index ], \Yjs\encodeStateAsUpdateV2( $doc )->toHexString(), $case['name'] . ' doc ' . $index . ' update V2' );
		}
	}

	/**
	 * @param array<int,Doc>      $docs      Documents.
	 * @param array<string,mixed> $operation Operation descriptor.
	 * @return void
	 */
	private function applyOperation( array $docs, array $operation ): void {
		$xml = $docs[ $operation['user'] ]->get( 'xml', YXmlElement::class );
		switch ( $operation['op'] ) {
			case 'setAttribute':
				$xml->setAttribute( $operation['key'], $operation['value'] );
				break;
			case 'removeAttribute':
				$xml->removeAttribute( $operation['key'] );
				break;
			case 'insertText':
				$xml->insert( $operation['pos'], array( new YXmlText( $operation['value'] ) ) );
				break;
			case 'insertElement':
				$element = new YXmlElement( $operation['nodeName'] );
				foreach ( $operation['attrs'] as $entry ) {
					$element->setAttribute( $entry[0], $entry[1] );
				}
				if ( null !== $operation['text'] ) {
					$element->insert( 0, array( new YXmlText( $operation['text'] ) ) );
				}
				$xml->insert( $operation['pos'], array( $element ) );
				break;
			case 'insertHook':
				$hook = new YXmlHook( $operation['hookName'] );
				foreach ( $operation['entries'] as $entry ) {
					$hook->set( $entry[0], $entry[1] );
				}
				$xml->insert( $operation['pos'], array( $hook ) );
				break;
			case 'delete':
				$xml->delete( $operation['pos'], $operation['len'] );
				break;
			case 'formatTextChild':
				$child = $xml->get( $operation['childIndex'] );
				if ( $child instanceof YXmlText ) {
					$child->format( 0, $operation['len'], $this->materializeFormattingAttributes( $operation['attrs'] ) );
				}
				break;
			case 'noop':
				break;
			default:
				self::fail( 'Unknown YXml fixture operation: ' . $operation['op'] );
		}
	}

	/**
	 * @param mixed $type XML type.
	 * @return array<string,mixed>
	 */
	private function xmlDescriptor( $type ): array {
		if ( $type instanceof YXmlText ) {
			return array(
				'type'       => 'YXmlText',
				'string'     => $type->toString(),
				'delta'      => $type->toDelta(),
				'attributes' => $type->getAttributes(),
			);
		}
		if ( $type instanceof YXmlHook ) {
			return array(
				'type'     => 'YXmlHook',
				'hookName' => $type->hookName,
				'json'     => $type->toJSON(),
				'string'   => $type->toString(),
			);
		}
		if ( $type instanceof YXmlElement ) {
			return array(
				'type'       => 'YXmlElement',
				'nodeName'   => $type->nodeName,
				'attributes' => $type->getAttributes(),
				'string'     => $type->toString(),
				'children'   => array_map(
					function ( $child ): array {
						return $this->xmlDescriptor( $child );
					},
					$type->toArray()
				),
			);
		}
		if ( $type instanceof YXmlFragment ) {
			return array(
				'type'     => 'YXmlFragment',
				'string'   => $type->toString(),
				'children' => array_map(
					function ( $child ): array {
						return $this->xmlDescriptor( $child );
					},
					$type->toArray()
				),
			);
		}
		self::fail( 'Unable to describe XML type.' );
	}

	private function rootClass( string $type ): string {
		switch ( $type ) {
			case 'YXmlElement':
				return YXmlElement::class;
			case 'YXmlFragment':
				return YXmlFragment::class;
			case 'YXmlText':
				return YXmlText::class;
			default:
				self::fail( 'Unknown YXml root type: ' . $type );
		}
	}

	/**
	 * @param array<string,mixed> $attrs Formatting attrs from JSON fixtures.
	 * @return array<string,mixed>
	 */
	private function materializeFormattingAttributes( array $attrs ): array {
		$result = array();
		foreach ( $attrs as $key => $value ) {
			$result[ $key ] = $this->materializeEmptyObjects( $value );
		}
		return $result;
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function materializeEmptyObjects( $value ) {
		if ( is_array( $value ) ) {
			if ( array() === $value ) {
				return new \stdClass();
			}
			$result = array();
			foreach ( $value as $key => $item ) {
				$result[ $key ] = $this->materializeEmptyObjects( $item );
			}
			return $result;
		}
		return $value;
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function normalizeJsonValue( $value ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return json_decode( json_encode( $value ), true );
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
}
