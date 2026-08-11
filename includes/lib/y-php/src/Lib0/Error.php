<?php
/**
 * Error helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/error.js.
 */
final class Error {
	/**
	 * @param string $message Error message.
	 * @return \RuntimeException
	 */
	public static function create( string $message ): \RuntimeException {
		return new \RuntimeException( $message );
	}

	/**
	 * @return void
	 */
	public static function methodUnimplemented(): void {
		throw self::create( 'Method unimplemented' );
	}

	/**
	 * @return void
	 */
	public static function unexpectedCase(): void {
		throw self::create( 'Unexpected case' );
	}
}
