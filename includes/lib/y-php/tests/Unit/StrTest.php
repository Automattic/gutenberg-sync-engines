<?php
/**
 * Str helper tests.
 *
 * These pin the contract of the utf16Length() and sliceUtf16() fast paths
 * (see src/Lib0/Str.php): the byte-histogram length formula, the ASCII and
 * BMP-only slice fast paths, the astral straddle behavior of the walk, and
 * the invalid-UTF-8 fallback (one unit per byte, matching the str_split()
 * path).
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Str;

/**
 * UTF-16 unit accounting on UTF-8 strings.
 */
final class StrTest extends TestCase {
	/**
	 * @return array<string,array{string,int}>
	 */
	public function utf16LengthProvider(): array {
		return array(
			'empty'              => array( '', 0 ),
			'ascii'              => array( 'hello', 5 ),
			'two-byte chars'     => array( 'héllo', 5 ),
			'three-byte chars'   => array( '日本語', 3 ),
			'astral char'        => array( '🎉', 2 ),
			'mixed astral'       => array( 'a🎉b', 4 ),
			'zwj family'         => array( '👩‍👩‍👧‍👦', 11 ),
			'invalid lead byte'  => array( "a\xFFb", 3 ),
			'truncated sequence' => array( "\xC3", 1 ),
			'lone continuation'  => array( "\x80\x80", 2 ),
		);
	}

	/**
	 * @dataProvider utf16LengthProvider
	 *
	 * @param string $string   Input.
	 * @param int    $expected UTF-16 code units.
	 * @return void
	 */
	public function testUtf16Length( string $string, int $expected ): void {
		$this->assertSame( $expected, Str::utf16Length( $string ) );
	}

	/**
	 * @return void
	 */
	public function testSliceUtf16Ascii(): void {
		$this->assertSame( 'el', Str::sliceUtf16( 'hello', 1, 3 ) );
		$this->assertSame( 'llo', Str::sliceUtf16( 'hello', 2 ) );
		$this->assertSame( '', Str::sliceUtf16( 'hello', 9, 12 ) );
		$this->assertSame( '', Str::sliceUtf16( 'hello', 3, 3 ) );
		$this->assertSame( 'hello', Str::sliceUtf16( 'hello', 0 ) );
	}

	/**
	 * @return void
	 */
	public function testSliceUtf16BmpMultibyte(): void {
		$this->assertSame( 'él', Str::sliceUtf16( 'héllo', 1, 3 ) );
		$this->assertSame( '本語', Str::sliceUtf16( '日本語', 1 ) );
		$this->assertSame( '', Str::sliceUtf16( '日本語', 5, 9 ) );
	}

	/**
	 * Invalid UTF-8 falls back to one char per byte and one UTF-16 unit per
	 * byte everywhere (length, slice, and the string codec), so slicing
	 * composes like substr() even on garbage input.
	 *
	 * @return void
	 */
	public function testSliceUtf16InvalidBytes(): void {
		$this->assertSame( "a\xF1", Str::sliceUtf16( "a\xF1b", 0, 2 ) );
		$this->assertSame( "\xF1b", Str::sliceUtf16( "a\xF1b", 1 ) );
		$this->assertSame( "\xFF", Str::sliceUtf16( "a\xFFb", 1, 2 ) );
	}

	/**
	 * Astral chars force the walk; a char overlapping the requested range
	 * is included whole (PHP cannot represent a lone surrogate half).
	 *
	 * @return void
	 */
	public function testSliceUtf16Astral(): void {
		$this->assertSame( 'a', Str::sliceUtf16( 'a🎉b', 0, 1 ) );
		$this->assertSame( '🎉', Str::sliceUtf16( 'a🎉b', 1, 3 ) );
		$this->assertSame( '🎉', Str::sliceUtf16( 'a🎉b', 1, 2 ) );
		$this->assertSame( 'b', Str::sliceUtf16( 'a🎉b', 3 ) );
	}
}
