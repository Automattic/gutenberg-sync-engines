<?php
/**
 * JS update utility conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Buffer;
use Yjs\Utils\Doc;

/**
 * Verifies V1 merge/diff/state-vector helpers against real Yjs bytes.
 */
final class UpdateUtilitiesV1Test extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function caseProvider(): array {
		$fixture = $this->fixture( 'update-utilities-v1.json' );
		return array_map(
			static fn ( array $case ): array => array( $case ),
			$fixture['cases']
		);
	}

	/**
	 * @dataProvider caseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testUpdateUtilitiesMatchJsBytes( array $case ): void {
		$updates = array_map(
			static fn ( string $hex ): Buffer => Buffer::fromHexString( $hex ),
			$case['updatesHex']
		);
		$merged  = \Yjs\mergeUpdates( $updates );
		$diff    = \Yjs\diffUpdate( $merged, Buffer::fromHexString( $case['firstStateVectorHex'] ) );
		$meta    = \Yjs\parseUpdateMeta( $merged );
		$metaOut = array(
			'from' => $this->mapToPairs( $meta['from'] ),
			'to'   => $this->mapToPairs( $meta['to'] ),
		);

		self::assertSame( $case['mergedHex'], $merged->toHexString(), $case['name'] . ' merged update' );
		self::assertSame( $case['stateVectorFromMergedHex'], \Yjs\encodeStateVectorFromUpdate( $merged )->toHexString(), $case['name'] . ' state vector from update' );
		self::assertSame( $case['diffFromFirstHex'], $diff->toHexString(), $case['name'] . ' diff from first state' );
		self::assertSame( $case['parseMergedMeta'], $metaOut, $case['name'] . ' parsed metadata' );

		$doc = new Doc( array( 'gc' => false ) );
		\Yjs\applyUpdate( $doc, $merged );
		self::assertSame( $case['finalStateVectorHex'], \Yjs\encodeStateVector( $doc )->toHexString(), $case['name'] . ' final state vector' );
		self::assertSame( $case['snapshotHex'], \Yjs\encodeSnapshot( \Yjs\snapshot( $doc ) )->toHexString(), $case['name'] . ' snapshot' );
	}

	/**
	 * @param array<int,int> $map Map.
	 * @return array<int,array{0:int,1:int}>
	 */
	private function mapToPairs( array $map ): array {
		$pairs = array();
		foreach ( $map as $client => $clock ) {
			$pairs[] = array( (int) $client, $clock );
		}
		return $pairs;
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
