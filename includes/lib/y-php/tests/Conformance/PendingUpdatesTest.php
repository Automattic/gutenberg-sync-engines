<?php
/**
 * JS pending update conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Buffer;
use Yjs\Utils\Doc;

/**
 * Verifies out-of-order update handling against real Yjs bytes.
 *
 * Updates delivered before their causal dependencies must be parked on
 * store->pendingStructs / store->pendingDs (byte-identical to JS), survive
 * re-encoding via encodeStateAsUpdate(V2), and integrate once the gap fills.
 */
final class PendingUpdatesTest extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function structsCaseProvider(): array {
		$fixture = $this->fixture( 'pending-updates.json' );
		return array_map(
			static fn ( array $case ): array => array( $case ),
			$fixture['structsCases']
		);
	}

	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function deleteSetCaseProvider(): array {
		$fixture = $this->fixture( 'pending-updates.json' );
		return array_map(
			static fn ( array $case ): array => array( $case ),
			$fixture['deleteSetCases']
		);
	}

	/**
	 * @dataProvider structsCaseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testOutOfOrderStructsMatchJsBytes( array $case ): void {
		$codec = $case['codec'];
		$u1    = Buffer::fromHexString( $case['u1Hex'] );
		$u2    = Buffer::fromHexString( $case['u2Hex'] );

		$doc = new Doc();
		$this->applyUpdateForCodec( $doc, $u2, $codec );

		self::assertNotNull( $doc->store->pendingStructs, $case['name'] . ' pending structs parked' );
		self::assertSame( $case['pendingUpdateHex'], $doc->store->pendingStructs['update']->toHexString(), $case['name'] . ' pending update bytes' );
		self::assertSame( $case['pendingMissing'], $this->mapToPairs( $doc->store->pendingStructs['missing'] ), $case['name'] . ' pending missing map' );
		self::assertSame( $case['encodedWithPendingHex'], $this->encodeStateForCodec( $doc, $codec )->toHexString(), $case['name'] . ' encoded with pending' );

		$this->applyUpdateForCodec( $doc, $u1, $codec );

		self::assertSame( $case['finalText'], $doc->getText( 't' )->toString(), $case['name'] . ' final text' );
		self::assertNull( $doc->store->pendingStructs, $case['name'] . ' pending structs cleared' );
		self::assertSame( $case['finalUpdateHex'], $this->encodeStateForCodec( $doc, $codec )->toHexString(), $case['name'] . ' final update bytes' );
	}

	/**
	 * @dataProvider deleteSetCaseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testPendingDeleteSetMatchesJsBytes( array $case ): void {
		$codec = $case['codec'];
		$u1    = Buffer::fromHexString( $case['u1Hex'] );
		$u2    = Buffer::fromHexString( $case['u2Hex'] );

		$doc = new Doc();
		$this->applyUpdateForCodec( $doc, $u2, $codec );

		self::assertNotNull( $doc->store->pendingDs, $case['name'] . ' pending delete set parked' );
		self::assertSame( $case['pendingDsHex'], $doc->store->pendingDs->toHexString(), $case['name'] . ' pending delete set bytes' );
		self::assertSame( $case['encodedWithPendingHex'], $this->encodeStateForCodec( $doc, $codec )->toHexString(), $case['name'] . ' encoded with pending' );

		$this->applyUpdateForCodec( $doc, $u1, $codec );

		self::assertSame( $case['finalText'], $doc->getText( 't' )->toString(), $case['name'] . ' final text' );
		self::assertNull( $doc->store->pendingDs, $case['name'] . ' pending delete set cleared' );
		self::assertSame( $case['finalUpdateHex'], $this->encodeStateForCodec( $doc, $codec )->toHexString(), $case['name'] . ' final update bytes' );
	}

	/**
	 * @param Doc    $doc    Document.
	 * @param Buffer $update Update bytes.
	 * @param string $codec  Codec family, v1 or v2.
	 * @return void
	 */
	private function applyUpdateForCodec( Doc $doc, Buffer $update, string $codec ): void {
		if ( 'v2' === $codec ) {
			\Yjs\applyUpdateV2( $doc, $update );
		} else {
			\Yjs\applyUpdate( $doc, $update );
		}
	}

	/**
	 * @param Doc    $doc   Document.
	 * @param string $codec Codec family, v1 or v2.
	 * @return Buffer
	 */
	private function encodeStateForCodec( Doc $doc, string $codec ): Buffer {
		if ( 'v2' === $codec ) {
			return \Yjs\encodeStateAsUpdateV2( $doc );
		}

		return \Yjs\encodeStateAsUpdate( $doc );
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
