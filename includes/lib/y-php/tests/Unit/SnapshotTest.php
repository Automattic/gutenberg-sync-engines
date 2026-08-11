<?php
/**
 * Snapshot tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Lib0\Buffer;
use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Types\YArray;
use Yjs\Types\YMap;
use Yjs\Types\YXmlElement;
use Yjs\Utils\Doc;

use function Yjs\Tests\Support\init;

/**
 * Translated coverage for yjs/tests/snapshot.tests.js.
 */
final class SnapshotTest extends TranslatedTestCase {
	public function testBasic(): void {
		$ydoc = new Doc( array( 'gc' => false ) );
		$ydoc->getText()->insert( 0, 'world!' );
		$snapshot = \Yjs\snapshot( $ydoc );
		$ydoc->getText()->insert( 0, 'hello ' );

		$restored = \Yjs\createDocFromSnapshot( $ydoc, $snapshot );

		self::assertSame( 'world!', $restored->getText()->toString() );
	}

	public function testBasicXmlAttributes(): void {
		$ydoc = new Doc( array( 'gc' => false ) );
		$yxml = new YXmlElement( 'div' );
		$ydoc->getMap()->set( 'el', $yxml );
		$snapshot1 = \Yjs\snapshot( $ydoc );
		$yxml->setAttribute( 'a', '1' );
		$snapshot2 = \Yjs\snapshot( $ydoc );
		$yxml->setAttribute( 'a', '2' );

		self::assertSame( array( 'a' => '2' ), $yxml->getAttributes() );
		self::assertSame( array( 'a' => '1' ), $yxml->getAttributes( $snapshot2 ) );
		self::assertSame( array(), $yxml->getAttributes( $snapshot1 ) );
	}

	public function testBasicRestoreSnapshot(): void {
		$doc = new Doc( array( 'gc' => false ) );
		$doc->getArray( 'array' )->insert( 0, array( 'hello' ) );
		$snap = \Yjs\snapshot( $doc );
		$doc->getArray( 'array' )->insert( 1, array( 'world' ) );

		$docRestored = \Yjs\createDocFromSnapshot( $doc, $snap );

		self::assertSame( array( 'hello' ), $docRestored->getArray( 'array' )->toArray() );
		self::assertSame( array( 'hello', 'world' ), $doc->getArray( 'array' )->toArray() );
	}

	public function testEmptyRestoreSnapshot(): void {
		$doc            = new Doc( array( 'gc' => false ) );
		$snap           = \Yjs\snapshot( $doc );
		$snap->sv[9999] = 0;
		$doc->getArray()->insert( 0, array( 'world' ) );

		$docRestored  = \Yjs\createDocFromSnapshot( $doc, $snap );
		$snap2        = \Yjs\snapshot( $doc );
		$docRestored2 = \Yjs\createDocFromSnapshot( $doc, $snap2 );

		self::assertSame( array(), $docRestored->getArray()->toArray() );
		self::assertSame( array( 'world' ), $docRestored2->getArray()->toArray() );
	}

	public function testRestoreSnapshotWithSubType(): void {
		$doc = new Doc( array( 'gc' => false ) );
		$doc->getArray( 'array' )->insert( 0, array( new YMap() ) );
		$subMap = $doc->getArray( 'array' )->get( 0 );
		$subMap->set( 'key1', 'value1' );
		$snap = \Yjs\snapshot( $doc );
		$subMap->set( 'key2', 'value2' );

		$docRestored = \Yjs\createDocFromSnapshot( $doc, $snap );

		self::assertEquals( array( (object) array( 'key1' => 'value1' ) ), $docRestored->getArray( 'array' )->toJSON() );
		self::assertEquals(
			array(
				(object) array(
					'key1' => 'value1',
					'key2' => 'value2',
				),
			),
			$doc->getArray( 'array' )->toJSON()
		);
	}

	public function testRestoreDeletedItem1(): void {
		$doc = new Doc( array( 'gc' => false ) );
		$doc->getArray( 'array' )->insert( 0, array( 'item1', 'item2' ) );
		$snap = \Yjs\snapshot( $doc );
		$doc->getArray( 'array' )->delete( 0 );

		$docRestored = \Yjs\createDocFromSnapshot( $doc, $snap );

		self::assertSame( array( 'item1', 'item2' ), $docRestored->getArray( 'array' )->toArray() );
		self::assertSame( array( 'item2' ), $doc->getArray( 'array' )->toArray() );
	}

	public function testRestoreLeftItem(): void {
		$doc = new Doc( array( 'gc' => false ) );
		$doc->getArray( 'array' )->insert( 0, array( 'item1' ) );
		$doc->getMap( 'map' )->set( 'test', 1 );
		$doc->getArray( 'array' )->insert( 0, array( 'item0' ) );
		$snap = \Yjs\snapshot( $doc );
		$doc->getArray( 'array' )->delete( 1 );

		$docRestored = \Yjs\createDocFromSnapshot( $doc, $snap );

		self::assertSame( array( 'item0', 'item1' ), $docRestored->getArray( 'array' )->toArray() );
		self::assertSame( array( 'item0' ), $doc->getArray( 'array' )->toArray() );
	}

	public function testDeletedItemsBase(): void {
		$doc = new Doc( array( 'gc' => false ) );
		$doc->getArray( 'array' )->insert( 0, array( 'item1' ) );
		$doc->getArray( 'array' )->delete( 0 );
		$snap = \Yjs\snapshot( $doc );
		$doc->getArray( 'array' )->insert( 0, array( 'item0' ) );

		$docRestored = \Yjs\createDocFromSnapshot( $doc, $snap );

		self::assertSame( array(), $docRestored->getArray( 'array' )->toArray() );
		self::assertSame( array( 'item0' ), $doc->getArray( 'array' )->toArray() );
	}

	public function testDeletedItems2(): void {
		$doc = new Doc( array( 'gc' => false ) );
		$doc->getArray( 'array' )->insert( 0, array( 'item1', 'item2', 'item3' ) );
		$doc->getArray( 'array' )->delete( 1 );
		$snap = \Yjs\snapshot( $doc );
		$doc->getArray( 'array' )->insert( 0, array( 'item0' ) );

		$docRestored = \Yjs\createDocFromSnapshot( $doc, $snap );

		self::assertSame( array( 'item1', 'item3' ), $docRestored->getArray( 'array' )->toArray() );
		self::assertSame( array( 'item0', 'item1', 'item3' ), $doc->getArray( 'array' )->toArray() );
	}

	public function testDependentChanges(): void {
		$setup           = init( $this, array( 'users' => 2 ) );
		$array0          = $setup['array0'];
		$array1          = $setup['array1'];
		$array0->doc->gc = false;
		$array1->doc->gc = false;
		$array0->insert( 0, array( 'user1item1' ) );
		$setup['testConnector']->syncAll();
		$array1->insert( 1, array( 'user2item1' ) );
		$setup['testConnector']->syncAll();
		$snap = \Yjs\snapshot( $array0->doc );
		$array0->insert( 2, array( 'user1item2' ) );
		$setup['testConnector']->syncAll();
		$array1->insert( 3, array( 'user2item2' ) );
		$setup['testConnector']->syncAll();

		$docRestored0 = \Yjs\createDocFromSnapshot( $array0->doc, $snap );
		$docRestored1 = \Yjs\createDocFromSnapshot( $array1->doc, $snap );

		self::assertSame( array( 'user1item1', 'user2item1' ), $docRestored0->getArray( 'array' )->toArray() );
		self::assertSame( array( 'user1item1', 'user2item1' ), $docRestored1->getArray( 'array' )->toArray() );
	}

	public function testContainsUpdate(): void {
		$ydoc    = new Doc();
		$updates = array();
		$ydoc->on(
			'update',
			static function ( Buffer $update ) use ( &$updates ): void {
				$updates[] = $update;
			}
		);
		$yarr      = $ydoc->getArray();
		$snapshot1 = \Yjs\snapshot( $ydoc );
		$yarr->insert( 0, array( 1 ) );
		$snapshot2 = \Yjs\snapshot( $ydoc );
		$yarr->delete( 0, 1 );
		$snapshotFinal = \Yjs\snapshot( $ydoc );

		self::assertFalse( \Yjs\snapshotContainsUpdate( $snapshot1, $updates[0] ) );
		self::assertFalse( \Yjs\snapshotContainsUpdate( $snapshot2, $updates[1] ) );
		self::assertTrue( \Yjs\snapshotContainsUpdate( $snapshot2, $updates[0] ) );
		self::assertTrue( \Yjs\snapshotContainsUpdate( $snapshotFinal, $updates[0] ) );
		self::assertTrue( \Yjs\snapshotContainsUpdate( $snapshotFinal, $updates[1] ) );
	}

	public function testContainsUpdate2(): void {
		$local  = new Doc();
		$remote = new Doc();
		$local->getText( 't' )->insert( 0, 'abcdefghij' );
		$local->getText( 't' )->delete( 0, 3 );
		\Yjs\applyUpdate( $remote, \Yjs\encodeStateAsUpdate( $local ) );
		$snap = \Yjs\snapshot( $remote );
		$local->getText( 't' )->delete( 0, 3 );

		self::assertFalse( \Yjs\snapshotContainsUpdate( $snap, \Yjs\encodeStateAsUpdate( $local ) ) );
	}
}
