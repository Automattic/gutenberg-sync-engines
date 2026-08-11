<?php
/**
 * Time helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Small time helper collection.
 */
final class Time {
	/**
	 * @return \DateTimeImmutable
	 */
	public static function getDate(): \DateTimeImmutable {
		return new \DateTimeImmutable();
	}

	/**
	 * @return int
	 */
	public static function getUnixTime(): int {
		return (int) floor( microtime( true ) * 1000 );
	}
}
