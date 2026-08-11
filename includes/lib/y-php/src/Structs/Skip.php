<?php
/**
 * Skip struct.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

use Yjs\Lib0\Encoding;
use Yjs\Lib0\Error;

const STRUCT_SKIP_REF_NUMBER = 10;

/**
 * Port of yjs/src/structs/Skip.js.
 */
class Skip extends AbstractStruct {
	/**
	 * @return bool
	 */
	protected function getDeleted(): bool {
		return true;
	}

	/**
	 * @return void
	 */
	public function delete(): void {}

	/**
	 * @param AbstractStruct $right Struct to merge.
	 * @return bool
	 */
	public function mergeWith( AbstractStruct $right ): bool {
		if ( self::class !== get_class( $right ) ) {
			return false;
		}
		$this->length += $right->length;
		return true;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param int   $offset      Offset.
	 * @return void
	 */
	public function integrate( $transaction, int $offset ): void {
		unset( $transaction, $offset );
		Error::unexpectedCase();
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @param int   $offset  Offset.
	 * @param int   $unused  Unused encoding ref.
	 * @return void
	 */
	public function write( $encoder, int $offset, int $unused = 0 ): void {
		unset( $unused );
		$encoder->writeInfo( STRUCT_SKIP_REF_NUMBER );
		Encoding::writeVarUint( $encoder->restEncoder, $this->length - $offset );
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param mixed $store       Store.
	 * @return null
	 */
	public function getMissing( $transaction, $store ) {
		unset( $transaction, $store );
		return null;
	}
}
