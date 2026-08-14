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
 *
 * Cases are read from tests/fixtures/compatibility-v1.json, which is captured
 * from the JS source by tools/gen-fixtures.mjs.
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
		$path = dirname( __DIR__ ) . '/fixtures/compatibility-v1.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$source = file_get_contents( $path );
		if ( false === $source ) {
			self::fail( 'Unable to read ' . $path );
		}

		$fixtures = json_decode( $source, true );
		if ( ! is_array( $fixtures ) || ! isset( $fixtures[ $exportName ] ) ) {
			self::fail( 'Unable to find ' . $exportName . ' in ' . $path );
		}

		return $fixtures[ $exportName ];
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
