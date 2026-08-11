<?php
/**
 * CRDT item struct.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

use Yjs\Lib0\Binary;
use Yjs\Lib0\Error;
use Yjs\Utils\ID;

/**
 * Port of yjs/src/structs/Item.js.
 */
class Item extends AbstractStruct {
	/**
	 * @var ID|null
	 */
	public ?ID $origin;

	/**
	 * @var Item|null
	 */
	public ?Item $left;

	/**
	 * @var Item|null
	 */
	public ?Item $right;

	/**
	 * @var ID|null
	 */
	public ?ID $rightOrigin;

	/**
	 * @var mixed
	 */
	public $parent;

	/**
	 * @var string|null
	 */
	public ?string $parentSub;

	/**
	 * @var ID|null
	 */
	public ?ID $redone = null;

	/**
	 * @var object
	 */
	public object $content;

	/**
	 * Bit1: keep; bit2: countable; bit3: deleted; bit4: marker.
	 *
	 * @var int
	 */
	public int $info;

	/**
	 * @param ID          $id          Item id.
	 * @param Item|null   $left        Current left item.
	 * @param ID|null     $origin      Origin id.
	 * @param Item|null   $right       Current right item.
	 * @param ID|null     $rightOrigin Right origin id.
	 * @param mixed       $parent      Parent value.
	 * @param string|null $parentSub   Parent sub key.
	 * @param object      $content     Item content.
	 */
	public function __construct( ID $id, ?Item $left, ?ID $origin, ?Item $right, ?ID $rightOrigin, $parent, ?string $parentSub, object $content ) {
		parent::__construct( $id, $content->getLength() );

		$this->origin      = $origin;
		$this->left        = $left;
		$this->right       = $right;
		$this->rightOrigin = $rightOrigin;
		$this->parent      = $parent;
		$this->parentSub   = $parentSub;
		$this->content     = $content;
		$this->info        = $content->isCountable() ? Binary::BIT2 : 0;
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		switch ( $name ) {
			case 'marker':
				return 0 < ( $this->info & Binary::BIT4 );
			case 'keep':
				return 0 < ( $this->info & Binary::BIT1 );
			case 'countable':
				return 0 < ( $this->info & Binary::BIT2 );
			case 'deleted':
				return $this->getDeleted();
			case 'next':
				return $this->getNext();
			case 'prev':
				return $this->getPrev();
			case 'lastId':
				return 1 === $this->length ? $this->id : \Yjs\createID( $this->id->client, $this->id->clock + $this->length - 1 );
		}

		return parent::__get( $name );
	}

	/**
	 * @param string $name  Property name.
	 * @param mixed  $value Property value.
	 * @return void
	 */
	public function __set( string $name, $value ): void {
		switch ( $name ) {
			case 'marker':
				if ( ( 0 < ( $this->info & Binary::BIT4 ) ) !== (bool) $value ) {
					$this->info ^= Binary::BIT4;
				}
				return;
			case 'keep':
				if ( $this->__get( 'keep' ) !== (bool) $value ) {
					$this->info ^= Binary::BIT1;
				}
				return;
			case 'deleted':
				if ( $this->getDeleted() !== (bool) $value ) {
					$this->info ^= Binary::BIT3;
				}
				return;
		}

		$this->{$name} = $value;
	}

	/**
	 * @return bool
	 */
	protected function getDeleted(): bool {
		return 0 < ( $this->info & Binary::BIT3 );
	}

	/**
	 * @return void
	 */
	public function markDeleted(): void {
		$this->info |= Binary::BIT3;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param mixed $store       Store.
	 * @return int|null
	 */
	public function getMissing( $transaction, $store ): ?int {
		if ( null !== $this->origin && $this->origin->client !== $this->id->client && $this->origin->clock >= \Yjs\getState( $store, $this->origin->client ) ) {
			return $this->origin->client;
		}
		if ( null !== $this->rightOrigin && $this->rightOrigin->client !== $this->id->client && $this->rightOrigin->clock >= \Yjs\getState( $store, $this->rightOrigin->client ) ) {
			return $this->rightOrigin->client;
		}
		if ( $this->parent instanceof ID && $this->id->client !== $this->parent->client && $this->parent->clock >= \Yjs\getState( $store, $this->parent->client ) ) {
			return $this->parent->client;
		}

		if ( null !== $this->origin ) {
			$this->left   = \Yjs\getItemCleanEnd( $transaction, $store, $this->origin );
			$this->origin = $this->left->lastId;
		}
		if ( null !== $this->rightOrigin ) {
			$this->right       = \Yjs\getItemCleanStart( $transaction, $this->rightOrigin );
			$this->rightOrigin = $this->right->id;
		}
		if ( ( null !== $this->left && $this->left instanceof GC ) || ( null !== $this->right && $this->right instanceof GC ) ) {
			$this->parent = null;
		} elseif ( null === $this->parent ) {
			if ( null !== $this->left ) {
				$this->parent    = $this->left->parent;
				$this->parentSub = $this->left->parentSub;
			} elseif ( null !== $this->right ) {
				$this->parent    = $this->right->parent;
				$this->parentSub = $this->right->parentSub;
			}
		} elseif ( $this->parent instanceof ID ) {
			$parentItem = \Yjs\getItem( $store, $this->parent );
			if ( $parentItem instanceof GC ) {
				$this->parent = null;
			} else {
				$this->parent = $parentItem->content->type;
			}
		}
		return null;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param int   $offset      Offset.
	 * @return void
	 */
	public function integrate( $transaction, int $offset ): void {
		if ( 0 < $offset ) {
			$this->id->clock += $offset;
			$this->left       = \Yjs\getItemCleanEnd( $transaction, $transaction->doc->store, \Yjs\createID( $this->id->client, $this->id->clock - 1 ) );
			$this->origin     = $this->left->lastId;
			$this->content    = $this->content->splice( $offset );
			$this->length    -= $offset;
		}

		if ( null !== $this->parent ) {
			if ( ( null === $this->left && ( null === $this->right || null !== $this->right->left ) ) || ( null !== $this->left && $this->left->right !== $this->right ) ) {
				$left = $this->left;
				if ( null !== $left ) {
					$o = $left->right;
				} elseif ( null !== $this->parentSub ) {
					$o = $this->parent->_map[ $this->parentSub ] ?? null;
					while ( null !== $o && null !== $o->left ) {
						$o = $o->left;
					}
				} else {
					$o = $this->parent->_start;
				}

				$conflictingItems  = new \SplObjectStorage();
				$itemsBeforeOrigin = new \SplObjectStorage();
				while ( null !== $o && $o !== $this->right ) {
					$itemsBeforeOrigin->attach( $o );
					$conflictingItems->attach( $o );
					if ( \Yjs\compareIDs( $this->origin, $o->origin ) ) {
						if ( $o->id->client < $this->id->client ) {
							$left             = $o;
							$conflictingItems = new \SplObjectStorage();
						} elseif ( \Yjs\compareIDs( $this->rightOrigin, $o->rightOrigin ) ) {
							break;
						}
					} elseif ( null !== $o->origin && $itemsBeforeOrigin->contains( \Yjs\getItem( $transaction->doc->store, $o->origin ) ) ) {
						if ( ! $conflictingItems->contains( \Yjs\getItem( $transaction->doc->store, $o->origin ) ) ) {
							$left             = $o;
							$conflictingItems = new \SplObjectStorage();
						}
					} else {
						break;
					}
					$o = $o->right;
				}
				$this->left = $left;
			}

			if ( null !== $this->left ) {
				$right             = $this->left->right;
				$this->right       = $right;
				$this->left->right = $this;
			} else {
				if ( null !== $this->parentSub ) {
					$r = $this->parent->_map[ $this->parentSub ] ?? null;
					while ( null !== $r && null !== $r->left ) {
						$r = $r->left;
					}
				} else {
					$r                    = $this->parent->_start;
					$this->parent->_start = $this;
				}
				$this->right = $r;
			}

			if ( null !== $this->right ) {
				$this->right->left = $this;
			} elseif ( null !== $this->parentSub ) {
				$this->parent->_map[ $this->parentSub ] = $this;
				if ( null !== $this->left ) {
					$this->left->delete( $transaction );
				}
			}

			if ( null === $this->parentSub && $this->countable && ! $this->deleted ) {
				$this->parent->_length += $this->length;
			}
			\Yjs\addStruct( $transaction->doc->store, $this );
			$this->content->integrate( $transaction, $this );
			\Yjs\addChangedTypeToTransaction( $transaction, $this->parent, $this->parentSub );
			if ( ( null !== $this->parent->_item && $this->parent->_item->deleted ) || ( null !== $this->parentSub && null !== $this->right ) ) {
				$this->delete( $transaction );
			}
		} else {
			( new GC( $this->id, $this->length ) )->integrate( $transaction, 0 );
		}
	}

	/**
	 * @param AbstractStruct $right Struct to merge.
	 * @return bool
	 */
	public function mergeWith( AbstractStruct $right ): bool {
		if (
			get_class( $this ) === get_class( $right ) &&
			$right instanceof Item &&
			\Yjs\compareIDs( $right->origin, $this->lastId ) &&
			$this->right === $right &&
			\Yjs\compareIDs( $this->rightOrigin, $right->rightOrigin ) &&
			$this->id->client === $right->id->client &&
			$this->id->clock + $this->length === $right->id->clock &&
			$this->deleted === $right->deleted &&
			null === $this->redone &&
			null === $right->redone &&
			get_class( $this->content ) === get_class( $right->content ) &&
			$this->content->mergeWith( $right->content )
		) {
			$searchMarker = $this->parent->_searchMarker ?? null;
			if ( null !== $searchMarker ) {
				foreach ( $searchMarker as $marker ) {
					if ( $marker->p === $right ) {
						$marker->p = $this;
						if ( ! $this->deleted && $this->countable ) {
							$marker->index -= $this->length;
						}
					}
				}
			}
			if ( $right->keep ) {
				$this->keep = true;
			}
			$this->right = $right->right;
			if ( null !== $this->right ) {
				$this->right->left = $this;
			}
			$this->length += $right->length;
			return true;
		}
		return false;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @return void
	 */
	public function delete( $transaction ): void {
		if ( ! $this->getDeleted() ) {
			$parent = $this->parent;
			if ( $this->countable && null === $this->parentSub ) {
				$parent->_length -= $this->length;
			}
			$this->markDeleted();
			\Yjs\addToDeleteSet( $transaction->deleteSet, $this->id->client, $this->id->clock, $this->length );
			\Yjs\addChangedTypeToTransaction( $transaction, $parent, $this->parentSub );
			$this->content->delete( $transaction );
		}
	}

	/**
	 * @param mixed $store      Store.
	 * @param bool  $parentGCd  Whether parent was garbage-collected.
	 * @return void
	 */
	public function gc( $store, bool $parentGCd ): void {
		if ( ! $this->deleted ) {
			Error::unexpectedCase();
		}
		$this->content->gc( $store );
		if ( $parentGCd ) {
			\Yjs\replaceStruct( $store, $this, new GC( $this->id, $this->length ) );
		} else {
			$this->content = new ContentDeleted( $this->length );
		}
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @param int   $offset  Offset.
	 * @param int   $unused  Unused encoding ref.
	 * @return void
	 */
	public function write( $encoder, int $offset, int $unused = 0 ): void {
		unset( $unused );
		$origin      = 0 < $offset ? \Yjs\createID( $this->id->client, $this->id->clock + $offset - 1 ) : $this->origin;
		$rightOrigin = $this->rightOrigin;
		$parentSub   = $this->parentSub;
		$info        = ( $this->content->getRef() & Binary::BITS5 ) |
			( null === $origin ? 0 : Binary::BIT8 ) |
			( null === $rightOrigin ? 0 : Binary::BIT7 ) |
			( null === $parentSub ? 0 : Binary::BIT6 );
		$encoder->writeInfo( $info );
		if ( null !== $origin ) {
			$encoder->writeLeftID( $origin );
		}
		if ( null !== $rightOrigin ) {
			$encoder->writeRightID( $rightOrigin );
		}
		if ( null === $origin && null === $rightOrigin ) {
			$parent = $this->parent;
			if ( is_object( $parent ) && property_exists( $parent, '_item' ) ) {
				$parentItem = $parent->_item;
				if ( null === $parentItem ) {
					$encoder->writeParentInfo( true );
					$encoder->writeString( \Yjs\findRootTypeKey( $parent ) );
				} else {
					$encoder->writeParentInfo( false );
					$encoder->writeLeftID( $parentItem->id );
				}
			} elseif ( is_string( $parent ) ) {
				$encoder->writeParentInfo( true );
				$encoder->writeString( $parent );
			} elseif ( $parent instanceof ID ) {
				$encoder->writeParentInfo( false );
				$encoder->writeLeftID( $parent );
			} else {
				Error::unexpectedCase();
			}
			if ( null !== $parentSub ) {
				$encoder->writeString( $parentSub );
			}
		}
		$this->content->write( $encoder, $offset );
	}

	/**
	 * @return Item|null
	 */
	private function getNext(): ?Item {
		$item = $this->right;
		while ( null !== $item && $item->deleted ) {
			$item = $item->right;
		}
		return $item;
	}

	/**
	 * @return Item|null
	 */
	private function getPrev(): ?Item {
		$item = $this->left;
		while ( null !== $item && $item->deleted ) {
			$item = $item->left;
		}
		return $item;
	}
}
