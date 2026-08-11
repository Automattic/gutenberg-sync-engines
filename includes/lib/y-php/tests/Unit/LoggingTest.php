<?php
/**
 * Logging utility tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yjs\Utils\Doc;
use Yjs\Yjs;

/**
 * Tests for debug logging helpers.
 */
final class LoggingTest extends TestCase {
	public function testLogTypePrintsChildrenAndLiveContent(): void {
		$doc   = new Doc();
		$array = $doc->getArray( 'array' );
		$array->insert( 0, array( 'a' ) );
		$array->insert( 1, array( 'b' ) );
		$array->delete( 0 );

		ob_start();
		Yjs::logType( $array );
		$output = ob_get_clean();

		self::assertIsString( $output );
		$lines = array_values(
			array_filter(
				explode( PHP_EOL, $output ),
				static fn ( string $line ): bool => '' !== $line
			)
		);

		self::assertCount( 2, $lines );
		self::assertStringStartsWith( 'Children: ', $lines[0] );
		self::assertStringStartsWith( 'Children content: ', $lines[1] );

		$children = self::decodeLogLine( $lines[0], 'Children: ' );
		$content  = self::decodeLogLine( $lines[1], 'Children content: ' );

		self::assertNotEmpty( $children );
		self::assertContains( true, array_column( $children, 'deleted' ) );
		self::assertSame( array( 'b' ), $content[0]['content'] );
	}

	/**
	 * @param string $line   Output line.
	 * @param string $prefix Line prefix.
	 * @return array<int,mixed>
	 */
	private static function decodeLogLine( string $line, string $prefix ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode
		$decoded = json_decode( substr( $line, strlen( $prefix ) ), true );
		self::assertIsArray( $decoded );
		return $decoded;
	}
}
