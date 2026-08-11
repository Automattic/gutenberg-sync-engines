<?php
/**
 * Translated compatibility.tests.js tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Lib0\Buffer;
use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Types\AbstractType;
use Yjs\Utils\Doc;

/**
 * Translated test slots from yjs/tests/compatibility.tests.js.
 */
final class CompatibilityTest extends TranslatedTestCase {
	/**
	 * Source: yjs/tests/compatibility.tests.js::testArrayCompatibilityV1
	 *
	 * @return void
	 */
	public function testArrayCompatibilityV1(): void {
		$case = $this->compatibilityCase( 'testArrayCompatibilityV1' );
		$doc  = new Doc();

		\Yjs\applyUpdate( $doc, Buffer::fromBase64( $case['oldDoc'] ) );

		self::assertSame( $case['oldVal'], $this->normalizeValue( $doc->getArray( 'array' )->toJSON() ) );
	}

	/**
	 * Source: yjs/tests/compatibility.tests.js::testMapDecodingCompatibilityV1
	 *
	 * @return void
	 */
	public function testMapDecodingCompatibilityV1(): void {
		$case = $this->compatibilityCase( 'testMapDecodingCompatibilityV1' );
		$doc  = new Doc();

		\Yjs\applyUpdate( $doc, Buffer::fromBase64( $case['oldDoc'] ) );

		self::assertSame( $case['oldVal'], $this->normalizeValue( $doc->getMap( 'map' )->toJSON() ) );
	}

	/**
	 * Source: yjs/tests/compatibility.tests.js::testTextDecodingCompatibilityV1
	 *
	 * @return void
	 */
	public function testTextDecodingCompatibilityV1(): void {
		$case = $this->compatibilityCase( 'testTextDecodingCompatibilityV1' );
		$doc  = new Doc();

		\Yjs\applyUpdate( $doc, Buffer::fromBase64( $case['oldDoc'] ) );

		self::assertSame( $case['oldVal'], $this->normalizeValue( $doc->getText( 'text' )->toDelta() ) );
	}

	/**
	 * @param string $exportName Exported JS compatibility test name.
	 * @return array{oldDoc:string,oldVal:mixed}
	 */
	private function compatibilityCase( string $exportName ): array {
		$block = $this->compatibilityBlock( $exportName );
		if ( ! preg_match( "/const oldDoc = '([^']+)'/", $block, $oldDocMatch ) ) {
			self::fail( 'Unable to find oldDoc in ' . $exportName );
		}

		return array(
			'oldDoc' => $oldDocMatch[1],
			'oldVal' => $this->decodeOldVal( $block, $exportName ),
		);
	}

	/**
	 * @param string $exportName Exported JS compatibility test name.
	 * @return string
	 */
	private function compatibilityBlock( string $exportName ): string {
		$path = dirname( __DIR__, 3 ) . '/yjs/tests/compatibility.tests.js';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$source = file_get_contents( $path );
		if ( false === $source ) {
			self::fail( 'Unable to read ' . $path );
		}

		$start = strpos( $source, 'export const ' . $exportName );
		if ( false === $start ) {
			self::fail( 'Unable to find ' . $exportName );
		}

		$end = strpos( $source, "\n/**", $start + 1 );
		if ( false === $end ) {
			$end = strlen( $source );
		}

		return substr( $source, $start, $end - $start );
	}

	/**
	 * @param string $block      JS test block.
	 * @param string $exportName Exported JS compatibility test name.
	 * @return mixed
	 */
	private function decodeOldVal( string $block, string $exportName ) {
		$marker = 'const oldVal = ';
		$start  = strpos( $block, $marker );
		$end    = strpos( $block, "\n  const doc =", false === $start ? 0 : $start );
		if ( false === $start || false === $end ) {
			self::fail( 'Unable to find oldVal in ' . $exportName );
		}

		$literal = trim( substr( $block, $start + strlen( $marker ), $end - $start - strlen( $marker ) ) );
		if ( preg_match( "/^JSON\\.parse\\('(.+)'\\)$/s", $literal, $jsonStringMatch ) ) {
			return $this->decodeJson( stripcslashes( $jsonStringMatch[1] ), $exportName );
		}

		$literal = trim( preg_replace( '#/\*\*.*?\*/#s', '', $literal ) ?? $literal );
		if ( '(' === substr( $literal, 0, 1 ) && ')' === substr( $literal, -1 ) ) {
			$literal = trim( substr( $literal, 1, -1 ) );
		}

		return $this->decodeJson( $literal, $exportName );
	}

	/**
	 * @param string $json       JSON value.
	 * @param string $exportName Exported JS compatibility test name.
	 * @return mixed
	 */
	private function decodeJson( string $json, string $exportName ) {
		try {
			return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			self::fail( 'Unable to decode oldVal in ' . $exportName . ': ' . $exception->getMessage() );
		}
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function normalizeValue( $value ) {
		if ( $value instanceof AbstractType ) {
			return $this->normalizeValue( $value->toJSON() );
		}
		if ( $value instanceof \stdClass ) {
			return $this->normalizeValue( get_object_vars( $value ) );
		}
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $key => $item ) {
				$result[ $key ] = $this->normalizeValue( $item );
			}
			return $result;
		}
		return $value;
	}
}
