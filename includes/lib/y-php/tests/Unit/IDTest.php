<?php
/**
 * ID unit tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yjs\Utils\ID;

use function Yjs\compareIDs;
use function Yjs\createID;
use function Yjs\findRootTypeKey;

/**
 * Tests for yjs/src/utils/ID.js helpers.
 */
final class IDTest extends TestCase {
	/**
	 * @return void
	 */
	public function testCreateIDStoresClientAndClock(): void {
		$id = createID( 4294967295, 128 );

		self::assertInstanceOf( ID::class, $id );
		self::assertSame( 4294967295, $id->client );
		self::assertSame( 128, $id->clock );
	}

	/**
	 * @return void
	 */
	public function testCompareIDsMatchesClientAndClockSemantics(): void {
		$id = createID( 1, 2 );

		self::assertTrue( compareIDs( $id, $id ) );
		self::assertTrue( compareIDs( createID( 1, 2 ), createID( 1, 2 ) ) );
		self::assertTrue( compareIDs( null, null ) );
		self::assertFalse( compareIDs( null, createID( 1, 2 ) ) );
		self::assertFalse( compareIDs( createID( 1, 2 ), null ) );
		self::assertFalse( compareIDs( createID( 1, 2 ), createID( 2, 2 ) ) );
		self::assertFalse( compareIDs( createID( 1, 2 ), createID( 1, 3 ) ) );
	}

	/**
	 * @return void
	 */
	public function testFindRootTypeKeyUsesDocShareIdentity(): void {
		$type      = new \stdClass();
		$other     = new \stdClass();
		$type->doc = (object) array(
			'share' => array(
				'other' => $other,
				'root'  => $type,
			),
		);

		self::assertSame( 'root', findRootTypeKey( $type ) );
	}

	/**
	 * @return void
	 */
	public function testFindRootTypeKeyThrowsWhenTypeIsNotShared(): void {
		$this->expectException( \RuntimeException::class );

		$type      = new \stdClass();
		$type->doc = (object) array(
			'share' => array(),
		);

		findRootTypeKey( $type );
	}
}
