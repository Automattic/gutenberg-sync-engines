<?php
/**
 * Struct store.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Port of yjs/src/utils/StructStore.js.
 */
class StructStore {
	/**
	 * Per-client structs, keyed by client id.
	 *
	 * @var array<int,array<int,\Yjs\Structs\AbstractStruct>>
	 */
	public array $clients = array();

	/**
	 * @var array{missing:array<int,int>,update:\Yjs\Lib0\Buffer}|null
	 */
	public ?array $pendingStructs = null;

	/**
	 * @var \Yjs\Lib0\Buffer|null
	 */
	public $pendingDs = null;
}
