<?php
/**
 * Update encoding namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param object                            $encoder Encoder.
 * @param array<int,Structs\AbstractStruct> $structs Structs.
 * @param int                               $client  Client id.
 * @param int                               $clock   Clock.
 * @return void
 */
function writeStructs( object $encoder, array $structs, int $client, int $clock ): void {
	$clock           = Lib0\Math::max( $clock, $structs[0]->id->clock );
	$startNewStructs = findIndexSS( $structs, $clock );
	Lib0\Encoding::writeVarUint( $encoder->restEncoder, count( $structs ) - $startNewStructs );
	$encoder->writeClient( $client );
	Lib0\Encoding::writeVarUint( $encoder->restEncoder, $clock );
	$firstStruct = $structs[ $startNewStructs ];
	$firstStruct->write( $encoder, $clock - $firstStruct->id->clock );
	for ( $i = $startNewStructs + 1, $len = count( $structs ); $i < $len; $i++ ) {
		$structs[ $i ]->write( $encoder, 0 );
	}
}

/**
 * @param object            $encoder Encoder.
 * @param Utils\StructStore $store   Store.
 * @param array<int,int>    $_sm     Target state vector.
 * @return void
 */
function writeClientsStructs( object $encoder, Utils\StructStore $store, array $_sm ): void {
	$sm = array();
	foreach ( $_sm as $client => $clock ) {
		if ( getState( $store, (int) $client ) > $clock ) {
			$sm[ (int) $client ] = $clock;
		}
	}
	foreach ( getStateVector( $store ) as $client => $_clock ) {
		if ( ! array_key_exists( $client, $_sm ) ) {
			$sm[ (int) $client ] = 0;
		}
	}
	Lib0\Encoding::writeVarUint( $encoder->restEncoder, count( $sm ) );
	krsort( $sm, SORT_NUMERIC );
	foreach ( $sm as $client => $clock ) {
		writeStructs( $encoder, $store->clients[ $client ], (int) $client, $clock );
	}
}

/**
 * @param object    $decoder Decoder.
 * @param Utils\Doc $doc     Document.
 * @return array<int,array{i:int,refs:array<int,Structs\AbstractStruct>}>
 */
function readClientsStructRefs( object $decoder, Utils\Doc $doc ): array {
	$clientRefs        = array();
	$numOfStateUpdates = Lib0\Decoding::readVarUint( $decoder->restDecoder );
	for ( $i = 0; $i < $numOfStateUpdates; $i++ ) {
		$numberOfStructs       = Lib0\Decoding::readVarUint( $decoder->restDecoder );
		$refs                  = array();
		$client                = $decoder->readClient();
		$clock                 = Lib0\Decoding::readVarUint( $decoder->restDecoder );
		$clientRefs[ $client ] = array(
			'i'    => 0,
			'refs' => &$refs,
		);
		for ( $j = 0; $j < $numberOfStructs; $j++ ) {
			$info = $decoder->readInfo();
			switch ( Lib0\Binary::BITS5 & $info ) {
				case 0:
					$len    = $decoder->readLen();
					$refs[] = new Structs\GC( createID( $client, $clock ), $len );
					$clock += $len;
					break;
				case 10:
					$len    = Lib0\Decoding::readVarUint( $decoder->restDecoder );
					$refs[] = new Structs\Skip( createID( $client, $clock ), $len );
					$clock += $len;
					break;
				default:
					$cantCopyParentInfo = ( $info & ( Lib0\Binary::BIT7 | Lib0\Binary::BIT8 ) ) === 0;
					$struct             = new Structs\Item(
						createID( $client, $clock ),
						null,
						( $info & Lib0\Binary::BIT8 ) === Lib0\Binary::BIT8 ? $decoder->readLeftID() : null,
						null,
						( $info & Lib0\Binary::BIT7 ) === Lib0\Binary::BIT7 ? $decoder->readRightID() : null,
						$cantCopyParentInfo ? ( $decoder->readParentInfo() ? $doc->get( $decoder->readString() ) : $decoder->readLeftID() ) : null,
						$cantCopyParentInfo && ( $info & Lib0\Binary::BIT6 ) === Lib0\Binary::BIT6 ? $decoder->readString() : null,
						readItemContent( $decoder, $info )
					);
					$refs[]             = $struct;
					$clock             += $struct->length;
			}
		}
		$clientRefs[ $client ]['refs'] = $refs;
		unset( $refs );
	}
	return $clientRefs;
}

/**
 * @param Utils\Transaction                                              $transaction       Transaction.
 * @param Utils\StructStore                                              $store             Store.
 * @param array<int,array{i:int,refs:array<int,Structs\AbstractStruct>}> $clientsStructRefs Struct refs.
 * @return array{update:Lib0\Buffer,missing:array<int,int>}|null
 */
function integrateStructs( Utils\Transaction $transaction, Utils\StructStore $store, array &$clientsStructRefs ): ?array {
	$stack                = array();
	$clientsStructRefsIds = array_keys( $clientsStructRefs );
	sort( $clientsStructRefsIds, SORT_NUMERIC );
	if ( 0 === count( $clientsStructRefsIds ) ) {
		return null;
	}
	$getNextStructTarget = static function () use ( &$clientsStructRefsIds, &$clientsStructRefs ): ?array {
		if ( 0 === count( $clientsStructRefsIds ) ) {
			return null;
		}
			$nextClient        = $clientsStructRefsIds[ count( $clientsStructRefsIds ) - 1 ];
			$nextStructsTarget = $clientsStructRefs[ $nextClient ];
			$nextRefsCount     = count( $nextStructsTarget['refs'] );
		while ( $nextRefsCount === $nextStructsTarget['i'] ) {
			array_pop( $clientsStructRefsIds );
			if ( 0 < count( $clientsStructRefsIds ) ) {
				$nextClient        = $clientsStructRefsIds[ count( $clientsStructRefsIds ) - 1 ];
				$nextStructsTarget = $clientsStructRefs[ $nextClient ];
				$nextRefsCount     = count( $nextStructsTarget['refs'] );
			} else {
				return null;
			}
		}
		return array( 'client' => $nextClient );
	};
	$target              = $getNextStructTarget();
	if ( null === $target ) {
		return null;
	}
	$restStructs      = new Utils\StructStore();
	$missingSV        = array();
	$updateMissingSv  = static function ( int $client, int $clock ) use ( &$missingSV ): void {
		if ( ! array_key_exists( $client, $missingSV ) || $missingSV[ $client ] > $clock ) {
			$missingSV[ $client ] = $clock;
		}
	};
	$nextFromTarget   = static function ( int $client ) use ( &$clientsStructRefs ) {
		$index                             = $clientsStructRefs[ $client ]['i'];
		$clientsStructRefs[ $client ]['i'] = $index + 1;
		return $clientsStructRefs[ $client ]['refs'][ $index ];
	};
	$stackHead        = $nextFromTarget( $target['client'] );
	$state            = array();
	$addStackToRestSS = static function () use ( &$stack, &$clientsStructRefs, &$clientsStructRefsIds, $restStructs ): void {
		foreach ( $stack as $item ) {
			$client = $item->id->client;
			if ( array_key_exists( $client, $clientsStructRefs ) ) {
				--$clientsStructRefs[ $client ]['i'];
				$restStructs->clients[ $client ] = array_slice( $clientsStructRefs[ $client ]['refs'], $clientsStructRefs[ $client ]['i'] );
				unset( $clientsStructRefs[ $client ] );
			} else {
				$restStructs->clients[ $client ] = array( $item );
			}
			$clientsStructRefsIds = array_values(
				array_filter(
					$clientsStructRefsIds,
					static fn ( int $c ): bool => $c !== $client
				)
			);
		}
		$stack = array();
	};

	while ( true ) {
		if ( ! $stackHead instanceof Structs\Skip ) {
			$client = $stackHead->id->client;
			if ( ! array_key_exists( $client, $state ) ) {
				$state[ $client ] = getState( $store, $client );
			}
			$localClock = $state[ $client ];
			$offset     = $localClock - $stackHead->id->clock;
			if ( $offset < 0 ) {
				$stack[] = $stackHead;
				$updateMissingSv( $stackHead->id->client, $stackHead->id->clock - 1 );
				$addStackToRestSS();
			} else {
				$missing = $stackHead->getMissing( $transaction, $store );
				if ( null !== $missing ) {
					$stack[] = $stackHead;
					if ( ! array_key_exists( $missing, $clientsStructRefs ) || count( $clientsStructRefs[ $missing ]['refs'] ) === $clientsStructRefs[ $missing ]['i'] ) {
						$updateMissingSv( $missing, getState( $store, $missing ) );
						$addStackToRestSS();
					} else {
						$stackHead = $nextFromTarget( $missing );
						continue;
					}
				} elseif ( 0 === $offset || $offset < $stackHead->length ) {
					$stackHead->integrate( $transaction, $offset );
					$state[ $stackHead->id->client ] = $stackHead->id->clock + $stackHead->length;
				}
			}
		}
		if ( 0 < count( $stack ) ) {
			$stackHead = array_pop( $stack );
		} elseif ( null !== $target && $clientsStructRefs[ $target['client'] ]['i'] < count( $clientsStructRefs[ $target['client'] ]['refs'] ) ) {
			$stackHead = $nextFromTarget( $target['client'] );
		} else {
			$target = $getNextStructTarget();
			if ( null === $target ) {
				break;
			}
			$stackHead = $nextFromTarget( $target['client'] );
		}
	}
	if ( 0 < count( $restStructs->clients ) ) {
		$encoder = new Utils\UpdateEncoderV1();
		writeClientsStructs( $encoder, $restStructs, array() );
		Lib0\Encoding::writeVarUint( $encoder->restEncoder, 0 );
		return array(
			'missing' => $missingSV,
			'update'  => $encoder->toUint8Array(),
		);
	}
	return null;
}

/**
 * @param object            $encoder     Encoder.
 * @param Utils\Transaction $transaction Transaction.
 * @return void
 */
function writeStructsFromTransaction( object $encoder, Utils\Transaction $transaction ): void {
	writeClientsStructs( $encoder, $transaction->doc->store, $transaction->beforeState );
}

/**
 * @param Utils\Doc   $ydoc              Document.
 * @param Lib0\Buffer $update            Update.
 * @param mixed       $transactionOrigin Origin.
 * @return void
 */
function applyUpdate( Utils\Doc $ydoc, Lib0\Buffer $update, $transactionOrigin = null ): void {
	applyUpdateV2( $ydoc, $update, $transactionOrigin, Utils\UpdateDecoderV1::class );
}

/**
 * @param Utils\Doc   $ydoc              Document.
 * @param Lib0\Buffer $update            Update.
 * @param mixed       $transactionOrigin Origin.
 * @param string      $YDecoder          Decoder class.
 * @return void
 */
function applyUpdateV2( Utils\Doc $ydoc, Lib0\Buffer $update, $transactionOrigin = null, string $YDecoder = Utils\UpdateDecoderV2::class ): void {
	$decoder = Lib0\Decoding::createDecoder( $update );
	readUpdateV2( $decoder, $ydoc, $transactionOrigin, new $YDecoder( $decoder ) );
}

/**
 * @param Lib0\Decoder $decoder           Decoder.
 * @param Utils\Doc    $ydoc              Document.
 * @param mixed        $transactionOrigin Origin.
 * @return void
 */
function readUpdate( Lib0\Decoder $decoder, Utils\Doc $ydoc, $transactionOrigin = null ): void {
	readUpdateV2( $decoder, $ydoc, $transactionOrigin, new Utils\UpdateDecoderV1( $decoder ) );
}

/**
 * @param Lib0\Decoder $decoder           Decoder.
 * @param Utils\Doc    $ydoc              Document.
 * @param mixed        $transactionOrigin Origin.
 * @param object       $structDecoder     Struct decoder.
 * @return void
 */
function readUpdateV2( Lib0\Decoder $decoder, Utils\Doc $ydoc, $transactionOrigin = null, ?object $structDecoder = null ): void {
	$structDecoder = $structDecoder ?? new Utils\UpdateDecoderV2( $decoder );
	transact(
		$ydoc,
		function ( Utils\Transaction $transaction ) use ( $structDecoder ): void {
			$transaction->local    = false;
			$doc                   = $transaction->doc;
			$store                 = $doc->store;
			$ss                    = readClientsStructRefs( $structDecoder, $doc );
			$restStructs           = integrateStructs( $transaction, $store, $ss );
			$store->pendingStructs = $restStructs;
			$dsRest                = readAndApplyDeleteSet( $structDecoder, $transaction, $store );
			$store->pendingDs      = $dsRest;
		},
		$transactionOrigin,
		false
	);
}

/**
 * @param object         $encoder           Encoder.
 * @param Utils\Doc      $doc               Document.
 * @param array<int,int> $targetStateVector Target state vector.
 * @return void
 */
function writeStateAsUpdate( object $encoder, Utils\Doc $doc, array $targetStateVector = array() ): void {
	writeClientsStructs( $encoder, $doc->store, $targetStateVector );
	writeDeleteSet( $encoder, createDeleteSetFromStructStore( $doc->store ) );
}

/**
 * @param Utils\Doc        $doc                      Document.
 * @param Lib0\Buffer|null $encodedTargetStateVector Encoded target state.
 * @param object|null      $encoder                  Encoder.
 * @return Lib0\Buffer
 */
function encodeStateAsUpdateV2( Utils\Doc $doc, ?Lib0\Buffer $encodedTargetStateVector = null, ?object $encoder = null ): Lib0\Buffer {
	$targetStateVector = null === $encodedTargetStateVector ? array() : decodeStateVector( $encodedTargetStateVector );
	$encoder           = $encoder ?? new Utils\UpdateEncoderV2();
	writeStateAsUpdate( $encoder, $doc, $targetStateVector );
	return $encoder->toUint8Array();
}

/**
 * @param Utils\Doc        $doc                      Document.
 * @param Lib0\Buffer|null $encodedTargetStateVector Encoded target state.
 * @return Lib0\Buffer
 */
function encodeStateAsUpdate( Utils\Doc $doc, ?Lib0\Buffer $encodedTargetStateVector = null ): Lib0\Buffer {
	return encodeStateAsUpdateV2( $doc, $encodedTargetStateVector, new Utils\UpdateEncoderV1() );
}

/**
 * @param object $decoder Update decoder.
 * @return array<int,Structs\AbstractStruct>
 */
function lazyStructReaderStructs( object $decoder ): array {
	$structs           = array();
	$numOfStateUpdates = Lib0\Decoding::readVarUint( $decoder->restDecoder );
	for ( $i = 0; $i < $numOfStateUpdates; $i++ ) {
		$numberOfStructs = Lib0\Decoding::readVarUint( $decoder->restDecoder );
		$client          = $decoder->readClient();
		$clock           = Lib0\Decoding::readVarUint( $decoder->restDecoder );
		for ( $j = 0; $j < $numberOfStructs; $j++ ) {
			$info = $decoder->readInfo();
			if ( 10 === $info ) {
				$len       = Lib0\Decoding::readVarUint( $decoder->restDecoder );
				$structs[] = new Structs\Skip( createID( $client, $clock ), $len );
				$clock    += $len;
			} elseif ( ( Lib0\Binary::BITS5 & $info ) !== 0 ) {
				$cantCopyParentInfo = ( $info & ( Lib0\Binary::BIT7 | Lib0\Binary::BIT8 ) ) === 0;
				$struct             = new Structs\Item(
					createID( $client, $clock ),
					null,
					( $info & Lib0\Binary::BIT8 ) === Lib0\Binary::BIT8 ? $decoder->readLeftID() : null,
					null,
					( $info & Lib0\Binary::BIT7 ) === Lib0\Binary::BIT7 ? $decoder->readRightID() : null,
					$cantCopyParentInfo ? ( $decoder->readParentInfo() ? $decoder->readString() : $decoder->readLeftID() ) : null,
					$cantCopyParentInfo && ( $info & Lib0\Binary::BIT6 ) === Lib0\Binary::BIT6 ? $decoder->readString() : null,
					readItemContent( $decoder, $info )
				);
				$structs[]          = $struct;
				$clock             += $struct->length;
			} else {
				$len       = $decoder->readLen();
				$structs[] = new Structs\GC( createID( $client, $clock ), $len );
				$clock    += $len;
			}
		}
	}
	return $structs;
}

/**
 * @param Lib0\Buffer $update Update.
 * @return array{structs:array<int,Structs\AbstractStruct>,ds:Utils\DeleteSet}
 */
function decodeUpdate( Lib0\Buffer $update ): array {
	return decodeUpdateV2( $update, Utils\UpdateDecoderV1::class );
}

/**
 * @param Lib0\Buffer $update   Update.
 * @param string      $YDecoder Decoder class.
 * @return array{structs:array<int,Structs\AbstractStruct>,ds:Utils\DeleteSet}
 */
function decodeUpdateV2( Lib0\Buffer $update, string $YDecoder = Utils\UpdateDecoderV2::class ): array {
	$updateDecoder = new $YDecoder( Lib0\Decoding::createDecoder( $update ) );
	$lazyDecoder   = new Utils\LazyStructReader( $updateDecoder, false );
	$structs       = array();
	for ( $curr = $lazyDecoder->curr; null !== $curr; $curr = $lazyDecoder->next() ) {
		$structs[] = $curr;
	}
	return array(
		'structs' => $structs,
		'ds'      => readDeleteSet( $updateDecoder ),
	);
}
