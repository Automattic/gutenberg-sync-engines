<?php
/**
 * Garbage-collection namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param array<int,Structs\AbstractStruct> $structs Structs.
 * @param int                               $pos     Position.
 * @return int
 */
function tryToMergeWithLefts( array &$structs, int $pos ): int {
	$right = $structs[ $pos ];
	$left  = $structs[ $pos - 1 ];
	$i     = $pos;
	while ( $i > 0 ) {
		if ( $left->deleted === $right->deleted && get_class( $left ) === get_class( $right ) ) {
			if ( $left->mergeWith( $right ) ) {
				if ( $right instanceof Structs\Item && null !== $right->parentSub && ( $right->parent->_map[ $right->parentSub ] ?? null ) === $right ) {
					$right->parent->_map[ $right->parentSub ] = $left;
				}
				$right = $left;
				--$i;
				if ( $i > 0 ) {
					$left = $structs[ $i - 1 ];
				}
				continue;
			}
		}
		break;
	}
	$merged = $pos - $i;
	if ( 0 < $merged ) {
		array_splice( $structs, $pos + 1 - $merged, $merged );
	}
	return $merged;
}

/**
 * @param Utils\DeleteSet   $ds       Delete set.
 * @param Utils\StructStore $store    Store.
 * @param callable          $gcFilter GC filter.
 * @return void
 */
function tryGcDeleteSet( Utils\DeleteSet $ds, Utils\StructStore $store, callable $gcFilter ): void {
	foreach ( $ds->clients as $client => $deleteItems ) {
		if ( ! array_key_exists( $client, $store->clients ) ) {
			continue;
		}
		$structs =& $store->clients[ $client ];
		for ( $di = count( $deleteItems ) - 1; $di >= 0; $di-- ) {
			$deleteItem         = $deleteItems[ $di ];
			$endDeleteItemClock = $deleteItem->clock + $deleteItem->len;
			$structCount        = count( $structs );
			for ( $si = findIndexSS( $structs, $deleteItem->clock ); $si < $structCount && $structs[ $si ]->id->clock < $endDeleteItemClock; $si++ ) {
				$struct = $structs[ $si ];
				if ( $deleteItem->clock + $deleteItem->len <= $struct->id->clock ) {
					break;
				}
				if ( $struct instanceof Structs\Item && $struct->deleted && ! $struct->keep && $gcFilter( $struct ) ) {
					$struct->gc( $store, false );
				}
			}
		}
		unset( $structs );
	}
}

/**
 * @param Utils\DeleteSet   $ds    Delete set.
 * @param Utils\StructStore $store Store.
 * @return void
 */
function tryMergeDeleteSet( Utils\DeleteSet $ds, Utils\StructStore $store ): void {
	foreach ( $ds->clients as $client => $deleteItems ) {
		if ( ! array_key_exists( $client, $store->clients ) ) {
			continue;
		}
		$structs =& $store->clients[ $client ];
		for ( $di = count( $deleteItems ) - 1; $di >= 0; $di-- ) {
			$deleteItem            = $deleteItems[ $di ];
			$mostRightIndexToCheck = Lib0\Math::min( count( $structs ) - 1, 1 + findIndexSS( $structs, $deleteItem->clock + $deleteItem->len - 1 ) );
			for ( $si = $mostRightIndexToCheck; $si > 0 && $structs[ $si ]->id->clock >= $deleteItem->clock; ) {
				$si -= 1 + tryToMergeWithLefts( $structs, $si );
			}
		}
		unset( $structs );
	}
}

/**
 * @param Utils\DeleteSet   $ds       Delete set.
 * @param Utils\StructStore $store    Store.
 * @param callable          $gcFilter GC filter.
 * @return void
 */
function tryGc( Utils\DeleteSet $ds, Utils\StructStore $store, callable $gcFilter ): void {
	tryGcDeleteSet( $ds, $store, $gcFilter );
	tryMergeDeleteSet( $ds, $store );
}
