<?php
/**
 * Signed 64-bit bigint carrier.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Lib0;

/**
 * Represents JavaScript bigint values encoded with DataView.setBigInt64.
 */
final class BigInt64 {
	private const MAX           = '9223372036854775807';
	private const MIN_MAGNITUDE = '9223372036854775808';

	/**
	 * Signed decimal representation.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * @param string $value Signed decimal representation.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * @param int $value Integer value.
	 * @return self
	 */
	public static function fromInt( int $value ): self {
		return new self( (string) $value );
	}

	/**
	 * @param string $value Signed decimal representation.
	 * @return self
	 */
	public static function fromString( string $value ): self {
		$value = self::normalizeDecimal( $value );
		self::assertInRange( $value );
		return new self( $value );
	}

	/**
	 * @param Buffer $buffer Eight-byte big-endian two's-complement data.
	 * @return self
	 */
	public static function fromBytes( Buffer $buffer ): self {
		if ( 8 !== $buffer->byteLength() ) {
			throw new \InvalidArgumentException( 'BigInt64 requires exactly 8 bytes.' );
		}

		$bytes = $buffer->toByteArray();
		if ( 0 === ( $bytes[0] & Binary::BIT8 ) ) {
			return new self( self::bytesToDecimal( $bytes ) );
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$bytes[ $i ] = ( ~$bytes[ $i ] ) & Binary::BITS8;
		}
		self::addOneToBytes( $bytes );

		$magnitude = self::bytesToDecimal( $bytes );
		if ( '0' === $magnitude ) {
			return new self( '0' );
		}
		return new self( '-' . $magnitude );
	}

	/**
	 * @return Buffer
	 */
	public function toBytes(): Buffer {
		$is_negative = '-' === $this->value[0];
		$magnitude   = $is_negative ? substr( $this->value, 1 ) : $this->value;
		$bytes       = self::decimalToBytes( $magnitude );

		if ( $is_negative ) {
			for ( $i = 0; $i < 8; $i++ ) {
				$bytes[ $i ] = ( ~$bytes[ $i ] ) & Binary::BITS8;
			}
			self::addOneToBytes( $bytes );
		}

		return Buffer::fromByteArray( $bytes );
	}

	/**
	 * @return string
	 */
	public function toString(): string {
		return $this->value;
	}

	/**
	 * @return int
	 */
	public function toInt(): int {
		return (int) $this->value;
	}

	/**
	 * @return string
	 */
	public function __toString(): string {
		return $this->value;
	}

	/**
	 * @param string $value Decimal string.
	 * @return string
	 */
	private static function normalizeDecimal( string $value ): string {
		$value = trim( $value );
		if ( ! preg_match( '/^-?[0-9]+$/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid BigInt64 decimal value.' );
		}

		$is_negative = '-' === $value[0];
		$digits      = $is_negative ? substr( $value, 1 ) : $value;
		$digits      = ltrim( $digits, '0' );
		if ( '' === $digits ) {
			return '0';
		}
		return $is_negative ? '-' . $digits : $digits;
	}

	/**
	 * @param string $value Signed decimal string.
	 * @return void
	 */
	private static function assertInRange( string $value ): void {
		if ( '-' === $value[0] ) {
			$magnitude = substr( $value, 1 );
			if ( 0 < self::compareDecimal( $magnitude, self::MIN_MAGNITUDE ) ) {
				throw new \OutOfRangeException( 'BigInt64 is below the signed 64-bit minimum.' );
			}
			return;
		}

		if ( 0 < self::compareDecimal( $value, self::MAX ) ) {
			throw new \OutOfRangeException( 'BigInt64 is above the signed 64-bit maximum.' );
		}
	}

	/**
	 * @param string $left  Non-negative decimal string.
	 * @param string $right Non-negative decimal string.
	 * @return int
	 */
	private static function compareDecimal( string $left, string $right ): int {
		$left  = ltrim( $left, '0' );
		$right = ltrim( $right, '0' );
		$left  = '' === $left ? '0' : $left;
		$right = '' === $right ? '0' : $right;
		if ( strlen( $left ) !== strlen( $right ) ) {
			return strlen( $left ) < strlen( $right ) ? -1 : 1;
		}
		return $left <=> $right;
	}

	/**
	 * @param string $decimal Non-negative decimal string.
	 * @return array<int,int>
	 */
	private static function decimalToBytes( string $decimal ): array {
		$decimal = ltrim( $decimal, '0' );
		$decimal = '' === $decimal ? '0' : $decimal;
		$bytes   = array_fill( 0, 8, 0 );

		for ( $i = 7; $i >= 0 && '0' !== $decimal; $i-- ) {
			$division    = self::divModDecimalByInt( $decimal, 256 );
			$decimal     = $division[0];
			$bytes[ $i ] = $division[1];
		}

		return $bytes;
	}

	/**
	 * @param array<int,int> $bytes Big-endian bytes.
	 * @return string
	 */
	private static function bytesToDecimal( array $bytes ): string {
		$decimal = '0';
		foreach ( $bytes as $byte ) {
			$decimal = self::multiplyDecimalByInt( $decimal, 256 );
			$decimal = self::addIntToDecimal( $decimal, $byte );
		}
		return $decimal;
	}

	/**
	 * @param string $decimal Non-negative decimal string.
	 * @param int    $divisor Divisor.
	 * @return array{0:string,1:int}
	 */
	private static function divModDecimalByInt( string $decimal, int $divisor ): array {
		$quotient = '';
		$carry    = 0;
		$length   = strlen( $decimal );
		for ( $i = 0; $i < $length; $i++ ) {
			$value     = $carry * 10 + (int) $decimal[ $i ];
			$digit     = intdiv( $value, $divisor );
			$carry     = $value % $divisor;
			$quotient .= (string) $digit;
		}
		$quotient = ltrim( $quotient, '0' );
		return array(
			'' === $quotient ? '0' : $quotient,
			$carry,
		);
	}

	/**
	 * @param string $decimal Non-negative decimal string.
	 * @param int    $factor Small integer factor.
	 * @return string
	 */
	private static function multiplyDecimalByInt( string $decimal, int $factor ): string {
		$carry  = 0;
		$result = '';
		for ( $i = strlen( $decimal ) - 1; $i >= 0; $i-- ) {
			$product = ( (int) $decimal[ $i ] ) * $factor + $carry;
			$result  = (string) ( $product % 10 ) . $result;
			$carry   = intdiv( $product, 10 );
		}
		while ( 0 < $carry ) {
			$result = (string) ( $carry % 10 ) . $result;
			$carry  = intdiv( $carry, 10 );
		}
		$result = ltrim( $result, '0' );
		return '' === $result ? '0' : $result;
	}

	/**
	 * @param string $decimal Non-negative decimal string.
	 * @param int    $addend Small integer addend.
	 * @return string
	 */
	private static function addIntToDecimal( string $decimal, int $addend ): string {
		$carry  = $addend;
		$result = '';
		for ( $i = strlen( $decimal ) - 1; $i >= 0; $i-- ) {
			$sum    = (int) $decimal[ $i ] + $carry;
			$result = (string) ( $sum % 10 ) . $result;
			$carry  = intdiv( $sum, 10 );
		}
		while ( 0 < $carry ) {
			$result = (string) ( $carry % 10 ) . $result;
			$carry  = intdiv( $carry, 10 );
		}
		$result = ltrim( $result, '0' );
		return '' === $result ? '0' : $result;
	}

	/**
	 * @param array<int,int> $bytes Bytes modified in place.
	 * @return void
	 */
	private static function addOneToBytes( array &$bytes ): void {
		for ( $i = 7; $i >= 0; $i-- ) {
			++$bytes[ $i ];
			if ( $bytes[ $i ] <= Binary::BITS8 ) {
				return;
			}
			$bytes[ $i ] = 0;
		}
	}
}
