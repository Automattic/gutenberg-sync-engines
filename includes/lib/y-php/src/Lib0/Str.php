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
	 * @param string $string UTF-8 string.
	 * @return int
	 */
	public static function utf16Length( string $string ): int {
		$length = 0;
		foreach ( self::utf8Chars( $string ) as $char ) {
			$length += self::utf16UnitLength( $char );
		}
		return $length;
	}

	/**
	 * @param string   $string UTF-8 string.
	 * @param int      $start  Inclusive UTF-16 code-unit start.
	 * @param int|null $end    Exclusive UTF-16 code-unit end.
	 * @return string
	 */
	public static function sliceUtf16( string $string, int $start, ?int $end = null ): string {
		if ( null !== $end && $end <= $start ) {
			return '';
		}

		$out      = '';
		$position = 0;
		$limit    = $end;

		foreach ( self::utf8Chars( $string ) as $char ) {
			$unitLength = self::utf16UnitLength( $char );
			$next       = $position + $unitLength;

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

	/**
	 * @param string $char UTF-8 character.
	 * @return int
	 */
	private static function utf16UnitLength( string $char ): int {
		return self::codePoint( $char ) > 0xFFFF ? 2 : 1;
	}

	/**
	 * @param string $char UTF-8 character.
	 * @return int
	 */
	private static function codePoint( string $char ): int {
		$first = ord( $char[0] );
		if ( $first < 0x80 ) {
			return $first;
		}
		if ( $first < 0xE0 ) {
			return ( ( $first & 0x1F ) << 6 ) | ( ord( $char[1] ) & 0x3F );
		}
		if ( $first < 0xF0 ) {
			return ( ( $first & 0x0F ) << 12 ) | ( ( ord( $char[1] ) & 0x3F ) << 6 ) | ( ord( $char[2] ) & 0x3F );
		}
		return ( ( $first & 0x07 ) << 18 ) | ( ( ord( $char[1] ) & 0x3F ) << 12 ) | ( ( ord( $char[2] ) & 0x3F ) << 6 ) | ( ord( $char[3] ) & 0x3F );
	}
}
