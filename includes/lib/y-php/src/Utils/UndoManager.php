<?php
/**
 * Undo manager.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

use Yjs\Types\AbstractType;

/**
 * Port of yjs/src/utils/UndoManager.js.
 */
class UndoManager extends \Yjs\Lib0\Observable {
	/**
	 * @var array<int,AbstractType|Doc>
	 */
	public array $scope = array();

	/**
	 * @var Doc
	 */
	public Doc $doc;

	/**
	 * @var callable
	 */
	public $deleteFilter;

	/**
	 * @var array<int,mixed>
	 */
	public array $trackedOrigins;

	/**
	 * @var callable
	 */
	public $captureTransaction;

	/**
	 * @var array<int,StackItem>
	 */
	public array $undoStack = array();

	/**
	 * @var array<int,StackItem>
	 */
	public array $redoStack = array();

	/**
	 * @var bool
	 */
	public bool $undoing = false;

	/**
	 * @var bool
	 */
	public bool $redoing = false;

	/**
	 * @var StackItem|null
	 */
	public ?StackItem $currStackItem = null;

	/**
	 * @var int
	 */
	public int $lastChange = 0;

	/**
	 * @var bool
	 */
	public bool $ignoreRemoteMapChanges;

	/**
	 * @var int|float
	 */
	public $captureTimeout;

	/**
	 * @var callable
	 */
	public $afterTransactionHandler;

	/**
	 * @param Doc|AbstractType|array<int,Doc|AbstractType> $typeScope Scope.
	 * @param array<string,mixed>                          $options   Options.
	 */
	public function __construct( $typeScope, array $options = array() ) {
		$this->captureTimeout         = $options['captureTimeout'] ?? 500;
		$this->captureTransaction     = $options['captureTransaction'] ?? static function ( Transaction $transaction ): bool {
			unset( $transaction );
			return true;
		};
		$this->deleteFilter           = $options['deleteFilter'] ?? static function ( \Yjs\Structs\Item $item ): bool {
			unset( $item );
			return true;
		};
		$this->ignoreRemoteMapChanges = (bool) ( $options['ignoreRemoteMapChanges'] ?? false );
		$this->doc                    = $options['doc'] ?? $this->inferDoc( $typeScope );
		$this->trackedOrigins         = $this->normalizeTrackedOrigins( $options['trackedOrigins'] ?? array( null ) );
		$this->addTrackedOrigin( $this );
		$this->addToScope( $typeScope );

		$this->afterTransactionHandler = function ( Transaction $transaction ): void {
			$this->afterTransaction( $transaction );
		};
		$this->doc->on( 'afterTransaction', $this->afterTransactionHandler );
		$this->doc->on(
			'destroy',
			function (): void {
				$this->destroy();
			}
		);
	}

	/**
	 * @param Doc|AbstractType|array<int,Doc|AbstractType> $typeScope Scope.
	 * @return Doc
	 */
	private function inferDoc( $typeScope ): Doc {
		if ( is_array( $typeScope ) ) {
			$first = $typeScope[0] ?? null;
			if ( $first instanceof Doc ) {
				return $first;
			}
			if ( $first instanceof AbstractType && null !== $first->doc ) {
				return $first->doc;
			}
		}
		if ( $typeScope instanceof Doc ) {
			return $typeScope;
		}
		if ( $typeScope instanceof AbstractType && null !== $typeScope->doc ) {
			return $typeScope->doc;
		}
		throw new \InvalidArgumentException( 'UndoManager requires a document or integrated type scope.' );
	}

	/**
	 * @param mixed $origins Origins option.
	 * @return array<int,mixed>
	 */
	private function normalizeTrackedOrigins( $origins ): array {
		if ( $origins instanceof \SplObjectStorage ) {
			$result = array();
			foreach ( $origins as $origin ) {
				$result[] = $origin;
			}
			return $result;
		}
		if ( is_array( $origins ) ) {
			return array_values( $origins );
		}
		return array( $origins );
	}

	/**
	 * @param Transaction $transaction Transaction.
	 * @return void
	 */
	private function afterTransaction( Transaction $transaction ): void {
		if (
			! ( $this->captureTransaction )( $transaction ) ||
			! $this->scopeMatchesTransaction( $transaction ) ||
			! $this->isOriginTracked( $transaction->origin )
		) {
			return;
		}

		$undoing = $this->undoing;
		$redoing = $this->redoing;
		if ( $undoing ) {
			$this->stopCapturing();
		} elseif ( ! $redoing ) {
			$this->clear( false, true );
		}

		$insertions = new DeleteSet();
		foreach ( $transaction->afterState as $client => $endClock ) {
			$startClock = $transaction->beforeState[ $client ] ?? 0;
			$len        = $endClock - $startClock;
			if ( $len > 0 ) {
				\Yjs\addToDeleteSet( $insertions, (int) $client, $startClock, $len );
			}
		}

		$now    = \Yjs\Lib0\Time::getUnixTime();
		$didAdd = false;
		if ( $this->lastChange > 0 && $now - $this->lastChange < $this->captureTimeout && $this->activeStackCount( $undoing ) > 0 && ! $undoing && ! $redoing ) {
			$index                                 = count( $this->undoStack ) - 1;
			$this->undoStack[ $index ]->deletions  = \Yjs\mergeDeleteSets( array( $this->undoStack[ $index ]->deletions, $transaction->deleteSet ) );
			$this->undoStack[ $index ]->insertions = \Yjs\mergeDeleteSets( array( $this->undoStack[ $index ]->insertions, $insertions ) );
		} else {
			$stackItem = new StackItem( $transaction->deleteSet, $insertions );
			if ( $undoing ) {
				$this->redoStack[] = $stackItem;
			} else {
				$this->undoStack[] = $stackItem;
			}
			$didAdd = true;
		}

		if ( ! $undoing && ! $redoing ) {
			$this->lastChange = $now;
		}

		\Yjs\iterateDeletedStructs(
			$transaction,
			$transaction->deleteSet,
			function ( $item ) use ( $transaction ): void {
				if ( $item instanceof \Yjs\Structs\Item && $this->scopeContainsItem( $transaction->doc, $item ) ) {
					\Yjs\keepItem( $item, true );
				}
			}
		);

		$stack       = $undoing ? $this->redoStack : $this->undoStack;
		$changeEvent = array(
			'stackItem'          => $stack[ count( $stack ) - 1 ],
			'origin'             => $transaction->origin,
			'type'               => $undoing ? 'redo' : 'undo',
			'changedParentTypes' => $transaction->changedParentTypes,
		);
		$this->emit( $didAdd ? 'stack-item-added' : 'stack-item-updated', array( $changeEvent, $this ) );
	}

	/**
	 * @param bool $undoing Whether the undo stack is active.
	 * @return int
	 */
	private function activeStackCount( bool $undoing ): int {
		return count( $undoing ? $this->redoStack : $this->undoStack );
	}

	/**
	 * @param Transaction $transaction Transaction.
	 * @return bool
	 */
	private function scopeMatchesTransaction( Transaction $transaction ): bool {
		foreach ( $this->scope as $type ) {
			if ( $type === $this->doc ) {
				return true;
			}
			if ( $type instanceof AbstractType && $transaction->changedParentTypes->contains( $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param mixed $origin Origin.
	 * @return bool
	 */
	private function isOriginTracked( $origin ): bool {
		foreach ( $this->trackedOrigins as $tracked ) {
			if ( $tracked === $origin ) {
				return true;
			}
			if ( is_object( $origin ) && is_string( $tracked ) && is_a( $origin, $tracked ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param Doc               $doc  Transaction document.
	 * @param \Yjs\Structs\Item $item Item.
	 * @return bool
	 */
	public function scopeContainsItem( Doc $doc, \Yjs\Structs\Item $item ): bool {
		foreach ( $this->scope as $type ) {
			if ( $type === $doc || ( $type instanceof AbstractType && \Yjs\isParentOf( $type, $item ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param Doc|AbstractType|array<int,Doc|AbstractType> $ytypes Types to add.
	 * @return void
	 */
	public function addToScope( $ytypes ): void {
		$items = is_array( $ytypes ) ? $ytypes : array( $ytypes );
		foreach ( $items as $ytype ) {
			if ( ! in_array( $ytype, $this->scope, true ) ) {
				$this->scope[] = $ytype;
			}
		}
	}

	/**
	 * @param mixed $origin Origin.
	 * @return void
	 */
	public function addTrackedOrigin( $origin ): void {
		if ( ! in_array( $origin, $this->trackedOrigins, true ) ) {
			$this->trackedOrigins[] = $origin;
		}
	}

	/**
	 * @param mixed $origin Origin.
	 * @return void
	 */
	public function removeTrackedOrigin( $origin ): void {
		$this->trackedOrigins = array_values(
			array_filter(
				$this->trackedOrigins,
				static fn ( $tracked ): bool => $tracked !== $origin
			)
		);
	}

	/**
	 * @param bool $clearUndoStack Whether to clear undo stack.
	 * @param bool $clearRedoStack Whether to clear redo stack.
	 * @return void
	 */
	public function clear( bool $clearUndoStack = true, bool $clearRedoStack = true ): void {
		if ( ( $clearUndoStack && $this->canUndo() ) || ( $clearRedoStack && $this->canRedo() ) ) {
			$this->doc->transact(
				function ( Transaction $tr ) use ( $clearUndoStack, $clearRedoStack ): void {
					if ( $clearUndoStack ) {
						foreach ( $this->undoStack as $item ) {
							\Yjs\clearUndoManagerStackItem( $tr, $this, $item );
						}
						$this->undoStack = array();
					}
					if ( $clearRedoStack ) {
						foreach ( $this->redoStack as $item ) {
							\Yjs\clearUndoManagerStackItem( $tr, $this, $item );
						}
						$this->redoStack = array();
					}
					$this->emit(
						'stack-cleared',
						array(
							array(
								'undoStackCleared' => $clearUndoStack,
								'redoStackCleared' => $clearRedoStack,
							),
						)
					);
				}
			);
		}
	}

	/**
	 * @return void
	 */
	public function stopCapturing(): void {
		$this->lastChange = 0;
	}

	/**
	 * @return StackItem|null
	 */
	public function undo(): ?StackItem {
		$this->undoing = true;
		try {
			return \Yjs\popStackItem( $this, $this->undoStack, 'undo' );
		} finally {
			$this->undoing = false;
		}
	}

	/**
	 * @return StackItem|null
	 */
	public function redo(): ?StackItem {
		$this->redoing = true;
		try {
			return \Yjs\popStackItem( $this, $this->redoStack, 'redo' );
		} finally {
			$this->redoing = false;
		}
	}

	/**
	 * @return bool
	 */
	public function canUndo(): bool {
		return count( $this->undoStack ) > 0;
	}

	/**
	 * @return bool
	 */
	public function canRedo(): bool {
		return count( $this->redoStack ) > 0;
	}

	/**
	 * @return void
	 */
	public function destroy(): void {
		$this->removeTrackedOrigin( $this );
		$this->doc->off( 'afterTransaction', $this->afterTransactionHandler );
		parent::destroy();
	}
}
