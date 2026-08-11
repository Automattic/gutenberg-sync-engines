<?php
/**
 * Item identifier.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Port of yjs/src/utils/ID.js.
 */
class ID {
	/**
	 * Client id.
	 *
	 * @var int
	 */
	public int $client;

	/**
	 * Unique per client id, continuous number.
	 *
	 * @var int
	 */
	public int $clock;

	/**
	 * @param int $client Client id.
	 * @param int $clock  Clock.
	 */
	public function __construct( int $client, int $clock ) {
		$this->client = $client;
		$this->clock  = $clock;
	}
}
