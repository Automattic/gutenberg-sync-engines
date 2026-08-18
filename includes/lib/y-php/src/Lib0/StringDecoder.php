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
 *
 * The buffer's shape is additionally classified once on first read so the
 * common shapes skip the per-char cursor walk entirely; see $mode.
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
	 * Chars of $str, split once on first read (null until then). Unused in
	 * 'ascii' mode, where no split is needed.
	 *
	 * @var array<int,string>|null
	 */
	private ?array $chars = null;

	/**
	 * Buffer shape, classified once on first read (null until then).
	 *
	 * 'ascii' buffers (no bytes >= 0x80) need no char split at all: one
	 * UTF-16 unit per byte, so a read is substr() by unit offsets. 'single'
	 * buffers (valid UTF-8 without astral code points, or the str_split()
	 * fallback for invalid UTF-8) have one unit per char, so a read is an
	 * array_slice() of the char split. Only 'walk' buffers (astral input,
	 * where a char can straddle a read boundary) pay the per-char cursor
	 * walk.
	 *
	 * @var string|null
	 */
	private ?string $mode = null;

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

		if ( null === $this->mode ) {
			if ( ! preg_match( '/[\x80-\xFF]/', $this->str ) ) {
				$this->mode = 'ascii';
			} else {
				$matches = array();

				if ( false === preg_match_all( '/./us', $this->str, $matches ) ) {
					// Invalid UTF-8: one char per byte, each one unit.
					$this->chars = str_split( $this->str );
					$this->mode  = 'single';
				} else {
					$this->chars = $matches[0];

					if ( preg_match( '/[\xF0-\xFF]/', $this->str ) ) {
						$this->mode = 'walk';
					} else {
						$this->mode = 'single';
					}
				}
			}
		}

		if ( 'ascii' === $this->mode ) {
			// One unit per byte: the unit cursor is a byte cursor.
			$result = substr( $this->str, $this->spos, $end - $this->spos );

			if ( false === $result ) {
				// PHP < 8: reading past a truncated buffer returns false
				// where the walk below returns ''.
				$result = '';
			}

			$this->spos = $end;
			return $result;
		}

		if ( 'single' === $this->mode ) {
			// One unit per char, so no char can straddle a read boundary.
			$take             = $end - $this->charPos;
			$result           = implode( '', array_slice( $this->chars, $this->charIndex, $take ) );
			$this->charIndex += $take;
			$this->charPos    = $end;
			$this->spos       = $end;
			return $result;
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
