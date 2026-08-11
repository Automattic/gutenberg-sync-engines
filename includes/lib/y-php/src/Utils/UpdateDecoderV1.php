<?php
/**
 * Update decoder V1.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

use Yjs\Lib0\Buffer;
use Yjs\Lib0\Decoding;
use Yjs\Lib0\Error;

/**
 * Port of UpdateDecoderV1 from yjs/src/utils/UpdateDecoder.js.
 */
class UpdateDecoderV1 extends DSDecoderV1 {
	/**
	 * @return ID
	 */
	public function readLeftID(): ID {
		return \Yjs\createID(
			Decoding::readVarUint( $this->restDecoder ),
			Decoding::readVarUint( $this->restDecoder )
		);
	}

	/**
	 * @return ID
	 */
	public function readRightID(): ID {
		return \Yjs\createID(
			Decoding::readVarUint( $this->restDecoder ),
			Decoding::readVarUint( $this->restDecoder )
		);
	}

	/**
	 * Read the next client id.
	 *
	 * @return int
	 */
	public function readClient(): int {
		return Decoding::readVarUint( $this->restDecoder );
	}

	/**
	 * @return int Unsigned 8-bit integer.
	 */
	public function readInfo(): int {
		return Decoding::readUint8( $this->restDecoder );
	}

	/**
	 * @return string
	 */
	public function readString(): string {
		return Decoding::readVarString( $this->restDecoder );
	}

	/**
	 * @return bool
	 */
	public function readParentInfo(): bool {
		return 1 === Decoding::readVarUint( $this->restDecoder );
	}

	/**
	 * @return int
	 */
	public function readTypeRef(): int {
		return Decoding::readVarUint( $this->restDecoder );
	}

	/**
	 * Write len of a struct - well suited for Opt RLE encoder.
	 *
	 * @return int
	 */
	public function readLen(): int {
		return Decoding::readVarUint( $this->restDecoder );
	}

	/**
	 * @return mixed
	 */
	public function readAny() {
		return Decoding::readAny( $this->restDecoder );
	}

	/**
	 * @return Buffer
	 */
	public function readBuf(): Buffer {
		return Decoding::readVarUint8Array( $this->restDecoder )->copyUint8Array();
	}

	/**
	 * Legacy implementation uses JSON parse. We use any-decoding in v2.
	 *
	 * @return mixed
	 */
	public function readJSON() {
		$json = Decoding::readVarString( $this->restDecoder );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode
		$value = json_decode( $json );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw Error::create( 'Unexpected JSON decode failure.' );
		}
		return $value;
	}

	/**
	 * @return string
	 */
	public function readKey(): string {
		return Decoding::readVarString( $this->restDecoder );
	}
}
