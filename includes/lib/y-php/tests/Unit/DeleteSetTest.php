<?php
/**
 * DeleteSet unit tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yjs\Structs\GC;
use Yjs\Structs\Item;
use Yjs\Utils\DeleteItem;
use Yjs\Utils\DeleteSet;
use Yjs\Utils\StructStore;

use function Yjs\addStruct;
use function Yjs\addToDeleteSet;
use function Yjs\createDeleteSetFromStructStore;
use function Yjs\createID;
use function Yjs\equalDeleteSets;
use function Yjs\isDeleted;
use function Yjs\iterateDeletedStructs;
use function Yjs\mergeDeleteSets;
use function Yjs\sortAndMergeDeleteSet;

/**
 * Tests for yjs/src/utils/DeleteSet.js.
 */
final class DeleteSetTest extends TestCase {
	/**
	 * @return void
	 */
	public function testSortAndMergeDeleteSetOrdersAndCoalescesRanges(): void {
		$ds             = new DeleteSet();
		$ds->clients[1] = array(
			new DeleteItem( 10, 2 ),
			new DeleteItem( 0, 3 ),
			new DeleteItem( 3, 2 ),
			new DeleteItem( 20, 1 ),
			new DeleteItem( 11, 4 ),
		);

		sortAndMergeDeleteSet( $ds );

		self::assertSame(
			array(
				1 => array(
					array( 0, 5 ),
					array( 10, 5 ),
					array( 20, 1 ),
				),
			),
			$this->normalize( $ds )
		);
	}

	/**
	 * @return void
	 */
	public function testMergeDeleteSetsAndEqualityMatchRangeSemantics(): void {
		$ds1 = new DeleteSet();
		addToDeleteSet( $ds1, 2, 5, 2 );
		addToDeleteSet( $ds1, 1, 0, 1 );

		$ds2 = new DeleteSet();
		addToDeleteSet( $ds2, 2, 7, 3 );
		addToDeleteSet( $ds2, 1, 2, 2 );

		$merged = mergeDeleteSets( array( $ds1, $ds2 ) );

		$expected             = new DeleteSet();
		$expected->clients[2] = array( new DeleteItem( 5, 5 ) );
		$expected->clients[1] = array( new DeleteItem( 0, 1 ), new DeleteItem( 2, 2 ) );

		self::assertTrue( equalDeleteSets( $expected, $merged ) );
		self::assertTrue( isDeleted( $merged, createID( 2, 8 ) ) );
		self::assertFalse( isDeleted( $merged, createID( 2, 10 ) ) );
	}

	/**
	 * @return void
	 */
	public function testCreateDeleteSetFromStructStoreCoalescesContiguousDeletedStructs(): void {
		$store = new StructStore();
		addStruct( $store, $this->item( 7, 0, 2, false ) );
		addStruct( $store, $this->item( 7, 2, 3, true ) );
		addStruct( $store, new GC( createID( 7, 5 ), 1 ) );
		addStruct( $store, $this->item( 7, 6, 2, true ) );
		addStruct( $store, $this->item( 7, 8, 1, false ) );
		addStruct( $store, new GC( createID( 8, 0 ), 4 ) );

		$ds = createDeleteSetFromStructStore( $store );

		self::assertSame(
			array(
				7 => array( array( 2, 6 ) ),
				8 => array( array( 0, 4 ) ),
			),
			$this->normalize( $ds )
		);
	}

	/**
	 * @return void
	 */
	public function testIterateDeletedStructsSplitsStoredStructRanges(): void {
		$store = new StructStore();
		addStruct( $store, $this->item( 1, 0, 10, false ) );

		$ds = new DeleteSet();
		addToDeleteSet( $ds, 1, 2, 5 );
		addToDeleteSet( $ds, 99, 0, 1 );

		$visited = array();
		iterateDeletedStructs(
			$this->transactionForStore( $store ),
			$ds,
			static function ( $struct ) use ( &$visited ): void {
				$visited[] = array( $struct->id->clock, $struct->length );
			}
		);

		self::assertSame( array( array( 2, 5 ) ), $visited );
		self::assertSame( array( 0, 2, 7 ), array_map( static fn ( $struct ): int => $struct->id->clock, $store->clients[1] ) );
		self::assertSame( array( 2, 5, 3 ), array_map( static fn ( $struct ): int => $struct->length, $store->clients[1] ) );
	}

	/**
	 * @param DeleteSet $ds Delete set.
	 * @return array<int,array<int,array{0:int,1:int}>>
	 */
	private function normalize( DeleteSet $ds ): array {
		$normalized = array();
		foreach ( $ds->clients as $client => $items ) {
			$normalized[ $client ] = array_map(
				static fn ( DeleteItem $item ): array => array( $item->clock, $item->len ),
				$items
			);
		}
		return $normalized;
	}

	/**
	 * @param int  $client  Client id.
	 * @param int  $clock   Clock.
	 * @param int  $length  Length.
	 * @param bool $deleted Whether to mark deleted.
	 * @return Item
	 */
	private function item( int $client, int $clock, int $length, bool $deleted ): Item {
		$item = new Item( createID( $client, $clock ), null, null, null, null, null, null, $this->content( $length ) );
		if ( $deleted ) {
			$item->markDeleted();
		}
		return $item;
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
