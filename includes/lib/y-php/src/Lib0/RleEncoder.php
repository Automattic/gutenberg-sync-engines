<?php
/**
 * Basic run-length encoder.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/encoding.js RleEncoder.
 */
class RleEncoder {
	/**
	 * @var Encoder
	 */
	public Encoder $encoder;

	/**
	 * @var callable
	 */
	private $writer;

	/**
	 * @var mixed|null
	 */
	private $state = null;

	/**
	 * @var int
	 */
	private int $count = 0;

	/**
	 * @param callable $writer Value writer.
	 */
	public function __construct( callable $writer ) {
		$this->encoder = Encoding::createEncoder();
		$this->writer  = $writer;
	}

	/**
	 * @param mixed $value Value.
	 * @return void
	 */
	public function write( $value ): void {
		if ( $this->state === $value ) {
			++$this->count;
			return;
		}
		if ( $this->count > 0 ) {
			Encoding::writeVarUint( $this->encoder, $this->count - 1 );
		}
		$this->count = 1;
		$writer      = $this->writer;
		$writer( $this->encoder, $value );
		$this->state = $value;
	}

	/**
	 * @return Buffer
	 */
	public function toUint8Array(): Buffer {
		return Encoding::toUint8Array( $this->encoder );
	}
}
