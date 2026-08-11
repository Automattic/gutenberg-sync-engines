<?php
/**
 * Shared type namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param Types\AbstractType $t Type.
 * @return array<int,Structs\Item>
 */
function getTypeChildren( Types\AbstractType $t ): array {
	$arr = array();
	for ( $s = $t->_start; null !== $s; $s = $s->right ) {
		$arr[] = $s;
	}
	return $arr;
}

/**
 * @param object $type AbstractType-like value.
 * @return string
 */
function findRootTypeKey( object $type ): string {
	if ( ! isset( $type->doc ) || ! is_object( $type->doc ) || ! isset( $type->doc->share ) ) {
		Lib0\Error::unexpectedCase();
		return '';
	}

	$share = $type->doc->share;
	if ( is_array( $share ) || $share instanceof \Traversable ) {
		foreach ( $share as $key => $value ) {
			if ( $value === $type ) {
				return (string) $key;
			}
		}
	} elseif ( is_object( $share ) ) {
		foreach ( get_object_vars( $share ) as $key => $value ) {
			if ( $value === $type ) {
				return (string) $key;
			}
		}
	}

	Lib0\Error::unexpectedCase();
	return '';
}

/**
 * @param Types\AbstractType $parent Parent type.
 * @param Structs\Item|null  $child  Child item.
 * @return bool
 */
function isParentOf( Types\AbstractType $parent, ?Structs\Item $child ): bool {
	while ( null !== $child ) {
		if ( $child->parent === $parent ) {
			return true;
		}
		$childParent = $child->parent;
		$child       = $childParent instanceof Types\AbstractType ? $childParent->_item : null;
	}
	return false;
}
