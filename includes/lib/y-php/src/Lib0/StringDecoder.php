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
 * The JS original slices the shared string buffer with native `str.slice()`,
 * which is O(slice) thanks to UTF-16 indexing. A direct port via
 * Str::sliceUtf16() re-walks the buffer from offset 0 on every read to find
 * the UTF-16 start offset, making n sequential reads O(n^2) in the buffer
 * size. Since read() only ever moves forward, this port instead splits the
 * buffer into chars once and keeps a forward cursor.
 *
 * Per-char semantics match Str::sliceUtf16() exactly: the split uses the
 * same `/./us` pattern and the same str_split() fallback for invalid UTF-8,
 * per-char UTF-16 unit lengths agree (a 4-byte UTF-8 sequence from the /u
 * split is exactly a code point above 0xFFFF, i.e. two UTF-16 units), and a
 * char straddling a read boundary is kept for re-inclusion in the next read,
 * mirroring sliceUtf16()'s global-position behavior.
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
			$char = $this->chars[ $this->charIndex ];

			if ( 4 === strlen( $char ) ) {
				$unitLength = 2;
			} else {
				$unitLength = 1;
			}

			$result .= $char;

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
