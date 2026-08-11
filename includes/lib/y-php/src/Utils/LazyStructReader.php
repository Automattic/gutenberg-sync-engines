<?php
/**
 * Lazy update struct reader.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Port of yjs/src/utils/updates.js LazyStructReader.
 */
class LazyStructReader {
	/**
	 * @var array<int,\Yjs\Structs\AbstractStruct>
	 */
	private array $structs;

	/**
	 * @var int
	 */
	private int $index = -1;

	/**
	 * @var bool
	 */
	private bool $filterSkips;

	/**
	 * @var \Yjs\Structs\AbstractStruct|null
	 */
	public $curr = null;

	/**
	 * @var bool
	 */
	public bool $done = false;

	/**
	 * @param object $decoder     Update decoder.
	 * @param bool   $filterSkips Whether to skip Skip structs.
	 */
	public function __construct( object $decoder, bool $filterSkips ) {
		$this->structs     = \Yjs\lazyStructReaderStructs( $decoder );
		$this->filterSkips = $filterSkips;
		$this->next();
	}

	/**
	 * @return \Yjs\Structs\AbstractStruct|null
	 */
	public function next() {
		do {
			++$this->index;
			$this->curr = $this->structs[ $this->index ] ?? null;
		} while ( $this->filterSkips && null !== $this->curr && $this->curr instanceof \Yjs\Structs\Skip );

		$this->done = null === $this->curr;
		return $this->curr;
	}
}
