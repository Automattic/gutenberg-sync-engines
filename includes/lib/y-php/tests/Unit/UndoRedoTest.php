<?php
/**
 * Undo/redo tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Types\YArray;
use Yjs\Types\YMap;
use Yjs\Types\YXmlElement;
use Yjs\Types\YXmlText;
use Yjs\Utils\Doc;
use Yjs\Utils\UndoManager;

use function Yjs\Tests\Support\init;

/**
 * Translated coverage for yjs/tests/undo-redo.tests.js.
 */
final class UndoRedoTest extends TranslatedTestCase {
	public function testInconsistentFormat(): void {
		$doc     = new Doc( array( 'gc' => false ) );
		$content = $doc->get( 'text', YXmlText::class );
		$content->insert(
			0,
			' After',
			array(
				'type'   => 'text',
				'italic' => true,
			)
		);
		$content->insert( 0, 'Test', array( 'type' => 'text' ) );
		$content->insert(
			0,
			'Merge ',
			array(
				'type' => 'text',
				'bold' => true,
			)
		);
		$content->format( 0, 6, array( 'bold' => null ) );
		$content->format( 6, 4, array( 'type' => 'text' ) );

		self::assertSame(
			array(
				array(
					'insert'     => 'Merge Test',
					'attributes' => array( 'type' => 'text' ),
				),
				array(
					'insert'     => ' After',
					'attributes' => array(
						'type'   => 'text',
						'italic' => true,
					),
				),
			),
			$content->toDelta()
		);
	}

	public function testInfiniteCaptureTimeout(): void {
		$setup       = init( $this, array( 'users' => 3 ) );
		$array0      = $setup['array0'];
		$undoManager = new UndoManager( $array0, array( 'captureTimeout' => PHP_FLOAT_MAX ) );
		$array0->push( array( 1, 2, 3 ) );
		$undoManager->stopCapturing();
		$array0->push( array( 4, 5, 6 ) );
		$undoManager->undo();

		self::assertSame( array( 1, 2, 3 ), $array0->toArray() );
	}

	public function testUndoText(): void {
		$setup       = init( $this, array( 'users' => 2 ) );
		$text0       = $setup['text0'];
		$text1       = $setup['text1'];
		$undoManager = new UndoManager( $text0 );
		$text0->insert( 0, 'abc' );
		$text1->insert( 0, 'xyz' );
		$setup['testConnector']->syncAll();
		$undoManager->undo();
		self::assertSame( 'xyz', $text0->toString() );
		$undoManager->redo();
		self::assertSame( 'abcxyz', $text0->toString() );
	}

	public function testEmptyTypeScope(): void {
		$ydoc   = new Doc();
		$um     = new UndoManager( array(), array( 'doc' => $ydoc ) );
		$yarray = $ydoc->getArray();
		$um->addToScope( $yarray );
		$yarray->insert( 0, array( 1 ) );
		$um->undo();

		self::assertSame( 0, $yarray->length );
	}

	public function testRejectUpdateExample(): void {
		$tmpydoc1 = new Doc();
		$tmpydoc1->getArray( 'restricted' )->insert( 0, array( 1 ) );
		$tmpydoc1->getArray( 'public' )->insert( 0, array( 1 ) );
		$update1  = \Yjs\encodeStateAsUpdate( $tmpydoc1 );
		$tmpydoc2 = new Doc();
		$tmpydoc2->getArray( 'public' )->insert( 0, array( 2 ) );
		$update2        = \Yjs\encodeStateAsUpdate( $tmpydoc2 );
		$ydoc           = new Doc();
		$restrictedType = $ydoc->getArray( 'restricted' );

		$handler = function ( $update ) use ( $ydoc, $restrictedType ): void {
			$um = new UndoManager( $restrictedType, array( 'trackedOrigins' => array( 'remote change' ) ) );
			try {
				\Yjs\applyUpdate( $ydoc, $update, 'remote change' );
			} finally {
				while ( $um->canUndo() ) {
					$um->undo();
				}
				$um->destroy();
			}
		};
		$handler( $update1 );
		$handler( $update2 );

		self::assertSame( 0, $restrictedType->length );
		self::assertSame( 2, $ydoc->getArray( 'public' )->length );
	}

	public function testGlobalScope(): void {
		$ydoc   = new Doc();
		$um     = new UndoManager( $ydoc );
		$yarray = $ydoc->getArray();
		$yarray->insert( 0, array( 1 ) );
		$um->undo();

		self::assertSame( 0, $yarray->length );
	}

	public function testDoubleUndo(): void {
		$doc  = new Doc();
		$text = $doc->getText();
		$text->insert( 0, '1221' );
		$manager = new UndoManager( $text );
		$text->insert( 2, '3' );
		$text->insert( 3, '3' );
		$manager->undo();
		$manager->undo();
		$text->insert( 2, '3' );

		self::assertSame( '12321', $text->toString() );
	}

	public function testUndoMap(): void {
		$setup = init( $this, array( 'users' => 2 ) );
		$map0  = $setup['map0'];
		$map1  = $setup['map1'];
		$map0->set( 'a', 0 );
		$undoManager = new UndoManager( $map0 );
		$map0->set( 'a', 1 );
		$undoManager->undo();
		self::assertSame( 0, $map0->get( 'a' ) );
		$undoManager->redo();
		self::assertSame( 1, $map0->get( 'a' ) );
		$setup['testConnector']->syncAll();
		$map1->set( 'a', 44 );
		$setup['testConnector']->syncAll();
		$undoManager->undo();
		self::assertSame( 44, $map0->get( 'a' ) );
	}

	public function testUndoArray(): void {
		$setup       = init( $this, array( 'users' => 3 ) );
		$array0      = $setup['array0'];
		$array1      = $setup['array1'];
		$undoManager = new UndoManager( $array0 );
		$array0->insert( 0, array( 1, 2, 3 ) );
		$array1->insert( 0, array( 4, 5, 6 ) );
		$setup['testConnector']->syncAll();
		$undoManager->undo();
		self::assertSame( array( 4, 5, 6 ), $array0->toArray() );
		$undoManager->redo();
		self::assertSame( array( 1, 2, 3, 4, 5, 6 ), $array0->toArray() );
	}

	public function testUndoXml(): void {
		$xml0        = init( $this, array( 'users' => 1 ) )['xml0'];
		$undoManager = new UndoManager( $xml0 );
		$child       = new YXmlElement( 'p' );
		$xml0->insert( 0, array( $child ) );
		$textchild = new YXmlText( 'content' );
		$child->insert( 0, array( $textchild ) );
		$undoManager->undo();

		self::assertSame( '<undefined></undefined>', $xml0->toString() );
		$undoManager->redo();
		self::assertSame( '<undefined><p>content</p></undefined>', $xml0->toString() );
	}

	public function testUndoEvents(): void {
		$doc     = new Doc();
		$text    = $doc->getText();
		$manager = new UndoManager( $text );
		$events  = array();
		$manager->on(
			'stack-item-added',
			static function ( array $event ) use ( &$events ): void {
				$events[] = $event['type'];
			}
		);
		$manager->on(
			'stack-item-popped',
			static function ( array $event ) use ( &$events ): void {
				$events[] = $event['type'];
			}
		);
		$text->insert( 0, 'a' );
		$manager->undo();

		self::assertSame( array( 'undo', 'redo', 'undo' ), $events );
	}

	public function testTrackClass(): void {
		$doc     = new Doc();
		$text    = $doc->getText();
		$origin  = new class() {};
		$manager = new UndoManager( $text, array( 'trackedOrigins' => array( get_class( $origin ) ) ) );
		$doc->transact(
			static function () use ( $text ): void {
				$text->insert( 0, 'a' );
			},
			$origin
		);

		self::assertTrue( $manager->canUndo() );
	}

	public function testTypeScope(): void {
		$doc     = new Doc();
		$array1  = $doc->getArray( 'one' );
		$array2  = $doc->getArray( 'two' );
		$manager = new UndoManager( $array1 );
		$array1->insert( 0, array( 1 ) );
		$array2->insert( 0, array( 2 ) );
		$manager->undo();

		self::assertSame( array(), $array1->toArray() );
		self::assertSame( array( 2 ), $array2->toArray() );
	}

	public function testUndoInEmbed(): void {
		$doc  = new Doc();
		$text = $doc->getText();
		$map  = new YMap();
		$text->insertEmbed( 0, $map );
		$manager = new UndoManager( $text );
		$map->set( 'a', 1 );
		$manager->undo();

		$delta = $text->toDelta();
		self::assertCount( 1, $delta );
		self::assertInstanceOf( YMap::class, $delta[0]['insert'] );
		self::assertEquals( new \stdClass(), $delta[0]['insert']->toJSON() );
	}

	public function testUndoDeleteFilter(): void {
		$doc     = new Doc();
		$array   = $doc->getArray();
		$manager = new UndoManager(
			$array,
			array(
				'deleteFilter' => static function ( $item ): bool {
					unset( $item );
					return false;
				},
			)
		);
		$array->insert( 0, array( 'kept' ) );
		$manager->undo();

		self::assertSame( array( 'kept' ), $array->toArray() );
	}

	public function testUndoUntilChangePerformed(): void {
		$doc = new Doc();
		$map = $doc->getMap();
		$map->set( 'a', 1 );
		$manager = new UndoManager( $map );
		$map->set( 'a', 2 );
		$manager->stopCapturing();
		$map->set( 'a', 3 );
		$manager->undo();
		self::assertSame( 2, $map->get( 'a' ) );
		$manager->undo();
		self::assertSame( 1, $map->get( 'a' ) );
	}

	public function testUndoNestedUndoIssue(): void {
		$doc     = new Doc();
		$text    = $doc->getText();
		$manager = new UndoManager( $text );
		$text->insert( 0, 'a' );
		$manager->stopCapturing();
		$text->insert( 1, 'b' );
		$manager->on(
			'stack-item-popped',
			static function () use ( $manager ): void {
				if ( $manager->canUndo() ) {
					$manager->undo();
				}
			}
		);
		$manager->undo();

		self::assertSame( '', $text->toString() );
	}

	public function testConsecutiveRedoBug(): void {
		$doc     = new Doc();
		$text    = $doc->getText();
		$manager = new UndoManager( $text );
		$text->insert( 0, 'a' );
		$manager->undo();
		$manager->redo();
		$manager->undo();
		$manager->redo();

		self::assertSame( 'a', $text->toString() );
	}

	public function testUndoXmlBug(): void {
		$xml     = ( new Doc() )->get( 'xml', YXmlElement::class );
		$manager = new UndoManager( $xml );
		$xml->insert( 0, array( new YXmlText( 'x' ) ) );
		$manager->undo();

		self::assertSame( '<undefined></undefined>', $xml->toString() );
	}

	public function testUndoBlockBug(): void {
		$doc     = new Doc();
		$array   = $doc->getArray();
		$manager = new UndoManager( $array );
		$array->insert( 0, array( new YArray() ) );
		$manager->stopCapturing();
		$array->get( 0 )->push( array( 'nested' ) );
		$manager->undo();

		self::assertSame( array( array() ), $array->toJSON() );
	}

	public function testUndoDeleteTextFormat(): void {
		$doc  = new Doc();
		$text = $doc->getText();
		$text->insert( 0, 'abc', array( 'bold' => true ) );
		$manager = new UndoManager( $text );
		$text->delete( 1, 1 );
		$manager->undo();

		self::assertSame( 'abc', $text->toString() );
		self::assertSame(
			array(
				array(
					'insert'     => 'abc',
					'attributes' => array( 'bold' => true ),
				),
			),
			$text->toDelta()
		);
	}

	public function testBehaviorOfIgnoreremotemapchangesProperty(): void {
		$setup   = init( $this, array( 'users' => 2 ) );
		$map0    = $setup['map0'];
		$map1    = $setup['map1'];
		$manager = new UndoManager( $map0, array( 'ignoreRemoteMapChanges' => true ) );
		$map0->set( 'a', 1 );
		$setup['testConnector']->syncAll();
		$map1->set( 'a', 2 );
		$setup['testConnector']->syncAll();
		$manager->undo();

		self::assertSame( 2, $map0->get( 'a' ) );
	}

	public function testSpecialDeletionCase(): void {
		$doc   = new Doc();
		$array = $doc->getArray();
		$array->insert( 0, array( 'a', 'b', 'c' ) );
		$manager = new UndoManager( $array );
		$array->delete( 1, 1 );
		$array->delete( 1, 1 );
		$manager->undo();

		self::assertSame( array( 'a', 'b', 'c' ), $array->toArray() );
	}

	public function testUndoDeleteInMap(): void {
		$doc = new Doc();
		$map = $doc->getMap();
		$map->set( 'a', 1 );
		$manager = new UndoManager( $map );
		$map->delete( 'a' );
		$manager->undo();

		self::assertSame( 1, $map->get( 'a' ) );
	}

	public function testUndoDoingStackItem(): void {
		$doc     = new Doc();
		$text    = $doc->getText();
		$manager = new UndoManager( $text );
		$text->insert( 0, 'a' );
		$item = $manager->undo();

		self::assertNotNull( $item );
		self::assertArrayHasKey( 0, $manager->redoStack );
	}

	public function testUndoSetAttributeAndDeleteSyncsAttributes(): void {
		$xml   = ( new Doc() )->get( 'xml', YXmlElement::class );
		$child = new YXmlElement( 'p' );
		$xml->insert( 0, array( $child ) );
		$manager = new UndoManager( $xml );
		$child->setAttribute( 'a', '1' );
		$manager->stopCapturing();
		$xml->delete( 0, 1 );
		$manager->undo();

		self::assertSame( '<undefined><p a="1"></p></undefined>', $xml->toString() );
	}
}
