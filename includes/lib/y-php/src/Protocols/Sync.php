<?php
/**
 * Sync protocol helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Protocols;

use Yjs\Lib0\Decoder;
use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoder;
use Yjs\Lib0\Encoding;
use Yjs\Utils\Doc;

/**
 * Subset of y-protocols/sync required by the translated test helper.
 */
final class Sync {
	public const MESSAGE_YJS_SYNC_STEP1 = 0;
	public const MESSAGE_YJS_SYNC_STEP2 = 1;
	public const MESSAGE_YJS_UPDATE     = 2;

	/**
	 * @param Encoder $encoder Encoder.
	 * @param Doc     $doc     Document.
	 * @return void
	 */
	public static function writeSyncStep1( Encoder $encoder, Doc $doc ): void {
		Encoding::writeVarUint( $encoder, self::MESSAGE_YJS_SYNC_STEP1 );
		Encoding::writeVarUint8Array( $encoder, \Yjs\encodeStateVector( $doc ) );
	}

	/**
	 * @param Encoder          $encoder            Encoder.
	 * @param Doc              $doc                Document.
	 * @param \Yjs\Lib0\Buffer $encodedStateVector Encoded state vector.
	 * @return void
	 */
	public static function writeSyncStep2( Encoder $encoder, Doc $doc, ?\Yjs\Lib0\Buffer $encodedStateVector = null ): void {
		Encoding::writeVarUint( $encoder, self::MESSAGE_YJS_SYNC_STEP2 );
		Encoding::writeVarUint8Array( $encoder, \Yjs\encodeStateAsUpdate( $doc, $encodedStateVector ) );
	}

	/**
	 * @param Decoder $decoder Decoder.
	 * @param Encoder $encoder Reply encoder.
	 * @param Doc     $doc     Document.
	 * @return void
	 */
	public static function readSyncStep1( Decoder $decoder, Encoder $encoder, Doc $doc ): void {
		self::writeSyncStep2( $encoder, $doc, Decoding::readVarUint8Array( $decoder ) );
	}

	/**
	 * @param Decoder $decoder           Decoder.
	 * @param Doc     $doc               Document.
	 * @param mixed   $transactionOrigin Transaction origin.
	 * @return void
	 */
	public static function readSyncStep2( Decoder $decoder, Doc $doc, $transactionOrigin = null ): void {
		\Yjs\applyUpdate( $doc, Decoding::readVarUint8Array( $decoder ), $transactionOrigin );
	}

	/**
	 * @param Encoder          $encoder Encoder.
	 * @param \Yjs\Lib0\Buffer $update  Update.
	 * @return void
	 */
	public static function writeUpdate( Encoder $encoder, \Yjs\Lib0\Buffer $update ): void {
		Encoding::writeVarUint( $encoder, self::MESSAGE_YJS_UPDATE );
		Encoding::writeVarUint8Array( $encoder, $update );
	}

	/**
	 * @param Decoder $decoder           Decoder.
	 * @param Encoder $encoder           Reply encoder.
	 * @param Doc     $doc               Document.
	 * @param mixed   $transactionOrigin Transaction origin.
	 * @return int
	 */
	public static function readSyncMessage( Decoder $decoder, Encoder $encoder, Doc $doc, $transactionOrigin = null ): int {
		$messageType = Decoding::readVarUint( $decoder );
		switch ( $messageType ) {
			case self::MESSAGE_YJS_SYNC_STEP1:
				self::readSyncStep1( $decoder, $encoder, $doc );
				break;
			case self::MESSAGE_YJS_SYNC_STEP2:
			case self::MESSAGE_YJS_UPDATE:
				self::readSyncStep2( $decoder, $doc, $transactionOrigin );
				break;
			default:
				throw new \RuntimeException( 'Unknown message type' );
		}
		return $messageType;
	}
}
