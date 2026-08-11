<?php
/**
 * Arbitrary item content.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

/**
 * Port of yjs/src/structs/ContentAny.js.
 */
class ContentAny {
	/**
	 * @var array<int,mixed>
	 */
	public array $arr;

	/**
	 * @param array<int,mixed> $arr Content array.
	 */
	public function __construct( array $arr ) {
		$this->arr = array_map( '\Yjs\copyJsonValue', $arr );
	}

	/**
	 * @return int
	 */
	public function getLength(): int {
		return count( $this->arr );
	}

	/**
	 * @return array<int,mixed>
	 */
	public function getContent(): array {
		return array_map( '\Yjs\copyJsonValue', $this->arr );
	}

	/**
	 * @return bool
	 */
	public function isCountable(): bool {
		return true;
	}

	/**
	 * @return ContentAny
	 */
	public function copy(): ContentAny {
		return new ContentAny( $this->arr );
	}

	/**
	 * @param int $offset Offset.
	 * @return ContentAny
	 */
	public function splice( int $offset ): ContentAny {
		$right     = new ContentAny( array_slice( $this->arr, $offset ) );
		$this->arr = array_slice( $this->arr, 0, $offset );
		return $right;
	}

	/**
	 * @param ContentAny $right Right content.
	 * @return bool
	 */
	public function mergeWith( ContentAny $right ): bool {
		$this->arr = array_map( '\Yjs\copyJsonValue', array_merge( $this->arr, $right->arr ) );
		return true;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param Item  $item        Item.
	 * @return void
	 */
	public function integrate( $transaction, Item $item ): void {
		unset( $transaction, $item );
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
		$len = count( $this->arr );
		$encoder->writeLen( $len - $offset );
		for ( $i = $offset; $i < $len; $i++ ) {
			$encoder->writeAny( $this->arr[ $i ] );
		}
	}

	/**
	 * @return int
	 */
	public function getRef(): int {
		return 8;
	}
}
