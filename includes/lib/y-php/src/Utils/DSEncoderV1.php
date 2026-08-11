<?php
/**
 * Delete-set encoder V1.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

use Yjs\Lib0\Buffer;
use Yjs\Lib0\Encoder;
use Yjs\Lib0\Encoding;

/**
 * Port of DSEncoderV1 from yjs/src/utils/UpdateEncoder.js.
 */
class DSEncoderV1 {
	/**
	 * Encodes all V1 data directly.
	 *
	 * @var Encoder
	 */
	public Encoder $restEncoder;

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
		// nop.
	}

	/**
	 * @param int $clock Clock.
	 * @return void
	 */
	public function writeDsClock( int $clock ): void {
		Encoding::writeVarUint( $this->restEncoder, $clock );
	}

	/**
	 * @param int $len Length.
	 * @return void
	 */
	public function writeDsLen( int $len ): void {
		Encoding::writeVarUint( $this->restEncoder, $len );
	}
}
