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
	 * @return array<int,string>
	 */
	public function getContent(): array {
		$content = array();
		$len     = $this->getLength();
		for ( $i = 0; $i < $len; $i++ ) {
			$content[] = self::sliceUtf16( $this->str, $i, $i + 1 );
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
	 * @param string $str UTF-8 string.
	 * @return int
	 */
	private static function utf16Length( string $str ): int {
		$length = 0;
		foreach ( self::utf8Chars( $str ) as $char ) {
			$length += self::utf16UnitLength( $char );
		}
		return $length;
	}

	/**
	 * @param string   $str   UTF-8 string.
	 * @param int      $start Inclusive UTF-16 code-unit start.
	 * @param int|null $end   Exclusive UTF-16 code-unit end.
	 * @return string
	 */
	private static function sliceUtf16( string $str, int $start, ?int $end = null ): string {
		if ( null !== $end && $end <= $start ) {
			return '';
		}

		$out      = '';
		$position = 0;
		$limit    = $end;

		foreach ( self::utf8Chars( $str ) as $char ) {
			$unitLength = self::utf16UnitLength( $char );
			$next       = $position + $unitLength;

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
