<?php
/**
 * AbstractConnector API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Base connector type.
 */
class AbstractConnector extends \Yjs\Lib0\Observable {
	use \Yjs\NotImplementedTrait;

	/**
	 * @param mixed ...$args Constructor arguments.
	 */
	public function __construct( ...$args ) {
		unset( $args );
		$this->notImplemented( __METHOD__ );
	}

	/**
	 * @return void
	 */
	public function destroy(): void {
		$this->notImplemented( __METHOD__ );
	}
}
