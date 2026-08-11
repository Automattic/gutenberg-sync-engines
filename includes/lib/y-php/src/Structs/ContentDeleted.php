<?php
/**
 * Deleted item content.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

/**
 * Port of yjs/src/structs/ContentDeleted.js.
 */
class ContentDeleted {
	/**
	 * @var int
	 */
	public int $len;

	/**
	 * @param int $len Deleted length.
	 */
	public function __construct( int $len ) {
		$this->len = $len;
	}

	/**
	 * @return int
	 */
	public function getLength(): int {
		return $this->len;
	}

	/**
	 * @return array<int,mixed>
	 */
	public function getContent(): array {
		return array();
	}

	/**
	 * @return bool
	 */
	public function isCountable(): bool {
		return false;
	}

	/**
	 * @return ContentDeleted
	 */
	public function copy(): ContentDeleted {
		return new ContentDeleted( $this->len );
	}

	/**
	 * @param int $offset Offset.
	 * @return ContentDeleted
	 */
	public function splice( int $offset ): ContentDeleted {
		$right     = new ContentDeleted( $this->len - $offset );
		$this->len = $offset;
		return $right;
	}

	/**
	 * @param ContentDeleted $right Right content.
	 * @return bool
	 */
	public function mergeWith( ContentDeleted $right ): bool {
		$this->len += $right->len;
		return true;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param Item  $item        Item.
	 * @return void
	 */
	public function integrate( $transaction, Item $item ): void {
		\Yjs\addToDeleteSet( $transaction->deleteSet, $item->id->client, $item->id->clock, $this->len );
		$item->markDeleted();
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @return void
	 */
	public function delete( $transaction ): void {
		unset( $transaction );
	}

	/**
	 * @param mixed $store Struct store.
	 * @return void
	 */
	public function gc( $store ): void {
		unset( $store );
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @param int   $offset  Offset.
	 * @return void
	 */
	public function write( $encoder, int $offset ): void {
		$encoder->writeLen( $this->len - $offset );
	}

	/**
	 * @return int
	 */
	public function getRef(): int {
		return 1;
	}
}
