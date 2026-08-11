<?php
/**
 * YText shared rich-text type.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

use Yjs\Structs\ContentEmbed;
use Yjs\Structs\ContentFormat;
use Yjs\Structs\ContentString;
use Yjs\Structs\ContentType;
use Yjs\Utils\ID;
use Yjs\Utils\Snapshot;
use Yjs\Utils\Transaction;

/**
 * Port of yjs/src/types/YText.js.
 */
class YText extends AbstractType {
	/**
	 * @var array<int,callable>|null
	 */
	public ?array $_pending;

	/**
	 * @param string|null $string Initial text.
	 */
	public function __construct( ?string $string = null ) {
		parent::__construct();
		$this->_pending       = array();
		$this->_searchMarker  = array();
		$this->_hasFormatting = false;

		if ( null !== $string ) {
			$this->_pending[] = function () use ( $string ): void {
				$this->insert( 0, $string );
			};
		}
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		if ( 'length' === $name ) {
			return $this->_length;
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
		foreach ( $this->_pending ?? array() as $f ) {
			$f();
		}
		$this->_pending = null;
	}

	/**
	 * @return YText
	 */
	public function _copy(): YText {
		return new YText();
	}

	/**
	 * @return YText
	 */
	public function clone(): YText {
		$text = new YText();
		$text->applyDelta( $this->toDelta() );
		return $text;
	}

	/**
	 * @param Transaction            $transaction Transaction.
	 * @param array<int,string|null> $parentSubs  Changed parent subs.
	 * @return void
	 */
	public function _callObserver( $transaction, array $parentSubs ): void {
		parent::_callObserver( $transaction, $parentSubs );
		\Yjs\callTypeObservers( $this, $transaction, new YTextEvent( $this, $transaction, $parentSubs ) );
		if ( ! $transaction->local && $this->_hasFormatting ) {
			$transaction->_needFormattingCleanup = true;
		}
	}

	/**
	 * @return string
	 */
	public function toString(): string {
		$out = '';
		for ( $item = $this->_start; null !== $item; $item = $item->right ) {
			if ( ! $item->deleted && $item->countable && $item->content instanceof ContentString ) {
				$out .= $item->content->str;
			}
		}
		return $out;
	}

	/**
	 * @return string
	 */
	public function toJSON(): string {
		return $this->toString();
	}

	/**
	 * @param array<int,array<string,mixed>> $delta Delta operations.
	 * @param array<string,mixed>            $opts  Options.
	 * @return void
	 */
	public function applyDelta( array $delta, array $opts = array() ): void {
		$sanitize = array_key_exists( 'sanitize', $opts ) ? (bool) $opts['sanitize'] : true;
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( Transaction $transaction ) use ( $delta, $sanitize ): void {
					$currPos  = new ItemTextListPosition( null, $this->_start, 0, array() );
					$deltaLen = count( $delta );
					for ( $i = 0; $i < $deltaLen; $i++ ) {
						$op = $delta[ $i ];
						if ( \Yjs\opHas( $op, 'insert' ) ) {
							$insert = \Yjs\opGet( $op, 'insert' );
							$ins    = (
								! $sanitize &&
								is_string( $insert ) &&
								$i === $deltaLen - 1 &&
								null === $currPos->right &&
								"\n" === substr( $insert, -1 )
							) ? substr( $insert, 0, -1 ) : $insert;

							if ( ! is_string( $ins ) || '' !== $ins ) {
								\Yjs\insertText( $transaction, $this, $currPos, $ins, \Yjs\opGet( $op, 'attributes', array() ) );
							}
						} elseif ( \Yjs\opHas( $op, 'retain' ) ) {
							\Yjs\formatText( $transaction, $this, $currPos, (int) \Yjs\opGet( $op, 'retain' ), \Yjs\opGet( $op, 'attributes', array() ) );
						} elseif ( \Yjs\opHas( $op, 'delete' ) ) {
							\Yjs\deleteText( $transaction, $currPos, (int) \Yjs\opGet( $op, 'delete' ) );
						}
					}
				}
			);
		} else {
			$this->_pending[] = function () use ( $delta ): void {
				$this->applyDelta( $delta );
			};
		}
	}

	/**
	 * @param Snapshot|null $snapshot       Snapshot.
	 * @param Snapshot|null $prevSnapshot   Previous snapshot.
	 * @param callable|null $computeYChange Y-change mapper.
	 * @return array<int,array<string,mixed>>
	 */
	public function toDelta( ?Snapshot $snapshot = null, ?Snapshot $prevSnapshot = null, ?callable $computeYChange = null ): array {
		$ops               = array();
		$currentAttributes = array();
		$doc               = $this->doc;
		$str               = '';
		$n                 = $this->_start;

		$packStr = function () use ( &$ops, &$currentAttributes, &$str ): void {
			if ( '' === $str ) {
				return;
			}
			$op = array( 'insert' => $str );
			if ( count( $currentAttributes ) > 0 ) {
				$op['attributes'] = $currentAttributes;
			}
			$ops[] = $op;
			$str   = '';
		};

		$computeDelta = function () use ( &$n, &$str, &$ops, &$currentAttributes, $snapshot, $prevSnapshot, $computeYChange, $packStr ): void {
			while ( null !== $n ) {
				if ( \Yjs\isVisible( $n, $snapshot ) || ( null !== $prevSnapshot && \Yjs\isVisible( $n, $prevSnapshot ) ) ) {
					$content = $n->content;
					if ( $content instanceof ContentString ) {
						$cur = $currentAttributes['ychange'] ?? null;
						if ( null !== $snapshot && ! \Yjs\isVisible( $n, $snapshot ) ) {
							if ( null === $cur || \Yjs\changeAttrGet( $cur, 'user' ) !== $n->id->client || 'removed' !== \Yjs\changeAttrGet( $cur, 'type' ) ) {
								$packStr();
								$currentAttributes['ychange'] = null !== $computeYChange ? $computeYChange( 'removed', $n->id ) : array( 'type' => 'removed' );
							}
						} elseif ( null !== $prevSnapshot && ! \Yjs\isVisible( $n, $prevSnapshot ) ) {
							if ( null === $cur || \Yjs\changeAttrGet( $cur, 'user' ) !== $n->id->client || 'added' !== \Yjs\changeAttrGet( $cur, 'type' ) ) {
								$packStr();
								$currentAttributes['ychange'] = null !== $computeYChange ? $computeYChange( 'added', $n->id ) : array( 'type' => 'added' );
							}
						} elseif ( null !== $cur ) {
							$packStr();
							unset( $currentAttributes['ychange'] );
						}
						$str .= $content->str;
					} elseif ( $content instanceof ContentType || $content instanceof ContentEmbed ) {
						$packStr();
						$op = array( 'insert' => $content->getContent()[0] );
						if ( count( $currentAttributes ) > 0 ) {
							$op['attributes'] = $currentAttributes;
						}
						$ops[] = $op;
					} elseif ( $content instanceof ContentFormat ) {
						if ( \Yjs\isVisible( $n, $snapshot ) ) {
							$packStr();
							\Yjs\updateCurrentAttributes( $currentAttributes, $content );
						}
					}
				}
				$n = $n->right;
			}
			$packStr();
		};

		if ( ( null !== $snapshot || null !== $prevSnapshot ) && null !== $doc ) {
			\Yjs\transact(
				$doc,
				function ( Transaction $transaction ) use ( $snapshot, $prevSnapshot, $computeDelta ): void {
					if ( null !== $snapshot ) {
						\Yjs\splitSnapshotAffectedStructs( $transaction, $snapshot );
					}
					if ( null !== $prevSnapshot ) {
						\Yjs\splitSnapshotAffectedStructs( $transaction, $prevSnapshot );
					}
					$computeDelta();
				},
				'cleanup'
			);
		} else {
			$computeDelta();
		}

		return $ops;
	}

	/**
	 * @param int                      $index      Insert index.
	 * @param string                   $text       Text content.
	 * @param array<string,mixed>|null $attributes Formatting attributes.
	 * @return void
	 */
	public function insert( int $index, string $text, ?array $attributes = null ): void {
		if ( '' === $text ) {
			return;
		}
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( Transaction $transaction ) use ( $index, $text, $attributes ): void {
					$pos        = \Yjs\findPosition( $transaction, $this, $index, null === $attributes );
					$attrsToUse = $attributes;
					if ( null === $attrsToUse ) {
						$attrsToUse = array();
						foreach ( $pos->currentAttributes as $key => $value ) {
							$attrsToUse[ $key ] = $value;
						}
					}
					\Yjs\insertText( $transaction, $this, $pos, $text, $attrsToUse );
				}
			);
		} else {
			$this->_pending[] = function () use ( $index, $text, $attributes ): void {
				$this->insert( $index, $text, $attributes );
			};
		}
	}

	/**
	 * @param int                      $index      Insert index.
	 * @param mixed                    $embed      Embed.
	 * @param array<string,mixed>|null $attributes Formatting attributes.
	 * @return void
	 */
	public function insertEmbed( int $index, $embed, ?array $attributes = null ): void {
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( Transaction $transaction ) use ( $index, $embed, $attributes ): void {
					$pos = \Yjs\findPosition( $transaction, $this, $index, null === $attributes );
					\Yjs\insertText( $transaction, $this, $pos, $embed, $attributes ?? array() );
				}
			);
		} else {
			$this->_pending[] = function () use ( $index, $embed, $attributes ): void {
				$this->insertEmbed( $index, $embed, $attributes ?? array() );
			};
		}
	}

	/**
	 * @param int $index  Delete index.
	 * @param int $length Delete length.
	 * @return void
	 */
	public function delete( int $index, int $length = 1 ): void {
		if ( 0 === $length ) {
			return;
		}
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( Transaction $transaction ) use ( $index, $length ): void {
					\Yjs\deleteText( $transaction, \Yjs\findPosition( $transaction, $this, $index, true ), $length );
				}
			);
		} else {
			$this->_pending[] = function () use ( $index, $length ): void {
				$this->delete( $index, $length );
			};
		}
	}

	/**
	 * @param int                 $index      Format index.
	 * @param int                 $length     Format length.
	 * @param array<string,mixed> $attributes Formatting attributes.
	 * @return void
	 */
	public function format( int $index, int $length, array $attributes ): void {
		if ( 0 === $length ) {
			return;
		}
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( Transaction $transaction ) use ( $index, $length, $attributes ): void {
					$pos = \Yjs\findPosition( $transaction, $this, $index, false );
					if ( null === $pos->right ) {
						return;
					}
					\Yjs\formatText( $transaction, $this, $pos, $length, $attributes );
				}
			);
		} else {
			$this->_pending[] = function () use ( $index, $length, $attributes ): void {
				$this->format( $index, $length, $attributes );
			};
		}
	}

	/**
	 * @param string $attributeName Attribute name.
	 * @return void
	 */
	public function removeAttribute( string $attributeName ): void {
		if ( null !== $this->doc ) {
			\Yjs\transact(
				$this->doc,
				function ( Transaction $transaction ) use ( $attributeName ): void {
					\Yjs\typeMapDelete( $transaction, $this, $attributeName );
				}
			);
		} else {
			$this->_pending[] = function () use ( $attributeName ): void {
				$this->removeAttribute( $attributeName );
			};
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
				function ( Transaction $transaction ) use ( $attributeName, $attributeValue ): void {
					\Yjs\typeMapSet( $transaction, $this, $attributeName, $attributeValue );
				}
			);
		} else {
			$this->_pending[] = function () use ( $attributeName, $attributeValue ): void {
				$this->setAttribute( $attributeName, $attributeValue );
			};
		}
	}

	/**
	 * @param string $attributeName Attribute name.
	 * @return mixed
	 */
	public function getAttribute( string $attributeName ) {
		return \Yjs\typeMapGet( $this, $attributeName );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getAttributes(): array {
		return \Yjs\typeMapGetAll( $this );
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @return void
	 */
	public function _write( $encoder ): void {
		$encoder->writeTypeRef( 2 );
	}
}
