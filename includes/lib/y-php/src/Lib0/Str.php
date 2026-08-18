<?php
/**
 * String helpers.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Port of the lib0/string.js helpers needed by M0.
 */
final class Str {
	/**
	 * @param int $code Character code.
	 * @return string
	 */
	public static function fromCharCode( int $code ): string {
		return chr( $code );
	}

	/**
	 * @param int $code Code point.
	 * @return string
	 */
	public static function fromCodePoint( int $code ): string {
		if ( $code <= 0x7F ) {
			return chr( $code );
		}
		if ( function_exists( 'mb_chr' ) ) {
			return mb_chr( $code, 'UTF-8' );
		}
		return html_entity_decode( '&#' . $code . ';', ENT_NOQUOTES, 'UTF-8' );
	}

	/**
	 * @param string $string UTF-8 string.
	 * @return int
	 */
	public static function utf8ByteLength( string $string ): int {
		return strlen( $string );
	}

	/**
	 * The per-char walk (two PHP calls per character) dominates the cost of
	 * encoding or decoding any real document, while in JS `str.length` IS the
	 * UTF-16 length, natively. For valid UTF-8 the same number is a
	 * byte-histogram formula:
	 *
	 *   Units = bytes - continuationBytes(0x80..0xBF) + fourByteLeads(0xF0..)
	 *
	 * (every code point contributes its lead byte; astral code points count
	 * one extra unit for the surrogate pair). count_chars() computes the
	 * histogram in C. Invalid UTF-8 counts one unit per byte, i.e. strlen(),
	 * the same accounting the char-split fallback uses in sliceUtf16() and
	 * StringDecoder, so the string codec stays self-consistent on garbage
	 * input.
	 *
	 * @param string $string UTF-8 string.
	 * @return int
	 */
	public static function utf16Length( string $string ): int {
		if ( '' === $string ) {
			return 0;
		}

		if ( ! preg_match( '/[\x80-\xFF]/', $string ) ) {
			return strlen( $string );
		}

		if ( 1 !== preg_match( '//u', $string ) ) {
			return strlen( $string );
		}

		$length = strlen( $string );

		foreach ( count_chars( $string, 1 ) as $byte => $count ) {
			if ( $byte >= 0x80 && $byte < 0xC0 ) {
				$length -= $count;
			} elseif ( $byte >= 0xF0 ) {
				$length += $count;
			}
		}

		return $length;
	}

	/**
	 * Fast paths for the overwhelmingly common shapes, avoiding the per-char
	 * PHP walk. ASCII: one UTF-16 unit per byte, so the slice is substr().
	 * Valid UTF-8 without astral code points (no 0xF0+ lead bytes): one unit
	 * per code point, so the slice is mb_substr() by code-point offsets. Both
	 * agree with the walk exactly (no char can straddle a boundary when every
	 * char is a single unit). Astral, invalid, or negative-start input keeps
	 * the original walk.
	 *
	 * @param string   $string UTF-8 string.
	 * @param int      $start  Inclusive UTF-16 code-unit start.
	 * @param int|null $end    Exclusive UTF-16 code-unit end.
	 * @return string
	 */
	public static function sliceUtf16( string $string, int $start, ?int $end = null ): string {
		if ( null !== $end && $end <= $start ) {
			return '';
		}

		if ( '' === $string ) {
			return '';
		}

		if ( $start >= 0 && ! preg_match( '/[\x80-\xFF]/', $string ) ) {
			if ( $start >= strlen( $string ) ) {
				// The walk returns '' here; on PHP < 8 substr() would return false.
				return '';
			}

			if ( null === $end ) {
				return substr( $string, $start );
			}

			return substr( $string, $start, $end - $start );
		}

		if ( $start >= 0 && function_exists( 'mb_substr' ) && ! preg_match( '/[\xF0-\xFF]/', $string ) && 1 === preg_match( '//u', $string ) ) {
			if ( null === $end ) {
				return mb_substr( $string, $start, null, 'UTF-8' );
			}

			return mb_substr( $string, $start, $end - $start, 'UTF-8' );
		}

		$out      = '';
		$position = 0;
		$limit    = $end;

		foreach ( self::utf8Chars( $string ) as $char ) {
			// A valid 4-byte UTF-8 sequence is exactly an astral code point,
			// i.e. two UTF-16 units; every other char (including single bytes
			// from the invalid-UTF-8 str_split() fallback) counts one, keeping
			// unit accounting consistent with utf16Length() and StringDecoder.
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

			$out     .= $char;
			$position = $next;
		}

		return $out;
	}

	/**
	 * @param string $string UTF-8 string.
	 * @return Buffer
	 */
	public static function encodeUtf8( string $string ): Buffer {
		return Buffer::fromBinaryString( $string );
	}

	/**
	 * @param Buffer $buffer UTF-8 bytes.
	 * @return string
	 */
	public static function decodeUtf8( Buffer $buffer ): string {
		return $buffer->toBinaryString();
	}

	/**
	 * @param string $source Source string.
	 * @param int    $index  Starting offset.
	 * @param int    $remove Number of bytes to remove.
	 * @param string $insert Inserted string.
	 * @return string
	 */
	public static function splice( string $source, int $index, int $remove, string $insert = '' ): string {
		return substr( $source, 0, $index ) . $insert . substr( $source, $index + $remove );
	}

	/**
	 * @param string $source Source string.
	 * @param int    $count Repeat count.
	 * @return string
	 */
	public static function repeat( string $source, int $count ): string {
		return str_repeat( $source, $count );
	}

	/**
	 * @param string $string UTF-8 string.
	 * @return array<int,string>
	 */
	private static function utf8Chars( string $string ): array {
		if ( '' === $string ) {
			return array();
		}

		$matches = array();
		if ( false === preg_match_all( '/./us', $string, $matches ) ) {
			return str_split( $string );
		}
		return $matches[0];
	}

}
