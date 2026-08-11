<?php
/**
 * Delete-set decoder V2.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

use Yjs\Lib0\Decoder;
use Yjs\Lib0\Decoding;

/**
 * Port of DSDecoderV2 from yjs/src/utils/UpdateDecoder.js.
 */
class DSDecoderV2 {
	/**
	 * Decodes all non-optimized data.
	 *
	 * @var Decoder
	 */
	public Decoder $restDecoder;

	/**
	 * @var int
	 */
	private int $dsCurrVal = 0;

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
		$this->dsCurrVal = 0;
	}

	/**
	 * @return int
	 */
	public function readDsClock(): int {
		$this->dsCurrVal += Decoding::readVarUint( $this->restDecoder );
		return $this->dsCurrVal;
	}

	/**
	 * @return int
	 */
	public function readDsLen(): int {
		$diff             = Decoding::readVarUint( $this->restDecoder ) + 1;
		$this->dsCurrVal += $diff;
		return $diff;
	}
}
