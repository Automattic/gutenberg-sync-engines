<?php
/**
 * Translated encoding.tests.js tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Lib0\Buffer;
use Yjs\Structs\ContentAny;
use Yjs\Structs\ContentBinary;
use Yjs\Structs\ContentDeleted;
use Yjs\Structs\ContentDoc;
use Yjs\Structs\ContentEmbed;
use Yjs\Structs\ContentFormat;
use Yjs\Structs\ContentJSON;
use Yjs\Structs\ContentString;
use Yjs\Structs\ContentType;
use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Utils\Doc;
use Yjs\Utils\PermanentUserData;

/**
 * Translated test slots from yjs/tests/encoding.tests.js.
 */
final class EncodingTest extends TranslatedTestCase {
	/**
	 * Source: yjs/tests/encoding.tests.js::testStructReferences
	 *
	 * @return void
	 */
	public function testStructReferences(): void {
		$decoder = new class() {
			public function readLen(): int {
				return 1;
			}

			public function readString(): string {
				return 'null';
			}

			public function readBuf(): Buffer {
				return Buffer::fromByteArray( array( 1 ) );
			}

			public function readJSON() {
				return null;
			}

			public function readKey(): string {
				return 'key';
			}

			public function readTypeRef(): int {
				return 0;
			}

			public function readAny() {
				return null;
			}
		};

		self::assertInstanceOf( ContentDeleted::class, \Yjs\readItemContent( $decoder, 1 ) );
		self::assertInstanceOf( ContentJSON::class, \Yjs\readItemContent( $decoder, 2 ) );
		self::assertInstanceOf( ContentBinary::class, \Yjs\readItemContent( $decoder, 3 ) );
		self::assertInstanceOf( ContentString::class, \Yjs\readItemContent( $decoder, 4 ) );
		self::assertInstanceOf( ContentEmbed::class, \Yjs\readItemContent( $decoder, 5 ) );
		self::assertInstanceOf( ContentFormat::class, \Yjs\readItemContent( $decoder, 6 ) );
		self::assertInstanceOf( ContentType::class, \Yjs\readItemContent( $decoder, 7 ) );
		self::assertInstanceOf( ContentAny::class, \Yjs\readItemContent( $decoder, 8 ) );
		self::assertInstanceOf( ContentDoc::class, \Yjs\readItemContent( $decoder, 9 ) );
	}

	/**
	 * Source: yjs/tests/encoding.tests.js::testPermanentUserData
	 *
	 * @return void
	 */
	public function testPermanentUserData(): void {
		$ydoc1 = new Doc();
		$ydoc2 = new Doc();
		$pd1   = new PermanentUserData( $ydoc1 );
		$pd2   = new PermanentUserData( $ydoc2 );
		$pd1->setUserMapping( $ydoc1, $ydoc1->clientID, 'user a' );
		$pd2->setUserMapping( $ydoc2, $ydoc2->clientID, 'user b' );
		$ydoc1TextClock = \Yjs\getState( $ydoc1->store, $ydoc1->clientID );
		$ydoc2TextClock = \Yjs\getState( $ydoc2->store, $ydoc2->clientID );

		$ydoc1->getText()->insert( 0, 'xhi' );
		$ydoc1->getText()->delete( 0, 1 );
		$ydoc2->getText()->insert( 0, 'hxxi' );
		$ydoc2->getText()->delete( 1, 2 );

		\Yjs\applyUpdate( $ydoc2, \Yjs\encodeStateAsUpdate( $ydoc1 ) );
		\Yjs\applyUpdate( $ydoc1, \Yjs\encodeStateAsUpdate( $ydoc2 ) );

		self::assertSame( 'user a', $pd2->getUserByClientId( $ydoc1->clientID ) );
		self::assertSame( 'user b', $pd1->getUserByClientId( $ydoc2->clientID ) );
		self::assertSame( 'user a', $pd2->getUserByDeletedId( \Yjs\createID( $ydoc1->clientID, $ydoc1TextClock ) ) );
		self::assertSame( 'user b', $pd1->getUserByDeletedId( \Yjs\createID( $ydoc2->clientID, $ydoc2TextClock + 1 ) ) );

		$ydoc3 = new Doc();
		\Yjs\applyUpdate( $ydoc3, \Yjs\encodeStateAsUpdate( $ydoc1 ) );
		$pd3 = new PermanentUserData( $ydoc3 );
		$pd3->setUserMapping( $ydoc3, $ydoc3->clientID, 'user a' );

		self::assertSame( 'user a', $pd3->getUserByClientId( $ydoc1->clientID ) );
		self::assertSame( 'user b', $pd3->getUserByClientId( $ydoc2->clientID ) );
		self::assertSame( 'user a', $pd3->getUserByDeletedId( \Yjs\createID( $ydoc1->clientID, $ydoc1TextClock ) ) );
		self::assertSame( 'user b', $pd3->getUserByDeletedId( \Yjs\createID( $ydoc2->clientID, $ydoc2TextClock + 1 ) ) );
	}

	/**
	 * Source: yjs/tests/encoding.tests.js::testDiffStateVectorOfUpdateIsEmpty
	 *
	 * @return void
	 */
	public function testDiffStateVectorOfUpdateIsEmpty(): void {
		$ydoc = new Doc();
		$sv   = null;
		$ydoc->getText()->insert( 0, 'a' );
		$ydoc->on(
			'update',
			static function ( Buffer $update ) use ( &$sv ): void {
				$sv = \Yjs\encodeStateVectorFromUpdate( $update );
			}
		);
		$ydoc->getText()->insert( 0, 'a' );

		self::assertInstanceOf( Buffer::class, $sv );
		self::assertSame( 1, $sv->byteLength() );
		self::assertSame( 0, $sv->get( 0 ) );
	}

	/**
	 * Source: yjs/tests/encoding.tests.js::testDiffStateVectorOfUpdateIgnoresSkips
	 *
	 * @return void
	 */
	public function testDiffStateVectorOfUpdateIgnoresSkips(): void {
		$ydoc    = new Doc();
		$updates = array();
		$ydoc->on(
			'update',
			static function ( Buffer $update ) use ( &$updates ): void {
				$updates[] = $update;
			}
		);
		$ydoc->getText()->insert( 0, 'a' );
		$ydoc->getText()->insert( 0, 'b' );
		$ydoc->getText()->insert( 0, 'c' );
		$update13 = \Yjs\mergeUpdates( array( $updates[0], $updates[2] ) );
		$state    = \Yjs\decodeStateVector( \Yjs\encodeStateVectorFromUpdate( $update13 ) );

		self::assertSame( 1, $state[ $ydoc->clientID ] ?? null );
		self::assertCount( 1, $state );
	}
}
