<?php
/**
 * XML tree walker.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

use Yjs\Structs\ContentType;
use Yjs\Structs\Item;

/**
 * Port of yjs/src/types/YXmlFragment.js YXmlTreeWalker.
 */
class YXmlTreeWalker implements \IteratorAggregate {
	/**
	 * @var callable
	 */
	private $filter;

	/**
	 * @var YXmlFragment|YXmlElement
	 */
	private YXmlFragment $root;

	/**
	 * @var Item|null
	 */
	private ?Item $currentNode;

	/**
	 * @var bool
	 */
	private bool $firstCall = true;

	/**
	 * @param YXmlFragment  $root   Root node.
	 * @param callable|null $filter Node filter.
	 */
	public function __construct( YXmlFragment $root, ?callable $filter = null ) {
		$this->filter      = $filter ?? static fn (): bool => true;
		$this->root        = $root;
		$this->currentNode = $root->_start;
	}

	/**
	 * @return array{value:mixed,done:bool}
	 */
	public function next(): array {
		$n      = $this->currentNode;
		$filter = $this->filter;
		$type   = self::typeFromItem( $n );

		if ( null !== $n && ( ! $this->firstCall || $n->deleted || ! $filter( $type ) ) ) {
			do {
				$type = self::typeFromItem( $n );
				if (
					! $n->deleted
					&& ( get_class( $type ) === YXmlElement::class || get_class( $type ) === YXmlFragment::class )
					&& null !== $type->_start
				) {
					$n = $type->_start;
				} else {
					while ( null !== $n ) {
						$nxt = $n->next;
						if ( null !== $nxt ) {
							$n = $nxt;
							break;
						}
						if ( $n->parent === $this->root ) {
							$n = null;
						} else {
							$n = $n->parent->_item;
						}
					}
				}
			} while ( null !== $n && ( $n->deleted || ! $filter( self::typeFromItem( $n ) ) ) );
		}

		$this->firstCall = false;
		if ( null === $n ) {
			return array(
				'value' => \Yjs\Lib0\UndefinedValue::getInstance(),
				'done'  => true,
			);
		}

		$this->currentNode = $n;
		return array(
			'value' => self::typeFromItem( $n ),
			'done'  => false,
		);
	}

	/**
	 * @return \Traversable<int,mixed>
	 */
	public function getIterator(): \Traversable {
		while ( true ) {
			$next = $this->next();
			if ( $next['done'] ) {
				return;
			}
			yield $next['value'];
		}
	}

	/**
	 * @param Item|null $item Item.
	 * @return mixed
	 */
	private static function typeFromItem( ?Item $item ) {
		if ( null === $item || ! $item->content instanceof ContentType ) {
			return null;
		}
		return $item->content->type;
	}
}
