<?php
/**
 * Relative-position namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param Utils\RelativePosition $rpos Relative position.
 * @return \stdClass
 */
function relativePositionToJSON( Utils\RelativePosition $rpos ): \stdClass {
	$json = new \stdClass();
	if ( null !== $rpos->type ) {
		$json->type = $rpos->type;
	}
	if ( null !== $rpos->tname ) {
		$json->tname = $rpos->tname;
	}
	if ( null !== $rpos->item ) {
		$json->item = $rpos->item;
	}
	$json->assoc = $rpos->assoc;
	return $json;
}

/**
 * @param mixed $json JSON-like position object.
 * @return Utils\RelativePosition
 */
function createRelativePositionFromJSON( $json ): Utils\RelativePosition {
	$type  = relativePositionJsonHas( $json, 'type' ) ? relativePositionJsonId( relativePositionJsonGet( $json, 'type' ) ) : null;
	$tname = relativePositionJsonHas( $json, 'tname' ) ? (string) relativePositionJsonGet( $json, 'tname' ) : null;
	$item  = relativePositionJsonHas( $json, 'item' ) ? relativePositionJsonId( relativePositionJsonGet( $json, 'item' ) ) : null;
	$assoc = relativePositionJsonHas( $json, 'assoc' ) ? (int) relativePositionJsonGet( $json, 'assoc' ) : 0;
	return new Utils\RelativePosition( $type, $tname, $item, $assoc );
}

/**
 * @param mixed  $json JSON-like value.
 * @param string $key  Key.
 * @return bool
 */
function relativePositionJsonHas( $json, string $key ): bool {
	return is_array( $json ) ? array_key_exists( $key, $json ) : ( $json instanceof \stdClass && property_exists( $json, $key ) );
}

/**
 * @param mixed  $json JSON-like value.
 * @param string $key  Key.
 * @return mixed
 */
function relativePositionJsonGet( $json, string $key ) {
	return is_array( $json ) ? $json[ $key ] : $json->{$key};
}

/**
 * @param mixed $json JSON-like ID.
 * @return Utils\ID|null
 */
function relativePositionJsonId( $json ): ?Utils\ID {
	if ( null === $json ) {
		return null;
	}
	return createID( (int) relativePositionJsonGet( $json, 'client' ), (int) relativePositionJsonGet( $json, 'clock' ) );
}

/**
 * @param Types\AbstractType $type  Type.
 * @param Utils\ID|null      $item  Item id.
 * @param int                $assoc Association.
 * @return Utils\RelativePosition
 */
function createRelativePosition( Types\AbstractType $type, ?Utils\ID $item, int $assoc = 0 ): Utils\RelativePosition {
	$typeid = null;
	$tname  = null;
	if ( null === $type->_item ) {
		$tname = findRootTypeKey( $type );
	} else {
		$typeid = createID( $type->_item->id->client, $type->_item->id->clock );
	}
	return new Utils\RelativePosition( $typeid, $tname, $item, $assoc );
}

/**
 * @param Types\AbstractType $type  Type.
 * @param int                $index Absolute index.
 * @param int                $assoc Association.
 * @return Utils\RelativePosition
 */
function createRelativePositionFromTypeIndex( Types\AbstractType $type, int $index, int $assoc = 0 ): Utils\RelativePosition {
	$t = $type->_start;
	if ( $assoc < 0 ) {
		if ( 0 === $index ) {
			return createRelativePosition( $type, null, $assoc );
		}
		--$index;
	}
	while ( null !== $t ) {
		if ( ! $t->deleted && $t->countable ) {
			if ( $t->length > $index ) {
				return createRelativePosition( $type, createID( $t->id->client, $t->id->clock + $index ), $assoc );
			}
			$index -= $t->length;
		}
		if ( null === $t->right && $assoc < 0 ) {
			return createRelativePosition( $type, $t->lastId, $assoc );
		}
		$t = $t->right;
	}
	return createRelativePosition( $type, null, $assoc );
}

/**
 * @param Lib0\Encoder           $encoder Encoder.
 * @param Utils\RelativePosition $rpos    Relative position.
 * @return Lib0\Encoder
 */
function writeRelativePosition( Lib0\Encoder $encoder, Utils\RelativePosition $rpos ): Lib0\Encoder {
	if ( null !== $rpos->item ) {
		Lib0\Encoding::writeVarUint( $encoder, 0 );
		writeID( $encoder, $rpos->item );
	} elseif ( null !== $rpos->tname ) {
		Lib0\Encoding::writeUint8( $encoder, 1 );
		Lib0\Encoding::writeVarString( $encoder, $rpos->tname );
	} elseif ( null !== $rpos->type ) {
		Lib0\Encoding::writeUint8( $encoder, 2 );
		writeID( $encoder, $rpos->type );
	} else {
		Lib0\Error::unexpectedCase();
	}
	Lib0\Encoding::writeVarInt( $encoder, $rpos->assoc );
	return $encoder;
}

/**
 * @param Utils\RelativePosition $rpos Relative position.
 * @return Lib0\Buffer
 */
function encodeRelativePosition( Utils\RelativePosition $rpos ): Lib0\Buffer {
	$encoder = Lib0\Encoding::createEncoder();
	writeRelativePosition( $encoder, $rpos );
	return Lib0\Encoding::toUint8Array( $encoder );
}

/**
 * @param Lib0\Decoder $decoder Decoder.
 * @return Utils\RelativePosition
 */
function readRelativePosition( Lib0\Decoder $decoder ): Utils\RelativePosition {
	$type   = null;
	$tname  = null;
	$itemID = null;
	switch ( Lib0\Decoding::readVarUint( $decoder ) ) {
		case 0:
			$itemID = readID( $decoder );
			break;
		case 1:
			$tname = Lib0\Decoding::readVarString( $decoder );
			break;
		case 2:
			$type = readID( $decoder );
			break;
	}
	$assoc = Lib0\Decoding::hasContent( $decoder ) ? (int) Lib0\Decoding::readVarInt( $decoder ) : 0;
	return new Utils\RelativePosition( $type, $tname, $itemID, $assoc );
}

/**
 * @param Lib0\Buffer $uint8Array Encoded relative position.
 * @return Utils\RelativePosition
 */
function decodeRelativePosition( Lib0\Buffer $uint8Array ): Utils\RelativePosition {
	return readRelativePosition( Lib0\Decoding::createDecoder( $uint8Array ) );
}

/**
 * @param Utils\StructStore $store Store.
 * @param Utils\ID          $id    Item id.
 * @return array{item:Structs\AbstractStruct,diff:int}
 */
function getItemWithOffset( Utils\StructStore $store, Utils\ID $id ): array {
	$item = getItem( $store, $id );
	return array(
		'item' => $item,
		'diff' => $id->clock - $item->id->clock,
	);
}

/**
 * @param Utils\RelativePosition $rpos                   Relative position.
 * @param Utils\Doc              $doc                    Document.
 * @param bool                   $followUndoneDeletions  Whether to follow redone items.
 * @return Utils\AbsolutePosition|null
 */
function createAbsolutePositionFromRelativePosition( Utils\RelativePosition $rpos, Utils\Doc $doc, bool $followUndoneDeletions = true ): ?Utils\AbsolutePosition {
	$store   = $doc->store;
	$rightID = $rpos->item;
	$typeID  = $rpos->type;
	$tname   = $rpos->tname;
	$assoc   = $rpos->assoc;
	$type    = null;
	$index   = 0;
	if ( null !== $rightID ) {
		if ( getState( $store, $rightID->client ) <= $rightID->clock ) {
			return null;
		}
		$res   = $followUndoneDeletions ? followRedone( $store, $rightID ) : getItemWithOffset( $store, $rightID );
		$right = $res['item'];
		if ( ! $right instanceof Structs\Item ) {
			return null;
		}
		$type = $right->parent;
		if ( null === $type->_item || ! $type->_item->deleted ) {
			$index = ( $right->deleted || ! $right->countable ) ? 0 : ( $res['diff'] + ( $assoc >= 0 ? 0 : 1 ) );
			$n     = $right->left;
			while ( null !== $n ) {
				if ( ! $n->deleted && $n->countable ) {
					$index += $n->length;
				}
				$n = $n->left;
			}
		}
	} else {
		if ( null !== $tname ) {
			$type = $doc->get( $tname );
		} elseif ( null !== $typeID ) {
			if ( getState( $store, $typeID->client ) <= $typeID->clock ) {
				return null;
			}
			$res  = $followUndoneDeletions ? followRedone( $store, $typeID ) : array( 'item' => getItem( $store, $typeID ) );
			$item = $res['item'];
			if ( $item instanceof Structs\Item && $item->content instanceof Structs\ContentType ) {
				$type = $item->content->type;
			} else {
				return null;
			}
		} else {
			Lib0\Error::unexpectedCase();
		}
		$index = $assoc >= 0 ? $type->_length : 0;
	}
	return new Utils\AbsolutePosition( $type, $index, $rpos->assoc );
}

/**
 * @param Utils\RelativePosition|null $a Left position.
 * @param Utils\RelativePosition|null $b Right position.
 * @return bool
 */
function compareRelativePositions( ?Utils\RelativePosition $a, ?Utils\RelativePosition $b ): bool {
	return $a === $b || (
		null !== $a &&
		null !== $b &&
		$a->tname === $b->tname &&
		compareIDs( $a->item, $b->item ) &&
		compareIDs( $a->type, $b->type ) &&
		$a->assoc === $b->assoc
	);
}
