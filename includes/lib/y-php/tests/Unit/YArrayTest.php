<?php
/**
 * Ported y-array.tests.js tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Lib0\Prng;
use Yjs\Tests\Support\T;
use Yjs\Tests\Support\TestYInstance;
use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Types\YArray;
use Yjs\Types\YMap;
use Yjs\Utils\Doc;

use function Yjs\Tests\Support\applyRandomTests;
use function Yjs\Tests\Support\compare;
use function Yjs\Tests\Support\init;

/**
 * Ported assertions from yjs/tests/y-array.tests.js.
 */
final class YArrayTest extends TranslatedTestCase {
	/**
	 * @var int
	 */
	private static int $uniqueNumber = 0;

	public function testBasicUpdate(): void {
		$doc1           = new Doc();
		$doc2           = new Doc();
		$doc1->clientID = 1;
		$doc1->getArray( 'array' )->insert( 0, array( 'hi' ) );
		$update = \Yjs\encodeStateAsUpdate( $doc1 );
		\Yjs\applyUpdate( $doc2, $update );
		self::assertSame( array( 'hi' ), $doc2->getArray( 'array' )->toArray() );
	}

	public function testFailsObjectManipulationInDevMode(): void {
		$doc = new Doc();
		$a   = array( 1, 2, 3 );
		$b   = (object) array(
			'o' => (object) array(
				'nested' => 1,
			),
		);
		$doc->getArray( 'test' )->insert( 0, array( $a ) );
		$doc->getMap( 'map' )->set( 'k', $b );

		$a[0]         = 42;
		$b->o->nested = 42;

		self::assertSame( array( 1, 2, 3 ), $doc->getArray( 'test' )->get( 0 ) );
		self::assertEquals(
			(object) array(
				'o' => (object) array(
					'nested' => 1,
				),
			),
			$doc->getMap( 'map' )->get( 'k' )
		);

		$stored            = $doc->getMap( 'map' )->get( 'k' );
		$stored->o->nested = 99;
		self::assertEquals(
			(object) array(
				'o' => (object) array(
					'nested' => 1,
				),
			),
			$doc->getMap( 'map' )->get( 'k' )
		);
	}

	public function testSlice(): void {
		$doc1 = new Doc();
		$arr  = $doc1->getArray( 'array' );
		$arr->insert( 0, array( 1, 2, 3 ) );
		self::assertSame( array( 1, 2, 3 ), $arr->slice( 0 ) );
		self::assertSame( array( 2, 3 ), $arr->slice( 1 ) );
		self::assertSame( array( 1, 2 ), $arr->slice( 0, -1 ) );
		$arr->insert( 0, array( 0 ) );
		self::assertSame( array( 0, 1, 2, 3 ), $arr->slice( 0 ) );
		self::assertSame( array( 0, 1 ), $arr->slice( 0, 2 ) );
	}

	public function testArrayFrom(): void {
		$doc1         = new Doc();
		$db1          = $doc1->getMap( 'root' );
		$nestedArray1 = YArray::from( array( 0, 1, 2 ) );
		$db1->set( 'array', $nestedArray1 );
		self::assertSame( array( 0, 1, 2 ), $nestedArray1->toArray() );
	}

	public function testLengthIssue(): void {
		$doc1 = new Doc();
		$arr  = $doc1->getArray( 'array' );
		$arr->push( array( 0, 1, 2, 3 ) );
		$arr->delete( 0 );
		$arr->insert( 0, array( 0 ) );
		$this->assertLengthInvariant( $arr );
		$doc1->transact(
			function () use ( $arr ): void {
				$arr->delete( 1 );
				$this->assertLengthInvariant( $arr );
				$arr->insert( 1, array( 1 ) );
				$this->assertLengthInvariant( $arr );
				$arr->delete( 2 );
				$this->assertLengthInvariant( $arr );
				$arr->insert( 2, array( 2 ) );
				$this->assertLengthInvariant( $arr );
			}
		);
		$this->assertLengthInvariant( $arr );
		$arr->delete( 1 );
		$this->assertLengthInvariant( $arr );
		$arr->insert( 1, array( 1 ) );
		$this->assertLengthInvariant( $arr );
	}

	public function testLengthIssue2(): void {
		$doc  = new Doc();
		$next = $doc->getArray();
		$doc->transact(
			static function () use ( $next ): void {
				$next->insert( 0, array( 'group2' ) );
			}
		);
		$doc->transact(
			static function () use ( $next ): void {
				$next->insert( 1, array( 'rectangle3' ) );
			}
		);
		$doc->transact(
			static function () use ( $next ): void {
				$next->delete( 0 );
				$next->insert( 0, array( 'rectangle3' ) );
			}
		);
		$next->delete( 1 );
		foreach ( array( 'ellipse4', 'ellipse3', 'ellipse2' ) as $value ) {
			$doc->transact(
				static function () use ( $next, $value ): void {
					$next->insert( $next->length, array( $value ) );
				}
			);
		}
		$doc->transact(
			static function () use ( $doc, $next ): void {
				$doc->transact(
					static function () use ( $next ): void {
						T::fails(
							static function () use ( $next ): void {
								$next->insert( 5, array( 'rectangle2' ) );
							}
						);
						$next->insert( 4, array( 'rectangle2' ) );
					}
				);
				$doc->transact(
					static function () use ( $next ): void {
						$next->delete( 4 );
					}
				);
			}
		);
		$this->assertLengthInvariant( $next );
	}

	public function testDeleteInsert(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$array0 = $result['array0'];
		$array0->delete( 0, 0 );
		T::fails(
			static function () use ( $array0 ): void {
				$array0->delete( 1, 1 );
			}
		);
		$array0->insert( 0, array( 'A' ) );
		$array0->delete( 1, 0 );
		compare( $result['users'] );
	}

	public function testInsertThreeElementsTryRegetProperty(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$result['array0']->insert( 0, array( 1, true, false ) );
		self::assertSame( array( 1, true, false ), $result['array0']->toJSON() );
		$result['testConnector']->flushAllMessages();
		self::assertSame( array( 1, true, false ), $result['array1']->toJSON() );
		compare( $result['users'] );
	}

	public function testConcurrentInsertWithThreeConflicts(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$result['array0']->insert( 0, array( 0 ) );
		$result['array1']->insert( 0, array( 1 ) );
		$result['array2']->insert( 0, array( 2 ) );
		compare( $result['users'] );
	}

	public function testConcurrentInsertDeleteWithThreeConflicts(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$result['array0']->insert( 0, array( 'x', 'y', 'z' ) );
		$result['testConnector']->flushAllMessages();
		$result['array0']->insert( 1, array( 0 ) );
		$result['array1']->delete( 0 );
		$result['array1']->delete( 1, 1 );
		$result['array2']->insert( 1, array( 2 ) );
		compare( $result['users'] );
	}

	public function testInsertionsInLateSync(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$result['array0']->insert( 0, array( 'x', 'y' ) );
		$result['testConnector']->flushAllMessages();
		$result['users'][1]->disconnect();
		$result['users'][2]->disconnect();
		$result['array0']->insert( 1, array( 'user0' ) );
		$result['array1']->insert( 1, array( 'user1' ) );
		$result['array2']->insert( 1, array( 'user2' ) );
		$result['users'][1]->connect();
		$result['users'][2]->connect();
		$result['testConnector']->flushAllMessages();
		compare( $result['users'] );
	}

	public function testDisconnectReallyPreventsSendingMessages(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$result['array0']->insert( 0, array( 'x', 'y' ) );
		$result['testConnector']->flushAllMessages();
		$result['users'][1]->disconnect();
		$result['users'][2]->disconnect();
		$result['array0']->insert( 1, array( 'user0' ) );
		$result['array1']->insert( 1, array( 'user1' ) );
		self::assertSame( array( 'x', 'user0', 'y' ), $result['array0']->toJSON() );
		self::assertSame( array( 'x', 'user1', 'y' ), $result['array1']->toJSON() );
		$result['users'][1]->connect();
		$result['users'][2]->connect();
		compare( $result['users'] );
	}

	public function testDeletionsInLateSync(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$result['array0']->insert( 0, array( 'x', 'y' ) );
		$result['testConnector']->flushAllMessages();
		$result['users'][1]->disconnect();
		$result['array1']->delete( 1, 1 );
		$result['array0']->delete( 0, 2 );
		$result['users'][1]->connect();
		compare( $result['users'] );
	}

	public function testInsertThenMergeDeleteOnSync(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$result['array0']->insert( 0, array( 'x', 'y', 'z' ) );
		$result['testConnector']->flushAllMessages();
		$result['users'][0]->disconnect();
		$result['array1']->delete( 0, 3 );
		$result['users'][0]->connect();
		compare( $result['users'] );
	}

	public function testInsertAndDeleteEvents(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$event  = null;
		$result['array0']->observe(
			static function ( $e ) use ( &$event ): void {
				$event = $e;
			}
		);
		$result['array0']->insert( 0, array( 0, 1, 2 ) );
		self::assertNotNull( $event );
		$event = null;
		$result['array0']->delete( 0 );
		self::assertNotNull( $event );
		$event = null;
		$result['array0']->delete( 0, 2 );
		self::assertNotNull( $event );
		compare( $result['users'] );
	}

	public function testNestedObserverEvents(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$vals   = array();
		$result['array0']->observe(
			static function () use ( &$vals, $result ): void {
				if ( 1 === $result['array0']->length ) {
					$result['array0']->insert( 1, array( 1 ) );
					$vals[] = 0;
				} else {
					$vals[] = 1;
				}
			}
		);
		$result['array0']->insert( 0, array( 0 ) );
		self::assertSame( array( 0, 1 ), $vals );
		self::assertSame( array( 0, 1 ), $result['array0']->toArray() );
		compare( $result['users'] );
	}

	public function testInsertAndDeleteEventsForTypes(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$event  = null;
		$result['array0']->observe(
			static function ( $e ) use ( &$event ): void {
				$event = $e;
			}
		);
		$result['array0']->insert( 0, array( new YArray() ) );
		self::assertNotNull( $event );
		$event = null;
		$result['array0']->delete( 0 );
		self::assertNotNull( $event );
		compare( $result['users'] );
	}

	public function testObserveDeepEventOrder(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$events = array();
		$result['array0']->observeDeep(
			static function ( array $e ) use ( &$events ): void {
				$events = $e;
			}
		);
		$result['array0']->insert( 0, array( new YMap() ) );
		$result['users'][0]->transact(
			static function () use ( $result ): void {
				$result['array0']->get( 0 )->set( 'a', 'a' );
				$result['array0']->insert( 0, array( 0 ) );
			}
		);
		for ( $i = 1, $len = count( $events ); $i < $len; $i++ ) {
			self::assertLessThanOrEqual( count( $events[ $i ]->path ), count( $events[ $i - 1 ]->path ) );
		}
	}

	public function testObservedeepIndexes(): void {
		$doc = new Doc();
		$map = $doc->getMap();
		$map->set( 'my-array', new YArray() );
		$map->get( 'my-array' )->push( array( 'a', 'b', 'c', new YMap() ) );
		$eventPath = array();
		$map->observeDeep(
			static function ( array $events ) use ( &$eventPath ): void {
				$eventPath = $events[0]->path;
			}
		);
		$map->get( 'my-array' )->get( 3 )->set( 'hello', 'world' );
		self::assertSame( array( 'my-array', 3 ), $eventPath );
	}

	public function testChangeEvent(): void {
		$result  = init( $this, array( 'users' => 2 ) );
		$changes = null;
		$result['array0']->observe(
			static function ( $e ) use ( &$changes ): void {
				$changes = $e->changes;
			}
		);
		$newArr = new YArray();
		$result['array0']->insert( 0, array( $newArr, 4, 'dtrn' ) );
		self::assertNotNull( $changes );
		self::assertSame( 2, $changes['added']->count() );
		self::assertSame( 0, $changes['deleted']->count() );
		self::assertSame( array( array( 'insert' => array( $newArr, 4, 'dtrn' ) ) ), $changes['delta'] );
		$changes = null;
		$result['array0']->delete( 0, 2 );
		self::assertNotNull( $changes );
		self::assertSame( 0, $changes['added']->count() );
		self::assertSame( 2, $changes['deleted']->count() );
		self::assertSame( array( array( 'delete' => 2 ) ), $changes['delta'] );
		$changes = null;
		$result['array0']->insert( 1, array( 0.1 ) );
		self::assertNotNull( $changes );
		self::assertSame( 1, $changes['added']->count() );
		self::assertSame( 0, $changes['deleted']->count() );
		self::assertSame( array( array( 'retain' => 1 ), array( 'insert' => array( 0.1 ) ) ), $changes['delta'] );
		compare( $result['users'] );
	}

	public function testInsertAndDeleteEventsForTypes2(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$events = array();
		$result['array0']->observe(
			static function ( $e ) use ( &$events ): void {
				$events[] = $e;
			}
		);
		$result['array0']->insert( 0, array( 'hi', new YMap() ) );
		self::assertCount( 1, $events );
		$result['array0']->delete( 1 );
		self::assertCount( 2, $events );
		compare( $result['users'] );
	}

	public function testNewChildDoesNotEmitEventInTransaction(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$fired  = false;
		$result['users'][0]->transact(
			static function () use ( $result, &$fired ): void {
				$newMap = new YMap();
				$newMap->observe(
					static function () use ( &$fired ): void {
						$fired = true;
					}
				);
				$result['array0']->insert( 0, array( $newMap ) );
				$newMap->set( 'tst', 42 );
			}
		);
		self::assertFalse( $fired );
	}

	public function testGarbageCollector(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$result['array0']->insert( 0, array( 'x', 'y', 'z' ) );
		$result['testConnector']->flushAllMessages();
		$result['users'][0]->disconnect();
		$result['array0']->delete( 0, 3 );
		$result['users'][0]->connect();
		$result['testConnector']->flushAllMessages();
		compare( $result['users'] );
	}

	public function testEventTargetIsSetCorrectlyOnLocal(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$event  = null;
		$result['array0']->observe(
			static function ( $e ) use ( &$event ): void {
				$event = $e;
			}
		);
		$result['array0']->insert( 0, array( 'stuff' ) );
		self::assertSame( $result['array0'], $event->target );
		compare( $result['users'] );
	}

	public function testEventTargetIsSetCorrectlyOnRemote(): void {
		$result = init( $this, array( 'users' => 3 ) );
		$event  = null;
		$result['array0']->observe(
			static function ( $e ) use ( &$event ): void {
				$event = $e;
			}
		);
		$result['array1']->insert( 0, array( 'stuff' ) );
		$result['testConnector']->flushAllMessages();
		self::assertSame( $result['array0'], $event->target );
		compare( $result['users'] );
	}

	public function testIteratingArrayContainingTypes(): void {
		$y        = new Doc();
		$arr      = $y->getArray( 'arr' );
		$numItems = 10;
		for ( $i = 0; $i < $numItems; $i++ ) {
			$map = new YMap();
			$map->set( 'value', $i );
			$arr->push( array( $map ) );
		}
		$cnt = 0;
		foreach ( $arr as $item ) {
			self::assertSame( $cnt++, $item->get( 'value' ) );
		}
		$y->destroy();
	}

	public function testRepeatGeneratingYarrayTests6(): void {
		$this->runArrayRandomTests( 6 );
	}

	public function testRepeatGeneratingYarrayTests40(): void {
		$this->runArrayRandomTests( 40 );
	}

	public function testRepeatGeneratingYarrayTests42(): void {
		$this->runArrayRandomTests( 42 );
	}

	public function testRepeatGeneratingYarrayTests43(): void {
		$this->runArrayRandomTests( 43 );
	}

	public function testRepeatGeneratingYarrayTests44(): void {
		$this->runArrayRandomTests( 44 );
	}

	public function testRepeatGeneratingYarrayTests45(): void {
		$this->runArrayRandomTests( 45 );
	}

	public function testRepeatGeneratingYarrayTests46(): void {
		$this->runArrayRandomTests( 46 );
	}

	public function testRepeatGeneratingYarrayTests300(): void {
		$this->runArrayRandomTests( 300 );
	}

	public function testRepeatGeneratingYarrayTests400(): void {
		$this->runArrayRandomTests( 400 );
	}

	public function testRepeatGeneratingYarrayTests500(): void {
		$this->runArrayRandomTests( 500 );
	}

	public function testRepeatGeneratingYarrayTests600(): void {
		$this->runArrayRandomTests( 600 );
	}

	public function testRepeatGeneratingYarrayTests1000(): void {
		$this->runArrayRandomTests( 1000 );
	}

	public function testRepeatGeneratingYarrayTests1800(): void {
		$this->runArrayRandomTests( 1800 );
	}

	public function testRepeatGeneratingYarrayTests3000(): void {
		$this->runProductionArrayRandomTests( 3000 );
	}

	public function testRepeatGeneratingYarrayTests5000(): void {
		$this->runProductionArrayRandomTests( 5000 );
	}

	public function testRepeatGeneratingYarrayTests30000(): void {
		$this->runProductionArrayRandomTests( 30000 );
	}

	private function runArrayRandomTests( int $iterations ): void {
		applyRandomTests( $this, $this->arrayTransactions(), $iterations );
	}

	private function runProductionArrayRandomTests( int $iterations ): void {
		if ( self::shouldRunProductionFuzz() ) {
			$this->runArrayRandomTests( $iterations );
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
	private function arrayTransactions(): array {
		return array(
			static function ( TestYInstance $user, $gen ): void {
				$yarray       = $user->getArray( 'array' );
				$uniqueNumber = self::getUniqueNumber();
				$content      = array();
				$len          = Prng::int32( $gen, 1, 4 );
				for ( $i = 0; $i < $len; $i++ ) {
					$content[] = $uniqueNumber;
				}
				$pos        = Prng::int32( $gen, 0, $yarray->length );
				$oldContent = $yarray->toArray();
				$yarray->insert( $pos, $content );
				array_splice( $oldContent, $pos, 0, $content );
				T::compareArrays( $oldContent, $yarray->toArray() );
			},
			static function ( TestYInstance $user, $gen ): void {
				$yarray = $user->getArray( 'array' );
				$pos    = Prng::int32( $gen, 0, $yarray->length );
				$yarray->insert( $pos, array( new YArray() ) );
				$array2 = $yarray->get( $pos );
				$array2->insert( 0, array( 1, 2, 3, 4 ) );
			},
			static function ( TestYInstance $user, $gen ): void {
				$yarray = $user->getArray( 'array' );
				$pos    = Prng::int32( $gen, 0, $yarray->length );
				$yarray->insert( $pos, array( new YMap() ) );
				$map = $yarray->get( $pos );
				$map->set( 'someprop', 42 );
				$map->set( 'someprop', 43 );
				$map->set( 'someprop', 44 );
			},
			static function ( TestYInstance $user, $gen ): void {
				$yarray = $user->getArray( 'array' );
				$pos    = Prng::int32( $gen, 0, $yarray->length );
				$yarray->insert( $pos, array( null ) );
			},
			static function ( TestYInstance $user, $gen ): void {
				$yarray = $user->getArray( 'array' );
				$length = $yarray->length;
				if ( 0 < $length ) {
					$somePos   = Prng::int32( $gen, 0, $length - 1 );
					$delLength = Prng::int32( $gen, 1, min( 2, $length - $somePos ) );
					if ( Prng::bool( $gen ) ) {
						$type = $yarray->get( $somePos );
						if ( $type instanceof YArray && 0 < $type->length ) {
							$somePos   = Prng::int32( $gen, 0, $type->length - 1 );
							$delLength = Prng::int32( $gen, 0, min( 2, $type->length - $somePos ) );
							$type->delete( $somePos, $delLength );
						}
					} else {
						$oldContent = $yarray->toArray();
						$yarray->delete( $somePos, $delLength );
						array_splice( $oldContent, $somePos, $delLength );
						T::compareArrays( $oldContent, $yarray->toArray() );
					}
				}
			},
		);
	}

	private static function getUniqueNumber(): int {
		return self::$uniqueNumber++;
	}

	private function assertLengthInvariant( YArray $arr ): void {
		self::assertSame( count( $arr->toArray() ), $arr->length );
	}
}
