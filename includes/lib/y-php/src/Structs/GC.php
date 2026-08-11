<?php
/**
 * Garbage-collected struct.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

use Yjs\Lib0\Encoding;

const STRUCT_GC_REF_NUMBER = 0;

/**
 * Port of yjs/src/structs/GC.js.
 */
class GC extends AbstractStruct {
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
		if ( 0 < $offset ) {
			$this->id->clock += $offset;
			$this->length    -= $offset;
		}
		\Yjs\addStruct( $transaction->doc->store, $this );
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @param int   $offset  Offset.
	 * @param int   $unused  Unused encoding ref.
	 * @return void
	 */
	public function write( $encoder, int $offset, int $unused = 0 ): void {
		unset( $unused );
		$encoder->writeInfo( STRUCT_GC_REF_NUMBER );
		$encoder->writeLen( $this->length - $offset );
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
