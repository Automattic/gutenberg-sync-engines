<?php
/**
 * Array helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Small port of lib0/array.js.
 */
final class Arr {
	/**
	 * @param array<int|string,mixed> $array Input array.
	 * @return mixed
	 */
	public static function last( array $array ) {
		return $array[ array_key_last( $array ) ];
	}

	/**
	 * @return array<int,mixed>
	 */
	public static function create(): array {
		return array();
	}

	/**
	 * @param array<int|string,mixed> $array Input array.
	 * @return array<int|string,mixed>
	 */
	public static function copy( array $array ): array {
		return array_slice( $array, 0, null, true );
	}

	/**
	 * @param array<int,mixed> $dest Destination array.
	 * @param array<int,mixed> $src  Source array.
	 * @return void
	 */
	public static function appendTo( array &$dest, array $src ): void {
		foreach ( $src as $item ) {
			$dest[] = $item;
		}
	}

	/**
	 * @param array<int|string,mixed> $array Input array.
	 * @param callable                $fn    Predicate.
	 * @return bool
	 */
	public static function every( array $array, callable $fn ): bool {
		foreach ( $array as $key => $value ) {
			if ( ! $fn( $value, $key, $array ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<int|string,mixed> $array Input array.
	 * @param callable                $fn    Predicate.
	 * @return bool
	 */
	public static function some( array $array, callable $fn ): bool {
		foreach ( $array as $key => $value ) {
			if ( $fn( $value, $key, $array ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int|string,mixed> $left  Left array.
	 * @param array<int|string,mixed> $right Right array.
	 * @return bool
	 */
	public static function equalFlat( array $left, array $right ): bool {
		return $left === $right;
	}

	/**
	 * @param array<int,array<int,mixed>> $array Input arrays.
	 * @return array<int,mixed>
	 */
	public static function flatten( array $array ): array {
		return array_merge( array(), ...$array );
	}

	/**
	 * @param int      $len Length.
	 * @param callable $fn  Mapper.
	 * @return array<int,mixed>
	 */
	public static function unfold( int $len, callable $fn ): array {
		$array = array();
		for ( $i = 0; $i < $len; $i++ ) {
			$array[ $i ] = $fn( $i, $array );
		}
		return $array;
	}

	/**
	 * @param array<int|string,mixed> $array Input array.
	 * @param mixed                   $seed  Initial value.
	 * @param callable                $fn    Reducer.
	 * @return mixed
	 */
	public static function fold( array $array, $seed, callable $fn ) {
		$result = $seed;
		foreach ( $array as $key => $value ) {
			$result = $fn( $result, $value, $key );
		}
		return $result;
	}

	/**
	 * @param array<int|string,mixed> $array Input array.
	 * @return array<int,mixed>
	 */
	public static function unique( array $array ): array {
		return array_values( array_unique( $array, SORT_REGULAR ) );
	}

	/**
	 * @param array<int|string,mixed> $array Input array.
	 * @param callable                $fn    Mapper.
	 * @return array<int,mixed>
	 */
	public static function map( array $array, callable $fn ): array {
		$result = array();
		foreach ( $array as $key => $value ) {
			$result[] = $fn( $value, $key, $array );
		}
		return $result;
	}

	/**
	 * PHP 7.4 equivalent of array_is_list.
	 *
	 * @param array<int|string,mixed> $array Input array.
	 * @return bool
	 */
	public static function isList( array $array ): bool {
		$expected = 0;
		foreach ( $array as $key => $_value ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}
}
