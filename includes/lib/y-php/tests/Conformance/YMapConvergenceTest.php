<?php
/**
 * YMap fixture-backed convergence tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Types\YArray;
use Yjs\Types\YMap;
use Yjs\Utils\Doc;

/**
 * Replays deterministic JS YMap operation logs and compares final bytes.
 */
final class YMapConvergenceTest extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function convergenceProvider(): array {
		$fixture = $this->fixture( 'ymap-convergence.json' );
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
	public function testYMapConvergenceMatchesJsBytes( array $case ): void {
		$docs = array();
		for ( $i = 0; $i < $case['users']; $i++ ) {
			$doc           = new Doc( array( 'guid' => 'php-ymap-' . $case['seed'] . '-' . $i ) );
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
			self::assertSame(
				$case['json'][ $index ],
				$this->normalizeJsonValue( $doc->getMap( 'map' )->toJSON() ),
				$case['name'] . ' doc ' . $index . ' json'
			);
			self::assertSame(
				$case['stateVectorHexes'][ $index ],
				\Yjs\encodeStateVector( $doc )->toHexString(),
				$case['name'] . ' doc ' . $index . ' state vector'
			);
			self::assertSame(
				$case['updateHexes'][ $index ],
				\Yjs\encodeStateAsUpdate( $doc )->toHexString(),
				$case['name'] . ' doc ' . $index . ' update'
			);
			self::assertSame(
				$case['updateV2Hexes'][ $index ],
				\Yjs\encodeStateAsUpdateV2( $doc )->toHexString(),
				$case['name'] . ' doc ' . $index . ' update V2'
			);
		}
	}

	/**
	 * @param array<int,Doc>      $docs      Documents.
	 * @param array<string,mixed> $operation Operation descriptor.
	 * @return void
	 */
	private function applyOperation( array $docs, array $operation ): void {
		$map = $docs[ $operation['user'] ]->getMap( 'map' );
		switch ( $operation['op'] ) {
			case 'setString':
				$map->set( (string) $operation['key'], $operation['value'] );
				break;
			case 'setArray':
				$map->set( (string) $operation['key'], new YArray() );
				$map->get( (string) $operation['key'] )->insert( 0, $operation['content'] );
				break;
			case 'setMap':
				$map->set( (string) $operation['key'], new YMap() );
				$child = $map->get( (string) $operation['key'] );
				foreach ( $operation['entries'] as $entry ) {
					$child->set( (string) $entry[0], $entry[1] );
				}
				break;
			case 'delete':
				$map->delete( (string) $operation['key'] );
				break;
			default:
				self::fail( 'Unknown YMap fixture operation: ' . $operation['op'] );
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
