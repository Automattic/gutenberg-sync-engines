<?php
/**
 * Binary item content.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

use Yjs\Lib0\Buffer;
use Yjs\Lib0\Error;

/**
 * Port of yjs/src/structs/ContentBinary.js.
 */
class ContentBinary {
	/**
	 * @var Buffer
	 */
	public Buffer $content;

	/**
	 * @param Buffer $content Binary content.
	 */
	public function __construct( Buffer $content ) {
		$this->content = $content;
	}

	/**
	 * @return int
	 */
	public function getLength(): int {
		return 1;
	}

	/**
	 * @return array<int,Buffer>
	 */
	public function getContent(): array {
		return array( $this->content );
	}

	/**
	 * @return bool
	 */
	public function isCountable(): bool {
		return true;
	}

	/**
	 * @return ContentBinary
	 */
	public function copy(): ContentBinary {
		return new ContentBinary( $this->content );
	}

	/**
	 * @param int $offset Offset.
	 * @return ContentBinary
	 */
	public function splice( int $offset ): ContentBinary {
		unset( $offset );
		Error::methodUnimplemented();
		return new ContentBinary( $this->content );
	}

	/**
	 * @param ContentBinary $right Right content.
	 * @return bool
	 */
	public function mergeWith( ContentBinary $right ): bool {
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
		$encoder->writeBuf( $this->content );
	}

	/**
	 * @return int
	 */
	public function getRef(): int {
		return 3;
	}
}
