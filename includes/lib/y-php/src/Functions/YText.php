<?php
/**
 * YText namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param mixed $a Left attribute.
 * @param mixed $b Right attribute.
 * @return bool
 */
function equalAttrs( $a, $b ): bool {
	if ( $a === $b ) {
		return true;
	}
	if ( null === $a || null === $b ) {
		return false;
	}
	if ( ( is_array( $a ) || $a instanceof \stdClass ) && ( is_array( $b ) || $b instanceof \stdClass ) ) {
		$left  = Lib0\Obj::toArray( $a );
		$right = Lib0\Obj::toArray( $b );
		if ( count( $left ) !== count( $right ) ) {
			return false;
		}
		foreach ( $left as $key => $value ) {
			if ( ! array_key_exists( $key, $right ) || $right[ $key ] !== $value ) {
				return false;
			}
		}
		return true;
	}
	return false;
}

/**
 * @param array<string,mixed>   $currentAttributes Current attributes.
 * @param Structs\ContentFormat $format            Format item content.
 * @return void
 */
function updateCurrentAttributes( array &$currentAttributes, Structs\ContentFormat $format ): void {
	if ( null === $format->value ) {
		unset( $currentAttributes[ $format->key ] );
	} else {
		$currentAttributes[ $format->key ] = $format->value;
	}
}

/**
 * @param mixed                      $transaction Transaction.
 * @param Types\ItemTextListPosition $pos Position.
 * @param int                        $count       Count.
 * @return Types\ItemTextListPosition
 */
function findNextPosition( $transaction, Types\ItemTextListPosition $pos, int $count ): Types\ItemTextListPosition {
	while ( null !== $pos->right && $count > 0 ) {
		if ( $pos->right->content instanceof Structs\ContentFormat ) {
			if ( ! $pos->right->deleted ) {
				updateCurrentAttributes( $pos->currentAttributes, $pos->right->content );
			}
		} elseif ( ! $pos->right->deleted ) {
			if ( $count < $pos->right->length ) {
				getItemCleanStart( $transaction, createID( $pos->right->id->client, $pos->right->id->clock + $count ) );
			}
			$pos->index += $pos->right->length;
			$count      -= $pos->right->length;
		}
		$pos->left  = $pos->right;
		$pos->right = $pos->right->right;
	}
	return $pos;
}

/**
 * @param mixed              $transaction     Transaction.
 * @param Types\AbstractType $parent          Parent type.
 * @param int                $index           Index.
 * @param bool               $useSearchMarker Whether to use search markers.
 * @return Types\ItemTextListPosition
 */
function findPosition( $transaction, Types\AbstractType $parent, int $index, bool $useSearchMarker ): Types\ItemTextListPosition {
	$currentAttributes = array();
	$marker            = $useSearchMarker ? findMarker( $parent, $index ) : null;
	if ( null !== $marker ) {
		return findNextPosition( $transaction, new Types\ItemTextListPosition( $marker->p->left, $marker->p, $marker->index, $currentAttributes ), $index - $marker->index );
	}
	return findNextPosition( $transaction, new Types\ItemTextListPosition( null, $parent->_start, 0, $currentAttributes ), $index );
}

/**
 * @param Types\ItemTextListPosition $currPos    Current position.
 * @param array<string,mixed>        $attributes Attributes.
 * @return void
 */
function minimizeAttributeChanges( Types\ItemTextListPosition $currPos, array $attributes ): void {
	while ( true ) {
		if ( null === $currPos->right ) {
			break;
		}
		if ( $currPos->right->deleted ) {
			$currPos->forward();
			continue;
		}
		if ( $currPos->right->content instanceof Structs\ContentFormat ) {
			$key = $currPos->right->content->key;
			$val = array_key_exists( $key, $attributes ) && null !== $attributes[ $key ] ? $attributes[ $key ] : null;
			if ( equalAttrs( $val, $currPos->right->content->value ) ) {
				$currPos->forward();
				continue;
			}
		}
		break;
	}
}

/**
 * @param mixed                      $transaction Transaction.
 * @param Types\AbstractType         $parent      Parent type.
 * @param Types\ItemTextListPosition $currPos     Current position.
 * @param array<string,mixed>        $attributes  Attributes.
 * @return array<string,mixed>
 */
function insertAttributes( $transaction, Types\AbstractType $parent, Types\ItemTextListPosition $currPos, array $attributes ): array {
	$doc               = $transaction->doc;
	$ownClientId       = $doc->clientID;
	$negatedAttributes = array();
	foreach ( $attributes as $key => $val ) {
		$currentVal = array_key_exists( $key, $currPos->currentAttributes ) ? $currPos->currentAttributes[ $key ] : null;
		if ( ! equalAttrs( $currentVal, $val ) ) {
			$negatedAttributes[ (string) $key ] = $currentVal;
			$left                               = $currPos->left;
			$right                              = $currPos->right;
			$currPos->right                     = new Structs\Item(
				createID( $ownClientId, getState( $doc->store, $ownClientId ) ),
				$left,
				null !== $left ? $left->lastId : null,
				$right,
				null !== $right ? $right->id : null,
				$parent,
				null,
				new Structs\ContentFormat( (string) $key, $val )
			);
			$currPos->right->integrate( $transaction, 0 );
			$currPos->forward();
		}
	}
	return $negatedAttributes;
}

/**
 * @param mixed                      $transaction       Transaction.
 * @param Types\AbstractType         $parent            Parent type.
 * @param Types\ItemTextListPosition $currPos           Current position.
 * @param array<string,mixed>        $negatedAttributes Negated attributes.
 * @return void
 */
function insertNegatedAttributes( $transaction, Types\AbstractType $parent, Types\ItemTextListPosition $currPos, array $negatedAttributes ): void {
	while (
		null !== $currPos->right && (
			$currPos->right->deleted ||
			(
				$currPos->right->content instanceof Structs\ContentFormat &&
				array_key_exists( $currPos->right->content->key, $negatedAttributes ) &&
				equalAttrs( $negatedAttributes[ $currPos->right->content->key ], $currPos->right->content->value )
			)
		)
	) {
		if ( ! $currPos->right->deleted && $currPos->right->content instanceof Structs\ContentFormat ) {
			unset( $negatedAttributes[ $currPos->right->content->key ] );
		}
		$currPos->forward();
	}

	$doc         = $transaction->doc;
	$ownClientId = $doc->clientID;
	foreach ( $negatedAttributes as $key => $val ) {
		$left       = $currPos->left;
		$right      = $currPos->right;
		$nextFormat = new Structs\Item(
			createID( $ownClientId, getState( $doc->store, $ownClientId ) ),
			$left,
			null !== $left ? $left->lastId : null,
			$right,
			null !== $right ? $right->id : null,
			$parent,
			null,
			new Structs\ContentFormat( (string) $key, $val )
		);
		$nextFormat->integrate( $transaction, 0 );
		$currPos->right = $nextFormat;
		$currPos->forward();
	}
}

/**
 * @param mixed                      $transaction Transaction.
 * @param Types\AbstractType         $parent      Parent type.
 * @param Types\ItemTextListPosition $currPos     Current position.
 * @param mixed                      $text        Text/embed/type content.
 * @param array<string,mixed>        $attributes  Attributes.
 * @return void
 */
function insertText( $transaction, Types\AbstractType $parent, Types\ItemTextListPosition $currPos, $text, array $attributes ): void {
	foreach ( $currPos->currentAttributes as $key => $_val ) {
		if ( ! array_key_exists( $key, $attributes ) ) {
			$attributes[ $key ] = null;
		}
	}

	$doc         = $transaction->doc;
	$ownClientId = $doc->clientID;
	minimizeAttributeChanges( $currPos, $attributes );
	$negatedAttributes = insertAttributes( $transaction, $parent, $currPos, $attributes );
	if ( is_string( $text ) ) {
		$content = new Structs\ContentString( $text );
	} elseif ( $text instanceof Types\AbstractType ) {
		$content = new Structs\ContentType( $text );
	} else {
		$content = new Structs\ContentEmbed( $text );
	}

	$left  = $currPos->left;
	$right = $currPos->right;
	$index = $currPos->index;
	if ( null !== $parent->_searchMarker ) {
		updateMarkerChanges( $parent->_searchMarker, $currPos->index, $content->getLength() );
	}
	$right = new Structs\Item(
		createID( $ownClientId, getState( $doc->store, $ownClientId ) ),
		$left,
		null !== $left ? $left->lastId : null,
		$right,
		null !== $right ? $right->id : null,
		$parent,
		null,
		$content
	);
	$right->integrate( $transaction, 0 );
	$currPos->right = $right;
	$currPos->index = $index;
	$currPos->forward();
	insertNegatedAttributes( $transaction, $parent, $currPos, $negatedAttributes );
}

/**
 * @param mixed                      $transaction Transaction.
 * @param Types\AbstractType         $parent      Parent type.
 * @param Types\ItemTextListPosition $currPos     Current position.
 * @param int                        $length      Length.
 * @param array<string,mixed>        $attributes  Attributes.
 * @return void
 */
function formatText( $transaction, Types\AbstractType $parent, Types\ItemTextListPosition $currPos, int $length, array $attributes ): void {
	$doc         = $transaction->doc;
	$ownClientId = $doc->clientID;
	minimizeAttributeChanges( $currPos, $attributes );
	$negatedAttributes = insertAttributes( $transaction, $parent, $currPos, $attributes );

	while (
		null !== $currPos->right &&
		(
				$length > 0 ||
				(
					! empty( $negatedAttributes ) &&
					( $currPos->right->deleted || $currPos->right->content instanceof Structs\ContentFormat )
				)
		)
	) {
		if ( ! $currPos->right->deleted ) {
			if ( $currPos->right->content instanceof Structs\ContentFormat ) {
				$key         = $currPos->right->content->key;
				$value       = $currPos->right->content->value;
				$attrDefined = array_key_exists( $key, $attributes );
				if ( $attrDefined ) {
					$attr = $attributes[ $key ];
					if ( equalAttrs( $attr, $value ) ) {
						unset( $negatedAttributes[ $key ] );
					} else {
						if ( 0 === $length ) {
							break;
						}
						$negatedAttributes[ $key ] = $value;
					}
					$currPos->right->delete( $transaction );
				} else {
					$currPos->currentAttributes[ $key ] = $value;
				}
			} else {
				if ( $length < $currPos->right->length ) {
					getItemCleanStart( $transaction, createID( $currPos->right->id->client, $currPos->right->id->clock + $length ) );
				}
				$length -= $currPos->right->length;
			}
		}
		$currPos->forward();
	}

	if ( $length > 0 ) {
		$newlines       = str_repeat( "\n", $length );
		$currPos->right = new Structs\Item(
			createID( $ownClientId, getState( $doc->store, $ownClientId ) ),
			$currPos->left,
			null !== $currPos->left ? $currPos->left->lastId : null,
			$currPos->right,
			null !== $currPos->right ? $currPos->right->id : null,
			$parent,
			null,
			new Structs\ContentString( $newlines )
		);
		$currPos->right->integrate( $transaction, 0 );
		$currPos->forward();
	}
	insertNegatedAttributes( $transaction, $parent, $currPos, $negatedAttributes );
}

/**
 * @param mixed                      $transaction Transaction.
 * @param Types\ItemTextListPosition $currPos     Current position.
 * @param int                        $length      Delete length.
 * @return Types\ItemTextListPosition
 */
function deleteText( $transaction, Types\ItemTextListPosition $currPos, int $length ): Types\ItemTextListPosition {
	$startLength = $length;
	$startAttrs  = $currPos->currentAttributes;
	$start       = $currPos->right;
	while ( $length > 0 && null !== $currPos->right ) {
		if ( ! $currPos->right->deleted ) {
			$content = $currPos->right->content;
			if ( $content instanceof Structs\ContentType || $content instanceof Structs\ContentEmbed || $content instanceof Structs\ContentString ) {
				if ( $length < $currPos->right->length ) {
					getItemCleanStart( $transaction, createID( $currPos->right->id->client, $currPos->right->id->clock + $length ) );
				}
				$length -= $currPos->right->length;
				$currPos->right->delete( $transaction );
			}
		}
		$currPos->forward();
	}
	if ( null !== $start ) {
		cleanupFormattingGap( $transaction, $start, $currPos->right, $startAttrs, $currPos->currentAttributes );
	}
	$anchor = $currPos->left ?? $currPos->right;
	if ( null !== $anchor && null !== $anchor->parent->_searchMarker ) {
		updateMarkerChanges( $anchor->parent->_searchMarker, $currPos->index, -$startLength + $length );
	}
	return $currPos;
}

/**
 * @param mixed               $transaction     Transaction.
 * @param Structs\Item        $start           Start item.
 * @param Structs\Item|null   $curr            End item.
 * @param array<string,mixed> $startAttributes Start attributes.
 * @param array<string,mixed> $currAttributes  Current attributes.
 * @return int
 */
function cleanupFormattingGap( $transaction, Structs\Item $start, ?Structs\Item $curr, array $startAttributes, array &$currAttributes ): int {
	$end        = $start;
	$endFormats = array();
	while ( null !== $end && ( ! $end->countable || $end->deleted ) ) {
		if ( ! $end->deleted && $end->content instanceof Structs\ContentFormat ) {
			$endFormats[ $end->content->key ] = $end->content;
		}
		$end = $end->right;
	}

	$cleanups    = 0;
	$reachedCurr = false;
	while ( $start !== $end ) {
		if ( $curr === $start ) {
			$reachedCurr = true;
		}
		if ( ! $start->deleted && $start->content instanceof Structs\ContentFormat ) {
			$key            = $start->content->key;
			$value          = $start->content->value;
			$startAttrValue = array_key_exists( $key, $startAttributes ) ? $startAttributes[ $key ] : null;
			if ( ( $endFormats[ $key ] ?? null ) !== $start->content || $startAttrValue === $value ) {
				$start->delete( $transaction );
				++$cleanups;
				$currAttrValue = array_key_exists( $key, $currAttributes ) ? $currAttributes[ $key ] : null;
				if ( ! $reachedCurr && $currAttrValue === $value && $startAttrValue !== $value ) {
					if ( null === $startAttrValue ) {
						unset( $currAttributes[ $key ] );
					} else {
						$currAttributes[ $key ] = $startAttrValue;
					}
				}
			}
			if ( ! $reachedCurr && ! $start->deleted ) {
				updateCurrentAttributes( $currAttributes, $start->content );
			}
		}
		$start = $start->right;
	}
	return $cleanups;
}

/**
 * @param mixed             $transaction Transaction.
 * @param Structs\Item|null $item        Item.
 * @return void
 */
function cleanupContextlessFormattingGap( $transaction, ?Structs\Item $item ): void {
	while ( null !== $item && null !== $item->right && ( $item->right->deleted || ! $item->right->countable ) ) {
		$item = $item->right;
	}
	$attrs = array();
	while ( null !== $item && ( $item->deleted || ! $item->countable ) ) {
		if ( ! $item->deleted && $item->content instanceof Structs\ContentFormat ) {
			$key = $item->content->key;
			if ( array_key_exists( $key, $attrs ) ) {
				$item->delete( $transaction );
			} else {
				$attrs[ $key ] = true;
			}
		}
		$item = $item->left;
	}
}

/**
 * @param Types\YText $type Text type.
 * @return int
 */
function cleanupYTextFormatting( Types\YText $type ): int {
	$res = 0;
	if ( null === $type->doc ) {
		return $res;
	}
	transact(
		$type->doc,
		function ( Utils\Transaction $transaction ) use ( $type, &$res ): void {
			$start             = $type->_start;
			$end               = $type->_start;
			$startAttributes   = array();
			$currentAttributes = $startAttributes;
			while ( null !== $end ) {
				if ( false === $end->deleted ) {
					if ( $end->content instanceof Structs\ContentFormat ) {
						updateCurrentAttributes( $currentAttributes, $end->content );
					} elseif ( null !== $start ) {
						$res            += cleanupFormattingGap( $transaction, $start, $end, $startAttributes, $currentAttributes );
						$startAttributes = $currentAttributes;
						$start           = $end;
					}
				}
				$end = $end->right;
			}
		}
	);
	return $res;
}

/**
 * @param Utils\Transaction $transaction Transaction.
 * @return void
 */
function cleanupYTextAfterTransaction( Utils\Transaction $transaction ): void {
	$needFullCleanup = new \SplObjectStorage();
	$doc             = $transaction->doc;
	foreach ( $transaction->afterState as $client => $afterClock ) {
		$clock = $transaction->beforeState[ $client ] ?? 0;
		if ( $afterClock === $clock || ! array_key_exists( $client, $doc->store->clients ) ) {
			continue;
		}
		$structs =& $doc->store->clients[ $client ];
		iterateStructs(
			$transaction,
			$structs,
			$clock,
			$afterClock,
			static function ( $item ) use ( $needFullCleanup ): void {
				if ( $item instanceof Structs\Item && ! $item->deleted && $item->content instanceof Structs\ContentFormat && $item->parent instanceof Types\YText ) {
					$needFullCleanup->attach( $item->parent );
				}
			}
		);
		unset( $structs );
	}

	transact(
		$doc,
		function ( Utils\Transaction $t ) use ( $transaction, $needFullCleanup ): void {
			iterateDeletedStructs(
				$transaction,
				$transaction->deleteSet,
				static function ( $item ) use ( $t, $needFullCleanup ): void {
					if ( ! $item instanceof Structs\Item || ! $item->parent instanceof Types\YText || ! $item->parent->_hasFormatting || $needFullCleanup->contains( $item->parent ) ) {
						return;
					}
					$parent = $item->parent;
					if ( $item->content instanceof Structs\ContentFormat ) {
						$needFullCleanup->attach( $parent );
					} else {
						cleanupContextlessFormattingGap( $t, $item );
					}
				}
			);
			foreach ( $needFullCleanup as $yText ) {
				cleanupYTextFormatting( $yText );
			}
		}
	);
}

/**
 * @param mixed  $op  Delta operation.
 * @param string $key Operation key.
 * @return bool
 */
function opHas( $op, string $key ): bool {
	return is_array( $op ) ? array_key_exists( $key, $op ) : ( $op instanceof \stdClass && property_exists( $op, $key ) );
}

/**
 * @param mixed  $op      Delta operation.
 * @param string $key     Operation key.
 * @param mixed  $default Default value.
 * @return mixed
 */
function opGet( $op, string $key, $default = null ) {
	if ( is_array( $op ) ) {
		return array_key_exists( $key, $op ) ? $op[ $key ] : $default;
	}
	if ( $op instanceof \stdClass && property_exists( $op, $key ) ) {
		return $op->{$key};
	}
	return $default;
}

/**
 * @param mixed  $attr Attribute value.
 * @param string $key  Key.
 * @return mixed
 */
function changeAttrGet( $attr, string $key ) {
	if ( is_array( $attr ) ) {
		return $attr[ $key ] ?? null;
	}
	if ( $attr instanceof \stdClass && property_exists( $attr, $key ) ) {
		return $attr->{$key};
	}
	return null;
}
