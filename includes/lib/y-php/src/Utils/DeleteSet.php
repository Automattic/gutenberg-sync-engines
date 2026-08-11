<?php
/**
 * Delete set.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Port of yjs/src/utils/DeleteSet.js.
 */
class DeleteSet {
	/**
	 * Delete items keyed by client id.
	 *
	 * @var array<int,array<int,DeleteItem>>
	 */
	public array $clients = array();
}
