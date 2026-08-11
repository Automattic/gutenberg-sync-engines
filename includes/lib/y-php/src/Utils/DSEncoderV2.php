<?php
/**
 * Delete-set encoder V2.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

use Yjs\Lib0\Buffer;
use Yjs\Lib0\Encoder;
use Yjs\Lib0\Encoding;
use Yjs\Lib0\Error;

/**
 * Port of DSEncoderV2 from yjs/src/utils/UpdateEncoder.js.
 */
class DSEncoderV2 {
	/**
	 * Encodes all non-optimized data.
	 *
	 * @var Encoder
	 */
	public Encoder $restEncoder;

	/**
	 * @var int
	 */
	private int $dsCurrVal = 0;

	public function __construct() {
		$this->restEncoder = Encoding::createEncoder();
	}

	/**
	 * @return Buffer
	 */
	public function toUint8Array(): Buffer {
		return Encoding::toUint8Array( $this->restEncoder );
	}

	/**
	 * @return void
	 */
	public function resetDsCurVal(): void {
		$this->dsCurrVal = 0;
	}

	/**
	 * @param int $clock Clock.
	 * @return void
	 */
	public function writeDsClock( int $clock ): void {
		$diff            = $clock - $this->dsCurrVal;
		$this->dsCurrVal = $clock;
		Encoding::writeVarUint( $this->restEncoder, $diff );
	}

	/**
	 * @param int $len Length.
	 * @return void
	 */
	public function writeDsLen( int $len ): void {
		if ( 0 === $len ) {
			Error::unexpectedCase();
		}
		Encoding::writeVarUint( $this->restEncoder, $len - 1 );
		$this->dsCurrVal += $len;
	}
}
