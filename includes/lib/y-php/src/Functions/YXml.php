<?php
/**
 * YXml namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param mixed $value Value.
 * @return string
 */
function xmlStringifyValue( $value ): string {
	if ( $value instanceof Lib0\UndefinedValue ) {
		return 'undefined';
	}
	if ( null === $value ) {
		return 'null';
	}
	if ( true === $value ) {
		return 'true';
	}
	if ( false === $value ) {
		return 'false';
	}
	if ( is_int( $value ) ) {
		return (string) $value;
	}
	if ( is_float( $value ) ) {
		if ( is_nan( $value ) ) {
			return 'NaN';
		}
		if ( is_infinite( $value ) ) {
			return $value > 0 ? 'Infinity' : '-Infinity';
		}
		return (string) $value;
	}
	if ( is_string( $value ) ) {
		return $value;
	}
	if ( $value instanceof Lib0\Buffer ) {
		return implode( ',', $value->toByteArray() );
	}
	if ( is_array( $value ) ) {
		if ( ! isListArray( $value ) ) {
			return '[object Object]';
		}
		return implode(
			',',
			array_map(
				static function ( $item ): string {
					return ( null === $item || $item instanceof Lib0\UndefinedValue ) ? '' : xmlStringifyValue( $item );
				},
				$value
			)
		);
	}
	return '[object Object]';
}

/**
 * @param array<mixed> $value Value.
 * @return bool
 */
function isListArray( array $value ): bool {
	$index = 0;
	foreach ( array_keys( $value ) as $key ) {
		if ( $key !== $index++ ) {
			return false;
		}
	}
	return true;
}
