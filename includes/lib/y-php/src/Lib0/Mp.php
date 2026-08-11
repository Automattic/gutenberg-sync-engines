<?php
/**
 * Map helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Lightweight map helpers for PHP arrays.
 */
final class Mp {
	/**
	 * @return array<int|string,mixed>
	 */
	public static function create(): array {
		return array();
	}

	/**
	 * @param array<int|string,mixed> $map Map.
	 * @return array<int|string,mixed>
	 */
	public static function copy( array $map ): array {
		return array_slice( $map, 0, null, true );
	}

	/**
	 * @param array<int|string,mixed> $map    Map modified in place.
	 * @param int|string              $key    Key.
	 * @param callable                $create Factory.
	 * @return mixed
	 */
	public static function setIfUndefined( array &$map, $key, callable $create ) {
		if ( ! array_key_exists( $key, $map ) ) {
			$map[ $key ] = $create();
		}
		return $map[ $key ];
	}
}
