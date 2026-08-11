<?php
/**
 * Delete-set namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @return Utils\DeleteSet
 */
function createDeleteSet(): Utils\DeleteSet {
	return new Utils\DeleteSet();
}

/**
 * @param Utils\StructStore $ss Struct store.
 * @return Utils\DeleteSet
 */
function createDeleteSetFromStructStore( Utils\StructStore $ss ): Utils\DeleteSet {
	$ds = createDeleteSet();
	foreach ( $ss->clients as $client => $structs ) {
		$dsitems = array();
		for ( $i = 0, $len = count( $structs ); $i < $len; $i++ ) {
			$struct = $structs[ $i ];
			if ( $struct->deleted ) {
				$clock  = $struct->id->clock;
				$delLen = $struct->length;
				while ( $i + 1 < $len && $structs[ $i + 1 ]->deleted ) {
					++$i;
					$delLen += $structs[ $i ]->length;
				}
				$dsitems[] = new Utils\DeleteItem( $clock, $delLen );
			}
		}
		if ( 0 < count( $dsitems ) ) {
			$ds->clients[ $client ] = $dsitems;
		}
	}
	return $ds;
}

/**
 * @param mixed           $transaction Transaction.
 * @param Utils\DeleteSet $ds          Delete set.
 * @param callable        $f           Callback.
 * @return void
 */
function iterateDeletedStructs( $transaction, Utils\DeleteSet $ds, callable $f ): void {
	foreach ( $ds->clients as $clientid => $deletes ) {
		if ( array_key_exists( $clientid, $transaction->doc->store->clients ) ) {
			$structs    =& $transaction->doc->store->clients[ $clientid ];
			$lastStruct = $structs[ count( $structs ) - 1 ];
			$clockState = $lastStruct->id->clock + $lastStruct->length;
			for ( $i = 0, $len = count( $deletes ); $i < $len && $deletes[ $i ]->clock < $clockState; $i++ ) {
				$del = $deletes[ $i ];
				iterateStructs( $transaction, $structs, $del->clock, $del->len, $f );
			}
			unset( $structs );
		}
	}
}

/**
 * @param array<int,Utils\DeleteItem> $dis   Delete items.
 * @param int                         $clock Clock.
 * @return int|null
 */
function findIndexDS( array $dis, int $clock ): ?int {
	$left  = 0;
	$right = count( $dis ) - 1;
	while ( $left <= $right ) {
		$midindex = Lib0\Math::floor( ( $left + $right ) / 2 );
		$mid      = $dis[ $midindex ];
		$midclock = $mid->clock;
		if ( $midclock <= $clock ) {
			if ( $clock < $midclock + $mid->len ) {
				return $midindex;
			}
			$left = $midindex + 1;
		} else {
			$right = $midindex - 1;
		}
	}
	return null;
}

/**
 * @param Utils\DeleteSet $ds Delete set.
 * @param Utils\ID        $id ID.
 * @return bool
 */
function isDeleted( Utils\DeleteSet $ds, Utils\ID $id ): bool {
	return array_key_exists( $id->client, $ds->clients ) && null !== findIndexDS( $ds->clients[ $id->client ], $id->clock );
}

/**
 * @param Utils\DeleteSet $ds Delete set.
 * @return void
 */
function sortAndMergeDeleteSet( Utils\DeleteSet $ds ): void {
	foreach ( $ds->clients as $client => $dels ) {
		usort(
			$dels,
			static fn ( Utils\DeleteItem $a, Utils\DeleteItem $b ): int => $a->clock <=> $b->clock
		);

		$count = count( $dels );
		if ( 0 === $count ) {
			$ds->clients[ $client ] = $dels;
			continue;
		}

		for ( $i = 1, $j = 1; $i < $count; $i++ ) {
			$left  = $dels[ $j - 1 ];
			$right = $dels[ $i ];
			if ( $left->clock + $left->len >= $right->clock ) {
				$dels[ $j - 1 ] = new Utils\DeleteItem( $left->clock, Lib0\Math::max( $left->len, $right->clock + $right->len - $left->clock ) );
			} else {
				if ( $j < $i ) {
					$dels[ $j ] = $right;
				}
				++$j;
			}
		}
		$ds->clients[ $client ] = array_slice( $dels, 0, $j );
	}
}

/**
 * @param Utils\DeleteSet $ds     Delete set.
 * @param int             $client Client id.
 * @param int             $clock  Clock.
 * @param int             $length Length.
 * @return void
 */
function addToDeleteSet( Utils\DeleteSet $ds, int $client, int $clock, int $length ): void {
	if ( ! array_key_exists( $client, $ds->clients ) ) {
		$ds->clients[ $client ] = array();
	}
	$ds->clients[ $client ][] = new Utils\DeleteItem( $clock, $length );
}

/**
 * @param object          $encoder Encoder.
 * @param Utils\DeleteSet $ds      Delete set.
 * @return void
 */
function writeDeleteSet( object $encoder, Utils\DeleteSet $ds ): void {
	Lib0\Encoding::writeVarUint( $encoder->restEncoder, count( $ds->clients ) );

	$entries = array();
	foreach ( $ds->clients as $client => $dsitems ) {
		$entries[] = array( (int) $client, $dsitems );
	}
	usort(
		$entries,
		static fn ( array $a, array $b ): int => $b[0] <=> $a[0]
	);

	foreach ( $entries as $entry ) {
		$client  = $entry[0];
		$dsitems = $entry[1];
		$encoder->resetDsCurVal();
		Lib0\Encoding::writeVarUint( $encoder->restEncoder, $client );
		$len = count( $dsitems );
		Lib0\Encoding::writeVarUint( $encoder->restEncoder, $len );
		for ( $i = 0; $i < $len; $i++ ) {
			$item = $dsitems[ $i ];
			$encoder->writeDsClock( $item->clock );
			$encoder->writeDsLen( $item->len );
		}
	}
}

/**
 * @param object $decoder Decoder.
 * @return Utils\DeleteSet
 */
function readDeleteSet( object $decoder ): Utils\DeleteSet {
	$ds         = new Utils\DeleteSet();
	$numClients = Lib0\Decoding::readVarUint( $decoder->restDecoder );
	for ( $i = 0; $i < $numClients; $i++ ) {
		$decoder->resetDsCurVal();
		$client          = Lib0\Decoding::readVarUint( $decoder->restDecoder );
		$numberOfDeletes = Lib0\Decoding::readVarUint( $decoder->restDecoder );
		if ( 0 < $numberOfDeletes ) {
			if ( ! array_key_exists( $client, $ds->clients ) ) {
				$ds->clients[ $client ] = array();
			}
			for ( $deleteIndex = 0; $deleteIndex < $numberOfDeletes; $deleteIndex++ ) {
				$ds->clients[ $client ][] = new Utils\DeleteItem( $decoder->readDsClock(), $decoder->readDsLen() );
			}
		}
	}
	return $ds;
}

/**
 * @param object            $decoder     Decoder.
 * @param Utils\Transaction $transaction Transaction.
 * @param Utils\StructStore $store       Store.
 * @return Lib0\Buffer|null
 */
function readAndApplyDeleteSet( object $decoder, Utils\Transaction $transaction, Utils\StructStore $store ): ?Lib0\Buffer {
	$unappliedDS = new Utils\DeleteSet();
	$numClients  = Lib0\Decoding::readVarUint( $decoder->restDecoder );
	for ( $i = 0; $i < $numClients; $i++ ) {
		$decoder->resetDsCurVal();
		$client          = Lib0\Decoding::readVarUint( $decoder->restDecoder );
		$numberOfDeletes = Lib0\Decoding::readVarUint( $decoder->restDecoder );
		$structs         = $store->clients[ $client ] ?? array();
		$state           = getState( $store, $client );
		for ( $j = 0; $j < $numberOfDeletes; $j++ ) {
			$clock    = $decoder->readDsClock();
			$clockEnd = $clock + $decoder->readDsLen();
			if ( $clock < $state ) {
				if ( $state < $clockEnd ) {
					addToDeleteSet( $unappliedDS, $client, $state, $clockEnd - $state );
				}
				if ( ! array_key_exists( $client, $store->clients ) ) {
					continue;
				}
				$structs =& $store->clients[ $client ];
				$index   = findIndexSS( $structs, $clock );
				$struct  = $structs[ $index ];
				if ( ! $struct->deleted && $struct->id->clock < $clock && $struct instanceof Structs\Item ) {
					array_splice( $structs, $index + 1, 0, array( splitItem( $transaction, $struct, $clock - $struct->id->clock ) ) );
					++$index;
				}
				$structCount = count( $structs );
				while ( $index < $structCount ) {
					$struct = $structs[ $index++ ];
					if ( $struct->id->clock < $clockEnd ) {
						if ( ! $struct->deleted && $struct instanceof Structs\Item ) {
							if ( $clockEnd < $struct->id->clock + $struct->length ) {
								array_splice( $structs, $index, 0, array( splitItem( $transaction, $struct, $clockEnd - $struct->id->clock ) ) );
								++$structCount;
							}
							$struct->delete( $transaction );
						}
					} else {
						break;
					}
				}
				unset( $structs );
			} else {
				addToDeleteSet( $unappliedDS, $client, $clock, $clockEnd - $clock );
			}
		}
	}
	if ( 0 < count( $unappliedDS->clients ) ) {
		$ds = new Utils\UpdateEncoderV1();
		Lib0\Encoding::writeVarUint( $ds->restEncoder, 0 );
		writeDeleteSet( $ds, $unappliedDS );
		return $ds->toUint8Array();
	}
	return null;
}

/**
 * @param array<int,Utils\DeleteSet> $dss Delete sets.
 * @return Utils\DeleteSet
 */
function mergeDeleteSets( array $dss ): Utils\DeleteSet {
	$merged = new Utils\DeleteSet();
	for ( $dssI = 0, $dssLen = count( $dss ); $dssI < $dssLen; $dssI++ ) {
		foreach ( $dss[ $dssI ]->clients as $client => $delsLeft ) {
			if ( ! array_key_exists( $client, $merged->clients ) ) {
				$dels = array_values( $delsLeft );
				for ( $i = $dssI + 1; $i < $dssLen; $i++ ) {
					if ( array_key_exists( $client, $dss[ $i ]->clients ) ) {
						array_push( $dels, ...$dss[ $i ]->clients[ $client ] );
					}
				}
				$merged->clients[ $client ] = $dels;
			}
		}
	}
	sortAndMergeDeleteSet( $merged );
	return $merged;
}

/**
 * @param Utils\DeleteSet $ds1 First delete set.
 * @param Utils\DeleteSet $ds2 Second delete set.
 * @return bool
 */
function equalDeleteSets( Utils\DeleteSet $ds1, Utils\DeleteSet $ds2 ): bool {
	if ( count( $ds1->clients ) !== count( $ds2->clients ) ) {
		return false;
	}
	foreach ( $ds1->clients as $client => $deleteItems1 ) {
		if ( ! array_key_exists( $client, $ds2->clients ) ) {
			return false;
		}
		$deleteItems2 = $ds2->clients[ $client ];
		if ( count( $deleteItems1 ) !== count( $deleteItems2 ) ) {
			return false;
		}
		for ( $i = 0, $len = count( $deleteItems1 ); $i < $len; $i++ ) {
			$di1 = $deleteItems1[ $i ];
			$di2 = $deleteItems2[ $i ];
			if ( $di1->clock !== $di2->clock || $di1->len !== $di2->len ) {
				return false;
			}
		}
	}
	return true;
}
