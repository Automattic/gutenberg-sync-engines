<?php
/**
 * YXml event.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Types;

/**
 * Event emitted by XML tree and attribute changes.
 */
class YXmlEvent extends \Yjs\Utils\YEvent {
	/**
	 * @var bool
	 */
	public bool $childListChanged = false;

	/**
	 * @var array<string,bool>
	 */
	public array $attributesChanged = array();

	/**
	 * @param YXmlFragment|\Yjs\Types\YXmlElement|\Yjs\Types\YXmlText $target      Changed XML type.
	 * @param array<int,string|null>                                  $subs        Changed keys.
	 * @param \Yjs\Utils\Transaction                                  $transaction Transaction.
	 */
	public function __construct( YXmlFragment $target, array $subs, \Yjs\Utils\Transaction $transaction ) {
		parent::__construct( $target, $transaction );
		foreach ( $subs as $sub ) {
			if ( null === $sub ) {
				$this->childListChanged = true;
			} else {
				$this->attributesChanged[ $sub ] = true;
			}
		}
	}
}
