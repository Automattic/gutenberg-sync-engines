<?php
/**
 * Shared Yjs type API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

/**
 * Base class for shared Yjs types.
 */
class AbstractType {
	/**
	 * @var \Yjs\Structs\Item|null
	 */
	public ?\Yjs\Structs\Item $_item = null;

	/**
	 * @var array<string,\Yjs\Structs\Item|null>
	 */
	public array $_map = array();

	/**
	 * @var \Yjs\Structs\Item|null
	 */
	public ?\Yjs\Structs\Item $_start = null;

	/**
	 * @var object|null
	 */
	public ?object $doc = null;

	/**
	 * @var int
	 */
	public int $_length = 0;

	/**
	 * @var \Yjs\Utils\EventHandler
	 */
	public $_eH;

	/**
	 * @var \Yjs\Utils\EventHandler
	 */
	public $_dEH;

	/**
	 * @var array<int,mixed>|null
	 */
	public ?array $_searchMarker = null;

	/**
	 * @var bool
	 */
	public bool $_hasFormatting = false;

	public function __construct() {
		$this->_eH  = \Yjs\createEventHandler();
		$this->_dEH = \Yjs\createEventHandler();
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		if ( 'parent' === $name ) {
			return null !== $this->_item ? $this->_item->parent : null;
		}
		if ( '_first' === $name ) {
			$item = $this->_start;
			while ( null !== $item && $item->deleted ) {
				$item = $item->right;
			}
			return $item;
		}
		return null;
	}

	/**
	 * @param object                 $y    Y document.
	 * @param \Yjs\Structs\Item|null $item Item.
	 * @return void
	 */
	public function _integrate( object $y, ?\Yjs\Structs\Item $item ): void {
		$this->doc   = $y;
		$this->_item = $item;
	}

	/**
	 * @return AbstractType
	 */
	public function _copy(): AbstractType {
		\Yjs\Lib0\Error::methodUnimplemented();
		return new AbstractType();
	}

	public function clone() {
		\Yjs\Lib0\Error::methodUnimplemented();
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @return void
	 */
	public function _write( $encoder ): void {
		unset( $encoder );
	}

	public function _callObserver( $transaction, array $_parentSubs ): void {
		unset( $_parentSubs );
		if ( ! $transaction->local && null !== $this->_searchMarker ) {
			$this->_searchMarker = array();
		}
	}

	/**
	 * @param callable $f Observer.
	 * @return void
	 */
	public function observe( callable $f ): void {
		\Yjs\addEventHandlerListener( $this->_eH, $f );
	}

	/**
	 * @param callable $f Observer.
	 * @return void
	 */
	public function observeDeep( callable $f ): void {
		\Yjs\addEventHandlerListener( $this->_dEH, $f );
	}

	/**
	 * @param callable $f Observer.
	 * @return void
	 */
	public function unobserve( callable $f ): void {
		\Yjs\removeEventHandlerListener( $this->_eH, $f );
	}

	/**
	 * @param callable $f Observer.
	 * @return void
	 */
	public function unobserveDeep( callable $f ): void {
		\Yjs\removeEventHandlerListener( $this->_dEH, $f );
	}

	/**
	 * @return mixed
	 */
	public function toJSON() {
		return null;
	}
}
