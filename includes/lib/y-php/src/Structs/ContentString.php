<?php
/**
 * String item content.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Structs;

/**
 * Port of yjs/src/structs/ContentString.js.
 */
class ContentString {
	private const REPLACEMENT_CHARACTER = "\xEF\xBF\xBD";

	/**
	 * @var string
	 */
	public string $str;

	/**
	 * @param string $str String content.
	 */
	public function __construct( string $str ) {
		$this->str = $str;
	}

	/**
	 * @return int
	 */
	public function getLength(): int {
		return self::utf16Length( $this->str );
	}

	/**
	 * One pass over the chars replaces per-unit sliceUtf16() calls (each of
	 * which walked the string from offset 0, making this O(n^2)): a
	 * single-unit char is its own entry, and an astral char is two
	 * REPLACEMENT_CHARACTER entries, exactly what the per-unit slices
	 * returned for the two surrogate halves.
	 *
	 * @return array<int,string>
	 */
	public function getContent(): array {
		if ( '' === $this->str ) {
			return array();
		}

		if ( ! preg_match( '/[\x80-\xFF]/', $this->str ) ) {
			return str_split( $this->str );
		}

		$content = array();

		foreach ( self::utf8Chars( $this->str ) as $char ) {
			// A valid 4-byte UTF-8 sequence is exactly an astral code point,
			// i.e. two UTF-16 units.
			if ( 4 === strlen( $char ) ) {
				$content[] = self::REPLACEMENT_CHARACTER;
				$content[] = self::REPLACEMENT_CHARACTER;
			} else {
				$content[] = $char;
			}
		}

		return $content;
	}

	/**
	 * @return bool
	 */
	public function isCountable(): bool {
		return true;
	}

	/**
	 * @return ContentString
	 */
	public function copy(): ContentString {
		return new ContentString( $this->str );
	}

	/**
	 * @param int $offset UTF-16 code-unit offset.
	 * @return ContentString
	 */
	public function splice( int $offset ): ContentString {
		$right     = new ContentString( self::sliceUtf16( $this->str, $offset ) );
		$this->str = self::sliceUtf16( $this->str, 0, $offset );
		return $right;
	}

	/**
	 * @param ContentString $right Right content.
	 * @return bool
	 */
	public function mergeWith( ContentString $right ): bool {
		$this->str .= $right->str;
		return true;
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @param Item  $item        Item.
	 * @return void
	 */
	public function integrate( $transaction, Item $item ): void {
		unset( $transaction, $item );
	}

	/**
	 * @param mixed $transaction Transaction.
	 * @return void
	 */
	public function delete( $transaction ): void {
		unset( $transaction );
	}

	/**
	 * @param mixed $store Struct store.
	 * @return void
	 */
	public function gc( $store ): void {
		unset( $store );
	}

	/**
	 * @param mixed $encoder Encoder.
	 * @param int   $offset  UTF-16 code-unit offset.
	 * @return void
	 */
	public function write( $encoder, int $offset ): void {
		$encoder->writeString( 0 === $offset ? $this->str : self::sliceUtf16( $this->str, $offset ) );
	}

	/**
	 * @return int
	 */
	public function getRef(): int {
		return 4;
	}

	/**
	 * Native byte-histogram formula replacing the per-char walk; see
	 * Lib0\Str::utf16Length() for the derivation and parity notes.
	 *
	 * @param string $str UTF-8 string.
	 * @return int
	 */
	private static function utf16Length( string $str ): int {
		if ( '' === $str ) {
			return 0;
		}

		if ( ! preg_match( '/[\x80-\xFF]/', $str ) ) {
			return strlen( $str );
		}

		if ( 1 !== preg_match( '//u', $str ) ) {
			return strlen( $str );
		}

		$length = strlen( $str );

		foreach ( count_chars( $str, 1 ) as $byte => $count ) {
			if ( $byte >= 0x80 && $byte < 0xC0 ) {
				$length -= $count;
			} elseif ( $byte >= 0xF0 ) {
				$length += $count;
			}
		}

		return $length;
	}

	/**
	 * ASCII and no-astral fast paths, mirroring Lib0\Str::sliceUtf16(). The
	 * REPLACEMENT_CHARACTER lane in the walk below only fires when a slice
	 * boundary lands inside an astral char, which cannot happen when every
	 * char is a single UTF-16 unit, so the fast paths are exact.
	 *
	 * @param string   $str   UTF-8 string.
	 * @param int      $start Inclusive UTF-16 code-unit start.
	 * @param int|null $end   Exclusive UTF-16 code-unit end.
	 * @return string
	 */
	private static function sliceUtf16( string $str, int $start, ?int $end = null ): string {
		if ( null !== $end && $end <= $start ) {
			return '';
		}

		if ( '' === $str ) {
			return '';
		}

		if ( $start >= 0 && ! preg_match( '/[\x80-\xFF]/', $str ) ) {
			if ( $start >= strlen( $str ) ) {
				// The walk returns '' here; on PHP < 8 substr() would return false.
				return '';
			}

			if ( null === $end ) {
				return substr( $str, $start );
			}

			return substr( $str, $start, $end - $start );
		}

		if ( $start >= 0 && function_exists( 'mb_substr' ) && ! preg_match( '/[\xF0-\xFF]/', $str ) && 1 === preg_match( '//u', $str ) ) {
			if ( null === $end ) {
				return mb_substr( $str, $start, null, 'UTF-8' );
			}

			return mb_substr( $str, $start, $end - $start, 'UTF-8' );
		}

		$out      = '';
		$position = 0;
		$limit    = $end;

		foreach ( self::utf8Chars( $str ) as $char ) {
			// Byte-length unit accounting, as in Lib0\Str::sliceUtf16():
			// identical for valid UTF-8, one unit per byte for the invalid
			// fallback, matching utf16Length() above.
			if ( 4 === strlen( $char ) ) {
				$unitLength = 2;
			} else {
				$unitLength = 1;
			}

			$next = $position + $unitLength;

			if ( $next <= $start ) {
				$position = $next;
				continue;
			}
			if ( null !== $limit && $position >= $limit ) {
				break;
			}

			$startsInside = $start > $position;
			$endsInside   = null !== $limit && $limit < $next;
			$out         .= $startsInside || $endsInside ? self::REPLACEMENT_CHARACTER : $char;
			$position     = $next;
		}

		return $out;
	}

	/**
	 * @param string $str UTF-8 string.
	 * @return array<int,string>
	 */
	private static function utf8Chars( string $str ): array {
		if ( '' === $str ) {
			return array();
		}

		$matches = array();
		if ( false === preg_match_all( '/./us', $str, $matches ) ) {
			return str_split( $str );
		}
		return $matches[0];
	}

}
