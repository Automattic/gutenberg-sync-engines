<?php
/**
 * Function helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of small lib0/function.js helpers.
 */
final class Func {
	/**
	 * @param array<int,callable> $functions Functions.
	 * @param array<int,mixed>    $args      Arguments.
	 * @param int                 $i         Start index.
	 * @return void
	 */
	public static function callAll( array &$functions, array $args, int $i = 0 ): void {
		$count = count( $functions );
		try {
			for ( ; $i < $count; $i++ ) {
				$functions[ $i ]( ...$args );
				$count = count( $functions );
			}
		} finally {
			if ( $i < count( $functions ) ) {
				self::callAll( $functions, $args, $i + 1 );
			}
		}
	}

	/**
	 * @return void
	 */
	public static function nop(): void {}

	/**
	 * @param callable $fn Function.
	 * @return mixed
	 */
	public static function apply( callable $fn ) {
		return $fn();
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function id( $value ) {
		return $value;
	}

	/**
	 * @param mixed $left  Left value.
	 * @param mixed $right Right value.
	 * @return bool
	 */
	public static function equalityStrict( $left, $right ): bool {
		return $left === $right;
	}
}
