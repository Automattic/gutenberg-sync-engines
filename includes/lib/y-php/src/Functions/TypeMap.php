<?php
/**
 * Map type namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param mixed              $transaction Transaction.
 * @param Types\AbstractType $parent      Parent type.
 * @param string             $key         Key.
 * @return void
 */
function typeMapDelete( $transaction, Types\AbstractType $parent, string $key ): void {
	if ( array_key_exists( $key, $parent->_map ) ) {
		$parent->_map[ $key ]->delete( $transaction );
	}
}

/**
 * @param mixed              $transaction Transaction.
 * @param Types\AbstractType $parent      Parent type.
 * @param string             $key         Key.
 * @param mixed              $value       Value.
 * @return void
 */
function typeMapSet( $transaction, Types\AbstractType $parent, string $key, $value ): void {
	$left = $parent->_map[ $key ] ?? null;
	$doc  = $transaction->doc;
	( new Structs\Item(
		createID( $doc->clientID, getState( $doc->store, $doc->clientID ) ),
		$left,
		null !== $left ? $left->lastId : null,
		null,
		null,
		$parent,
		$key,
		contentForValue( $value )
	) )->integrate( $transaction, 0 );
}

/**
 * @param Types\AbstractType $parent Parent type.
 * @param string             $key    Key.
 * @return mixed
 */
function typeMapGet( Types\AbstractType $parent, string $key ) {
	$val = $parent->_map[ $key ] ?? null;
	return null !== $val && ! $val->deleted ? $val->content->getContent()[ $val->length - 1 ] : Lib0\UndefinedValue::getInstance();
}

/**
 * @param Types\AbstractType $parent Parent type.
 * @return array<string,mixed>
 */
function typeMapGetAll( Types\AbstractType $parent ): array {
	$res = array();
	foreach ( $parent->_map as $key => $value ) {
		if ( ! $value->deleted ) {
			$res[ (string) $key ] = $value->content->getContent()[ $value->length - 1 ];
		}
	}
	return $res;
}

/**
 * @param Types\AbstractType $parent Parent type.
 * @param string             $key    Key.
 * @return bool
 */
function typeMapHas( Types\AbstractType $parent, string $key ): bool {
	$val = $parent->_map[ $key ] ?? null;
	return null !== $val && ! $val->deleted;
}

/**
 * @param Types\AbstractType $type Type.
 * @return array<int,array{0:string,1:Structs\Item}>
 */
function createMapIterator( Types\AbstractType $type ): array {
	$entries = array();
	foreach ( $type->_map as $key => $value ) {
		if ( ! $value->deleted ) {
			$entries[] = array( (string) $key, $value );
		}
	}
	return $entries;
}

/**
 * @param Types\AbstractType $parent Parent type.
 * @param Types\AbstractType $child  Child type.
 * @return array<int,string|int>
 */
function getPathTo( Types\AbstractType $parent, Types\AbstractType $child ): array {
	$path = array();
	while ( null !== $child->_item && $child !== $parent ) {
		if ( null !== $child->_item->parentSub ) {
			array_unshift( $path, $child->_item->parentSub );
		} else {
			$i = 0;
			for ( $c = $child->_item->parent->_start; null !== $c; $c = $c->right ) {
				if ( $c === $child->_item ) {
					break;
				}
				if ( ! $c->deleted && $c->countable ) {
					$i += $c->length;
				}
			}
			array_unshift( $path, $i );
		}
		$child = $child->_item->parent;
	}
	return $path;
}

/**
 * @param Types\AbstractType $parent   Parent type.
 * @param string             $key      Key.
 * @param Utils\Snapshot     $snapshot Snapshot.
 * @return mixed
 */
function typeMapGetSnapshot( Types\AbstractType $parent, string $key, Utils\Snapshot $snapshot ) {
	$v = $parent->_map[ $key ] ?? null;
	while ( null !== $v && ( ! isVisible( $v, $snapshot ) || null === $v->parentSub || $v->parentSub !== $key ) ) {
		$v = $v->left;
	}
	return null === $v ? Lib0\UndefinedValue::getInstance() : $v->content->getContent()[ $v->length - 1 ];
}

/**
 * @param Types\AbstractType $parent   Parent type.
 * @param Utils\Snapshot     $snapshot Snapshot.
 * @return array<string,mixed>
 */
function typeMapGetAllSnapshot( Types\AbstractType $parent, Utils\Snapshot $snapshot ): array {
	$res = array();
	foreach ( $parent->_map as $key => $_value ) {
		$value = typeMapGetSnapshot( $parent, (string) $key, $snapshot );
		if ( ! $value instanceof Lib0\UndefinedValue ) {
			$res[ (string) $key ] = $value;
		}
	}
	return $res;
}
