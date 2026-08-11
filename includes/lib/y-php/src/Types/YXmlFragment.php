<?php
/**
 * YXmlFragment public API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

/**
 * Shared XML fragment list type.
 */
class YXmlFragment extends AbstractType {
	use \Yjs\NotImplementedTrait;

	/**
	 * @var array<int,mixed>|null
	 */
	public ?array $_prelimContent;

	public function __construct() {
		parent::__construct();
		$this->_prelimContent = array();
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		if ( 'firstChild' === $name ) {
			$first = parent::__get( '_first' );
			return null !== $first ? $first->content->getContent()[0] : null;
		}
		if ( 'length' === $name ) {
			return null === $this->_prelimContent ? $this->_length : count( $this->_prelimContent );
		}
		return parent::__get( $name );
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
	 * @return YXmlFragment
	 */
	public function _copy(): YXmlFragment {
		return new YXmlFragment();
	}

	public function clone(): YXmlFragment {
		$fragment = new YXmlFragment();
		$fragment->insert(
			0,
			array_map(
				static fn ( $el ) => $el instanceof AbstractType ? $el->clone() : $el,
				$this->toArray()
			)
		);
		return $fragment;
	}

	public function createTreeWalker( ?callable $filter = null ): YXmlTreeWalker {
		return new YXmlTreeWalker( $this, $filter );
	}

	/**
	 * @param string $query Query selector.
	 * @return YXmlElement|YXmlText|YXmlHook|null
	 */
	public function querySelector( string $query ) {
		$query    = strtoupper( $query );
		$iterator = new YXmlTreeWalker(
			$this,
			static function ( $element ) use ( $query ): bool {
				return is_object( $element ) && isset( $element->nodeName ) && strtoupper( $element->nodeName ) === $query;
			}
		);
		$next     = $iterator->next();
		return $next['done'] ? null : $next['value'];
	}

	/**
	 * @param string $query Query selector.
	 * @return array<int,YXmlElement|YXmlText|YXmlHook|null>
	 */
	public function querySelectorAll( string $query ): array {
		$query = strtoupper( $query );
		return iterator_to_array(
			new YXmlTreeWalker(
				$this,
				static function ( $element ) use ( $query ): bool {
					return is_object( $element ) && isset( $element->nodeName ) && strtoupper( $element->nodeName ) === $query;
				}
			),
			false
		);
	}

	public function _callObserver( $transaction, array $parentSubs ): void {
		parent::_callObserver( $transaction, $parentSubs );
		\Yjs\callTypeObservers( $this, $transaction, new YXmlEvent( $this, $parentSubs, $transaction ) );
	}

	public function toString(): string {
		$out = '';
		foreach ( $this->toArray() as $child ) {
			$out .= is_object( $child ) && method_exists( $child, 'toString' ) ? $child->toString() : (string) $child;
		}
		return $out;
	}

	public function toJSON(): string {
		return $this->toString();
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return void
	 */
	public function toDOM( ...$args ) {
		unset( $args );
		$this->notImplemented( __METHOD__ );
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

	/**
	 * @param \Yjs\Structs\Item|AbstractType|null $ref     Reference item/type.
	 * @param array<int,mixed>                    $content Content to insert.
	 * @return void
	 */
	public function insertAfter( $ref, array $content ): void {
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( $transaction ) use ( $ref, $content ): void {
					$refItem = $ref instanceof AbstractType ? $ref->_item : $ref;
					\Yjs\typeListInsertGenericsAfter( $transaction, $this, $refItem, $content );
				}
			);
			return;
		}

		$index = 0;
		if ( null !== $ref ) {
			$index = false;
			foreach ( $this->_prelimContent ?? array() as $i => $element ) {
				if ( $element === $ref ) {
					$index = $i + 1;
					break;
				}
			}
			if ( false === $index ) {
				throw \Yjs\Lib0\Error::create( 'Reference item not found' );
			}
		}
		array_splice( $this->_prelimContent, (int) $index, 0, $content );
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

	public function toArray(): array {
		return \Yjs\typeListToArray( $this );
	}

	public function push( array $content ): void {
		$this->insert( $this->_length, $content );
	}

	public function unshift( array $content ): void {
		$this->insert( 0, $content );
	}

	public function get( int $index ) {
		return \Yjs\typeListGet( $this, $index );
	}

	public function slice( int $start = 0, ?int $end = null ): array {
		return \Yjs\typeListSlice( $this, $start, $end ?? $this->__get( 'length' ) );
	}

	public function forEach( callable $f ): void {
		\Yjs\typeListForEach( $this, $f );
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @return void
	 */
	public function _write( $encoder ): void {
		$encoder->writeTypeRef( 4 );
	}
}
