<?php
/**
 * JS update round-trip conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Buffer;
use Yjs\Utils\Doc;

/**
 * Verifies that PHP can ingest JS updates and re-emit identical bytes.
 */
final class UpdateRoundTripTest extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function scenarioProvider(): array {
		$fixture = $this->fixture( 'yjs-scenarios.json' );
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
	public function testJsUpdateFixturesRoundTripByteIdentically( array $scenario ): void {
		$doc = new Doc();
		\Yjs\applyUpdate( $doc, Buffer::fromHexString( $scenario['updateHex'] ) );

		self::assertSame( $scenario['stateVectorHex'], \Yjs\encodeStateVector( $doc )->toHexString(), $scenario['name'] . ' state vector' );
		self::assertSame( $scenario['updateHex'], \Yjs\encodeStateAsUpdate( $doc )->toHexString(), $scenario['name'] . ' update' );
		self::assertSame( $scenario['updateV2Hex'], \Yjs\encodeStateAsUpdateV2( $doc )->toHexString(), $scenario['name'] . ' update V2' );

		$v2Doc = new Doc();
		\Yjs\applyUpdateV2( $v2Doc, Buffer::fromHexString( $scenario['updateV2Hex'] ) );
		self::assertSame( $scenario['stateVectorHex'], \Yjs\encodeStateVector( $v2Doc )->toHexString(), $scenario['name'] . ' V2 state vector' );
		self::assertSame( $scenario['updateHex'], \Yjs\encodeStateAsUpdate( $v2Doc )->toHexString(), $scenario['name'] . ' V2 to V1 update' );
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
