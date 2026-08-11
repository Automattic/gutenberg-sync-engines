<?php
/**
 * Ported y-map.tests.js tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Lib0\Prng;
use Yjs\Lib0\UndefinedValue;
use Yjs\Tests\Support\T;
use Yjs\Tests\Support\TestYInstance;
use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Types\YArray;
use Yjs\Types\YMap;
use Yjs\Types\YMapEvent;
use Yjs\Types\YText;
use Yjs\Utils\Doc;

use function Yjs\compareIDs;
use function Yjs\Tests\Support\applyRandomTests;
use function Yjs\Tests\Support\compare;
use function Yjs\Tests\Support\init;

/**
 * Ported assertions from yjs/tests/y-map.tests.js.
 */
final class YMapTest extends TranslatedTestCase {
	public function testIterators(): void {
		$doc  = new Doc();
		$ymap = $doc->getMap();
		self::assertSame( array(), $ymap->values() );
		self::assertSame( array(), $ymap->entries() );
		self::assertSame( array(), $ymap->keys() );

		$ymap->set( 'one', 1 );
		$ymap->set( 'two', 2 );
		self::assertSame( array( 1, 2 ), $ymap->values() );
		self::assertSame( array( array( 'one', 1 ), array( 'two', 2 ) ), $ymap->entries() );
		self::assertSame( array( 'one', 'two' ), $ymap->keys() );
		self::assertSame( $ymap->entries(), iterator_to_array( $ymap ) );
	}

	public function testMapEventError(): void {
		$doc   = new Doc();
		$ymap  = $doc->getMap();
		$event = null;
		$ymap->observe(
			static function ( YMapEvent $e ) use ( &$event ): void {
				$event = $e;
			}
		);
		$ymap->set( 'key', 'value' );
		self::assertInstanceOf( YMapEvent::class, $event );
		T::fails(
			static function () use ( $event ): void {
				$event->keys;
			}
		);
		T::fails(
			static function () use ( $event ): void {
				$event->keys;
			}
		);
	}

	public function testMapHavingIterableAsConstructorParamTests(): void {
		$result = init( $this, array( 'users' => 1 ) );
		$map0   = $result['map0'];

		$m1 = new YMap( array( array( 'number', 1 ), array( 'string', 'hello' ) ) );
		$map0->set( 'm1', $m1 );
		self::assertSame( 1, $m1->get( 'number' ) );
		self::assertSame( 'hello', $m1->get( 'string' ) );

		$m2 = new YMap( array( array( 'object', (object) array( 'x' => 1 ) ), array( 'boolean', true ) ) );
		$map0->set( 'm2', $m2 );
		self::assertEquals( (object) array( 'x' => 1 ), $m2->get( 'object' ) );
		self::assertTrue( $m2->get( 'boolean' ) );

		$m3 = new YMap( array_merge( $m1->entries(), $m2->entries() ) );
		$map0->set( 'm3', $m3 );
		self::assertSame( 1, $m3->get( 'number' ) );
		self::assertSame( 'hello', $m3->get( 'string' ) );
		self::assertEquals( (object) array( 'x' => 1 ), $m3->get( 'object' ) );
		self::assertTrue( $m3->get( 'boolean' ) );
	}

	public function testBasicMapTests(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$result['users'][2]->disconnect();

		$map0 = $result['map0'];
		$map0->set( 'null', null );
		$map0->set( 'number', 1 );
		$map0->set( 'string', 'hello Y' );
		$map0->set( 'object', (object) array( 'key' => (object) array( 'key2' => 'value' ) ) );
		$map0->set( 'y-map', new YMap() );
		$map0->set( 'boolean1', true );
		$map0->set( 'boolean0', false );
		$map = $map0->get( 'y-map' );
		$map->set( 'y-array', new YArray() );
		$array = $map->get( 'y-array' );
		$array->insert( 0, array( 0 ) );
		$array->insert( 0, array( -1 ) );

		$this->assertBasicMapContent( $map0 );

		$result['users'][2]->connect();
		$result['testConnector']->flushAllMessages();

		$this->assertBasicMapContent( $result['map1'] );
		$this->assertBasicMapContent( $result['map2'] );
		compare( $result['users'] );
	}

	public function testGetAndSetOfMapProperty(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$map0   = $result['map0'];
		$map0->set( 'stuff', 'stuffy' );
		$map0->set( 'undefined', UndefinedValue::getInstance() );
		$map0->set( 'null', null );
		self::assertSame( 'stuffy', $map0->get( 'stuff' ) );

		$result['testConnector']->flushAllMessages();

		foreach ( $result['users'] as $user ) {
			$u = $user->getMap( 'map' );
			self::assertSame( 'stuffy', $u->get( 'stuff' ) );
			self::assertSame( UndefinedValue::getInstance(), $u->get( 'undefined' ) );
			self::assertNull( $u->get( 'null' ) );
		}
		compare( $result['users'] );
	}

	public function testYmapSetsYmap(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$map    = $result['map0']->set( 'Map', new YMap() );
		self::assertSame( $map, $result['map0']->get( 'Map' ) );
		$map->set( 'one', 1 );
		self::assertSame( 1, $map->get( 'one' ) );
		compare( $result['users'] );
	}

	public function testYmapSetsYarray(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$array  = $result['map0']->set( 'Array', new YArray() );
		self::assertSame( $array, $result['map0']->get( 'Array' ) );
		$array->insert( 0, array( 1, 2, 3 ) );
		self::assertEquals( (object) array( 'Array' => array( 1, 2, 3 ) ), $result['map0']->toJSON() );
		compare( $result['users'] );
	}

	public function testGetAndSetOfMapPropertySyncs(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$result['map0']->set( 'stuff', 'stuffy' );
		self::assertSame( 'stuffy', $result['map0']->get( 'stuff' ) );
		$result['testConnector']->flushAllMessages();
		foreach ( $result['users'] as $user ) {
			self::assertSame( 'stuffy', $user->getMap( 'map' )->get( 'stuff' ) );
		}
		compare( $result['users'] );
	}

	public function testGetAndSetOfMapPropertyWithConflict(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$result['map0']->set( 'stuff', 'c0' );
		$result['map1']->set( 'stuff', 'c1' );
		$result['testConnector']->flushAllMessages();
		foreach ( $result['users'] as $user ) {
			self::assertSame( 'c1', $user->getMap( 'map' )->get( 'stuff' ) );
		}
		compare( $result['users'] );
	}

	public function testSizeAndDeleteOfMapProperty(): void {
		$result = init( $this, array( 'users' => 1 ) );
		$map0   = $result['map0'];
		$map0->set( 'stuff', 'c0' );
		$map0->set( 'otherstuff', 'c1' );
		self::assertSame( 2, $map0->size );
		$map0->delete( 'stuff' );
		self::assertSame( 1, $map0->size );
		$map0->delete( 'otherstuff' );
		self::assertSame( 0, $map0->size );
	}

	public function testGetAndSetAndDeleteOfMapProperty(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$result['map0']->set( 'stuff', 'c0' );
		$result['map1']->set( 'stuff', 'c1' );
		$result['map1']->delete( 'stuff' );
		$result['testConnector']->flushAllMessages();
		foreach ( $result['users'] as $user ) {
			self::assertSame( UndefinedValue::getInstance(), $user->getMap( 'map' )->get( 'stuff' ) );
		}
		compare( $result['users'] );
	}

	public function testSetAndClearOfMapProperties(): void {
		$result = init( $this, array( 'users' => 1 ) );
		$result['map0']->set( 'stuff', 'c0' );
		$result['map0']->set( 'otherstuff', 'c1' );
		$result['map0']->clear();
		$result['testConnector']->flushAllMessages();
		foreach ( $result['users'] as $user ) {
			$u = $user->getMap( 'map' );
			self::assertSame( UndefinedValue::getInstance(), $u->get( 'stuff' ) );
			self::assertSame( UndefinedValue::getInstance(), $u->get( 'otherstuff' ) );
			self::assertSame( 0, $u->size );
		}
		compare( $result['users'] );
	}

	public function testSetAndClearOfMapPropertiesWithConflicts(): void {
		$result = init( $this, array( 'users' => 4 ) );
		$result['map0']->set( 'stuff', 'c0' );
		$result['map1']->set( 'stuff', 'c1' );
		$result['map1']->set( 'stuff', 'c2' );
		$result['map2']->set( 'stuff', 'c3' );
		$result['testConnector']->flushAllMessages();
		$result['map0']->set( 'otherstuff', 'c0' );
		$result['map1']->set( 'otherstuff', 'c1' );
		$result['map2']->set( 'otherstuff', 'c2' );
		$result['map3']->set( 'otherstuff', 'c3' );
		$result['map3']->clear();
		$result['testConnector']->flushAllMessages();
		foreach ( $result['users'] as $user ) {
			$u = $user->getMap( 'map' );
			self::assertSame( UndefinedValue::getInstance(), $u->get( 'stuff' ) );
			self::assertSame( UndefinedValue::getInstance(), $u->get( 'otherstuff' ) );
			self::assertSame( 0, $u->size );
		}
		compare( $result['users'] );
	}

	public function testGetAndSetOfMapPropertyWithThreeConflicts(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$result['map0']->set( 'stuff', 'c0' );
		$result['map1']->set( 'stuff', 'c1' );
		$result['map1']->set( 'stuff', 'c2' );
		$result['map2']->set( 'stuff', 'c3' );
		$result['testConnector']->flushAllMessages();
		foreach ( $result['users'] as $user ) {
			self::assertSame( 'c3', $user->getMap( 'map' )->get( 'stuff' ) );
		}
		compare( $result['users'] );
	}

	public function testGetAndSetAndDeleteOfMapPropertyWithThreeConflicts(): void {
		$result = init( $this, array( 'users' => 4 ) );
		$result['map0']->set( 'stuff', 'c0' );
		$result['map1']->set( 'stuff', 'c1' );
		$result['map1']->set( 'stuff', 'c2' );
		$result['map2']->set( 'stuff', 'c3' );
		$result['testConnector']->flushAllMessages();
		$result['map0']->set( 'stuff', 'deleteme' );
		$result['map1']->set( 'stuff', 'c1' );
		$result['map2']->set( 'stuff', 'c2' );
		$result['map3']->set( 'stuff', 'c3' );
		$result['map3']->delete( 'stuff' );
		$result['testConnector']->flushAllMessages();
		foreach ( $result['users'] as $user ) {
			self::assertSame( UndefinedValue::getInstance(), $user->getMap( 'map' )->get( 'stuff' ) );
		}
		compare( $result['users'] );
	}

	public function testObserveDeepProperties(): void {
		$result = init( $this, array( 'users' => 4 ) );
		$map1   = $result['map1']->set( 'map', new YMap() );
		$calls  = 0;
		$dmapid = null;
		$result['map1']->observeDeep(
			static function ( array $events ) use ( &$calls, &$dmapid ): void {
				foreach ( $events as $event ) {
					++$calls;
					self::assertContains( 'deepmap', $event->keysChanged );
					self::assertCount( 1, $event->path );
					self::assertSame( 'map', $event->path[0] );
					$dmapid = $event->target->get( 'deepmap' )->_item->id;
				}
			}
		);
		$result['testConnector']->flushAllMessages();
		$map3 = $result['map3']->get( 'map' );
		$map3->set( 'deepmap', new YMap() );
		$result['testConnector']->flushAllMessages();
		$map2 = $result['map2']->get( 'map' );
		$map2->set( 'deepmap', new YMap() );
		$result['testConnector']->flushAllMessages();
		$dmap1 = $map1->get( 'deepmap' );
		$dmap2 = $map2->get( 'deepmap' );
		$dmap3 = $map3->get( 'deepmap' );
		self::assertGreaterThan( 0, $calls );
		self::assertTrue( compareIDs( $dmap1->_item->id, $dmap2->_item->id ) );
		self::assertTrue( compareIDs( $dmap1->_item->id, $dmap3->_item->id ) );
		self::assertTrue( compareIDs( $dmap1->_item->id, $dmapid ) );
		compare( $result['users'] );
	}

	public function testObserversUsingObservedeep(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$paths  = array();
		$calls  = 0;
		$result['map0']->observeDeep(
			static function ( array $events ) use ( &$paths, &$calls ): void {
				foreach ( $events as $event ) {
					$paths[] = $event->path;
				}
				++$calls;
			}
		);
		$result['map0']->set( 'map', new YMap() );
		$result['map0']->get( 'map' )->set( 'array', new YArray() );
		$result['map0']->get( 'map' )->get( 'array' )->insert( 0, array( 'content' ) );
		self::assertSame( 3, $calls );
		self::assertSame( array( array(), array( 'map' ), array( 'map', 'array' ) ), $paths );
		compare( $result['users'] );
	}

	public function testPathsOfSiblingEvents(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$paths  = array();
		$calls  = 0;
		$doc    = $result['users'][0];
		$result['map0']->set( 'map', new YMap() );
		$result['map0']->get( 'map' )->set( 'text1', new YText( 'initial' ) );
		$result['map0']->observeDeep(
			static function ( array $events ) use ( &$paths, &$calls ): void {
				foreach ( $events as $event ) {
					$paths[] = $event->path;
				}
				++$calls;
			}
		);
		$doc->transact(
			static function () use ( $result ): void {
				$result['map0']->get( 'map' )->get( 'text1' )->insert( 0, 'post-' );
				$result['map0']->get( 'map' )->set( 'text2', new YText( 'new' ) );
			}
		);
		self::assertSame( 1, $calls );
		self::assertSame( array( array( 'map' ), array( 'map', 'text1' ) ), $paths );
		compare( $result['users'] );
	}

	public function testThrowsAddAndUpdateAndDeleteEvents(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$event  = null;
		$result['map0']->observe(
			static function ( YMapEvent $e ) use ( &$event ): void {
				$event = $e;
			}
		);
		$result['map0']->set( 'stuff', 4 );
		$this->assertMapEvent( $event, $result['map0'], array( 'stuff' ) );
		$result['map0']->set( 'stuff', new YArray() );
		$this->assertMapEvent( $event, $result['map0'], array( 'stuff' ) );
		$result['map0']->set( 'stuff', 5 );
		$result['map0']->delete( 'stuff' );
		$this->assertMapEvent( $event, $result['map0'], array( 'stuff' ) );
		compare( $result['users'] );
	}

	public function testThrowsDeleteEventsOnClear(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$event  = null;
		$result['map0']->observe(
			static function ( YMapEvent $e ) use ( &$event ): void {
				$event = $e;
			}
		);
		$result['map0']->set( 'stuff', 4 );
		$result['map0']->set( 'otherstuff', new YArray() );
		$result['map0']->clear();
		$this->assertMapEvent( $event, $result['map0'], array( 'stuff', 'otherstuff' ) );
		compare( $result['users'] );
	}

	public function testChangeEvent(): void {
		$result  = init( $this, array( 'users' => 2 ) );
		$changes = null;
		$result['map0']->observe(
			static function ( YMapEvent $e ) use ( &$changes ): void {
				$changes = $e->changes;
			}
		);
		$result['map0']->set( 'a', 1 );
		$this->assertKeyChange( $changes, 'a', 'add', UndefinedValue::getInstance() );
		$result['map0']->set( 'a', 2 );
		$this->assertKeyChange( $changes, 'a', 'update', 1 );
		$result['users'][0]->transact(
			static function () use ( $result ): void {
				$result['map0']->set( 'a', 3 );
				$result['map0']->set( 'a', 4 );
			}
		);
		$this->assertKeyChange( $changes, 'a', 'update', 2 );
		$result['users'][0]->transact(
			static function () use ( $result ): void {
				$result['map0']->set( 'b', 1 );
				$result['map0']->set( 'b', 2 );
			}
		);
		$this->assertKeyChange( $changes, 'b', 'add', UndefinedValue::getInstance() );
		$result['users'][0]->transact(
			static function () use ( $result ): void {
				$result['map0']->set( 'c', 1 );
				$result['map0']->delete( 'c' );
			}
		);
		self::assertSame( array(), $changes['keys'] );
		$result['users'][0]->transact(
			static function () use ( $result ): void {
				$result['map0']->set( 'd', 1 );
				$result['map0']->set( 'd', 2 );
			}
		);
		$this->assertKeyChange( $changes, 'd', 'add', UndefinedValue::getInstance() );
		compare( $result['users'] );
	}

	public function testYmapEventExceptionsShouldCompleteTransaction(): void {
		$doc                        = new Doc();
		$map                        = $doc->getMap( 'map' );
		$updateCalled               = false;
		$throwingObserverCalled     = false;
		$throwingDeepObserverCalled = false;
		$throwingObserver           = static function () use ( &$throwingObserverCalled ): void {
			$throwingObserverCalled = true;
			throw new \RuntimeException( 'Failure' );
		};
		$throwingDeepObserver       = static function () use ( &$throwingDeepObserverCalled ): void {
			$throwingDeepObserverCalled = true;
			throw new \RuntimeException( 'Failure' );
		};
		$doc->on(
			'update',
			static function () use ( &$updateCalled ): void {
				$updateCalled = true;
			}
		);
		$map->observe( $throwingObserver );
		$map->observeDeep( $throwingDeepObserver );

		T::fails(
			static function () use ( $map ): void {
				$map->set( 'y', '2' );
			}
		);
		self::assertTrue( $updateCalled );
		self::assertTrue( $throwingObserverCalled );
		self::assertTrue( $throwingDeepObserverCalled );

		$updateCalled               = false;
		$throwingObserverCalled     = false;
		$throwingDeepObserverCalled = false;
		T::fails(
			static function () use ( $map ): void {
				$map->set( 'z', '3' );
			}
		);
		self::assertTrue( $updateCalled );
		self::assertTrue( $throwingObserverCalled );
		self::assertTrue( $throwingDeepObserverCalled );
		self::assertSame( '3', $map->get( 'z' ) );
	}

	public function testYmapEventHasCorrectValueWhenSettingAPrimitive(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$event  = null;
		$result['map0']->observe(
			static function ( YMapEvent $e ) use ( &$event ): void {
				$event = $e;
			}
		);
		$result['map0']->set( 'stuff', 2 );
		self::assertInstanceOf( YMapEvent::class, $event );
		self::assertSame( 2, $event->target->get( 'stuff' ) );
		self::assertNull( $event->value );
		self::assertNull( $event->name );
		compare( $result['users'] );
	}

	public function testYmapEventHasCorrectValueWhenSettingAPrimitiveFromOtherUser(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$event  = null;
		$result['map0']->observe(
			static function ( YMapEvent $e ) use ( &$event ): void {
				$event = $e;
			}
		);
		$result['map1']->set( 'stuff', 2 );
		$result['testConnector']->flushAllMessages();
		self::assertInstanceOf( YMapEvent::class, $event );
		self::assertSame( 2, $event->target->get( 'stuff' ) );
		self::assertNull( $event->value );
		self::assertNull( $event->name );
		compare( $result['users'] );
	}

	public function testRepeatGeneratingYmapTests10(): void {
		$this->runMapRandomTests( 3 );
	}

	public function testRepeatGeneratingYmapTests40(): void {
		$this->runMapRandomTests( 40 );
	}

	public function testRepeatGeneratingYmapTests42(): void {
		$this->runMapRandomTests( 42 );
	}

	public function testRepeatGeneratingYmapTests43(): void {
		$this->runMapRandomTests( 43 );
	}

	public function testRepeatGeneratingYmapTests44(): void {
		$this->runMapRandomTests( 44 );
	}

	public function testRepeatGeneratingYmapTests45(): void {
		$this->runMapRandomTests( 45 );
	}

	public function testRepeatGeneratingYmapTests46(): void {
		$this->runMapRandomTests( 46 );
	}

	public function testRepeatGeneratingYmapTests300(): void {
		$this->runMapRandomTests( 300 );
	}

	public function testRepeatGeneratingYmapTests400(): void {
		$this->runMapRandomTests( 400 );
	}

	public function testRepeatGeneratingYmapTests500(): void {
		$this->runMapRandomTests( 500 );
	}

	public function testRepeatGeneratingYmapTests600(): void {
		$this->runMapRandomTests( 600 );
	}

	public function testRepeatGeneratingYmapTests1000(): void {
		$this->runMapRandomTests( 1000 );
	}

	public function testRepeatGeneratingYmapTests1800(): void {
		$this->runMapRandomTests( 1800 );
	}

	public function testRepeatGeneratingYmapTests5000(): void {
		$this->runProductionMapRandomTests( 5000 );
	}

	public function testRepeatGeneratingYmapTests10000(): void {
		$this->runProductionMapRandomTests( 10000 );
	}

	public function testRepeatGeneratingYmapTests100000(): void {
		$this->runProductionMapRandomTests( 100000 );
	}

	private function assertBasicMapContent( YMap $map ): void {
		self::assertNull( $map->get( 'null' ) );
		self::assertSame( 1, $map->get( 'number' ) );
		self::assertSame( 'hello Y', $map->get( 'string' ) );
		self::assertFalse( $map->get( 'boolean0' ) );
		self::assertTrue( $map->get( 'boolean1' ) );
		self::assertEquals( (object) array( 'key' => (object) array( 'key2' => 'value' ) ), $map->get( 'object' ) );
		self::assertSame( -1, $map->get( 'y-map' )->get( 'y-array' )->get( 0 ) );
		self::assertSame( 7, $map->size );
	}

	private function assertMapEvent( ?YMapEvent $event, YMap $target, array $keys ): void {
		self::assertInstanceOf( YMapEvent::class, $event );
		self::assertSame( $target, $event->target );
		$actual = $event->keysChanged;
		sort( $actual );
		sort( $keys );
		self::assertSame( $keys, $actual );
	}

	private function assertKeyChange( ?array $changes, string $key, string $action, $oldValue ): void {
		self::assertNotNull( $changes );
		self::assertArrayHasKey( $key, $changes['keys'] );
		self::assertSame( $action, $changes['keys'][ $key ]['action'] );
		self::assertSame( $oldValue, $changes['keys'][ $key ]['oldValue'] );
	}

	private function runMapRandomTests( int $iterations ): void {
		$result = applyRandomTests( $this, $this->mapTransactions(), $iterations );
		$first  = $this->normalizeJsonValue( $result['users'][0]->getMap( 'map' )->toJSON() );
		foreach ( $result['users'] as $user ) {
			self::assertEquals( $first, $this->normalizeJsonValue( $user->getMap( 'map' )->toJSON() ) );
		}
	}

	private function runProductionMapRandomTests( int $iterations ): void {
		if ( self::shouldRunProductionFuzz() ) {
			$this->runMapRandomTests( $iterations );
			return;
		}

		$this->addToAssertionCount( 1 );
	}

	private static function shouldRunProductionFuzz(): bool {
		$enabled = getenv( 'Y_PHP_RUN_PRODUCTION_FUZZ' );
		return false !== $enabled && '' !== $enabled && '0' !== $enabled;
	}

	/**
	 * @return array<int,callable>
	 */
	private function mapTransactions(): array {
		return array(
			static function ( TestYInstance $user, $gen ): void {
				$key   = Prng::oneOf( $gen, array( 'one', 'two' ) );
				$value = Prng::word( $gen );
				$user->getMap( 'map' )->set( $key, $value );
			},
			static function ( TestYInstance $user, $gen ): void {
				$key  = Prng::oneOf( $gen, array( 'one', 'two' ) );
				$type = Prng::bool( $gen ) ? new YArray() : new YMap();
				$user->getMap( 'map' )->set( $key, $type );
				if ( $type instanceof YArray ) {
					$type->insert( 0, array( 1, 2, 3, 4 ) );
				} else {
					$type->set( 'deepkey', 'deepvalue' );
				}
			},
			static function ( TestYInstance $user, $gen ): void {
				$key = Prng::oneOf( $gen, array( 'one', 'two' ) );
				$user->getMap( 'map' )->delete( $key );
			},
		);
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function normalizeJsonValue( $value ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return json_decode( json_encode( $value ), true );
	}
}
