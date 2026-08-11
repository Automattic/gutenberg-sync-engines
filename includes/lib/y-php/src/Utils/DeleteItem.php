<?php
/**
 * Delete item.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Port of yjs/src/utils/DeleteSet.js DeleteItem.
 */
class DeleteItem {
	/**
	 * Start clock.
	 *
	 * @var int
	 */
	public int $clock;

	/**
	 * Length.
	 *
	 * @var int
	 */
	public int $len;

	/**
	 * @param int $clock Start clock.
	 * @param int $len   Length.
	 */
	public function __construct( int $clock, int $len ) {
		$this->clock = $clock;
		$this->len   = $len;
	}
}
