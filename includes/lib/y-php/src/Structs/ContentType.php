<?php
/**
 * Nested type item content.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

use Yjs\Lib0\Error;

/**
 * Port of yjs/src/structs/ContentType.js.
 */
class ContentType {
	/**
	 * @var object
	 */
	public object $type;

	/**
	 * @param object $type Nested shared type.
	 */
	public function __construct( object $type ) {
		$this->type = $type;
	}

	/**
	 * @return int
	 */
	public function getLength(): int {
		return 1;
	}

	/**
	 * @return array<int,object>
	 */
	public function getContent(): array {
		return array( $this->type );
	}

	/**
	 * @return bool
	 */
	public function isCountable(): bool {
		return true;
	}

	/**
	 * @return ContentType
	 */
	public function copy(): ContentType {
		return new ContentType( $this->type->_copy() );
	}

	/**
	 * @param int $offset Offset.
	 * @return ContentType
	 */
	public function splice( int $offset ): ContentType {
		unset( $offset );
		Error::methodUnimplemented();
		return new ContentType( $this->type );
	}

	/**
	 * @param ContentType $right Right content.
	 * @return bool
	 */
	public function mergeWith( ContentType $right ): bool {
		unset( $right );
		return false;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param Item  $item        Item.
	 * @return void
	 */
	public function integrate( $transaction, Item $item ): void {
		$this->type->_integrate( $transaction->doc, $item );
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @return void
	 */
	public function delete( $transaction ): void {
		$item = $this->type->_start ?? null;
		while ( null !== $item ) {
			$this->deleteNestedItem( $transaction, $item );
			$item = $item->right;
		}

		foreach ( $this->type->_map ?? array() as $mapItem ) {
			$item = $mapItem;
			while ( null !== $item ) {
				$this->deleteNestedItem( $transaction, $item );
				$item = $item->left;
			}
		}

		self::deleteChangedType( $transaction, $this->type );
	}

	/**
	 * @param mixed $store Struct store.
	 * @return void
	 */
	public function gc( $store ): void {
		$item = $this->type->_start ?? null;
		while ( null !== $item ) {
			$item->gc( $store, true );
			$item = $item->right;
		}
		$this->type->_start = null;

		foreach ( $this->type->_map ?? array() as $mapItem ) {
			$item = $mapItem;
			while ( null !== $item ) {
				$item->gc( $store, true );
				$item = $item->left;
			}
		}
		$this->type->_map = array();
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @param int   $offset  Offset.
	 * @return void
	 */
	public function write( $encoder, int $offset ): void {
		unset( $offset );
		$this->type->_write( $encoder );
	}

	/**
	 * @return int
	 */
	public function getRef(): int {
		return 7;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param Item  $item        Nested item.
	 * @return void
	 */
	private function deleteNestedItem( $transaction, Item $item ): void {
		if ( ! $item->deleted ) {
			$item->delete( $transaction );
		} elseif ( $item->id->clock < self::transactionBeforeState( $transaction, $item->id->client ) ) {
			if ( ! isset( $transaction->_mergeStructs ) || ! is_array( $transaction->_mergeStructs ) ) {
				$transaction->_mergeStructs = array();
			}
			$transaction->_mergeStructs[] = $item;
		}
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param int   $client      Client id.
	 * @return int
	 */
	private static function transactionBeforeState( $transaction, int $client ): int {
		if ( ! is_object( $transaction ) || ! isset( $transaction->beforeState ) ) {
			return 0;
		}
		$beforeState = $transaction->beforeState;
		if ( is_array( $beforeState ) ) {
			return $beforeState[ $client ] ?? 0;
		}
		if ( is_object( $beforeState ) && method_exists( $beforeState, 'get' ) ) {
			return $beforeState->get( $client ) ?? 0;
		}
		return 0;
	}

	/**
	 * @param mixed  $transaction Transaction.
	 * @param object $type        Type.
	 * @return void
	 */
	private static function deleteChangedType( $transaction, object $type ): void {
		if ( ! is_object( $transaction ) || ! isset( $transaction->changed ) ) {
			return;
		}
		if ( $transaction->changed instanceof \SplObjectStorage ) {
			$transaction->changed->detach( $type );
			return;
		}
		if ( is_array( $transaction->changed ) ) {
			$transaction->changed = array_values(
				array_filter(
					$transaction->changed,
					static fn ( $candidate ): bool => $candidate !== $type
				)
			);
		}
	}
}
