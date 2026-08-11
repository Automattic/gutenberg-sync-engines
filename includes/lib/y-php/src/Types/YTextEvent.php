<?php
/**
 * YText event.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

use Yjs\Structs\ContentEmbed;
use Yjs\Structs\ContentFormat;
use Yjs\Structs\ContentString;
use Yjs\Structs\ContentType;
use Yjs\Utils\Transaction;

/**
 * Event emitted by YText changes.
 */
class YTextEvent extends \Yjs\Utils\YEvent {
	/**
	 * @var bool
	 */
	public bool $childListChanged = false;

	/**
	 * @var array<string,bool>
	 */
	public array $keysChanged = array();

	/**
	 * @param YText                  $ytext       Changed text.
	 * @param Transaction            $transaction Transaction.
	 * @param array<int,string|null> $subs        Changed keys.
	 */
	public function __construct( YText $ytext, Transaction $transaction, array $subs ) {
		parent::__construct( $ytext, $transaction );
		foreach ( $subs as $sub ) {
			if ( null === $sub ) {
				$this->childListChanged = true;
			} else {
				$this->keysChanged[ $sub ] = true;
			}
		}
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		if ( 'changes' === $name ) {
			return $this->computeTextChanges();
		}
		if ( 'delta' === $name ) {
			return $this->computeDelta();
		}
		return parent::__get( $name );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function computeTextChanges(): array {
		if ( null === $this->_changes ) {
			$this->_changes = array(
				'keys'    => parent::__get( 'keys' ),
				'delta'   => $this->computeDelta(),
				'added'   => new \SplObjectStorage(),
				'deleted' => new \SplObjectStorage(),
			);
		}
		return $this->_changes;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function computeDelta(): array {
		if ( null !== $this->_delta ) {
			return $this->_delta;
		}

		$y     = $this->target->doc;
		$delta = array();
		if ( null === $y ) {
			$this->_delta = $delta;
			return $delta;
		}

		\Yjs\transact(
			$y,
			function ( Transaction $transaction ) use ( &$delta ): void {
				$currentAttributes = array();
				$oldAttributes     = array();
				$item              = $this->target->_start;
				$action            = null;
				$attributes        = array();
				$insert            = '';
				$retain            = 0;
				$deleteLen         = 0;

				$addOp = function () use ( &$delta, &$action, &$attributes, &$insert, &$retain, &$deleteLen, &$currentAttributes ): void {
					if ( null === $action ) {
						return;
					}

					$op = null;
					switch ( $action ) {
						case 'delete':
							if ( $deleteLen > 0 ) {
								$op = array( 'delete' => $deleteLen );
							}
							$deleteLen = 0;
							break;
						case 'insert':
							if ( ! is_string( $insert ) || '' !== $insert ) {
								$op = array( 'insert' => $insert );
								if ( count( $currentAttributes ) > 0 ) {
									$op['attributes'] = array();
									foreach ( $currentAttributes as $key => $value ) {
										if ( null !== $value ) {
											$op['attributes'][ $key ] = $value;
										}
									}
									if ( 0 === count( $op['attributes'] ) ) {
										unset( $op['attributes'] );
									}
								}
							}
							$insert = '';
							break;
						case 'retain':
							if ( $retain > 0 ) {
								$op = array( 'retain' => $retain );
								if ( count( $attributes ) > 0 ) {
									$op['attributes'] = $attributes;
								}
							}
							$retain = 0;
							break;
					}

					if ( null !== $op ) {
						$delta[] = $op;
					}
					$action = null;
				};

				while ( null !== $item ) {
					$content = $item->content;
					if ( $content instanceof ContentType || $content instanceof ContentEmbed ) {
						if ( $this->adds( $item ) ) {
							if ( ! $this->deletes( $item ) ) {
								$addOp();
								$action = 'insert';
								$insert = $content->getContent()[0];
								$addOp();
							}
						} elseif ( $this->deletes( $item ) ) {
							if ( 'delete' !== $action ) {
								$addOp();
								$action = 'delete';
							}
							++$deleteLen;
						} elseif ( ! $item->deleted ) {
							if ( 'retain' !== $action ) {
								$addOp();
								$action = 'retain';
							}
							++$retain;
						}
					} elseif ( $content instanceof ContentString ) {
						if ( $this->adds( $item ) ) {
							if ( ! $this->deletes( $item ) ) {
								if ( 'insert' !== $action ) {
									$addOp();
									$action = 'insert';
								}
								$insert .= $content->str;
							}
						} elseif ( $this->deletes( $item ) ) {
							if ( 'delete' !== $action ) {
								$addOp();
								$action = 'delete';
							}
							$deleteLen += $item->length;
						} elseif ( ! $item->deleted ) {
							if ( 'retain' !== $action ) {
								$addOp();
								$action = 'retain';
							}
							$retain += $item->length;
						}
					} elseif ( $content instanceof ContentFormat ) {
						$key   = $content->key;
						$value = $content->value;
						if ( $this->adds( $item ) ) {
							if ( ! $this->deletes( $item ) ) {
								$curVal = array_key_exists( $key, $currentAttributes ) ? $currentAttributes[ $key ] : null;
								if ( ! \Yjs\equalAttrs( $curVal, $value ) ) {
									if ( 'retain' === $action ) {
										$addOp();
									}
									$oldVal = array_key_exists( $key, $oldAttributes ) ? $oldAttributes[ $key ] : null;
									if ( \Yjs\equalAttrs( $value, $oldVal ) ) {
										unset( $attributes[ $key ] );
									} else {
										$attributes[ $key ] = $value;
									}
								} elseif ( null !== $value ) {
									$item->delete( $transaction );
								}
							}
						} elseif ( $this->deletes( $item ) ) {
							$oldAttributes[ $key ] = $value;
							$curVal                = array_key_exists( $key, $currentAttributes ) ? $currentAttributes[ $key ] : null;
							if ( ! \Yjs\equalAttrs( $curVal, $value ) ) {
								if ( 'retain' === $action ) {
									$addOp();
								}
								$attributes[ $key ] = $curVal;
							}
						} elseif ( ! $item->deleted ) {
							$oldAttributes[ $key ] = $value;
							if ( array_key_exists( $key, $attributes ) ) {
								$attr = $attributes[ $key ];
								if ( ! \Yjs\equalAttrs( $attr, $value ) ) {
									if ( 'retain' === $action ) {
										$addOp();
									}
									if ( null === $value ) {
										unset( $attributes[ $key ] );
									} else {
										$attributes[ $key ] = $value;
									}
								} elseif ( null !== $attr ) {
									$item->delete( $transaction );
								}
							}
						}
						if ( ! $item->deleted ) {
							if ( 'insert' === $action ) {
								$addOp();
							}
							\Yjs\updateCurrentAttributes( $currentAttributes, $content );
						}
					}
					$item = $item->right;
				}

				$addOp();
				while ( ! empty( $delta ) ) {
					$lastIndex = count( $delta ) - 1;
					$lastOp    = $delta[ $lastIndex ];
					if ( array_key_exists( 'retain', $lastOp ) && ! array_key_exists( 'attributes', $lastOp ) ) {
						array_pop( $delta );
					} else {
						break;
					}
				}
			}
		);

		$this->_delta = $delta;
		return $delta;
	}
}
