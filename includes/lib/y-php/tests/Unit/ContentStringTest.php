<?php
/**
 * ContentString tests.
 *
 * These pin the UTF-16 unit accounting of getContent(), getLength() and
 * splice() across the ASCII, BMP-multibyte and astral lanes (see
 * src/Structs/ContentString.php). JS Yjs represents content as one array
 * entry per UTF-16 code unit, so an astral char contributes two entries;
 * PHP cannot represent a lone surrogate half, so each half becomes
 * U+FFFD REPLACEMENT CHARACTER.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yjs\Structs\ContentString;

/**
 * UTF-16 unit semantics of string item content.
 */
final class ContentStringTest extends TestCase {
	private const FFFD = "\xEF\xBF\xBD";

	/**
	 * @return void
	 */
	public function testGetContentEmpty(): void {
		$content = new ContentString( '' );
		$this->assertSame( array(), $content->getContent() );
		$this->assertSame( 0, $content->getLength() );
	}

	/**
	 * @return void
	 */
	public function testGetContentAscii(): void {
		$content = new ContentString( 'abc' );
		$this->assertSame( array( 'a', 'b', 'c' ), $content->getContent() );
		$this->assertSame( 3, $content->getLength() );
	}

	/**
	 * @return void
	 */
	public function testGetContentBmpMultibyte(): void {
		$content = new ContentString( 'héé日' );
		$this->assertSame( array( 'h', 'é', 'é', '日' ), $content->getContent() );
		$this->assertSame( 4, $content->getLength() );
	}

	/**
	 * An astral char occupies two UTF-16 units, so it contributes two
	 * REPLACEMENT CHARACTER entries (one per surrogate half).
	 *
	 * @return void
	 */
	public function testGetContentAstral(): void {
		$content = new ContentString( 'a🎉b' );
		$this->assertSame( array( 'a', self::FFFD, self::FFFD, 'b' ), $content->getContent() );
		$this->assertSame( 4, $content->getLength() );
	}

	/**
	 * @return void
	 */
	public function testSpliceAscii(): void {
		$content = new ContentString( 'hello' );
		$right   = $content->splice( 2 );
		$this->assertSame( 'he', $content->str );
		$this->assertSame( 'llo', $right->str );
	}

	/**
	 * @return void
	 */
	public function testSpliceBmpMultibyte(): void {
		$content = new ContentString( 'héllo' );
		$right   = $content->splice( 2 );
		$this->assertSame( 'hé', $content->str );
		$this->assertSame( 'llo', $right->str );
	}

	/**
	 * A split offset landing inside an astral char leaves a replacement
	 * character on each side (each side holds one surrogate half).
	 *
	 * @return void
	 */
	public function testSpliceInsideAstralChar(): void {
		$content = new ContentString( 'a🎉b' );
		$right   = $content->splice( 2 );
		$this->assertSame( 'a' . self::FFFD, $content->str );
		$this->assertSame( self::FFFD . 'b', $right->str );
	}

	/**
	 * @return void
	 */
	public function testSpliceAtAstralCharBoundary(): void {
		$content = new ContentString( 'a🎉b' );
		$right   = $content->splice( 3 );
		$this->assertSame( 'a🎉', $content->str );
		$this->assertSame( 'b', $right->str );
	}
}
