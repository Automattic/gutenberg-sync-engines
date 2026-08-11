<?php
/**
 * Subdocument item content.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

use Yjs\Lib0\Error;
use Yjs\Utils\Doc;

/**
 * Port of yjs/src/structs/ContentDoc.js.
 */
class ContentDoc {
	/**
	 * @var Doc
	 */
	public Doc $doc;

	/**
	 * @var \stdClass
	 */
	public \stdClass $opts;

	/**
	 * @param Doc $doc Subdocument.
	 */
	public function __construct( Doc $doc ) {
		$this->doc  = $doc;
		$this->opts = new \stdClass();

		if ( ! $doc->gc ) {
			$this->opts->gc = false;
		}
		if ( $doc->autoLoad ) {
			$this->opts->autoLoad = true;
		}
		if ( null !== $doc->meta ) {
			$this->opts->meta = $doc->meta;
		}
	}

	/**
	 * @return int
	 */
	public function getLength(): int {
		return 1;
	}

	/**
	 * @return array<int,Doc>
	 */
	public function getContent(): array {
		return array( $this->doc );
	}

	/**
	 * @return bool
	 */
	public function isCountable(): bool {
		return true;
	}

	/**
	 * @return ContentDoc
	 */
	public function copy(): ContentDoc {
		return new ContentDoc( self::createDocFromOpts( $this->doc->guid, $this->opts ) );
	}

	/**
	 * @param int $offset Offset.
	 * @return ContentDoc
	 */
	public function splice( int $offset ): ContentDoc {
		unset( $offset );
		Error::methodUnimplemented();
		return new ContentDoc( $this->doc );
	}

	/**
	 * @param ContentDoc $right Right content.
	 * @return bool
	 */
	public function mergeWith( ContentDoc $right ): bool {
		unset( $right );
		return false;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param Item  $item        Item.
	 * @return void
	 */
	public function integrate( $transaction, Item $item ): void {
		$this->doc->_item = $item;
		self::addToTransactionSet( $transaction, 'subdocsAdded', $this->doc );
		if ( $this->doc->shouldLoad ) {
			self::addToTransactionSet( $transaction, 'subdocsLoaded', $this->doc );
		}
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @return void
	 */
	public function delete( $transaction ): void {
		if ( self::transactionSetContains( $transaction, 'subdocsAdded', $this->doc ) ) {
			self::deleteFromTransactionSet( $transaction, 'subdocsAdded', $this->doc );
		} else {
			self::addToTransactionSet( $transaction, 'subdocsRemoved', $this->doc );
		}
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
		$encoder->writeString( $this->doc->guid );
		$encoder->writeAny( $this->opts );
	}

	/**
	 * @return int
	 */
	public function getRef(): int {
		return 9;
	}

	/**
	 * @param string               $guid Document guid.
	 * @param array|\stdClass|null $opts Document opts.
	 * @return Doc
	 */
	public static function createDocFromOpts( string $guid, $opts ): Doc {
		$options               = self::normalizeOpts( $opts );
		$options['guid']       = $guid;
		$options['shouldLoad'] = ( ! empty( $options['shouldLoad'] ) || ! empty( $options['autoLoad'] ) );
		return new Doc( $options );
	}

	/**
	 * @param array|\stdClass|null $opts Options.
	 * @return array<string,mixed>
	 */
	private static function normalizeOpts( $opts ): array {
		if ( null === $opts ) {
			return array();
		}
		if ( $opts instanceof \stdClass ) {
			return get_object_vars( $opts );
		}
		if ( is_array( $opts ) ) {
			return $opts;
		}
		return array();
	}

	/**
	 * @param mixed  $transaction Transaction.
	 * @param string $property    Set-like property.
	 * @param Doc    $doc         Document.
	 * @return bool
	 */
	private static function transactionSetContains( $transaction, string $property, Doc $doc ): bool {
		if ( ! is_object( $transaction ) || ! isset( $transaction->{$property} ) ) {
			return false;
		}
		$set = $transaction->{$property};
		if ( $set instanceof \SplObjectStorage ) {
			return $set->contains( $doc );
		}
		return is_array( $set ) && in_array( $doc, $set, true );
	}

	/**
	 * @param mixed  $transaction Transaction.
	 * @param string $property    Set-like property.
	 * @param Doc    $doc         Document.
	 * @return void
	 */
	private static function addToTransactionSet( $transaction, string $property, Doc $doc ): void {
		if ( ! is_object( $transaction ) ) {
			return;
		}
		if ( ! isset( $transaction->{$property} ) ) {
			$transaction->{$property} = array();
		}
		if ( $transaction->{$property} instanceof \SplObjectStorage ) {
			$transaction->{$property}->attach( $doc );
		} elseif ( ! in_array( $doc, $transaction->{$property}, true ) ) {
			$transaction->{$property}[] = $doc;
		}
	}

	/**
	 * @param mixed  $transaction Transaction.
	 * @param string $property    Set-like property.
	 * @param Doc    $doc         Document.
	 * @return void
	 */
	private static function deleteFromTransactionSet( $transaction, string $property, Doc $doc ): void {
		if ( ! is_object( $transaction ) || ! isset( $transaction->{$property} ) ) {
			return;
		}
		if ( $transaction->{$property} instanceof \SplObjectStorage ) {
			$transaction->{$property}->detach( $doc );
			return;
		}
		if ( is_array( $transaction->{$property} ) ) {
			$transaction->{$property} = array_values(
				array_filter(
					$transaction->{$property},
					static fn ( $candidate ): bool => $candidate !== $doc
				)
			);
		}
	}
}
