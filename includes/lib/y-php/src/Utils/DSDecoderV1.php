<?php
/**
 * Delete-set decoder V1.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

use Yjs\Lib0\Decoder;
use Yjs\Lib0\Decoding;

/**
 * Port of DSDecoderV1 from yjs/src/utils/UpdateDecoder.js.
 */
class DSDecoderV1 {
	/**
	 * Decodes all V1 data directly.
	 *
	 * @var Decoder
	 */
	public Decoder $restDecoder;

	/**
	 * @param Decoder $decoder Decoder.
	 */
	public function __construct( Decoder $decoder ) {
		$this->restDecoder = $decoder;
	}

	/**
	 * @return void
	 */
	public function resetDsCurVal(): void {
		// nop.
	}

	/**
	 * @return int
	 */
	public function readDsClock(): int {
		return Decoding::readVarUint( $this->restDecoder );
	}

	/**
	 * @return int
	 */
	public function readDsLen(): int {
		return Decoding::readVarUint( $this->restDecoder );
	}
}
