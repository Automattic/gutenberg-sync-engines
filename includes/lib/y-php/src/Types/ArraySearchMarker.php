<?php
/**
 * Array search marker.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

use Yjs\Structs\Item;

/**
 * Port of yjs/src/types/AbstractType.js ArraySearchMarker.
 */
class ArraySearchMarker {
	/**
	 * @var Item
	 */
	public Item $p;

	/**
	 * @var int
	 */
	public int $index;

	/**
	 * @var int
	 */
	public int $timestamp;

	/**
	 * @param Item $p     Item at marker.
	 * @param int  $index Visible index.
	 */
	public function __construct( Item $p, int $index ) {
		$p->marker       = true;
		$this->p         = $p;
		$this->index     = $index;
		$this->timestamp = \Yjs\nextSearchMarkerTimestamp();
	}
}
