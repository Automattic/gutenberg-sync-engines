<?php
/**
 * Snapshot state.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Port of yjs/src/utils/Snapshot.js Snapshot.
 */
class Snapshot {
	/**
	 * @var DeleteSet
	 */
	public DeleteSet $ds;

	/**
	 * @var array<int,int>
	 */
	public array $sv;

	/**
	 * @param DeleteSet      $ds Delete set.
	 * @param array<int,int> $sv State vector.
	 */
	public function __construct( DeleteSet $ds, array $sv ) {
		$this->ds = $ds;
		$this->sv = $sv;
	}
}
