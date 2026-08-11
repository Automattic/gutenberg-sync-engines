<?php
/**
 * JS V2 update utility conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Buffer;
use Yjs\Utils\Doc;

/**
 * Verifies V2 merge/diff/conversion helpers against real Yjs bytes.
 */
final class UpdateUtilitiesV2Test extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function caseProvider(): array {
		$fixture = $this->fixture( 'update-utilities-v2.json' );
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
	public function testV2UpdateUtilitiesMatchJsBytes( array $case ): void {
		$updates = array_map(
			static fn ( string $hex ): Buffer => Buffer::fromHexString( $hex ),
			$case['updatesV2Hex']
		);
		$merged  = \Yjs\mergeUpdatesV2( $updates );
		$diff    = \Yjs\diffUpdateV2( $merged, Buffer::fromHexString( $case['firstStateVectorV2Hex'] ) );
		$meta    = \Yjs\parseUpdateMetaV2( $merged );
		$metaOut = array(
			'from' => $this->mapToPairs( $meta['from'] ),
			'to'   => $this->mapToPairs( $meta['to'] ),
		);

		self::assertSame( $case['mergedV2Hex'], $merged->toHexString(), $case['name'] . ' merged update' );
		self::assertSame( $case['stateVectorFromMergedV2Hex'], \Yjs\encodeStateVectorFromUpdateV2( $merged )->toHexString(), $case['name'] . ' state vector from update' );
		self::assertSame( $case['diffFromFirstV2Hex'], $diff->toHexString(), $case['name'] . ' diff from first state' );
		self::assertSame( $case['parseMergedMetaV2'], $metaOut, $case['name'] . ' parsed metadata' );

		$doc = new Doc( array( 'gc' => false ) );
		\Yjs\applyUpdateV2( $doc, $merged );
		self::assertSame( $case['finalStateVectorHex'], \Yjs\encodeStateVector( $doc )->toHexString(), $case['name'] . ' final state vector' );
		self::assertSame( $case['finalUpdateV2Hex'], \Yjs\encodeStateAsUpdateV2( $doc )->toHexString(), $case['name'] . ' final V2 update' );
		self::assertSame( $case['snapshotV2Hex'], \Yjs\encodeSnapshotV2( \Yjs\snapshot( $doc ) )->toHexString(), $case['name'] . ' snapshot V2' );
	}

	/**
	 * @dataProvider caseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testV1V2ConversionAndObfuscationMatchJsBytes( array $case ): void {
		$v1 = Buffer::fromHexString( $case['finalUpdateV1Hex'] );
		$v2 = Buffer::fromHexString( $case['finalUpdateV2Hex'] );

		$convertedV2 = \Yjs\convertUpdateFormatV1ToV2( $v1 );
		$convertedV1 = \Yjs\convertUpdateFormatV2ToV1( $v2 );

		self::assertSame( $case['convertedFinalV1ToV2Hex'], $convertedV2->toHexString(), $case['name'] . ' V1 to V2' );
		self::assertSame( $case['convertedFinalV2ToV1Hex'], $convertedV1->toHexString(), $case['name'] . ' V2 to V1' );
		self::assertSame( $case['finalUpdateV1Hex'], \Yjs\convertUpdateFormatV2ToV1( $convertedV2 )->toHexString(), $case['name'] . ' V1 roundtrip' );
		self::assertSame( $case['finalUpdateV2Hex'], \Yjs\convertUpdateFormatV1ToV2( $convertedV1 )->toHexString(), $case['name'] . ' V2 roundtrip' );

		$obfuscated = \Yjs\obfuscateUpdateV2( $v2 );
		self::assertSame( $case['obfuscatedFinalV2Hex'], $obfuscated->toHexString(), $case['name'] . ' obfuscate V2' );

		$originalDoc = new Doc( array( 'gc' => false ) );
		$maskedDoc   = new Doc( array( 'gc' => false ) );
		\Yjs\applyUpdateV2( $originalDoc, $v2 );
		\Yjs\applyUpdateV2( $maskedDoc, $obfuscated );
		self::assertSame( \Yjs\encodeStateVector( $originalDoc )->toHexString(), \Yjs\encodeStateVector( $maskedDoc )->toHexString(), $case['name'] . ' obfuscated state vector' );
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
