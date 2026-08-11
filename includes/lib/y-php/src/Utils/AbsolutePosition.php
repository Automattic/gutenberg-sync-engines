<?php
/**
 * AbsolutePosition API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Absolute location resolved from a relative position.
 */
class AbsolutePosition {
	/**
	 * @var \Yjs\Types\AbstractType
	 */
	public \Yjs\Types\AbstractType $type;

	/**
	 * @var int
	 */
	public int $index;

	/**
	 * @var int
	 */
	public int $assoc;

	/**
	 * @param \Yjs\Types\AbstractType $type  Type.
	 * @param int                     $index Absolute index.
	 * @param int                     $assoc Association.
	 */
	public function __construct( \Yjs\Types\AbstractType $type, int $index, int $assoc = 0 ) {
		$this->type  = $type;
		$this->index = $index;
		$this->assoc = $assoc;
	}
}
