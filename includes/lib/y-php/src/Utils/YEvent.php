<?php
/**
 * YEvent API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Event object describing changes on a Yjs type.
 */
class YEvent {
	/**
	 * @var \Yjs\Types\AbstractType
	 */
	public \Yjs\Types\AbstractType $target;

	/**
	 * @var \Yjs\Types\AbstractType
	 */
	public \Yjs\Types\AbstractType $currentTarget;

	/**
	 * @var Transaction
	 */
	public Transaction $transaction;

	/**
	 * @var mixed
	 */
	public $_changes = null;

	/**
	 * @var mixed
	 */
	public $_keys = null;

	/**
	 * @var mixed
	 */
	public $_delta = null;

	/**
	 * @var array<int,string|int>|null
	 */
	public ?array $_path = null;

	/**
	 * @param \Yjs\Types\AbstractType $target      Changed type.
	 * @param Transaction             $transaction Transaction.
	 */
	public function __construct( \Yjs\Types\AbstractType $target, Transaction $transaction ) {
		$this->target        = $target;
		$this->currentTarget = $target;
		$this->transaction   = $transaction;
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		if ( 'path' === $name ) {
			if ( null === $this->_path ) {
				$this->_path = \Yjs\getPathTo( $this->currentTarget, $this->target );
			}
			return $this->_path;
		}
		if ( 'delta' === $name ) {
			$changes = $this->__get( 'changes' );
			return $changes['delta'];
		}
		if ( 'changes' === $name ) {
			return $this->computeChanges();
		}
		if ( 'keys' === $name ) {
			return $this->computeKeys();
		}
		return null;
	}

	/**
	 * @param \Yjs\Structs\AbstractStruct $struct Struct.
	 * @return bool
	 */
	public function deletes( \Yjs\Structs\AbstractStruct $struct ): bool {
		return \Yjs\isDeleted( $this->transaction->deleteSet, $struct->id );
	}

	/**
	 * @param \Yjs\Structs\AbstractStruct $struct Struct.
	 * @return bool
	 */
	public function adds( \Yjs\Structs\AbstractStruct $struct ): bool {
		return $struct->id->clock >= ( $this->transaction->beforeState[ $struct->id->client ] ?? 0 );
	}

	/**
	 * @return array<int,string|int>
	 */
	public function getPath(): array {
		return $this->__get( 'path' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function computeChanges(): array {
		if ( null === $this->_changes ) {
			$this->assertCanComputeChanges();

			$target  = $this->target;
			$added   = new \SplObjectStorage();
			$deleted = new \SplObjectStorage();
			$delta   = array();

			$changed = $this->transaction->changed->contains( $target ) ? $this->transaction->changed[ $target ] : array();
			if ( in_array( null, $changed, true ) ) {
				$lastOp = null;
				$packOp = static function () use ( &$delta, &$lastOp ): void {
					if ( null !== $lastOp ) {
						$delta[] = $lastOp;
					}
				};

				for ( $item = $target->_start; null !== $item; $item = $item->right ) {
					if ( $item->deleted ) {
						if ( $this->deletes( $item ) && ! $this->adds( $item ) ) {
							if ( null === $lastOp || ! array_key_exists( 'delete', $lastOp ) ) {
								$packOp();
								$lastOp = array( 'delete' => 0 );
							}
							$lastOp['delete'] += $item->length;
							$deleted->attach( $item );
						}
					} elseif ( $this->adds( $item ) ) {
						if ( null === $lastOp || ! array_key_exists( 'insert', $lastOp ) ) {
							$packOp();
							$lastOp = array( 'insert' => array() );
						}
						$lastOp['insert'] = array_merge( $lastOp['insert'], $item->content->getContent() );
						$added->attach( $item );
					} else {
						if ( null === $lastOp || ! array_key_exists( 'retain', $lastOp ) ) {
							$packOp();
							$lastOp = array( 'retain' => 0 );
						}
						$lastOp['retain'] += $item->length;
					}
				}

				if ( null !== $lastOp && ! array_key_exists( 'retain', $lastOp ) ) {
					$packOp();
				}
			}

			$this->_changes = array(
				'added'   => $added,
				'deleted' => $deleted,
				'delta'   => $delta,
				'keys'    => $this->computeKeys(),
			);
		}
		return $this->_changes;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function computeKeys(): array {
		if ( null === $this->_keys ) {
			$this->assertCanComputeChanges();

			$keys    = array();
			$target  = $this->target;
			$changed = $this->transaction->changed->contains( $target ) ? $this->transaction->changed[ $target ] : array();

			foreach ( $changed as $key ) {
				if ( null === $key ) {
					continue;
				}
				$item = $target->_map[ $key ] ?? null;
				if ( null === $item ) {
					continue;
				}

				$action   = null;
				$oldValue = \Yjs\Lib0\UndefinedValue::getInstance();

				if ( $this->adds( $item ) ) {
					$prev = $item->left;
					while ( null !== $prev && $this->adds( $prev ) ) {
						$prev = $prev->left;
					}
					if ( $this->deletes( $item ) ) {
						if ( null !== $prev && $this->deletes( $prev ) ) {
							$action   = 'delete';
							$oldValue = $this->lastContentValue( $prev );
						} else {
							continue;
						}
					} elseif ( null !== $prev && $this->deletes( $prev ) ) {
						$action   = 'update';
						$oldValue = $this->lastContentValue( $prev );
					} else {
						$action = 'add';
					}
				} elseif ( $this->deletes( $item ) ) {
					$action   = 'delete';
					$oldValue = $this->lastContentValue( $item );
				} else {
					continue;
				}

				$keys[ (string) $key ] = array(
					'action'   => $action,
					'oldValue' => $oldValue,
				);
			}

			$this->_keys = $keys;
		}
		return $this->_keys;
	}

	private function assertCanComputeChanges(): void {
		if ( 0 === count( $this->transaction->doc->_transactionCleanups ) ) {
			throw \Yjs\Lib0\Error::create( 'You must not compute changes after the event-handler fired.' );
		}
	}

	/**
	 * @param \Yjs\Structs\Item $item Item.
	 * @return mixed
	 */
	private function lastContentValue( \Yjs\Structs\Item $item ) {
		$content = $item->content->getContent();
		return $content[ count( $content ) - 1 ];
	}
}
