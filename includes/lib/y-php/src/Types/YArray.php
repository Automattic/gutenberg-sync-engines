<?php
/**
 * YArray shared type API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

/**
 * Shared array type.
 */
class YArray extends AbstractType implements \IteratorAggregate {
	use \Yjs\NotImplementedTrait;

	/**
	 * @var array<int,mixed>|null
	 */
	public ?array $_prelimContent;

	public function __construct() {
		parent::__construct();
		$this->_prelimContent = array();
		$this->_searchMarker  = array();
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		if ( 'length' === $name ) {
			return $this->_length;
		}
		return parent::__get( $name );
	}

	public static function from( array $items ): YArray {
		$array = new YArray();
		$array->push( $items );
		return $array;
	}

	/**
	 * @param object                 $y    Y document.
	 * @param \Yjs\Structs\Item|null $item Item.
	 * @return void
	 */
	public function _integrate( object $y, ?\Yjs\Structs\Item $item ): void {
		parent::_integrate( $y, $item );
		$this->insert( 0, $this->_prelimContent ?? array() );
		$this->_prelimContent = null;
	}

	/**
	 * @return YArray
	 */
	public function _copy(): YArray {
		return new YArray();
	}

	public function clone(): YArray {
		$arr = new YArray();
		$arr->insert(
			0,
			array_map(
				static fn ( $el ) => $el instanceof AbstractType ? $el->clone() : $el,
				$this->toArray()
			)
		);
		return $arr;
	}

	public function _callObserver( $transaction, array $parentSubs ): void {
		parent::_callObserver( $transaction, $parentSubs );
		\Yjs\callTypeObservers( $this, $transaction, new \Yjs\Types\YArrayEvent( $this, $transaction ) );
	}

	public function insert( int $index, array $content ): void {
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( $transaction ) use ( $index, $content ): void {
					\Yjs\typeListInsertGenerics( $transaction, $this, $index, $content );
				}
			);
		} else {
			array_splice( $this->_prelimContent, $index, 0, $content );
		}
	}

	public function push( array $content ): void {
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( $transaction ) use ( $content ): void {
					\Yjs\typeListPushGenerics( $transaction, $this, $content );
				}
			);
		} else {
			array_push( $this->_prelimContent, ...$content );
		}
	}

	public function unshift( array $content ): void {
		$this->insert( 0, $content );
	}

	public function delete( int $index, int $length = 1 ): void {
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( $transaction ) use ( $index, $length ): void {
					\Yjs\typeListDelete( $transaction, $this, $index, $length );
				}
			);
		} else {
			array_splice( $this->_prelimContent, $index, $length );
		}
	}

	public function get( int $index ) {
		return \Yjs\typeListGet( $this, $index );
	}

	public function toArray(): array {
		return \Yjs\typeListToArray( $this );
	}

	public function slice( int $start = 0, ?int $end = null ): array {
		return \Yjs\typeListSlice( $this, $start, $end ?? $this->_length );
	}

	public function toJSON(): array {
		return $this->map(
			static fn ( $c ) => $c instanceof AbstractType ? $c->toJSON() : $c
		);
	}

	public function map( callable $f ): array {
		return \Yjs\typeListMap( $this, $f );
	}

	public function forEach( callable $f ): void {
		\Yjs\typeListForEach( $this, $f );
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @return void
	 */
	public function _write( $encoder ): void {
		$encoder->writeTypeRef( 0 );
	}

	/**
	 * @return \Traversable
	 */
	public function getIterator(): \Traversable {
		return \Yjs\typeListCreateIterator( $this );
	}
}
