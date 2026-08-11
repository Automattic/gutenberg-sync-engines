<?php
/**
 * Undo manager namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param Utils\StructStore $store Store.
 * @param Utils\ID          $id    ID.
 * @return array{item:Structs\AbstractStruct,diff:int}
 */
function followRedone( Utils\StructStore $store, Utils\ID $id ): array {
	$nextID = $id;
	$diff   = 0;
	do {
		if ( $diff > 0 ) {
			$nextID = createID( $nextID->client, $nextID->clock + $diff );
		}
		$item   = getItem( $store, $nextID );
		$diff   = $nextID->clock - $item->id->clock;
		$nextID = $item instanceof Structs\Item ? $item->redone : null;
	} while ( null !== $nextID && $item instanceof Structs\Item );
	return array(
		'item' => $item,
		'diff' => $diff,
	);
}

/**
 * @param Structs\Item|null $item Item.
 * @param bool              $keep Keep flag.
 * @return void
 */
function keepItem( ?Structs\Item $item, bool $keep ): void {
	while ( null !== $item && $item->keep !== $keep ) {
		$item->keep = $keep;
		$parent     = $item->parent;
		$item       = $parent instanceof Types\AbstractType ? $parent->_item : null;
	}
}

/**
 * @param array<int,Utils\StackItem> $stack Stack.
 * @param Utils\ID                   $id    ID.
 * @return bool
 */
function isDeletedByUndoStack( array $stack, Utils\ID $id ): bool {
	foreach ( $stack as $stackItem ) {
		if ( isDeleted( $stackItem->deletions, $id ) ) {
			return true;
		}
	}
	return false;
}

/**
 * @param Utils\Transaction $transaction            Transaction.
 * @param Structs\Item      $item                   Item to redo.
 * @param \SplObjectStorage $redoitems              Items to redo.
 * @param Utils\DeleteSet   $itemsToDelete          Insertions delete set.
 * @param bool              $ignoreRemoteMapChanges Whether to ignore remote map changes.
 * @param Utils\UndoManager $um                     Undo manager.
 * @return Structs\Item|null
 */
function redoItem( Utils\Transaction $transaction, Structs\Item $item, \SplObjectStorage $redoitems, Utils\DeleteSet $itemsToDelete, bool $ignoreRemoteMapChanges, Utils\UndoManager $um ): ?Structs\Item {
	$doc         = $transaction->doc;
	$store       = $doc->store;
	$ownClientID = $doc->clientID;
	$redone      = $item->redone;
	if ( null !== $redone ) {
		return getItemCleanStart( $transaction, $redone );
	}
	$parentItem = $item->parent instanceof Types\AbstractType ? $item->parent->_item : null;
	$left       = null;
	$right      = null;

	if ( null !== $parentItem && true === $parentItem->deleted ) {
		if ( null === $parentItem->redone && ( ! $redoitems->contains( $parentItem ) || null === redoItem( $transaction, $parentItem, $redoitems, $itemsToDelete, $ignoreRemoteMapChanges, $um ) ) ) {
			return null;
		}
		while ( null !== $parentItem->redone ) {
			$parentItem = getItemCleanStart( $transaction, $parentItem->redone );
		}
	}
	$parentType = null === $parentItem ? $item->parent : $parentItem->content->type;

	if ( null === $item->parentSub ) {
		$left  = $item->left;
		$right = $item;
		while ( null !== $left ) {
			$leftTrace = $left;
			while ( null !== $leftTrace && $leftTrace->parent instanceof Types\AbstractType && $leftTrace->parent->_item !== $parentItem ) {
				$leftTrace = null === $leftTrace->redone ? null : getItemCleanStart( $transaction, $leftTrace->redone );
			}
			if ( null !== $leftTrace && $leftTrace->parent instanceof Types\AbstractType && $leftTrace->parent->_item === $parentItem ) {
				$left = $leftTrace;
				break;
			}
			$left = $left->left;
		}
		while ( null !== $right ) {
			$rightTrace = $right;
			while ( null !== $rightTrace && $rightTrace->parent instanceof Types\AbstractType && $rightTrace->parent->_item !== $parentItem ) {
				$rightTrace = null === $rightTrace->redone ? null : getItemCleanStart( $transaction, $rightTrace->redone );
			}
			if ( null !== $rightTrace && $rightTrace->parent instanceof Types\AbstractType && $rightTrace->parent->_item === $parentItem ) {
				$right = $rightTrace;
				break;
			}
			$right = $right->right;
		}
	} else {
		$right = null;
		if ( null !== $item->right && ! $ignoreRemoteMapChanges ) {
			$left = $item;
			while (
				null !== $left &&
				null !== $left->right &&
				(
					null !== $left->right->redone ||
					isDeleted( $itemsToDelete, $left->right->id ) ||
					isDeletedByUndoStack( $um->undoStack, $left->right->id ) ||
					isDeletedByUndoStack( $um->redoStack, $left->right->id )
				)
			) {
				$left = $left->right;
				while ( null !== $left->redone ) {
					$left = getItemCleanStart( $transaction, $left->redone );
				}
			}
			if ( null !== $left && null !== $left->right ) {
				return null;
			}
		} else {
			$left = $parentType->_map[ $item->parentSub ] ?? null;
		}
		if ( null !== $left && $left->parent instanceof Types\AbstractType && $left->parent->_item !== $parentItem ) {
			$left = $parentType->_map[ $item->parentSub ] ?? null;
		}
	}

	$nextClock    = getState( $store, $ownClientID );
	$nextId       = createID( $ownClientID, $nextClock );
	$redoneItem   = new Structs\Item(
		$nextId,
		$left,
		null !== $left ? $left->lastId : null,
		$right,
		null !== $right ? $right->id : null,
		$parentType,
		$item->parentSub,
		$item->content->copy()
	);
	$item->redone = $nextId;
	keepItem( $redoneItem, true );
	$redoneItem->integrate( $transaction, 0 );
	return $redoneItem;
}

/**
 * @param Utils\Transaction $tr        Transaction.
 * @param Utils\UndoManager $um        Undo manager.
 * @param Utils\StackItem   $stackItem Stack item.
 * @return void
 */
function clearUndoManagerStackItem( Utils\Transaction $tr, Utils\UndoManager $um, Utils\StackItem $stackItem ): void {
	iterateDeletedStructs(
		$tr,
		$stackItem->deletions,
		static function ( $item ) use ( $tr, $um ): void {
			if ( $item instanceof Structs\Item && $um->scopeContainsItem( $tr->doc, $item ) ) {
				keepItem( $item, false );
			}
		}
	);
}

/**
 * @param Utils\UndoManager          $undoManager Undo manager.
 * @param array<int,Utils\StackItem> $stack       Stack, passed by reference.
 * @param string                     $eventType   Event type.
 * @return Utils\StackItem|null
 */
function popStackItem( Utils\UndoManager $undoManager, array &$stack, string $eventType ): ?Utils\StackItem {
	$_tr = null;
	$doc = $undoManager->doc;
		transact(
			$doc,
			function ( Utils\Transaction $transaction ) use ( $undoManager, &$stack, &$_tr ): void {
				while ( null === $undoManager->currStackItem ) {
					$stackItem = array_pop( $stack );
					if ( null === $stackItem ) {
						break;
					}
					$store           = $transaction->doc->store;
					$itemsToRedo     = new \SplObjectStorage();
					$itemsToDelete   = array();
					$performedChange = false;

					iterateDeletedStructs(
						$transaction,
						$stackItem->insertions,
						static function ( $struct ) use ( $transaction, $store, $undoManager, &$itemsToDelete ): void {
							if ( $struct instanceof Structs\Item ) {
								if ( null !== $struct->redone ) {
									$res = followRedone( $store, $struct->id );
									if ( $res['diff'] > 0 ) {
										$res['item'] = getItemCleanStart( $transaction, createID( $res['item']->id->client, $res['item']->id->clock + $res['diff'] ) );
									}
									$struct = $res['item'];
								}
								if ( $struct instanceof Structs\Item && ! $struct->deleted && $undoManager->scopeContainsItem( $transaction->doc, $struct ) ) {
									$itemsToDelete[] = $struct;
								}
							}
						}
					);

					iterateDeletedStructs(
						$transaction,
						$stackItem->deletions,
						static function ( $struct ) use ( $transaction, $undoManager, $stackItem, $itemsToRedo ): void {
							if (
							$struct instanceof Structs\Item &&
							$undoManager->scopeContainsItem( $transaction->doc, $struct ) &&
							! isDeleted( $stackItem->insertions, $struct->id )
							) {
								$itemsToRedo->attach( $struct );
							}
						}
					);

					foreach ( $itemsToRedo as $struct ) {
						$performedChange = null !== redoItem( $transaction, $struct, $itemsToRedo, $stackItem->insertions, $undoManager->ignoreRemoteMapChanges, $undoManager ) || $performedChange;
					}
					for ( $i = count( $itemsToDelete ) - 1; $i >= 0; $i-- ) {
						$item = $itemsToDelete[ $i ];
						if ( ( $undoManager->deleteFilter )( $item ) ) {
							$item->delete( $transaction );
							$performedChange = true;
						}
					}
					$undoManager->currStackItem = $performedChange ? $stackItem : null;
				}
				foreach ( $transaction->changed as $type ) {
					$subProps = $transaction->changed[ $type ];
					if ( in_array( null, $subProps, true ) && $type->_searchMarker ) {
						$type->_searchMarker = array();
					}
				}
				$_tr = $transaction;
			},
			$undoManager
		);
	$res = $undoManager->currStackItem;
	if ( null !== $res && null !== $_tr ) {
		$undoManager->currStackItem = null;
		$undoManager->emit(
			'stack-item-popped',
			array(
				array(
					'stackItem'          => $res,
					'type'               => $eventType,
					'changedParentTypes' => $_tr->changedParentTypes,
					'origin'             => $undoManager,
				),
				$undoManager,
			)
		);
	}
	return $res;
}
