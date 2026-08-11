<?php
/**
 * Delete-set V1 conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Buffer;
use Yjs\Lib0\Decoding;
use Yjs\Utils\DeleteItem;
use Yjs\Utils\DeleteSet;
use Yjs\Utils\UpdateDecoderV1;
use Yjs\Utils\UpdateEncoderV1;

use function Yjs\readDeleteSet;
use function Yjs\writeDeleteSet;

/**
 * Verifies delete-set byte parity with fixtures captured from real yjs.
 */
final class DeleteSetV1Test extends TestCase {
	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public function deleteSetCaseProvider(): array {
		$fixture = $this->fixture( 'delete-set-v1.json' );
		return array_map(
			static fn ( array $case ): array => array( $case ),
			$fixture['cases']
		);
	}

	/**
	 * @dataProvider deleteSetCaseProvider
	 *
	 * @param array<string,mixed> $case Fixture case.
	 * @return void
	 */
	public function testWriteDeleteSetMatchesJsBytesAndReadRoundTrips( array $case ): void {
		$ds      = $this->materializeDeleteSet( $case['input'] );
		$encoder = new UpdateEncoderV1();
		writeDeleteSet( $encoder, $ds );

		self::assertSame( $case['hex'], $encoder->toUint8Array()->toHexString(), $case['name'] );

		$decoder = new UpdateDecoderV1( Decoding::createDecoder( Buffer::fromHexString( $case['hex'] ) ) );
		$decoded = readDeleteSet( $decoder );

		self::assertSame( $case['decoded'], $this->normalizeDeleteSet( $decoded ), $case['name'] . ' decoded' );
		self::assertFalse( Decoding::hasContent( $decoder->restDecoder ), $case['name'] . ' consumes all bytes' );

		$roundtrip = new UpdateEncoderV1();
		writeDeleteSet( $roundtrip, $decoded );
		self::assertSame( $case['hex'], $roundtrip->toUint8Array()->toHexString(), $case['name'] . ' roundtrip' );
	}

	/**
	 * @param string $name Fixture file name.
	 * @return array<string,mixed>
	 */
	private function fixture( string $name ): array {
		$path = dirname( __DIR__ ) . '/fixtures/' . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			self::fail( 'Unable to read fixture ' . $name );
		}
		return $data;
	}

	/**
	 * @param array<int,array{client:int,deletes:array<int,array{clock:int,len:int}>}> $desc Delete-set descriptor.
	 * @return DeleteSet
	 */
	private function materializeDeleteSet( array $desc ): DeleteSet {
		$ds = new DeleteSet();
		foreach ( $desc as $clientDesc ) {
			$items = array();
			foreach ( $clientDesc['deletes'] as $deleteDesc ) {
				$items[] = new DeleteItem( $deleteDesc['clock'], $deleteDesc['len'] );
			}
			$ds->clients[ $clientDesc['client'] ] = $items;
		}
		return $ds;
	}

	/**
	 * @param DeleteSet $ds Delete set.
	 * @return array<int,array{client:int,deletes:array<int,array{clock:int,len:int}>}>
	 */
	private function normalizeDeleteSet( DeleteSet $ds ): array {
		$normalized = array();
		foreach ( $ds->clients as $client => $items ) {
			$normalized[] = array(
				'client'  => $client,
				'deletes' => array_map(
					static fn ( DeleteItem $item ): array => array(
						'clock' => $item->clock,
						'len'   => $item->len,
					),
					$items
				),
			);
		}
		return $normalized;
	}
}
