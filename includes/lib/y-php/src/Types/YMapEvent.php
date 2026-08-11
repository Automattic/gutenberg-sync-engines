<?php
/**
 * YMap event API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

/**
 * Event emitted by YMap changes.
 */
class YMapEvent extends \Yjs\Utils\YEvent {
	/**
	 * @var array<int,string|null>
	 */
	public array $keysChanged;

	/**
	 * @param YMap                   $ymap        Changed map.
	 * @param \Yjs\Utils\Transaction $transaction Transaction.
	 * @param array<int,string|null> $subs        Changed keys.
	 */
	public function __construct( YMap $ymap, \Yjs\Utils\Transaction $transaction, array $subs ) {
		parent::__construct( $ymap, $transaction );
		$this->keysChanged = $subs;
	}
}
