<?php
/**
 * Optimized string decoder.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of lib0/decoding.js StringDecoder.
 *
 * DELTA (gutenberg-sync-engines; candidate for upstream y-php): read() used
 * to call Str::sliceUtf16( $this->str, $this->spos, $end ), which walks the
 * shared string buffer from offset 0 on EVERY read to find the UTF-16 start
 * offset. The V2 update format concatenates all item strings into this one
 * buffer, so decoding n strings cost O(n^2) — the dominant cost of applying
 * any real multi-client document (the JS original is `str.slice()`, where
 * native UTF-16 indexing makes the same line O(slice)). Reads are strictly
 * sequential, so a forward cursor over a one-time char split is equivalent:
 * the split uses the same `/./us` pattern and the same str_split() fallback
 * for invalid UTF-8 as Str::sliceUtf16(), per-char UTF-16 unit lengths agree
 * (a 4-byte UTF-8 sequence from the /u split is exactly a code point above
 * 0xFFFF, i.e. two UTF-16 units; every fallback byte counts one, matching
 * Str::codePoint() on single bytes), and a char straddling a read boundary
 * is kept for re-inclusion in the next read, mirroring sliceUtf16()'s
 * global-position behavior. Byte-parity is enforced by the conformance
 * suite (composer test: 442 tests).
 */
class StringDecoder {
	/**
	 * @var UintOptRleDecoder
	 */
	private UintOptRleDecoder $decoder;

	/**
	 * @var string
	 */
	private string $str;

	/**
	 * @var int
	 */
	private int $spos = 0;

	/**
	 * Chars of $str, split once on first read (null until then).
	 *
	 * @var array<int,string>|null
	 */
	private ?array $chars = null;

	/**
	 * Index into $chars of the first unconsumed char.
	 *
	 * @var int
	 */
	private int $charIndex = 0;

	/**
	 * UTF-16 code-unit position of the char at $charIndex.
	 *
	 * @var int
	 */
	private int $charPos = 0;

	/**
	 * @param Buffer $buffer Encoded data.
	 */
	public function __construct( Buffer $buffer ) {
		$this->decoder = new UintOptRleDecoder( $buffer );
		$this->str     = Decoding::readVarString( $this->decoder->decoder );
	}

	/**
	 * @return string
	 */
	public function read(): string {
		$end = $this->spos + $this->decoder->read();
		if ( $end <= $this->spos ) {
			// Matches sliceUtf16()'s empty-range guard (a pending boundary
			// straddler must not leak into a zero-length read).
			$this->spos = $end;
			return '';
		}

		if ( null === $this->chars ) {
			$matches = array();
			if ( '' === $this->str ) {
				$this->chars = array();
			} elseif ( false === preg_match_all( '/./us', $this->str, $matches ) ) {
				$this->chars = str_split( $this->str );
			} else {
				$this->chars = $matches[0];
			}
		}

		$result = '';
		$count  = count( $this->chars );
		while ( $this->charIndex < $count && $this->charPos < $end ) {
			$char       = $this->chars[ $this->charIndex ];
			$unitLength = 4 === strlen( $char ) ? 2 : 1;
			$result    .= $char;
			if ( $this->charPos + $unitLength > $end ) {
				// The char straddles the read boundary: keep the cursor on it
				// so the next read re-includes it (sliceUtf16() parity).
				break;
			}
			$this->charPos += $unitLength;
			++$this->charIndex;
		}

		$this->spos = $end;
		return $result;
	}
}
