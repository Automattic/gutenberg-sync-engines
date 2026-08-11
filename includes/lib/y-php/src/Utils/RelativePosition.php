<?php
/**
 * RelativePosition API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Stable relative position descriptor.
 */
class RelativePosition {
	/**
	 * @var ID|null
	 */
	public ?ID $type;

	/**
	 * @var string|null
	 */
	public ?string $tname;

	/**
	 * @var ID|null
	 */
	public ?ID $item;

	/**
	 * @var int
	 */
	public int $assoc;

	/**
	 * @param ID|null     $type  Type id.
	 * @param string|null $tname Root type name.
	 * @param ID|null     $item  Item id.
	 * @param int         $assoc Association.
	 */
	public function __construct( ?ID $type, ?string $tname, ?ID $item, int $assoc = 0 ) {
		$this->type  = $type;
		$this->tname = $tname;
		$this->item  = $item;
		$this->assoc = $assoc;
	}
}
