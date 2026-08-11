<?php
/**
 * Event-handler namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @return Utils\EventHandler
 */
function createEventHandler(): Utils\EventHandler {
	return new Utils\EventHandler();
}

/**
 * @param Utils\EventHandler $eventHandler Event handler.
 * @param callable           $f            Listener.
 * @return void
 */
function addEventHandlerListener( Utils\EventHandler $eventHandler, callable $f ): void {
	$eventHandler->l[] = $f;
}

/**
 * @param Utils\EventHandler $eventHandler Event handler.
 * @param callable           $f            Listener.
 * @return void
 */
function removeEventHandlerListener( Utils\EventHandler $eventHandler, callable $f ): void {
	$eventHandler->l = array_values(
		array_filter(
			$eventHandler->l,
			static fn ( callable $g ): bool => $f !== $g
		)
	);
}

/**
 * @param Utils\EventHandler $eventHandler Event handler.
 * @param mixed              $arg0         First argument.
 * @param mixed              $arg1         Second argument.
 * @return void
 */
function callEventHandlerListeners( Utils\EventHandler $eventHandler, $arg0, $arg1 ): void {
	$listeners = $eventHandler->l;
	Lib0\Func::callAll( $listeners, array( $arg0, $arg1 ) );
}

/**
 * @param Types\AbstractType $type        Changed type.
 * @param Utils\Transaction  $transaction Transaction.
 * @param Utils\YEvent       $event       Event.
 * @return void
 */
function callTypeObservers( Types\AbstractType $type, Utils\Transaction $transaction, Utils\YEvent $event ): void {
	$changedType = $type;
	while ( true ) {
		$events                                   = $transaction->changedParentTypes->contains( $type ) ? $transaction->changedParentTypes[ $type ] : array();
		$events[]                                 = $event;
		$transaction->changedParentTypes[ $type ] = $events;
		if ( null === $type->_item ) {
			break;
		}
		$type = $type->_item->parent;
	}
	callEventHandlerListeners( $changedType->_eH, $event, $transaction );
}

/**
 * @param Utils\Transaction  $transaction Transaction.
 * @param Types\AbstractType $type        Changed type.
 * @param string|null        $parentSub   Changed parent key.
 * @return void
 */
function addChangedTypeToTransaction( Utils\Transaction $transaction, Types\AbstractType $type, ?string $parentSub ): void {
	$item = $type->_item;
	if ( null === $item || ( $item->id->clock < ( $transaction->beforeState[ $item->id->client ] ?? 0 ) && ! $item->deleted ) ) {
		$subs = $transaction->changed->contains( $type ) ? $transaction->changed[ $type ] : array();
		if ( ! in_array( $parentSub, $subs, true ) ) {
			$subs[] = $parentSub;
		}
		$transaction->changed[ $type ] = $subs;
	}
}
