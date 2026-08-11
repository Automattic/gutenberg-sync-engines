<?php
/**
 * State-vector namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param object $decoder Decoder.
 * @return array<int,int>
 */
function readStateVector( object $decoder ): array {
	$ss       = array();
	$ssLength = Lib0\Decoding::readVarUint( $decoder->restDecoder );
	for ( $i = 0; $i < $ssLength; $i++ ) {
		$client        = Lib0\Decoding::readVarUint( $decoder->restDecoder );
		$clock         = Lib0\Decoding::readVarUint( $decoder->restDecoder );
		$ss[ $client ] = $clock;
	}
	return $ss;
}

/**
 * @param Lib0\Buffer $decodedState Encoded state vector.
 * @return array<int,int>
 */
function decodeStateVector( Lib0\Buffer $decodedState ): array {
	return readStateVector( new Utils\DSDecoderV1( Lib0\Decoding::createDecoder( $decodedState ) ) );
}

/**
 * @param object         $encoder Encoder.
 * @param array<int,int> $sv      State vector.
 * @return object
 */
function writeStateVector( object $encoder, array $sv ): object {
	Lib0\Encoding::writeVarUint( $encoder->restEncoder, count( $sv ) );
	krsort( $sv, SORT_NUMERIC );
	foreach ( $sv as $client => $clock ) {
		Lib0\Encoding::writeVarUint( $encoder->restEncoder, (int) $client );
		Lib0\Encoding::writeVarUint( $encoder->restEncoder, $clock );
	}
	return $encoder;
}

/**
 * @param object    $encoder Encoder.
 * @param Utils\Doc $doc     Document.
 * @return object
 */
function writeDocumentStateVector( object $encoder, Utils\Doc $doc ): object {
	return writeStateVector( $encoder, getStateVector( $doc->store ) );
}

/**
 * @param Utils\Doc|array<int,int> $doc Doc or state vector.
 * @param object|null              $encoder Encoder.
 * @return Lib0\Buffer
 */
function encodeStateVectorV2( $doc, ?object $encoder = null ): Lib0\Buffer {
	$encoder = $encoder ?? new Utils\DSEncoderV2();
	if ( is_array( $doc ) ) {
		writeStateVector( $encoder, $doc );
	} else {
		writeDocumentStateVector( $encoder, $doc );
	}
	return $encoder->toUint8Array();
}

/**
 * @param Utils\Doc|array<int,int> $doc Doc or state vector.
 * @return Lib0\Buffer
 */
function encodeStateVector( $doc ): Lib0\Buffer {
	return encodeStateVectorV2( $doc, new Utils\DSEncoderV1() );
}
