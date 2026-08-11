<?php
/**
 * Lazy update struct writer.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Port of yjs/src/utils/updates.js LazyStructWriter.
 */
class LazyStructWriter {
	/**
	 * @var int
	 */
	public int $currClient = 0;

	/**
	 * @var int
	 */
	public int $startClock = 0;

	/**
	 * @var int
	 */
	public int $written = 0;

	/**
	 * @var object
	 */
	public object $encoder;

	/**
	 * @var array<int,array{written:int,restEncoder:\Yjs\Lib0\Buffer}>
	 */
	public array $clientStructs = array();

	/**
	 * @param object $encoder Update encoder.
	 */
	public function __construct( object $encoder ) {
		$this->encoder = $encoder;
	}
}
