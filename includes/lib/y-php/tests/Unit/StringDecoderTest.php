<?php
/**
 * StringDecoder round-trip tests.
 *
 * These pin the sequential-read contract the forward-cursor implementation
 * relies on (see src/Lib0/StringDecoder.php): many small strings, multibyte
 * and astral chars across read boundaries, zero-length reads.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\StringDecoder;
use Yjs\Lib0\StringEncoder;

/**
 * Encode/decode round-trips through the string codec.
 */
final class StringDecoderTest extends TestCase {
	/**
	 * Round-trips a list of strings and asserts exact recovery.
	 *
	 * @param array<int,string> $strings Strings to round-trip.
	 * @return void
	 */
	private function assertRoundTrip( array $strings ): void {
		$encoder = new StringEncoder();
		foreach ( $strings as $string ) {
			$encoder->write( $string );
		}

		$decoder = new StringDecoder( $encoder->toUint8Array() );
		foreach ( $strings as $i => $string ) {
			$this->assertSame( $string, $decoder->read(), "string {$i} did not round-trip" );
		}
	}

	/**
	 * @return void
	 */
	public function testRoundTripAscii(): void {
		$this->assertRoundTrip( array( 'hello', ' ', 'world', '', 'abc', str_repeat( 'x', 100 ) ) );
	}

	/**
	 * @return void
	 */
	public function testRoundTripMultibyte(): void {
		// 1-, 2-, 3- and 4-byte UTF-8 chars; astral chars count two UTF-16
		// units, so these exercise the unit-length accounting across reads.
		$this->assertRoundTrip(
			array(
				'héllo wörld',
				'日本語のテキスト',
				'🎉🎊',
				'mixed 中文 and 🚀 rockets',
				'',
				'👩‍👩‍👧‍👦 family',
				'ends with astral 😀',
				'😀 starts with astral',
			)
		);
	}

	/**
	 * @return void
	 */
	public function testRoundTripManySmallStrings(): void {
		// The V2 update format concatenates every item string into one shared
		// buffer; a real multi-client document decodes hundreds of small
		// strings from it sequentially. This shape is also the performance
		// case the forward cursor exists for.
		$strings = array();
		for ( $i = 0; $i < 500; $i++ ) {
			if ( 0 === $i % 7 ) {
				$suffix = '🙂';
			} else {
				$suffix = 'a';
			}

			$strings[] = sprintf( 'tok%03d-%s ', $i, $suffix );
		}

		$this->assertRoundTrip( $strings );
	}

	/**
	 * @return void
	 */
	public function testZeroLengthReadsInterleaved(): void {
		$this->assertRoundTrip( array( 'a', '', '', '🎉', '', 'b' ) );
	}

	/**
	 * A buffer that is multibyte but astral-free (every char one UTF-16
	 * unit), which takes the single-unit fast path, distinct from both the
	 * ASCII fast path and the per-char walk.
	 *
	 * @return void
	 */
	public function testRoundTripBmpMultibyteOnly(): void {
		$strings = array( 'héllo', 'wörld', '日本語のテキスト', '', 'çãé', 'русский текст' );
		for ( $i = 0; $i < 200; $i++ ) {
			$strings[] = sprintf( 'tökén%03d ', $i );
		}

		$this->assertRoundTrip( $strings );
	}

	/**
	 * Invalid UTF-8 counts one unit per byte consistently in the length
	 * stream (utf16Length) and the read cursor, so even garbage bytes
	 * round-trip when sharing a buffer with ASCII strings.
	 *
	 * @return void
	 */
	public function testRoundTripInvalidUtf8(): void {
		$this->assertRoundTrip( array( "\xF1", 'ab', "\xFF\xFE", 'tail' ) );
	}

	/**
	 * ASCII-only buffers at volume (the byte-cursor fast path).
	 *
	 * @return void
	 */
	public function testRoundTripAsciiOnlyManyStrings(): void {
		$strings = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$strings[] = sprintf( 'plain-token-%04d ', $i );
		}

		$this->assertRoundTrip( $strings );
	}
}
