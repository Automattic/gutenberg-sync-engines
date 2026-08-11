<?php
/**
 * Minimal t-style assertion shim.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Tiny compatibility layer for translated t-style tests.
 */
final class T {
	/**
	 * @param bool   $condition Condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	public static function assert( bool $condition, string $message = '' ): void {
		Assert::assertTrue( $condition, $message );
	}

	/**
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 * @return void
	 */
	public static function compare( $expected, $actual, string $message = '' ): void {
		Assert::assertEquals( $expected, $actual, $message );
	}

	/**
	 * @param array<int|string,mixed> $expected Expected array.
	 * @param array<int|string,mixed> $actual   Actual array.
	 * @param string                  $message  Failure message.
	 * @return void
	 */
	public static function compareArrays( array $expected, array $actual, string $message = '' ): void {
		Assert::assertSame( $expected, $actual, $message );
	}

	/**
	 * @param callable $fn      Function expected to fail.
	 * @param string   $message Failure message.
	 * @return void
	 */
	public static function fails( callable $fn, string $message = '' ): void {
		$failed = false;
		try {
			$fn();
		} catch ( \Throwable $throwable ) {
			$failed = true;
		}
		Assert::assertTrue( $failed, $message );
	}
}
