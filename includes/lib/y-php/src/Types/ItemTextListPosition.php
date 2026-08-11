<?php
/**
 * YText list cursor.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

use Yjs\Structs\ContentFormat;
use Yjs\Structs\Item;

/**
 * Mirrors ItemTextListPosition from yjs/src/types/YText.js.
 */
class ItemTextListPosition {
	/**
	 * @var Item|null
	 */
	public ?Item $left;

	/**
	 * @var Item|null
	 */
	public ?Item $right;

	/**
	 * @var int
	 */
	public int $index;

	/**
	 * @var array<string,mixed>
	 */
	public array $currentAttributes;

	/**
	 * @param Item|null           $left              Left item.
	 * @param Item|null           $right             Right item.
	 * @param int                 $index             Current text index.
	 * @param array<string,mixed> $currentAttributes Active formatting attributes.
	 */
	public function __construct( ?Item $left, ?Item $right, int $index, array $currentAttributes ) {
		$this->left              = $left;
		$this->right             = $right;
		$this->index             = $index;
		$this->currentAttributes = $currentAttributes;
	}

	/**
	 * Move to the next item.
	 *
	 * @return void
	 */
	public function forward(): void {
		if ( null === $this->right ) {
			\Yjs\Lib0\Error::unexpectedCase();
			return;
		}
		if ( $this->right->content instanceof ContentFormat ) {
			if ( ! $this->right->deleted ) {
				\Yjs\updateCurrentAttributes( $this->currentAttributes, $this->right->content );
			}
		} elseif ( ! $this->right->deleted ) {
			$this->index += $this->right->length;
		}
		$this->left  = $this->right;
		$this->right = $this->right->right;
	}
}
