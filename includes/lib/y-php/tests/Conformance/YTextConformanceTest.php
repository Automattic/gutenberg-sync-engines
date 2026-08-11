<?php
/**
 * YText fixture-backed conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Buffer;
use Yjs\Utils\Doc;

/**
 * Replays deterministic JS YText fixtures and compares final bytes.
 */
final class YTextConformanceTest extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function scenarioProvider(): array {
		$fixture = $this->fixture( 'ytext-scenarios.json' );
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
	public function testYTextFixturesRoundTripByteIdentically( array $scenario ): void {
		$doc = new Doc();
		\Yjs\applyUpdate( $doc, Buffer::fromHexString( $scenario['updateHex'] ) );
		$text = $doc->getText( 'text' );

		self::assertSame( $scenario['string'], $text->toString(), $scenario['name'] . ' string' );
		self::assertSame( $this->normalizeJsonValue( $scenario['delta'] ), $this->normalizeJsonValue( $text->toDelta() ), $scenario['name'] . ' delta' );
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
		$fixture = $this->fixture( 'ytext-convergence.json' );
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
	public function testYTextConvergenceMatchesJsBytes( array $case ): void {
		$docs = array();
		for ( $i = 0; $i < $case['users']; $i++ ) {
			$doc           = new Doc( array( 'guid' => 'php-ytext-' . $case['seed'] . '-' . $i ) );
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
			$text = $doc->getText( 'text' );
			self::assertSame( $case['strings'][ $index ], $text->toString(), $case['name'] . ' doc ' . $index . ' string' );
			self::assertSame( $this->normalizeJsonValue( $case['deltas'][ $index ] ), $this->normalizeJsonValue( $text->toDelta() ), $case['name'] . ' doc ' . $index . ' delta' );
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
		$text = $docs[ $operation['user'] ]->getText( 'text' );
		switch ( $operation['op'] ) {
			case 'insertText':
				$text->insert( $operation['pos'], $operation['value'] );
				break;
			case 'insertEmbed':
				$text->insertEmbed( $operation['pos'], $operation['embed'], $operation['attrs'] );
				break;
			case 'format':
				$text->format( $operation['pos'], $operation['len'], $operation['attrs'] );
				break;
			case 'delete':
				$text->delete( $operation['pos'], $operation['len'] );
				break;
			case 'applyDelta':
				$text->applyDelta( $operation['delta'] );
				break;
			default:
				self::fail( 'Unknown YText fixture operation: ' . $operation['op'] );
		}
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
