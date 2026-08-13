<?php
/**
 * Fixture-backed tests for updates that reference garbage-collected structs.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Types\YMap;
use Yjs\Types\YText;
use Yjs\Utils\Doc;

/**
 * A client edits inside a nested subtree while the server concurrently
 * deletes (and garbage-collects) that subtree. Applying the client's update
 * makes `Item::getMissing` resolve origin / rightOrigin / parent references
 * onto GC structs (or a ContentDeleted parent); the incoming items must be
 * discarded as GCs — not crash — and the resulting bytes must match JS.
 *
 * Replays the deterministic scenarios behind `gc-resolution.json` and
 * compares every encoding against the JS-generated fixture.
 */
final class GcResolutionTest extends TestCase {
	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function caseProvider(): array {
		$fixture = $this->fixture( 'gc-resolution.json' );
		$cases   = array();
		foreach ( $fixture['cases'] as $case ) {
			$cases[ $case['name'] ] = array( $case );
		}
		return $cases;
	}

	/**
	 * @dataProvider caseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testGcResolutionMatchesJsBytesV1( array $case ): void {
		$this->runScenario( $case, false );
	}

	/**
	 * @dataProvider caseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testGcResolutionMatchesJsBytesV2( array $case ): void {
		$this->runScenario( $case, true );
	}

	/**
	 * @param array<string,mixed> $case Fixture case.
	 * @param bool                $useV2 Apply the client update through the V2 codec.
	 * @return void
	 */
	private function runScenario( array $case, bool $useV2 ): void {
		$name   = $case['name'];
		$server = $this->buildServerDoc( $name );

		$client           = new Doc( array( 'guid' => 'y-php-gc-' . $name . '-client' ) );
		$client->clientID = 2;
		\Yjs\applyUpdate( $client, \Yjs\encodeStateAsUpdate( $server ) );
		$baseline = \Yjs\encodeStateVector( $client );

		$this->applyEdit( $name, $client );
		$clientUpdate   = \Yjs\encodeStateAsUpdate( $client, $baseline );
		$clientUpdateV2 = \Yjs\encodeStateAsUpdateV2( $client, $baseline );

		self::assertSame( $case['clientUpdateHex'], $clientUpdate->toHexString(), $name . ' client update' );
		self::assertSame( $case['clientUpdateV2Hex'], $clientUpdateV2->toHexString(), $name . ' client update V2' );

		// The server concurrently deletes the block; gc replaces the subtree
		// with GC structs (and the block item's content with ContentDeleted).
		$server->getArray( 'blocks' )->delete( 0 );

		if ( $useV2 ) {
			\Yjs\applyUpdateV2( $server, $clientUpdateV2 );
		} else {
			\Yjs\applyUpdate( $server, $clientUpdate );
		}

		self::assertSame( $case['json'], $this->normalizeJsonValue( $server->getArray( 'blocks' )->toJSON() ), $name . ' json' );
		self::assertSame( $case['stateVectorHex'], \Yjs\encodeStateVector( $server )->toHexString(), $name . ' state vector' );
		self::assertSame( $case['updateHex'], \Yjs\encodeStateAsUpdate( $server )->toHexString(), $name . ' update' );
		self::assertSame( $case['updateV2Hex'], \Yjs\encodeStateAsUpdateV2( $server )->toHexString(), $name . ' update V2' );
	}

	/**
	 * Root array "blocks" -> [ YMap block { meta: YMap, text: YText "hello" } ].
	 *
	 * @param string $name Case name (guid suffix).
	 * @return Doc
	 */
	private function buildServerDoc( string $name ): Doc {
		$server           = new Doc( array( 'guid' => 'y-php-gc-' . $name . '-server' ) );
		$server->clientID = 1;

		$blocks = $server->getArray( 'blocks' );
		$block  = new YMap();
		$blocks->insert( 0, array( $block ) );

		$meta = new YMap();
		$block->set( 'meta', $meta );

		$text = new YText();
		$block->set( 'text', $text );
		$text->insert( 0, 'hello' );

		return $server;
	}

	/**
	 * @param string $name   Case name.
	 * @param Doc    $client Client doc.
	 * @return void
	 */
	private function applyEdit( string $name, Doc $client ): void {
		$block = $client->getArray( 'blocks' )->get( 0 );
		switch ( $name ) {
			case 'parent-content-deleted':
				$block->set( 'newKey', 'v' );
				break;
			case 'parent-gc':
				$block->get( 'meta' )->set( 'k', 'v' );
				break;
			case 'origin-gc':
				$block->get( 'text' )->insert( 5, '!' );
				break;
			case 'rightorigin-gc':
				$block->get( 'text' )->insert( 0, '>' );
				break;
			default:
				self::fail( 'Unknown gc-resolution fixture case: ' . $name );
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
