<?php
/**
 * PRNG conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Prng;

/**
 * Verifies PRNG parity with captured lib0 output.
 */
final class PrngTest extends TestCase {
	/**
	 * @return void
	 */
	public function testNextSequenceMatchesJs(): void {
		$fixture = $this->fixture();
		$gen     = Prng::create( $fixture['seed'] );

		foreach ( $fixture['next'] as $expected ) {
			self::assertEqualsWithDelta( $expected, $gen->next(), 1e-15 );
		}
	}

	/**
	 * @return void
	 */
	public function testHelperSequenceMatchesJs(): void {
		$fixture = $this->fixture();
		$gen     = Prng::create( $fixture['seed'] );

		foreach ( $fixture['helperOps'] as $op ) {
			switch ( $op['op'] ) {
				case 'bool':
					$actual = Prng::bool( $gen );
					break;
				case 'int32':
					$actual = Prng::int32( $gen, $op['args'][0], $op['args'][1] );
					break;
				case 'uint32':
					$actual = Prng::uint32( $gen, $op['args'][0], $op['args'][1] );
					break;
				case 'word':
					$actual = Prng::word( $gen, $op['args'][0], $op['args'][1] );
					break;
				case 'oneOf':
					$actual = Prng::oneOf( $gen, $op['args'][0] );
					break;
				default:
					self::fail( 'Unknown PRNG op ' . $op['op'] );
			}
			self::assertSame( $op['value'], $actual, $op['op'] );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fixture(): array {
		$path = dirname( __DIR__ ) . '/fixtures/prng.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			self::fail( 'Unable to read PRNG fixture.' );
		}
		return $data;
	}
}
