<?php
/**
 * Struct store namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param Utils\StructStore $store  Struct store.
 * @param int               $client Client id.
 * @return int
 */
function getState( Utils\StructStore $store, int $client ): int {
	if ( ! array_key_exists( $client, $store->clients ) ) {
		return 0;
	}
	$structs    = $store->clients[ $client ];
	$lastStruct = $structs[ count( $structs ) - 1 ];
	return $lastStruct->id->clock + $lastStruct->length;
}

/**
 * @param Utils\StructStore $store Struct store.
 * @return array<int,int>
 */
function getStateVector( Utils\StructStore $store ): array {
	$sm = array();
	foreach ( $store->clients as $client => $structs ) {
		$struct        = $structs[ count( $structs ) - 1 ];
		$sm[ $client ] = $struct->id->clock + $struct->length;
	}
	return $sm;
}

/**
 * @param Utils\StructStore $store Struct store.
 * @return void
 */
function integrityCheck( Utils\StructStore $store ): void {
	foreach ( $store->clients as $structs ) {
		for ( $i = 1, $len = count( $structs ); $i < $len; $i++ ) {
			$left  = $structs[ $i - 1 ];
			$right = $structs[ $i ];
			if ( $left->id->clock + $left->length !== $right->id->clock ) {
				throw new \RuntimeException( 'StructStore failed integrity check' );
			}
		}
	}
}

/**
 * @param Utils\StructStore      $store  Struct store.
 * @param Structs\AbstractStruct $struct Struct.
 * @return void
 */
function addStruct( Utils\StructStore $store, Structs\AbstractStruct $struct ): void {
	$client = $struct->id->client;
	if ( ! array_key_exists( $client, $store->clients ) ) {
		$store->clients[ $client ] = array();
	} else {
		$structs    = $store->clients[ $client ];
		$lastStruct = $structs[ count( $structs ) - 1 ];
		if ( $lastStruct->id->clock + $lastStruct->length !== $struct->id->clock ) {
			Lib0\Error::unexpectedCase();
		}
	}
	$store->clients[ $client ][] = $struct;
}

/**
 * @param array<int,Structs\AbstractStruct> $structs Structs.
 * @param int                               $clock   Clock.
 * @return int
 */
function findIndexSS( array $structs, int $clock ): int {
	$left     = 0;
	$right    = count( $structs ) - 1;
	$mid      = $structs[ $right ];
	$midclock = $mid->id->clock;
	if ( $midclock === $clock ) {
		return $right;
	}
	$midindex = Lib0\Math::floor( ( $clock / ( $midclock + $mid->length - 1 ) ) * $right );
	while ( $left <= $right ) {
		$mid      = $structs[ $midindex ];
		$midclock = $mid->id->clock;
		if ( $midclock <= $clock ) {
			if ( $clock < $midclock + $mid->length ) {
				return $midindex;
			}
			$left = $midindex + 1;
		} else {
			$right = $midindex - 1;
		}
		$midindex = Lib0\Math::floor( ( $left + $right ) / 2 );
	}
	Lib0\Error::unexpectedCase();
	return 0;
}

/**
 * @param Utils\StructStore $store Struct store.
 * @param Utils\ID          $id    ID.
 * @return Structs\AbstractStruct
 */
function find( Utils\StructStore $store, Utils\ID $id ): Structs\AbstractStruct {
	$structs = $store->clients[ $id->client ];
	return $structs[ findIndexSS( $structs, $id->clock ) ];
}

/**
 * Expects that id is actually in store. This function throws or is an infinite loop otherwise.
 *
 * Like the upstream JS `getItem` (a plain type-cast alias of `find`), this may
 * return a GC struct: `Item::getMissing` relies on receiving the GC when an
 * incoming item references a garbage-collected parent.
 *
 * @param Utils\StructStore $store Struct store.
 * @param Utils\ID          $id    ID.
 * @return Structs\AbstractStruct
 */
function getItem( Utils\StructStore $store, Utils\ID $id ): Structs\AbstractStruct {
	return find( $store, $id );
}

/**
 * @param mixed                             $transaction Transaction.
 * @param array<int,Structs\AbstractStruct> $structs     Structs.
 * @param int                               $clock       Clock.
 * @return int
 */
function findIndexCleanStart( $transaction, array &$structs, int $clock ): int {
	$index  = findIndexSS( $structs, $clock );
	$struct = $structs[ $index ];
	if ( $struct->id->clock < $clock && $struct instanceof Structs\Item ) {
		array_splice( $structs, $index + 1, 0, array( splitItem( $transaction, $struct, $clock - $struct->id->clock ) ) );
		return $index + 1;
	}
	return $index;
}

/**
 * Expects that id is actually in store. This function throws or is an infinite loop otherwise.
 *
 * Like the upstream JS `getItemCleanStart`, this may return a GC struct
 * (`findIndexCleanStart` only splits Items): `Item::getMissing` relies on
 * receiving the GC when an incoming item's rightOrigin was garbage-collected.
 *
 * @param mixed    $transaction Transaction.
 * @param Utils\ID $id          ID.
 * @return Structs\AbstractStruct
 */
function getItemCleanStart( $transaction, Utils\ID $id ): Structs\AbstractStruct {
	$structs =& $transaction->doc->store->clients[ $id->client ];
	return $structs[ findIndexCleanStart( $transaction, $structs, $id->clock ) ];
}

/**
 * Expects that id is actually in store. This function throws or is an infinite loop otherwise.
 *
 * Like the upstream JS `getItemCleanEnd`, this may return a GC struct (GC
 * ranges are never split): `Item::getMissing` relies on receiving the GC
 * when an incoming item's origin was garbage-collected.
 *
 * @param mixed             $transaction Transaction.
 * @param Utils\StructStore $store       Struct store.
 * @param Utils\ID          $id          ID.
 * @return Structs\AbstractStruct
 */
function getItemCleanEnd( $transaction, Utils\StructStore $store, Utils\ID $id ): Structs\AbstractStruct {
	$structs =& $store->clients[ $id->client ];
	$index   = findIndexSS( $structs, $id->clock );
	$struct  = $structs[ $index ];
	if ( $id->clock !== $struct->id->clock + $struct->length - 1 && ! $struct instanceof Structs\GC ) {
		array_splice( $structs, $index + 1, 0, array( splitItem( $transaction, $struct, $id->clock - $struct->id->clock + 1 ) ) );
	}
	return $struct;
}

/**
 * @param Utils\StructStore      $store     Struct store.
 * @param Structs\AbstractStruct $struct    Old struct.
 * @param Structs\AbstractStruct $newStruct New struct.
 * @return void
 */
function replaceStruct( Utils\StructStore $store, Structs\AbstractStruct $struct, Structs\AbstractStruct $newStruct ): void {
	$structs =& $store->clients[ $struct->id->client ];
	$structs[ findIndexSS( $structs, $struct->id->clock ) ] = $newStruct;
}

/**
 * @param mixed                             $transaction Transaction.
 * @param array<int,Structs\AbstractStruct> $structs     Structs.
 * @param int                               $clockStart  Inclusive start.
 * @param int                               $len         Length.
 * @param callable                          $f           Callback.
 * @return void
 */
function iterateStructs( $transaction, array &$structs, int $clockStart, int $len, callable $f ): void {
	if ( 0 === $len ) {
		return;
	}
	$clockEnd = $clockStart + $len;
	$index    = findIndexCleanStart( $transaction, $structs, $clockStart );
	do {
		$struct = $structs[ $index++ ];
		if ( $clockEnd < $struct->id->clock + $struct->length ) {
			findIndexCleanStart( $transaction, $structs, $clockEnd );
		}
		$f( $struct );
		$structCount = count( $structs );
	} while ( $index < $structCount && $structs[ $index ]->id->clock < $clockEnd );
}

/**
 * @param mixed        $transaction Transaction.
 * @param Structs\Item $leftItem    Left item.
 * @param int          $diff        Split diff.
 * @return Structs\Item
 */
function splitItem( $transaction, Structs\Item $leftItem, int $diff ): Structs\Item {
	$client    = $leftItem->id->client;
	$clock     = $leftItem->id->clock;
	$rightItem = new Structs\Item(
		createID( $client, $clock + $diff ),
		$leftItem,
		createID( $client, $clock + $diff - 1 ),
		$leftItem->right,
		$leftItem->rightOrigin,
		$leftItem->parent,
		$leftItem->parentSub,
		$leftItem->content->splice( $diff )
	);
	if ( $leftItem->deleted ) {
		$rightItem->markDeleted();
	}
	if ( $leftItem->keep ) {
		$rightItem->keep = true;
	}
	if ( null !== $leftItem->redone ) {
		$rightItem->redone = createID( $leftItem->redone->client, $leftItem->redone->clock + $diff );
	}
	$leftItem->right = $rightItem;
	if ( null !== $rightItem->right ) {
		$rightItem->right->left = $rightItem;
	}
	if ( ! isset( $transaction->_mergeStructs ) || ! is_array( $transaction->_mergeStructs ) ) {
		$transaction->_mergeStructs = array();
	}
	$transaction->_mergeStructs[] = $rightItem;
	if ( null !== $rightItem->parentSub && null === $rightItem->right && is_object( $rightItem->parent ) && isset( $rightItem->parent->_map ) && is_array( $rightItem->parent->_map ) ) {
		$rightItem->parent->_map[ $rightItem->parentSub ] = $rightItem;
	}
	$leftItem->length = $diff;
	return $rightItem;
}
