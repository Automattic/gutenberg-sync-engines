<?php
/**
 * Formatting item content.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

use Yjs\Lib0\Error;

/**
 * Port of yjs/src/structs/ContentFormat.js.
 */
class ContentFormat {
	/**
	 * @var string
	 */
	public string $key;

	/**
	 * @var mixed
	 */
	public $value;

	/**
	 * @param string $key   Format key.
	 * @param mixed  $value Format value.
	 */
	public function __construct( string $key, $value ) {
		$this->key   = $key;
		$this->value = $value;
	}

	/**
	 * @return int
	 */
	public function getLength(): int {
		return 1;
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
	 * @return ContentFormat
	 */
	public function copy(): ContentFormat {
		return new ContentFormat( $this->key, $this->value );
	}

	/**
	 * @param int $offset Offset.
	 * @return ContentFormat
	 */
	public function splice( int $offset ): ContentFormat {
		unset( $offset );
		Error::methodUnimplemented();
		return new ContentFormat( $this->key, $this->value );
	}

	/**
	 * @param ContentFormat $right Right content.
	 * @return bool
	 */
	public function mergeWith( ContentFormat $right ): bool {
		unset( $right );
		return false;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param Item  $item        Item.
	 * @return void
	 */
	public function integrate( $transaction, Item $item ): void {
		unset( $transaction );
		if ( is_object( $item->parent ) ) {
			$item->parent->_searchMarker  = null;
			$item->parent->_hasFormatting = true;
		}
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
		unset( $offset );
		$encoder->writeKey( $this->key );
		$encoder->writeJSON( $this->value );
	}

	/**
	 * @return int
	 */
	public function getRef(): int {
		return 6;
	}
}
