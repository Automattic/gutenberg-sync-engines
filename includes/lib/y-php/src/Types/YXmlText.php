<?php
/**
 * YXmlText public API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

/**
 * Shared XML text type.
 */
class YXmlText extends YText {
	use \Yjs\NotImplementedTrait;

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
	 * @return YXmlText
	 */
	public function _copy(): YXmlText {
		return new YXmlText();
	}

	/**
	 * @return YXmlText
	 */
	public function clone(): YXmlText {
		$text = new YXmlText();
		$text->applyDelta( $this->toDelta() );
		return $text;
	}

	/**
	 * @param mixed ...$args Arguments.
	 * @return void
	 */
	public function toDOM( ...$args ) {
		unset( $args );
		$this->notImplemented( __METHOD__ );
	}

	public function toJSON(): string {
		return $this->toString();
	}

	public function toString(): string {
		$out = '';
		foreach ( $this->toDelta() as $delta ) {
			$nestedNodes = array();
			$attributes  = $delta['attributes'] ?? array();
			if ( $attributes instanceof \stdClass ) {
				$attributes = get_object_vars( $attributes );
			}
			if ( is_array( $attributes ) ) {
				foreach ( $attributes as $nodeName => $nodeAttrs ) {
					if ( $nodeAttrs instanceof \stdClass ) {
						$nodeAttrs = get_object_vars( $nodeAttrs );
					}
					$attrs = array();
					if ( is_array( $nodeAttrs ) ) {
						foreach ( $nodeAttrs as $key => $value ) {
							$attrs[] = array(
								'key'   => (string) $key,
								'value' => $value,
							);
						}
					}
					usort(
						$attrs,
						static fn ( array $a, array $b ): int => strcmp( $a['key'], $b['key'] )
					);
					$nestedNodes[] = array(
						'nodeName' => (string) $nodeName,
						'attrs'    => $attrs,
					);
				}
			}
			usort(
				$nestedNodes,
				static fn ( array $a, array $b ): int => strcmp( $a['nodeName'], $b['nodeName'] )
			);

			$str = '';
			foreach ( $nestedNodes as $node ) {
				$str .= '<' . $node['nodeName'];
				foreach ( $node['attrs'] as $attr ) {
					$str .= ' ' . $attr['key'] . '="' . \Yjs\xmlStringifyValue( $attr['value'] ) . '"';
				}
				$str .= '>';
			}
			$str .= \Yjs\xmlStringifyValue( $delta['insert'] ?? '' );
			for ( $i = count( $nestedNodes ) - 1; $i >= 0; $i-- ) {
				$str .= '</' . $nestedNodes[ $i ]['nodeName'] . '>';
			}
			$out .= $str;
		}
		return $out;
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @return void
	 */
	public function _write( $encoder ): void {
		$encoder->writeTypeRef( 6 );
	}
}
