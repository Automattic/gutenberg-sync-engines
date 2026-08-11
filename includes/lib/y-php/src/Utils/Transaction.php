<?php
/**
 * Transaction API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Port of yjs/src/utils/Transaction.js Transaction.
 */
class Transaction {
	/**
	 * @var Doc
	 */
	public Doc $doc;

	/**
	 * @var DeleteSet
	 */
	public DeleteSet $deleteSet;

	/**
	 * @var array<int,int>
	 */
	public array $beforeState;

	/**
	 * @var array<int,int>
	 */
	public array $afterState = array();

	/**
	 * Maps changed types to an array acting as Set<string|null>.
	 *
	 * @var \SplObjectStorage<object,array<int,string|null>>
	 */
	public \SplObjectStorage $changed;

	/**
	 * Maps parent types to queued deep events.
	 *
	 * @var \SplObjectStorage<object,array<int,YEvent>>
	 */
	public \SplObjectStorage $changedParentTypes;

	/**
	 * @var array<int,\Yjs\Structs\AbstractStruct>
	 */
	public array $_mergeStructs = array();

	/**
	 * @var mixed
	 */
	public $origin;

	/**
	 * @var array<string,mixed>
	 */
	public array $meta = array();

	/**
	 * @var bool
	 */
	public bool $local;

	/**
	 * @var \SplObjectStorage<Doc,null>
	 */
	public \SplObjectStorage $subdocsAdded;

	/**
	 * @var \SplObjectStorage<Doc,null>
	 */
	public \SplObjectStorage $subdocsRemoved;

	/**
	 * @var \SplObjectStorage<Doc,null>
	 */
	public \SplObjectStorage $subdocsLoaded;

	/**
	 * @var bool
	 */
	public bool $_needFormattingCleanup = false;

	/**
	 * @param Doc   $doc    Document.
	 * @param mixed $origin Transaction origin.
	 * @param bool  $local  Whether transaction is local.
	 */
	public function __construct( Doc $doc, $origin, bool $local ) {
		$this->doc                = $doc;
		$this->deleteSet          = new DeleteSet();
		$this->beforeState        = \Yjs\getStateVector( $doc->store );
		$this->changed            = new \SplObjectStorage();
		$this->changedParentTypes = new \SplObjectStorage();
		$this->origin             = $origin;
		$this->local              = $local;
		$this->subdocsAdded       = new \SplObjectStorage();
		$this->subdocsRemoved     = new \SplObjectStorage();
		$this->subdocsLoaded      = new \SplObjectStorage();
	}
}
