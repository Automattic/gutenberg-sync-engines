<?php
/**
 * Iterator helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Small iterator helper collection.
 */
final class Iterator {
	/**
	 * @param iterable $iterator Iterator.
	 * @param callable $fn       Mapper.
	 * @return \Generator
	 */
	public static function mapIterator( iterable $iterator, callable $fn ): \Generator {
		foreach ( $iterator as $value ) {
			yield $fn( $value );
		}
	}

	/**
	 * @param iterable $iterator Iterator.
	 * @param callable $filter   Filter predicate.
	 * @return \Generator
	 */
	public static function iteratorFilter( iterable $iterator, callable $filter ): \Generator {
		foreach ( $iterator as $value ) {
			if ( $filter( $value ) ) {
				yield $value;
			}
		}
	}

	/**
	 * @param iterable $iterator Iterator.
	 * @param callable $fn       Mapper.
	 * @return \Generator
	 */
	public static function iteratorMap( iterable $iterator, callable $fn ): \Generator {
		foreach ( $iterator as $value ) {
			yield $fn( $value );
		}
	}
}
