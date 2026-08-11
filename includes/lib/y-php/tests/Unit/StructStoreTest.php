<?php
/**
 * StructStore unit tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yjs\Structs\GC;
use Yjs\Structs\Item;
use Yjs\Utils\StructStore;

use function Yjs\addStruct;
use function Yjs\createID;
use function Yjs\findIndexSS;
use function Yjs\getItemCleanEnd;
use function Yjs\getItemCleanStart;
use function Yjs\getState;
use function Yjs\getStateVector;
use function Yjs\integrityCheck;
use function Yjs\iterateStructs;

/**
 * Tests for yjs/src/utils/StructStore.js.
 */
final class StructStoreTest extends TestCase {
	/**
	 * @return void
	 */
	public function testAddStructFindIndexAndStateVector(): void {
		$store = new StructStore();

		addStruct( $store, $this->item( 2, 0, 5 ) );
		addStruct( $store, $this->item( 2, 5, 3 ) );
		addStruct( $store, new GC( createID( 1, 0 ), 2 ) );

		self::assertSame( 0, findIndexSS( $store->clients[2], 0 ) );
		self::assertSame( 0, findIndexSS( $store->clients[2], 4 ) );
		self::assertSame( 1, findIndexSS( $store->clients[2], 5 ) );
		self::assertSame( 1, findIndexSS( $store->clients[2], 7 ) );
		self::assertSame( 8, getState( $store, 2 ) );
		self::assertSame( 0, getState( $store, 99 ) );
		self::assertSame(
			array(
				2 => 8,
				1 => 2,
			),
			getStateVector( $store )
		);

		integrityCheck( $store );
		self::assertTrue( true );
	}

	/**
	 * @return void
	 */
	public function testIntegrityCheckRejectsClockGaps(): void {
		$this->expectException( \RuntimeException::class );

		$store             = new StructStore();
		$store->clients[1] = array(
			$this->item( 1, 0, 2 ),
			$this->item( 1, 3, 1 ),
		);

		integrityCheck( $store );
	}

	/**
	 * @return void
	 */
	public function testCleanStartAndCleanEndSplitItemsAtRequestedClocks(): void {
		$store = new StructStore();
		addStruct( $store, $this->item( 1, 0, 10 ) );
		$transaction = $this->transactionForStore( $store );

		$right = getItemCleanStart( $transaction, createID( 1, 3 ) );

		self::assertSame( 3, $right->id->clock );
		self::assertSame( 3, $store->clients[1][0]->length );
		self::assertSame( 7, $right->length );
		self::assertSame( $right, $transaction->_mergeStructs[0] );

		$left = getItemCleanEnd( $transaction, $store, createID( 1, 5 ) );

		self::assertSame( 3, $left->id->clock );
		self::assertSame( 3, $left->length );
		self::assertSame( 6, $store->clients[1][2]->id->clock );
		self::assertSame( 4, $store->clients[1][2]->length );
		self::assertSame( $store->clients[1][2], $transaction->_mergeStructs[1] );
	}

	/**
	 * @return void
	 */
	public function testIterateStructsSplitsRangeBoundaries(): void {
		$store = new StructStore();
		addStruct( $store, $this->item( 1, 0, 10 ) );
		$transaction = $this->transactionForStore( $store );
		$visited     = array();

		$structs =& $store->clients[1];
		iterateStructs(
			$transaction,
			$structs,
			2,
			5,
			static function ( $struct ) use ( &$visited ): void {
				$visited[] = array( $struct->id->clock, $struct->length );
			}
		);

		self::assertSame( array( array( 2, 5 ) ), $visited );
		self::assertSame( array( 0, 2, 7 ), array_map( static fn ( $struct ): int => $struct->id->clock, $store->clients[1] ) );
		self::assertSame( array( 2, 5, 3 ), array_map( static fn ( $struct ): int => $struct->length, $store->clients[1] ) );
	}

	/**
	 * @param int $client Client id.
	 * @param int $clock  Clock.
	 * @param int $length Length.
	 * @return Item
	 */
	private function item( int $client, int $clock, int $length ): Item {
		return new Item( createID( $client, $clock ), null, null, null, null, null, null, $this->content( $length ) );
	}

	/**
	 * @param int $length Length.
	 * @return object
	 */
	private function content( int $length ): object {
		return new class( $length ) {
			/**
			 * @var int
			 */
			public int $length;

			/**
			 * @param int $length Length.
			 */
			public function __construct( int $length ) {
				$this->length = $length;
			}

			/**
			 * @return int
			 */
			public function getLength(): int {
				return $this->length;
			}

			/**
			 * @return bool
			 */
			public function isCountable(): bool {
				return true;
			}

			/**
			 * @param int $offset Offset.
			 * @return object
			 */
			public function splice( int $offset ): object {
				$right        = new self( $this->length - $offset );
				$this->length = $offset;
				return $right;
			}
		};
	}

	/**
	 * @param StructStore $store Struct store.
	 * @return object
	 */
	private function transactionForStore( StructStore $store ): object {
		return (object) array(
			'doc'           => (object) array( 'store' => $store ),
			'_mergeStructs' => array(),
		);
	}
}
