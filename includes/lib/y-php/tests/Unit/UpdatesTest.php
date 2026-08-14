<?php
/**
 * Update utility tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Lib0\Buffer;
use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Utils\Doc;

use function Yjs\Tests\Support\compare;
use function Yjs\Tests\Support\init;

/**
 * Translated coverage for yjs/tests/updates.tests.js.
 */
final class UpdatesTest extends TranslatedTestCase {
	public function testMergeUpdates(): void {
		$setup  = init( $this, array( 'users' => 3 ) );
		$array0 = $setup['array0'];
		$array1 = $setup['array1'];
		$array0->insert( 0, array( 1 ) );
		$array1->insert( 0, array( 2 ) );
		compare( $setup['users'] );

		$updates = array_map(
			static fn ( Doc $doc ): Buffer => \Yjs\encodeStateAsUpdate( $doc ),
			$setup['users']
		);
		$merged  = new Doc();
		\Yjs\applyUpdate( $merged, \Yjs\mergeUpdates( $updates ) );

		self::assertSame( $array0->toArray(), $merged->getArray( 'array' )->toArray() );
	}

	public function testKeyEncoding(): void {
		$doc0  = new Doc();
		$doc1  = new Doc();
		$text0 = $doc0->getText( 'text' );
		$text1 = $doc1->getText( 'text' );
		$text0->insert( 0, 'a', array( 'italic' => true ) );
		$text0->insert( 0, 'b' );
		$text0->insert( 0, 'c', array( 'italic' => true ) );

		\Yjs\applyUpdate( $doc1, \Yjs\encodeStateAsUpdate( $doc0 ) );

		self::assertSame(
			array(
				array(
					'insert'     => 'c',
					'attributes' => array( 'italic' => true ),
				),
				array( 'insert' => 'b' ),
				array(
					'insert'     => 'a',
					'attributes' => array( 'italic' => true ),
				),
			),
			$text1->toDelta()
		);
	}

	public function testMergeUpdates1(): void {
		$doc     = new Doc( array( 'gc' => false ) );
		$updates = array();
		$doc->on(
			'update',
			static function ( Buffer $update ) use ( &$updates ): void {
				$updates[] = $update;
			}
		);
		$array = $doc->getArray();
		$array->insert( 0, array( 1 ) );
		$array->insert( 0, array( 2 ) );
		$array->insert( 0, array( 3 ) );
		$array->insert( 0, array( 4 ) );

		$this->assertMergedUpdateCases( $doc, $updates );
	}

	public function testMergeUpdates2(): void {
		$doc     = new Doc( array( 'gc' => false ) );
		$updates = array();
		$doc->on(
			'update',
			static function ( Buffer $update ) use ( &$updates ): void {
				$updates[] = $update;
			}
		);
		$array = $doc->getArray();
		$array->insert( 0, array( 1, 2 ) );
		$array->delete( 1, 1 );
		$array->insert( 0, array( 3, 4 ) );
		$array->delete( 1, 2 );

		$this->assertMergedUpdateCases( $doc, $updates );
	}

	public function testMergePendingUpdates(): void {
		$yDoc          = new Doc();
		$serverUpdates = array();
		$yDoc->on(
			'update',
			static function ( Buffer $update ) use ( &$serverUpdates ): void {
				$serverUpdates[] = $update;
			}
		);
		$yText = $yDoc->getText( 'textBlock' );
		foreach ( array( 'r', 'o', 'n', 'e', 'n' ) as $char ) {
			$yText->applyDelta( array( array( 'insert' => $char ) ) );
		}

		$yDoc1 = new Doc();
		\Yjs\applyUpdate( $yDoc1, $serverUpdates[0] );
		$update1 = \Yjs\encodeStateAsUpdate( $yDoc1 );

		$yDoc2 = new Doc();
		\Yjs\applyUpdate( $yDoc2, $update1 );
		\Yjs\applyUpdate( $yDoc2, $serverUpdates[1] );
		$update2 = \Yjs\encodeStateAsUpdate( $yDoc2 );

		$yDoc3 = new Doc();
		\Yjs\applyUpdate( $yDoc3, $update2 );
		\Yjs\applyUpdate( $yDoc3, $serverUpdates[3] );
		$update3 = \Yjs\encodeStateAsUpdate( $yDoc3 );

		$yDoc4 = new Doc();
		\Yjs\applyUpdate( $yDoc4, $update3 );
		\Yjs\applyUpdate( $yDoc4, $serverUpdates[2] );
		$update4 = \Yjs\encodeStateAsUpdate( $yDoc4 );

		$yDoc5 = new Doc();
		\Yjs\applyUpdate( $yDoc5, $update4 );
		\Yjs\applyUpdate( $yDoc5, $serverUpdates[4] );

		$yText5 = $yDoc5->getText( 'textBlock' );
		self::assertSame( 'nenor', $yText5->toString() );
	}

	/**
	 * Not translated from upstream. Regression coverage for a y-php crash: applying an
	 * update whose causal dependencies are absent threw a TypeError inside
	 * integrateStructs() instead of parking the structs as pending like JS Yjs does.
	 */
	public function testOutOfOrderUpdateIsParkedAsPendingAndRecovered(): void {
		$d1           = new Doc();
		$d1->clientID = 42;
		$text         = $d1->getText( 't' );
		$sv0          = \Yjs\encodeStateVector( $d1 );
		$text->insert( 0, 'abc' );
		$u1  = \Yjs\encodeStateAsUpdateV2( $d1, $sv0 );
		$sv1 = \Yjs\encodeStateVector( $d1 );
		$text->insert( 3, 'def' );
		$u2 = \Yjs\encodeStateAsUpdateV2( $d1, $sv1 );

		$d2 = new Doc();
		\Yjs\applyUpdateV2( $d2, $u2 );

		self::assertSame( '', $d2->getText( 't' )->toString() );
		self::assertNotNull( $d2->store->pendingStructs );
		self::assertSame( array( 42 => 2 ), $d2->store->pendingStructs['missing'] );

		// Encoding a doc with pending structs must not drop them.
		$d3 = new Doc();
		\Yjs\applyUpdateV2( $d3, \Yjs\encodeStateAsUpdateV2( $d2 ) );
		self::assertNotNull( $d3->store->pendingStructs );

		// Filling the causal gap integrates the parked structs.
		\Yjs\applyUpdateV2( $d2, $u1 );
		self::assertSame( 'abcdef', $d2->getText( 't' )->toString() );
		self::assertNull( $d2->store->pendingStructs );

		\Yjs\applyUpdateV2( $d3, $u1 );
		self::assertSame( 'abcdef', $d3->getText( 't' )->toString() );
		self::assertNull( $d3->store->pendingStructs );
	}

	/**
	 * Not translated from upstream. A delete set that references structs the receiver
	 * has not seen yet must be parked as pendingDs and re-applied once the structs
	 * arrive, mirroring JS readUpdateV2().
	 */
	public function testPendingDeleteSetIsParkedAndReapplied(): void {
		$d1           = new Doc();
		$d1->clientID = 42;
		$text         = $d1->getText( 't' );
		$sv0          = \Yjs\encodeStateVector( $d1 );
		$text->insert( 0, 'abc' );
		$u1  = \Yjs\encodeStateAsUpdateV2( $d1, $sv0 );
		$sv1 = \Yjs\encodeStateVector( $d1 );
		$text->delete( 0, 1 );
		$u2 = \Yjs\encodeStateAsUpdateV2( $d1, $sv1 );

		$d2 = new Doc();
		\Yjs\applyUpdateV2( $d2, $u2 );

		self::assertSame( '', $d2->getText( 't' )->toString() );
		self::assertNotNull( $d2->store->pendingDs );

		// Encoding a doc with a pending delete set must not drop it.
		$d3 = new Doc();
		\Yjs\applyUpdateV2( $d3, \Yjs\encodeStateAsUpdateV2( $d2 ) );
		self::assertNotNull( $d3->store->pendingDs );

		\Yjs\applyUpdateV2( $d2, $u1 );
		self::assertSame( 'bc', $d2->getText( 't' )->toString() );
		self::assertNull( $d2->store->pendingDs );

		\Yjs\applyUpdateV2( $d3, $u1 );
		self::assertSame( 'bc', $d3->getText( 't' )->toString() );
		self::assertNull( $d3->store->pendingDs );
	}

	public function testObfuscateUpdates(): void {
		$doc = new Doc();
		$doc->getText( 'text' )->insert( 0, 'secret', array( 'bold' => true ) );
		$update     = \Yjs\encodeStateAsUpdate( $doc );
		$obfuscated = \Yjs\obfuscateUpdate( $update );
		$restored   = new Doc();
		\Yjs\applyUpdate( $restored, $obfuscated );

		self::assertNotSame( $update->toHexString(), $obfuscated->toHexString() );
		self::assertSame( \Yjs\encodeStateVector( $doc )->toHexString(), \Yjs\encodeStateVector( $restored )->toHexString() );
		self::assertSame( '111111', $restored->getText( 'text' )->toString() );
	}

	/**
	 * @param Doc               $targetDoc Target document.
	 * @param array<int,Buffer> $updates   Updates.
	 * @return void
	 */
	private function assertMergedUpdateCases( Doc $targetDoc, array $updates ): void {
		$cases = array(
			\Yjs\mergeUpdates( $updates ),
			\Yjs\mergeUpdates(
				array(
					\Yjs\mergeUpdates( array_slice( $updates, 2 ) ),
					\Yjs\mergeUpdates( array_slice( $updates, 0, 2 ) ),
				)
			),
			\Yjs\mergeUpdates(
				array(
					\Yjs\mergeUpdates( array_slice( $updates, 2 ) ),
					\Yjs\mergeUpdates( array_slice( $updates, 1, 2 ) ),
					$updates[0],
				)
			),
		);

		foreach ( $cases as $mergedUpdates ) {
			$merged = new Doc( array( 'gc' => false ) );
			\Yjs\applyUpdate( $merged, $mergedUpdates );
			self::assertSame( $targetDoc->getArray()->toArray(), $merged->getArray()->toArray() );
			self::assertSame( \Yjs\encodeStateVector( $merged )->toHexString(), \Yjs\encodeStateVectorFromUpdate( $mergedUpdates )->toHexString() );
			$meta = \Yjs\parseUpdateMeta( $mergedUpdates );
			foreach ( $meta['from'] as $clock ) {
				self::assertSame( 0, $clock );
			}
		}
	}
}
