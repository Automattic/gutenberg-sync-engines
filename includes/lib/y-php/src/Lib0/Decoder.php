<?php
/**
 * Decoding state.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Binary decoder state for lib0/decoding.
 */
final class Decoder {
	/**
	 * Decoding target.
	 *
	 * @var Buffer
	 */
	public Buffer $arr;

	/**
	 * Current decoding position.
	 *
	 * @var int
	 */
	public int $pos = 0;

	/**
	 * @param Buffer $arr Binary data.
	 */
	public function __construct( Buffer $arr ) {
		$this->arr = $arr;
	}
}
