<?php
/**
 * Embed item content.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

use Yjs\Lib0\Error;

/**
 * Port of yjs/src/structs/ContentEmbed.js.
 */
class ContentEmbed {
	/**
	 * @var mixed
	 */
	public $embed;

	/**
	 * @param mixed $embed Embed content.
	 */
	public function __construct( $embed ) {
		$this->embed = $embed;
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
		return array( $this->embed );
	}

	/**
	 * @return bool
	 */
	public function isCountable(): bool {
		return true;
	}

	/**
	 * @return ContentEmbed
	 */
	public function copy(): ContentEmbed {
		return new ContentEmbed( $this->embed );
	}

	/**
	 * @param int $offset Offset.
	 * @return ContentEmbed
	 */
	public function splice( int $offset ): ContentEmbed {
		unset( $offset );
		Error::methodUnimplemented();
		return new ContentEmbed( $this->embed );
	}

	/**
	 * @param ContentEmbed $right Right content.
	 * @return bool
	 */
	public function mergeWith( ContentEmbed $right ): bool {
		unset( $right );
		return false;
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
		unset( $offset );
		$encoder->writeJSON( $this->embed );
	}

	/**
	 * @return int
	 */
	public function getRef(): int {
		return 5;
	}
}
