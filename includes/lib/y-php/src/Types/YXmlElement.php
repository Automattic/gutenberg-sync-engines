<?php
/**
 * YXmlElement public API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

/**
 * Shared XML element type.
 */
class YXmlElement extends YXmlFragment {
	use \Yjs\NotImplementedTrait;

	/**
	 * @var string
	 */
	public string $nodeName;

	/**
	 * @var array<string,mixed>|null
	 */
	public ?array $_prelimAttrs;

	/**
	 * @param string $nodeName Node name.
	 */
	public function __construct( string $nodeName = 'UNDEFINED' ) {
		parent::__construct();
		$this->nodeName     = $nodeName;
		$this->_prelimAttrs = array();
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		if ( 'nextSibling' === $name ) {
			$n = null !== $this->_item ? $this->_item->next : null;
			return null !== $n ? $n->content->type : null;
		}
		if ( 'prevSibling' === $name ) {
			$n = null !== $this->_item ? $this->_item->prev : null;
			return null !== $n ? $n->content->type : null;
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
		foreach ( $this->_prelimAttrs ?? array() as $key => $value ) {
			$this->setAttribute( (string) $key, $value );
		}
		$this->_prelimAttrs = null;
	}

	/**
	 * @return YXmlElement
	 */
	public function _copy(): YXmlElement {
		return new YXmlElement( $this->nodeName );
	}

	/**
	 * @return YXmlElement
	 */
	public function clone(): YXmlElement {
		$element = new YXmlElement( $this->nodeName );
		foreach ( $this->getAttributes() as $key => $value ) {
			$element->setAttribute( (string) $key, $value );
		}
		$element->insert(
			0,
			array_map(
				static fn ( $el ) => $el instanceof AbstractType ? $el->clone() : $el,
				$this->toArray()
			)
		);
		return $element;
	}

	public function toString(): string {
		$attrs         = $this->getAttributes();
		$stringBuilder = array();
		$keys          = array_keys( $attrs );
		sort( $keys, SORT_STRING );
		foreach ( $keys as $key ) {
			$stringBuilder[] = $key . '="' . \Yjs\xmlStringifyValue( $attrs[ $key ] ) . '"';
		}
		$nodeName    = strtolower( $this->nodeName );
		$attrsString = count( $stringBuilder ) > 0 ? ' ' . implode( ' ', $stringBuilder ) : '';
		return '<' . $nodeName . $attrsString . '>' . parent::toString() . '</' . $nodeName . '>';
	}

	public function removeAttribute( string $attributeName ): void {
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( $transaction ) use ( $attributeName ): void {
					\Yjs\typeMapDelete( $transaction, $this, $attributeName );
				}
			);
		} else {
			unset( $this->_prelimAttrs[ $attributeName ] );
		}
	}

	/**
	 * @param string $attributeName  Attribute name.
	 * @param mixed  $attributeValue Attribute value.
	 * @return void
	 */
	public function setAttribute( string $attributeName, $attributeValue ): void {
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( $transaction ) use ( $attributeName, $attributeValue ): void {
					\Yjs\typeMapSet( $transaction, $this, $attributeName, $attributeValue );
				}
			);
		} else {
			$this->_prelimAttrs[ $attributeName ] = $attributeValue;
		}
	}

	public function getAttribute( string $attributeName ) {
		return \Yjs\typeMapGet( $this, $attributeName );
	}

	public function hasAttribute( string $attributeName ): bool {
		return \Yjs\typeMapHas( $this, $attributeName );
	}

	/**
	 * @param mixed|null $snapshot Snapshot.
	 * @return array<string,mixed>
	 */
	public function getAttributes( $snapshot = null ): array {
		return null !== $snapshot ? \Yjs\typeMapGetAllSnapshot( $this, $snapshot ) : \Yjs\typeMapGetAll( $this );
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return void
	 */
	public function toDOM( ...$args ) {
		unset( $args );
		$this->notImplemented( __METHOD__ );
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @return void
	 */
	public function _write( $encoder ): void {
		$encoder->writeTypeRef( 3 );
		$encoder->writeKey( $this->nodeName );
	}
}
