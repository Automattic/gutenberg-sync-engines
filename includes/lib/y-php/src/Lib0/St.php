<?php
/**
 * Set helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Lightweight set helpers for ordered PHP arrays.
 */
final class St {
	/**
	 * @return array<int,mixed>
	 */
	public static function create(): array {
		return array();
	}

	/**
	 * @param array<int,mixed> $set Set values.
	 * @return array<int,mixed>
	 */
	public static function toArray( array $set ): array {
		return array_values( $set );
	}

	/**
	 * @param array<int,mixed> $set Set values.
	 * @return mixed|null
	 */
	public static function first( array $set ) {
		foreach ( $set as $value ) {
			return $value;
		}
		return null;
	}

	/**
	 * @param array<int,mixed> $entries Entries.
	 * @return array<int,mixed>
	 */
	public static function from( array $entries ): array {
		return array_values( array_unique( $entries, SORT_REGULAR ) );
	}
}
