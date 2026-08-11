<?php
/**
 * Yjs public facade.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * Static facade mirroring the free-function exports from yjs/src/index.js.
 */
final class Yjs {
	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function getTypeChildren( ...$args ) {
		return getTypeChildren( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function createRelativePositionFromTypeIndex( ...$args ) {
		return createRelativePositionFromTypeIndex( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function createRelativePositionFromJSON( ...$args ) {
		return createRelativePositionFromJSON( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function createAbsolutePositionFromRelativePosition( ...$args ) {
		return createAbsolutePositionFromRelativePosition( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function compareRelativePositions( ...$args ) {
		return compareRelativePositions( ...$args );
	}

	/**
	 * @param int $client Client id.
	 * @param int $clock  Clock.
	 * @return Utils\ID
	 */
	public static function createID( int $client, int $clock ): Utils\ID {
		return createID( $client, $clock );
	}

	/**
	 * @param Utils\ID|null $a Left ID.
	 * @param Utils\ID|null $b Right ID.
	 * @return bool
	 */
	public static function compareIDs( ?Utils\ID $a, ?Utils\ID $b ): bool {
		return compareIDs( $a, $b );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function getState( ...$args ) {
		return getState( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function createSnapshot( ...$args ) {
		return createSnapshot( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function createDeleteSet( ...$args ) {
		return createDeleteSet( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function createDeleteSetFromStructStore( ...$args ) {
		return createDeleteSetFromStructStore( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function cleanupYTextFormatting( ...$args ) {
		return cleanupYTextFormatting( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function snapshot( ...$args ) {
		return snapshot( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function emptySnapshot( ...$args ) {
		return emptySnapshot( ...$args );
	}

	/**
	 * @param object $type AbstractType-like value.
	 * @return string
	 */
	public static function findRootTypeKey( object $type ): string {
		return findRootTypeKey( $type );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function findIndexSS( ...$args ) {
		return findIndexSS( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function getItem( ...$args ) {
		return getItem( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function getItemCleanStart( ...$args ) {
		return getItemCleanStart( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function getItemCleanEnd( ...$args ) {
		return getItemCleanEnd( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function typeListToArraySnapshot( ...$args ) {
		return typeListToArraySnapshot( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function typeMapGetSnapshot( ...$args ) {
		return typeMapGetSnapshot( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function typeMapGetAllSnapshot( ...$args ) {
		return typeMapGetAllSnapshot( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function createDocFromSnapshot( ...$args ) {
		return createDocFromSnapshot( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function iterateDeletedStructs( ...$args ) {
		return iterateDeletedStructs( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function applyUpdate( ...$args ) {
		return applyUpdate( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function applyUpdateV2( ...$args ) {
		return applyUpdateV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function readUpdate( ...$args ) {
		return readUpdate( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function readUpdateV2( ...$args ) {
		return readUpdateV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function encodeStateAsUpdate( ...$args ) {
		return encodeStateAsUpdate( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function encodeStateAsUpdateV2( ...$args ) {
		return encodeStateAsUpdateV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function encodeStateVector( ...$args ) {
		return encodeStateVector( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function decodeSnapshot( ...$args ) {
		return decodeSnapshot( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function encodeSnapshot( ...$args ) {
		return encodeSnapshot( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function decodeSnapshotV2( ...$args ) {
		return decodeSnapshotV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function encodeSnapshotV2( ...$args ) {
		return encodeSnapshotV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function decodeStateVector( ...$args ) {
		return decodeStateVector( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function logUpdate( ...$args ) {
		return logUpdate( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function logUpdateV2( ...$args ) {
		return logUpdateV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function decodeUpdate( ...$args ) {
		return decodeUpdate( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function decodeUpdateV2( ...$args ) {
		return decodeUpdateV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function relativePositionToJSON( ...$args ) {
		return relativePositionToJSON( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function isDeleted( ...$args ) {
		return isDeleted( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function isParentOf( ...$args ) {
		return isParentOf( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function equalSnapshots( ...$args ) {
		return equalSnapshots( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function tryGc( ...$args ) {
		return tryGc( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function transact( ...$args ) {
		return transact( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function logType( ...$args ) {
		logType( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function mergeUpdates( ...$args ) {
		return mergeUpdates( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function mergeUpdatesV2( ...$args ) {
		return mergeUpdatesV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function parseUpdateMeta( ...$args ) {
		return parseUpdateMeta( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function parseUpdateMetaV2( ...$args ) {
		return parseUpdateMetaV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function encodeStateVectorFromUpdate( ...$args ) {
		return encodeStateVectorFromUpdate( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function encodeStateVectorFromUpdateV2( ...$args ) {
		return encodeStateVectorFromUpdateV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function encodeRelativePosition( ...$args ) {
		return encodeRelativePosition( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function decodeRelativePosition( ...$args ) {
		return decodeRelativePosition( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function diffUpdate( ...$args ) {
		return diffUpdate( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function diffUpdateV2( ...$args ) {
		return diffUpdateV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function convertUpdateFormatV1ToV2( ...$args ) {
		return convertUpdateFormatV1ToV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function convertUpdateFormatV2ToV1( ...$args ) {
		return convertUpdateFormatV2ToV1( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function obfuscateUpdate( ...$args ) {
		return obfuscateUpdate( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function obfuscateUpdateV2( ...$args ) {
		return obfuscateUpdateV2( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function equalDeleteSets( ...$args ) {
		return equalDeleteSets( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function mergeDeleteSets( ...$args ) {
		return mergeDeleteSets( ...$args );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return mixed
	 */
	public static function snapshotContainsUpdate( ...$args ) {
		return snapshotContainsUpdate( ...$args );
	}
}
