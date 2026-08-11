<?php
/**
 * Base class for translated Yjs tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Support;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Prng;
use Yjs\Lib0\Xoroshiro128plus;

/**
 * Base class for translated tests that need deterministic randomness.
 */
abstract class TranslatedTestCase extends TestCase {
	/**
	 * @var Xoroshiro128plus
	 */
	public Xoroshiro128plus $prng;

	protected function setUp(): void {
		parent::setUp();
		$this->prng = Prng::create( 0 );
	}
}
