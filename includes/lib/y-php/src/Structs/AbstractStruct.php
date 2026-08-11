<?php
/**
 * Abstract CRDT struct.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

use Yjs\Lib0\Error;
use Yjs\Utils\ID;

/**
 * Port of yjs/src/structs/AbstractStruct.js.
 */
class AbstractStruct {
	/**
	 * Struct id.
	 *
	 * @var ID
	 */
	public ID $id;

	/**
	 * Struct length.
	 *
	 * @var int
	 */
	public int $length;

	/**
	 * @param ID  $id     Struct id.
	 * @param int $length Struct length.
	 */
	public function __construct( ID $id, int $length ) {
		$this->id     = $id;
		$this->length = $length;
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		if ( 'deleted' === $name ) {
			return $this->getDeleted();
		}

		unset( $name );
		throw Error::create( 'Undefined property.' );
	}

	/**
	 * @return bool
	 */
	protected function getDeleted(): bool {
		Error::methodUnimplemented();
		return false;
	}

	/**
	 * @param AbstractStruct $right Struct to the right.
	 * @return bool
	 */
	public function mergeWith( AbstractStruct $right ): bool {
		unset( $right );
		return false;
	}

	/**
	 * @param mixed $encoder     Encoder.
	 * @param int   $offset      Struct offset.
	 * @param int   $encodingRef Encoding ref.
	 * @return void
	 */
	public function write( $encoder, int $offset, int $encodingRef = 0 ): void {
		unset( $encoder, $offset, $encodingRef );
		Error::methodUnimplemented();
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param int   $offset      Struct offset.
	 * @return void
	 */
	public function integrate( $transaction, int $offset ): void {
		unset( $transaction, $offset );
		Error::methodUnimplemented();
	}
}
