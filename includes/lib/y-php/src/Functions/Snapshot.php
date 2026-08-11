<?php
/**
 * Snapshot namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param Utils\DeleteSet $ds Delete set.
 * @param array<int,int>  $sm State map.
 * @return Utils\Snapshot
 */
function createSnapshot( Utils\DeleteSet $ds, array $sm ): Utils\Snapshot {
	return new Utils\Snapshot( $ds, $sm );
}

/**
 * @return Utils\Snapshot
 */
function emptySnapshot(): Utils\Snapshot {
	return createSnapshot( createDeleteSet(), array() );
}

/**
 * @param Utils\Doc $doc Document.
 * @return Utils\Snapshot
 */
function snapshot( Utils\Doc $doc ): Utils\Snapshot {
	return createSnapshot( createDeleteSetFromStructStore( $doc->store ), getStateVector( $doc->store ) );
}

/**
 * @param Structs\Item        $item     Item.
 * @param Utils\Snapshot|null $snapshot Snapshot.
 * @return bool
 */
function isVisible( Structs\Item $item, ?Utils\Snapshot $snapshot = null ): bool {
	return null === $snapshot ? ! $item->deleted : array_key_exists( $item->id->client, $snapshot->sv ) && ( $snapshot->sv[ $item->id->client ] ?? 0 ) > $item->id->clock && ! isDeleted( $snapshot->ds, $item->id );
}

/**
 * @param Utils\Transaction $transaction Transaction.
 * @param Utils\Snapshot    $snapshot    Snapshot.
 * @return void
 */
function splitSnapshotAffectedStructs( Utils\Transaction $transaction, Utils\Snapshot $snapshot ): void {
	if ( ! array_key_exists( 'splitSnapshotAffectedStructs', $transaction->meta ) || ! $transaction->meta['splitSnapshotAffectedStructs'] instanceof \SplObjectStorage ) {
		$transaction->meta['splitSnapshotAffectedStructs'] = new \SplObjectStorage();
	}
	$meta = $transaction->meta['splitSnapshotAffectedStructs'];
	if ( $meta->contains( $snapshot ) ) {
		return;
	}
	$store = $transaction->doc->store;
	foreach ( $snapshot->sv as $client => $clock ) {
		if ( $clock < getState( $store, (int) $client ) ) {
			getItemCleanStart( $transaction, createID( (int) $client, $clock ) );
		}
	}
	iterateDeletedStructs(
		$transaction,
		$snapshot->ds,
		static function (): void {}
	);
	$meta->attach( $snapshot );
}

/**
 * @param Utils\Doc      $originDoc Origin document.
 * @param Utils\Snapshot $snapshot  Snapshot.
 * @param Utils\Doc|null $newDoc    Optional target document.
 * @return Utils\Doc
 */
function createDocFromSnapshot( Utils\Doc $originDoc, Utils\Snapshot $snapshot, ?Utils\Doc $newDoc = null ): Utils\Doc {
	if ( $originDoc->gc ) {
		throw new \RuntimeException( 'Garbage-collection must be disabled in `originDoc`!' );
	}
	$newDoc  = $newDoc ?? new Utils\Doc();
	$encoder = new Utils\UpdateEncoderV2();
	$sv      = $snapshot->sv;
	$ds      = $snapshot->ds;
	$originDoc->transact(
		function ( Utils\Transaction $transaction ) use ( $originDoc, $sv, $ds, $encoder ): void {
			$size = 0;
			foreach ( $sv as $clock ) {
				if ( $clock > 0 ) {
					++$size;
				}
			}
			Lib0\Encoding::writeVarUint( $encoder->restEncoder, $size );
			foreach ( $sv as $client => $clock ) {
				$client = (int) $client;
				if ( 0 === $clock ) {
					continue;
				}
				if ( $clock < getState( $originDoc->store, $client ) ) {
					getItemCleanStart( $transaction, createID( $client, $clock ) );
				}
				$structs         = $originDoc->store->clients[ $client ] ?? array();
				$lastStructIndex = findIndexSS( $structs, $clock - 1 );
				Lib0\Encoding::writeVarUint( $encoder->restEncoder, $lastStructIndex + 1 );
				$encoder->writeClient( $client );
				Lib0\Encoding::writeVarUint( $encoder->restEncoder, 0 );
				for ( $i = 0; $i <= $lastStructIndex; $i++ ) {
					$structs[ $i ]->write( $encoder, 0 );
				}
			}
			writeDeleteSet( $encoder, $ds );
		}
	);
	applyUpdateV2( $newDoc, $encoder->toUint8Array(), 'snapshot' );
	return $newDoc;
}

/**
 * @param Lib0\Buffer $buf Snapshot bytes.
 * @return Utils\Snapshot
 */
function decodeSnapshot( Lib0\Buffer $buf ): Utils\Snapshot {
	return decodeSnapshotV2( $buf, new Utils\DSDecoderV1( Lib0\Decoding::createDecoder( $buf ) ) );
}

/**
 * @param Utils\Snapshot $snapshot Snapshot.
 * @return Lib0\Buffer
 */
function encodeSnapshot( Utils\Snapshot $snapshot ): Lib0\Buffer {
	return encodeSnapshotV2( $snapshot, new Utils\DSEncoderV1() );
}

/**
 * @param Lib0\Buffer $buf     Snapshot bytes.
 * @param object|null $decoder Delete-set decoder.
 * @return Utils\Snapshot
 */
function decodeSnapshotV2( Lib0\Buffer $buf, ?object $decoder = null ): Utils\Snapshot {
	$decoder = $decoder ?? new Utils\DSDecoderV2( Lib0\Decoding::createDecoder( $buf ) );
	return new Utils\Snapshot( readDeleteSet( $decoder ), readStateVector( $decoder ) );
}

/**
 * @param Utils\Snapshot $snapshot Snapshot.
 * @param object|null    $encoder  Delete-set encoder.
 * @return Lib0\Buffer
 */
function encodeSnapshotV2( Utils\Snapshot $snapshot, ?object $encoder = null ): Lib0\Buffer {
	$encoder = $encoder ?? new Utils\DSEncoderV2();
	writeDeleteSet( $encoder, $snapshot->ds );
	writeStateVector( $encoder, $snapshot->sv );
	return $encoder->toUint8Array();
}

/**
 * @param Utils\Snapshot $snap1 Left snapshot.
 * @param Utils\Snapshot $snap2 Right snapshot.
 * @return bool
 */
function equalSnapshots( Utils\Snapshot $snap1, Utils\Snapshot $snap2 ): bool {
	if ( count( $snap1->sv ) !== count( $snap2->sv ) || count( $snap1->ds->clients ) !== count( $snap2->ds->clients ) ) {
		return false;
	}
	foreach ( $snap1->sv as $key => $value ) {
		if ( ! array_key_exists( $key, $snap2->sv ) || $snap2->sv[ $key ] !== $value ) {
			return false;
		}
	}
	foreach ( $snap1->ds->clients as $client => $dsitems1 ) {
		$dsitems2 = $snap2->ds->clients[ $client ] ?? array();
		if ( count( $dsitems1 ) !== count( $dsitems2 ) ) {
			return false;
		}
		for ( $i = 0, $len = count( $dsitems1 ); $i < $len; $i++ ) {
			if ( $dsitems1[ $i ]->clock !== $dsitems2[ $i ]->clock || $dsitems1[ $i ]->len !== $dsitems2[ $i ]->len ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * @param Utils\Snapshot $snapshot Snapshot.
 * @param Lib0\Buffer    $update   Update.
 * @param string         $YDecoder Decoder class.
 * @return bool
 */
function snapshotContainsUpdateV2( Utils\Snapshot $snapshot, Lib0\Buffer $update, string $YDecoder = Utils\UpdateDecoderV2::class ): bool {
	$updateDecoder = new $YDecoder( Lib0\Decoding::createDecoder( $update ) );
	$lazyDecoder   = new Utils\LazyStructReader( $updateDecoder, false );
	for ( $curr = $lazyDecoder->curr; null !== $curr; $curr = $lazyDecoder->next() ) {
		if ( ( $snapshot->sv[ $curr->id->client ] ?? 0 ) < $curr->id->clock + $curr->length ) {
			return false;
		}
	}
	$mergedDS = mergeDeleteSets( array( $snapshot->ds, readDeleteSet( $updateDecoder ) ) );
	return equalDeleteSets( $snapshot->ds, $mergedDS );
}

/**
 * @param Utils\Snapshot $snapshot Snapshot.
 * @param Lib0\Buffer    $update   Update.
 * @return bool
 */
function snapshotContainsUpdate( Utils\Snapshot $snapshot, Lib0\Buffer $update ): bool {
	return snapshotContainsUpdateV2( $snapshot, $update, Utils\UpdateDecoderV1::class );
}
