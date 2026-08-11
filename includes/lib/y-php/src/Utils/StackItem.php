<?php
/**
 * Undo manager stack item.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Port of yjs/src/utils/UndoManager.js StackItem.
 */
class StackItem {
	/**
	 * @var DeleteSet
	 */
	public DeleteSet $insertions;

	/**
	 * @var DeleteSet
	 */
	public DeleteSet $deletions;

	/**
	 * Metadata map for consumers.
	 *
	 * @var array<mixed,mixed>
	 */
	public array $meta = array();

	/**
	 * @param DeleteSet $deletions  Deleted ranges.
	 * @param DeleteSet $insertions Inserted ranges.
	 */
	public function __construct( DeleteSet $deletions, DeleteSet $insertions ) {
		$this->insertions = $insertions;
		$this->deletions  = $deletions;
	}
}
