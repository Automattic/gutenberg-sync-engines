<?php
/**
 * Transaction namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param object            $encoder     Encoder.
 * @param Utils\Transaction $transaction Transaction.
 * @return bool
 */
function writeUpdateMessageFromTransaction( object $encoder, Utils\Transaction $transaction ): bool {
	$changed = 0 < count( $transaction->deleteSet->clients );
	foreach ( $transaction->afterState as $client => $clock ) {
		if ( ( $transaction->beforeState[ $client ] ?? null ) !== $clock ) {
			$changed = true;
			break;
		}
	}
	if ( ! $changed ) {
		return false;
	}
	sortAndMergeDeleteSet( $transaction->deleteSet );
	writeStructsFromTransaction( $encoder, $transaction );
	writeDeleteSet( $encoder, $transaction->deleteSet );
	return true;
}

/**
 * @param \SplObjectStorage $storage Object storage.
 * @return int
 */
function objectStorageCount( \SplObjectStorage $storage ): int {
	$count = 0;
	foreach ( $storage as $_object ) {
		++$count;
	}
	return $count;
}

/**
 * @param \SplObjectStorage $storage Object storage.
 * @return array<int,Utils\Doc>
 */
function objectStorageToArray( \SplObjectStorage $storage ): array {
	$array = array();
	foreach ( $storage as $object ) {
		$array[] = $object;
	}
	return $array;
}

/**
 * @param array<int,Utils\Transaction> $transactionCleanups Transactions.
 * @param int                          $i                   Index.
 * @return void
 */
function cleanupTransactions( array &$transactionCleanups, int $i ): void {
	if ( $i >= count( $transactionCleanups ) ) {
		return;
	}
	$transaction  = $transactionCleanups[ $i ];
	$doc          = $transaction->doc;
	$store        = $doc->store;
	$ds           = $transaction->deleteSet;
	$mergeStructs = $transaction->_mergeStructs;
	try {
		sortAndMergeDeleteSet( $ds );
		$transaction->afterState = getStateVector( $transaction->doc->store );
		$doc->emit( 'beforeObserverCalls', array( $transaction, $doc ) );
		$fs = array();
		foreach ( $transaction->changed as $itemtype ) {
			$subs = $transaction->changed[ $itemtype ];
			$fs[] = static function () use ( $itemtype, $transaction, $subs ): void {
				if ( null === $itemtype->_item || ! $itemtype->_item->deleted ) {
					$itemtype->_callObserver( $transaction, $subs );
				}
			};
		}
		$fs[] = static function () use ( &$fs, $transaction, $doc ): void {
			foreach ( $transaction->changedParentTypes as $type ) {
				$events = $transaction->changedParentTypes[ $type ];
				if ( 0 < count( $type->_dEH->l ) && ( null === $type->_item || ! $type->_item->deleted ) ) {
					$events = array_values(
						array_filter(
							$events,
							static fn ( Utils\YEvent $event ): bool => null === $event->target->_item || ! $event->target->_item->deleted
						)
					);
					foreach ( $events as $event ) {
						$event->currentTarget = $type;
						$event->_path         = null;
					}
					usort(
						$events,
						static fn ( Utils\YEvent $a, Utils\YEvent $b ): int => count( $a->path ) <=> count( $b->path )
					);
					if ( 0 < count( $events ) ) {
						$fs[] = static function () use ( $type, $events, $transaction ): void {
							callEventHandlerListeners( $type->_dEH, $events, $transaction );
						};
					}
				}
			}
			$fs[] = static function () use ( $doc, $transaction ): void {
				$doc->emit( 'afterTransaction', array( $transaction, $doc ) );
			};
			$fs[] = static function () use ( $transaction ): void {
				if ( $transaction->_needFormattingCleanup ) {
					cleanupYTextAfterTransaction( $transaction );
				}
			};
		};
		Lib0\Func::callAll( $fs, array() );
	} finally {
		if ( $doc->gc ) {
			tryGcDeleteSet( $ds, $store, $doc->gcFilter );
		}
		tryMergeDeleteSet( $ds, $store );
		foreach ( $transaction->afterState as $client => $clock ) {
			$beforeClock = $transaction->beforeState[ $client ] ?? 0;
			if ( $beforeClock !== $clock && array_key_exists( $client, $store->clients ) ) {
				$structs        =& $store->clients[ $client ];
				$firstChangePos = Lib0\Math::max( findIndexSS( $structs, $beforeClock ), 1 );
				for ( $j = count( $structs ) - 1; $j >= $firstChangePos; ) {
					$j -= 1 + tryToMergeWithLefts( $structs, $j );
				}
				unset( $structs );
			}
		}
		for ( $j = count( $mergeStructs ) - 1; $j >= 0; $j-- ) {
			$client = $mergeStructs[ $j ]->id->client;
			$clock  = $mergeStructs[ $j ]->id->clock;
			if ( ! array_key_exists( $client, $store->clients ) ) {
				continue;
			}
			$structs           =& $store->clients[ $client ];
			$replacedStructPos = findIndexSS( $structs, $clock );
			if ( $replacedStructPos + 1 < count( $structs ) && tryToMergeWithLefts( $structs, $replacedStructPos + 1 ) > 1 ) {
				unset( $structs );
				continue;
			}
			if ( $replacedStructPos > 0 ) {
				tryToMergeWithLefts( $structs, $replacedStructPos );
			}
			unset( $structs );
		}
		if ( ! $transaction->local && ( $transaction->afterState[ $doc->clientID ] ?? null ) !== ( $transaction->beforeState[ $doc->clientID ] ?? null ) ) {
			$doc->clientID = generateNewClientId();
		}
		$doc->emit( 'afterTransactionCleanup', array( $transaction, $doc ) );
		if ( $doc->hasObservers( 'update' ) ) {
			$encoder    = new Utils\UpdateEncoderV1();
			$hasContent = writeUpdateMessageFromTransaction( $encoder, $transaction );
			if ( $hasContent ) {
				$doc->emit( 'update', array( $encoder->toUint8Array(), $transaction->origin, $doc, $transaction ) );
			}
		}
		if ( $doc->hasObservers( 'updateV2' ) ) {
			$v2Encoder = new Utils\UpdateEncoderV2();
			if ( writeUpdateMessageFromTransaction( $v2Encoder, $transaction ) ) {
				$doc->emit( 'updateV2', array( $v2Encoder->toUint8Array(), $transaction->origin, $doc, $transaction ) );
			}
		}
		if ( 0 < objectStorageCount( $transaction->subdocsAdded ) || 0 < objectStorageCount( $transaction->subdocsRemoved ) || 0 < objectStorageCount( $transaction->subdocsLoaded ) ) {
			foreach ( $transaction->subdocsAdded as $subdoc ) {
				$subdoc->clientID = $doc->clientID;
				if ( null === $subdoc->collectionid ) {
					$subdoc->collectionid = $doc->collectionid;
				}
				$doc->subdocs->attach( $subdoc );
			}
			foreach ( $transaction->subdocsRemoved as $subdoc ) {
				$doc->subdocs->detach( $subdoc );
			}
			$doc->emit(
				'subdocs',
				array(
					array(
						'loaded'  => objectStorageToArray( $transaction->subdocsLoaded ),
						'added'   => objectStorageToArray( $transaction->subdocsAdded ),
						'removed' => objectStorageToArray( $transaction->subdocsRemoved ),
					),
					$doc,
					$transaction,
				)
			);
			foreach ( $transaction->subdocsRemoved as $subdoc ) {
				$subdoc->destroy();
			}
		}
		if ( count( $transactionCleanups ) <= $i + 1 ) {
			$doc->_transactionCleanups = array();
			$doc->emit( 'afterAllTransactions', array( $doc, $transactionCleanups ) );
		} else {
			cleanupTransactions( $transactionCleanups, $i + 1 );
		}
	}
}

/**
 * @param Utils\Doc $doc    Document.
 * @param callable  $f      Callback.
 * @param mixed     $origin Origin.
 * @param bool      $local  Whether transaction is local.
 * @return mixed
 */
function transact( Utils\Doc $doc, callable $f, $origin = null, bool $local = true ) {
	$transactionCleanups =& $doc->_transactionCleanups;
	$initialCall         = false;
	$result              = null;
	if ( null === $doc->_transaction ) {
		$initialCall           = true;
		$doc->_transaction     = new Utils\Transaction( $doc, $origin, $local );
		$transactionCleanups[] = $doc->_transaction;
		if ( 1 === count( $transactionCleanups ) ) {
			$doc->emit( 'beforeAllTransactions', array( $doc ) );
		}
		$doc->emit( 'beforeTransaction', array( $doc->_transaction, $doc ) );
	}
	try {
		$result = $f( $doc->_transaction );
	} finally {
		if ( $initialCall ) {
			$finishCleanup     = $doc->_transaction === $transactionCleanups[0];
			$doc->_transaction = null;
			if ( $finishCleanup ) {
				cleanupTransactions( $transactionCleanups, 0 );
			}
		}
	}
	return $result;
}
