<?php
/**
 * List type namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @return int
 */
function nextSearchMarkerTimestamp(): int {
	static $timestamp = 0;
	return $timestamp++;
}

/**
 * @param array<int,Types\ArraySearchMarker> $searchMarker Search markers.
 * @param Structs\Item                       $p            Item.
 * @param int                                $index        Index.
 * @return Types\ArraySearchMarker
 */
function markPosition( array &$searchMarker, Structs\Item $p, int $index ): Types\ArraySearchMarker {
	if ( count( $searchMarker ) >= 80 ) {
		$marker = $searchMarker[0];
		foreach ( $searchMarker as $candidate ) {
			if ( $candidate->timestamp < $marker->timestamp ) {
				$marker = $candidate;
			}
		}
		overwriteMarker( $marker, $p, $index );
		return $marker;
	}
	$marker         = new Types\ArraySearchMarker( $p, $index );
	$searchMarker[] = $marker;
	return $marker;
}

/**
 * @param Types\ArraySearchMarker $marker Marker.
 * @param Structs\Item            $p      Item.
 * @param int                     $index  Index.
 * @return void
 */
function overwriteMarker( Types\ArraySearchMarker $marker, Structs\Item $p, int $index ): void {
	$marker->p->marker = false;
	$marker->p         = $p;
	$p->marker         = true;
	$marker->index     = $index;
	$marker->timestamp = nextSearchMarkerTimestamp();
}

/**
 * @param Types\ArraySearchMarker $marker Marker.
 * @return void
 */
function refreshMarkerTimestamp( Types\ArraySearchMarker $marker ): void {
	$marker->timestamp = nextSearchMarkerTimestamp();
}

/**
 * @param Types\AbstractType $yarray Type.
 * @param int                $index  Index.
 * @return Types\ArraySearchMarker|null
 */
function findMarker( Types\AbstractType $yarray, int $index ): ?Types\ArraySearchMarker {
	if ( null === $yarray->_start || 0 === $index || null === $yarray->_searchMarker ) {
		return null;
	}
	$marker = null;
	foreach ( $yarray->_searchMarker as $candidate ) {
		if ( null === $marker || abs( $index - $candidate->index ) < abs( $index - $marker->index ) ) {
			$marker = $candidate;
		}
	}
	$p      = $yarray->_start;
	$pindex = 0;
	if ( null !== $marker ) {
		$p      = $marker->p;
		$pindex = $marker->index;
		refreshMarkerTimestamp( $marker );
	}
	while ( null !== $p->right && $pindex < $index ) {
		if ( ! $p->deleted && $p->countable ) {
			if ( $index < $pindex + $p->length ) {
				break;
			}
			$pindex += $p->length;
		}
		$p = $p->right;
	}
	while ( null !== $p->left && $pindex > $index ) {
		$p = $p->left;
		if ( ! $p->deleted && $p->countable ) {
			$pindex -= $p->length;
		}
	}
	while ( null !== $p->left && $p->left->id->client === $p->id->client && $p->left->id->clock + $p->left->length === $p->id->clock ) {
		$p = $p->left;
		if ( ! $p->deleted && $p->countable ) {
			$pindex -= $p->length;
		}
	}
	if ( null !== $marker && abs( $marker->index - $pindex ) < $p->parent->_length / 80 ) {
		overwriteMarker( $marker, $p, $pindex );
		return $marker;
	}
	return markPosition( $yarray->_searchMarker, $p, $pindex );
}

/**
 * @param array<int,Types\ArraySearchMarker> $searchMarker Search markers.
 * @param int                                $index        Index.
 * @param int                                $len          Change length.
 * @return void
 */
function updateMarkerChanges( array &$searchMarker, int $index, int $len ): void {
	for ( $i = count( $searchMarker ) - 1; $i >= 0; $i-- ) {
		$m = $searchMarker[ $i ];
		if ( 0 < $len ) {
			$p         = $m->p;
			$p->marker = false;
			while ( null !== $p && ( $p->deleted || ! $p->countable ) ) {
				$p = $p->left;
				if ( null !== $p && ! $p->deleted && $p->countable ) {
					$m->index -= $p->length;
				}
			}
			if ( null === $p || true === $p->marker ) {
				array_splice( $searchMarker, $i, 1 );
				continue;
			}
			$m->p      = $p;
			$p->marker = true;
		}
		if ( $index < $m->index || ( 0 < $len && $index === $m->index ) ) {
			$m->index = Lib0\Math::max( $index, $m->index + $len );
		}
	}
}

/**
 * @param Types\AbstractType $type  Type.
 * @param int                $start Start.
 * @param int                $end   End.
 * @return array<int,mixed>
 */
function typeListSlice( Types\AbstractType $type, int $start, int $end ): array {
	if ( $start < 0 ) {
		$start = $type->_length + $start;
	}
	if ( $end < 0 ) {
		$end = $type->_length + $end;
	}
	$len = $end - $start;
	$cs  = array();
	for ( $n = $type->_start; null !== $n && 0 < $len; $n = $n->right ) {
		if ( $n->countable && ! $n->deleted ) {
			$c = $n->content->getContent();
			if ( count( $c ) <= $start ) {
				$start -= count( $c );
			} else {
				for ( $i = $start, $clen = count( $c ); $i < $clen && 0 < $len; $i++ ) {
					$cs[] = $c[ $i ];
					--$len;
				}
				$start = 0;
			}
		}
	}
	return $cs;
}

/**
 * @param Types\AbstractType $type Type.
 * @return array<int,mixed>
 */
function typeListToArray( Types\AbstractType $type ): array {
	$cs = array();
	for ( $n = $type->_start; null !== $n; $n = $n->right ) {
		if ( $n->countable && ! $n->deleted ) {
			array_push( $cs, ...$n->content->getContent() );
		}
	}
	return $cs;
}

/**
 * @param Types\AbstractType $type     Type.
 * @param mixed              $snapshot Snapshot.
 * @return array<int,mixed>
 */
function typeListToArraySnapshot( Types\AbstractType $type, $snapshot ): array {
	$cs = array();
	for ( $n = $type->_start; null !== $n; $n = $n->right ) {
		if ( $n->countable && isVisible( $n, $snapshot ) ) {
			array_push( $cs, ...$n->content->getContent() );
		}
	}
	return $cs;
}

/**
 * @param Types\AbstractType $type Type.
 * @param callable           $f    Callback.
 * @return void
 */
function typeListForEach( Types\AbstractType $type, callable $f ): void {
	$index = 0;
	for ( $n = $type->_start; null !== $n; $n = $n->right ) {
		if ( $n->countable && ! $n->deleted ) {
			foreach ( $n->content->getContent() as $c ) {
				$f( $c, $index++, $type );
			}
		}
	}
}

/**
 * @param Types\AbstractType $type Type.
 * @param callable           $f    Callback.
 * @return array<int,mixed>
 */
function typeListMap( Types\AbstractType $type, callable $f ): array {
	$result = array();
	typeListForEach(
		$type,
		static function ( $c, int $i, Types\AbstractType $parent ) use ( &$result, $f ): void {
			$result[] = $f( $c, $i, $parent );
		}
	);
	return $result;
}

/**
 * @param Types\AbstractType $type Type.
 * @return \Generator<int,mixed>
 */
function typeListCreateIterator( Types\AbstractType $type ): \Generator {
	$n                   = $type->_start;
	$currentContent      = null;
	$currentContentIndex = 0;

	while ( true ) {
		if ( null === $currentContent ) {
			while ( null !== $n && $n->deleted ) {
				$n = $n->right;
			}
			if ( null === $n ) {
				return;
			}
			$currentContent      = $n->content->getContent();
			$currentContentIndex = 0;
			$n                   = $n->right;
		}

		yield $currentContent[ $currentContentIndex++ ];

		if ( count( $currentContent ) <= $currentContentIndex ) {
			$currentContent = null;
		}
	}
}

/**
 * @param Types\AbstractType $type  Type.
 * @param int                $index Index.
 * @return mixed
 */
function typeListGet( Types\AbstractType $type, int $index ) {
	$marker = findMarker( $type, $index );
	$n      = $type->_start;
	if ( null !== $marker ) {
		$n      = $marker->p;
		$index -= $marker->index;
	}
	for ( ; null !== $n; $n = $n->right ) {
		if ( ! $n->deleted && $n->countable ) {
			if ( $index < $n->length ) {
				return $n->content->getContent()[ $index ];
			}
			$index -= $n->length;
		}
	}
	return Lib0\UndefinedValue::getInstance();
}

/**
 * @param mixed $value Value.
 * @return mixed
 */
function copyJsonValue( $value ) {
	if ( is_array( $value ) ) {
		$copy = array();
		foreach ( $value as $key => $item ) {
			$copy[ $key ] = copyJsonValue( $item );
		}
		return $copy;
	}
	if ( $value instanceof \stdClass ) {
		$copy = new \stdClass();
		foreach ( get_object_vars( $value ) as $key => $item ) {
			$copy->{$key} = copyJsonValue( $item );
		}
		return $copy;
	}
	return $value;
}

/**
 * @param mixed $value Value.
 * @return object
 */
function contentForValue( $value ): object {
	if ( $value instanceof Lib0\Buffer ) {
		return new Structs\ContentBinary( $value );
	}
	if ( $value instanceof Utils\Doc ) {
		return new Structs\ContentDoc( $value );
	}
	if ( $value instanceof Types\AbstractType ) {
		return new Structs\ContentType( $value );
	}
	return new Structs\ContentAny( array( copyJsonValue( $value ) ) );
}

/**
 * @param mixed              $transaction   Transaction.
 * @param Types\AbstractType $parent        Parent type.
 * @param Structs\Item|null  $referenceItem Reference item.
 * @param array<int,mixed>   $content       Content.
 * @return void
 */
function typeListInsertGenericsAfter( $transaction, Types\AbstractType $parent, ?Structs\Item $referenceItem, array $content ): void {
	$left            = $referenceItem;
	$doc             = $transaction->doc;
	$ownClientId     = $doc->clientID;
	$store           = $doc->store;
	$right           = null === $referenceItem ? $parent->_start : $referenceItem->right;
	$jsonContent     = array();
	$packJsonContent = static function () use ( &$jsonContent, &$left, $right, $parent, $transaction, $ownClientId, $store ): void {
		if ( 0 < count( $jsonContent ) ) {
			$left = new Structs\Item(
				createID( $ownClientId, getState( $store, $ownClientId ) ),
				$left,
				null !== $left ? $left->lastId : null,
				$right,
				null !== $right ? $right->id : null,
				$parent,
				null,
				new Structs\ContentAny( $jsonContent )
			);
			$left->integrate( $transaction, 0 );
			$jsonContent = array();
		}
	};

	foreach ( $content as $c ) {
		if ( null === $c || is_int( $c ) || is_float( $c ) || is_bool( $c ) || is_string( $c ) || is_array( $c ) || $c instanceof \stdClass ) {
			$jsonContent[] = copyJsonValue( $c );
			continue;
		}
		$packJsonContent();
		$left = new Structs\Item(
			createID( $ownClientId, getState( $store, $ownClientId ) ),
			$left,
			null !== $left ? $left->lastId : null,
			$right,
			null !== $right ? $right->id : null,
			$parent,
			null,
			contentForValue( $c )
		);
		$left->integrate( $transaction, 0 );
	}
	$packJsonContent();
}

/**
 * @return \RuntimeException
 */
function lengthExceeded(): \RuntimeException {
	return Lib0\Error::create( 'Length exceeded!' );
}

/**
 * @param mixed              $transaction Transaction.
 * @param Types\AbstractType $parent      Parent type.
 * @param int                $index       Index.
 * @param array<int,mixed>   $content     Content.
 * @return void
 */
function typeListInsertGenerics( $transaction, Types\AbstractType $parent, int $index, array $content ): void {
	if ( $index > $parent->_length ) {
		throw lengthExceeded();
	}
	if ( 0 === $index ) {
		if ( null !== $parent->_searchMarker ) {
			updateMarkerChanges( $parent->_searchMarker, $index, count( $content ) );
		}
		typeListInsertGenericsAfter( $transaction, $parent, null, $content );
		return;
	}
	$startIndex = $index;
	$marker     = findMarker( $parent, $index );
	$n          = $parent->_start;
	if ( null !== $marker ) {
		$n      = $marker->p;
		$index -= $marker->index;
		if ( 0 === $index ) {
			$n      = $n->prev;
			$index += ( null !== $n && $n->countable && ! $n->deleted ) ? $n->length : 0;
		}
	}
	for ( ; null !== $n; $n = $n->right ) {
		if ( ! $n->deleted && $n->countable ) {
			if ( $index <= $n->length ) {
				if ( $index < $n->length ) {
					getItemCleanStart( $transaction, createID( $n->id->client, $n->id->clock + $index ) );
				}
				break;
			}
			$index -= $n->length;
		}
	}
	if ( null !== $parent->_searchMarker ) {
		updateMarkerChanges( $parent->_searchMarker, $startIndex, count( $content ) );
	}
	typeListInsertGenericsAfter( $transaction, $parent, $n, $content );
}

/**
 * @param mixed              $transaction Transaction.
 * @param Types\AbstractType $parent      Parent type.
 * @param array<int,mixed>   $content     Content.
 * @return void
 */
function typeListPushGenerics( $transaction, Types\AbstractType $parent, array $content ): void {
	$n = $parent->_start;
	while ( null !== $n && null !== $n->right ) {
		$n = $n->right;
	}
	typeListInsertGenericsAfter( $transaction, $parent, $n, $content );
}

/**
 * @param mixed              $transaction Transaction.
 * @param Types\AbstractType $parent      Parent type.
 * @param int                $index       Index.
 * @param string             $text        Text.
 * @return void
 */
function typeListInsertText( $transaction, Types\AbstractType $parent, int $index, string $text ): void {
	if ( '' === $text ) {
		return;
	}
	if ( 0 === $index ) {
		$left  = null;
		$right = $parent->_start;
	} else {
		$marker = findMarker( $parent, $index );
		$n      = $parent->_start;
		if ( null !== $marker ) {
			$n      = $marker->p;
			$index -= $marker->index;
		}
		for ( ; null !== $n; $n = $n->right ) {
			if ( ! $n->deleted && $n->countable ) {
				if ( $index <= $n->length ) {
					if ( $index < $n->length ) {
						getItemCleanStart( $transaction, createID( $n->id->client, $n->id->clock + $index ) );
					}
					break;
				}
				$index -= $n->length;
			}
		}
		$left  = $n;
		$right = null === $n ? null : $n->right;
	}
	$doc  = $transaction->doc;
	$item = new Structs\Item(
		createID( $doc->clientID, getState( $doc->store, $doc->clientID ) ),
		$left,
		null !== $left ? $left->lastId : null,
		$right,
		null !== $right ? $right->id : null,
		$parent,
		null,
		new Structs\ContentString( $text )
	);
	$item->integrate( $transaction, 0 );
}

/**
 * @param mixed              $transaction Transaction.
 * @param Types\AbstractType $parent      Parent type.
 * @param int                $index       Index.
 * @param int                $length      Length.
 * @return void
 */
function typeListDelete( $transaction, Types\AbstractType $parent, int $index, int $length ): void {
	if ( 0 === $length ) {
		return;
	}
	$startIndex  = $index;
	$startLength = $length;
	$marker      = findMarker( $parent, $index );
	$n           = $parent->_start;
	if ( null !== $marker ) {
		$n      = $marker->p;
		$index -= $marker->index;
	}
	for ( ; null !== $n && 0 < $index; $n = $n->right ) {
		if ( ! $n->deleted && $n->countable ) {
			if ( $index < $n->length ) {
				getItemCleanStart( $transaction, createID( $n->id->client, $n->id->clock + $index ) );
			}
			$index -= $n->length;
		}
	}
	while ( 0 < $length && null !== $n ) {
		if ( ! $n->deleted ) {
			if ( $length < $n->length ) {
				getItemCleanStart( $transaction, createID( $n->id->client, $n->id->clock + $length ) );
			}
			$n->delete( $transaction );
			$length -= $n->length;
		}
		$n = $n->right;
	}
	if ( 0 < $length ) {
		throw lengthExceeded();
	}
	if ( null !== $parent->_searchMarker ) {
		updateMarkerChanges( $parent->_searchMarker, $startIndex, -$startLength + $length );
	}
}
