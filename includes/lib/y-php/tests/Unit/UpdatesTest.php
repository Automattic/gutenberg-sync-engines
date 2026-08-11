<?php
/**
 * Update utility tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Lib0\Buffer;
use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Utils\Doc;

use function Yjs\Tests\Support\compare;
use function Yjs\Tests\Support\init;

/**
 * Translated coverage for yjs/tests/updates.tests.js.
 */
final class UpdatesTest extends TranslatedTestCase {
	public function testMergeUpdates(): void {
		$setup  = init( $this, array( 'users' => 3 ) );
		$array0 = $setup['array0'];
		$array1 = $setup['array1'];
		$array0->insert( 0, array( 1 ) );
		$array1->insert( 0, array( 2 ) );
		compare( $setup['users'] );

		$updates = array_map(
			static fn ( Doc $doc ): Buffer => \Yjs\encodeStateAsUpdate( $doc ),
			$setup['users']
		);
		$merged  = new Doc();
		\Yjs\applyUpdate( $merged, \Yjs\mergeUpdates( $updates ) );

		self::assertSame( $array0->toArray(), $merged->getArray( 'array' )->toArray() );
	}

	public function testKeyEncoding(): void {
		$doc0  = new Doc();
		$doc1  = new Doc();
		$text0 = $doc0->getText( 'text' );
		$text1 = $doc1->getText( 'text' );
		$text0->insert( 0, 'a', array( 'italic' => true ) );
		$text0->insert( 0, 'b' );
		$text0->insert( 0, 'c', array( 'italic' => true ) );

		\Yjs\applyUpdate( $doc1, \Yjs\encodeStateAsUpdate( $doc0 ) );

		self::assertSame(
			array(
				array(
					'insert'     => 'c',
					'attributes' => array( 'italic' => true ),
				),
				array( 'insert' => 'b' ),
				array(
					'insert'     => 'a',
					'attributes' => array( 'italic' => true ),
				),
			),
			$text1->toDelta()
		);
	}

	public function testMergeUpdates1(): void {
		$doc     = new Doc( array( 'gc' => false ) );
		$updates = array();
		$doc->on(
			'update',
			static function ( Buffer $update ) use ( &$updates ): void {
				$updates[] = $update;
			}
		);
		$array = $doc->getArray();
		$array->insert( 0, array( 1 ) );
		$array->insert( 0, array( 2 ) );
		$array->insert( 0, array( 3 ) );
		$array->insert( 0, array( 4 ) );

		$this->assertMergedUpdateCases( $doc, $updates );
	}

	public function testMergeUpdates2(): void {
		$doc     = new Doc( array( 'gc' => false ) );
		$updates = array();
		$doc->on(
			'update',
			static function ( Buffer $update ) use ( &$updates ): void {
				$updates[] = $update;
			}
		);
		$array = $doc->getArray();
		$array->insert( 0, array( 1, 2 ) );
		$array->delete( 1, 1 );
		$array->insert( 0, array( 3, 4 ) );
		$array->delete( 1, 2 );

		$this->assertMergedUpdateCases( $doc, $updates );
	}

	public function testMergePendingUpdates(): void {
		$doc     = new Doc();
		$updates = array();
		$doc->on(
			'update',
			static function ( Buffer $update ) use ( &$updates ): void {
				$updates[] = $update;
			}
		);
		$text = $doc->getText( 'textBlock' );
		foreach ( array( 'r', 'o', 'n', 'e', 'n' ) as $char ) {
			$text->applyDelta( array( array( 'insert' => $char ) ) );
		}

		$doc1 = new Doc();
		\Yjs\applyUpdate( $doc1, $updates[0] );
		\Yjs\applyUpdate( $doc1, \Yjs\mergeUpdates( array_slice( $updates, 1 ) ) );

		self::assertSame( $text->toString(), $doc1->getText( 'textBlock' )->toString() );
		self::assertSame( \Yjs\encodeStateVector( $doc )->toHexString(), \Yjs\encodeStateVectorFromUpdate( \Yjs\mergeUpdates( $updates ) )->toHexString() );
	}

	public function testObfuscateUpdates(): void {
		$doc = new Doc();
		$doc->getText( 'text' )->insert( 0, 'secret', array( 'bold' => true ) );
		$update     = \Yjs\encodeStateAsUpdate( $doc );
		$obfuscated = \Yjs\obfuscateUpdate( $update );
		$restored   = new Doc();
		\Yjs\applyUpdate( $restored, $obfuscated );

		self::assertNotSame( $update->toHexString(), $obfuscated->toHexString() );
		self::assertSame( \Yjs\encodeStateVector( $doc )->toHexString(), \Yjs\encodeStateVector( $restored )->toHexString() );
		self::assertSame( '111111', $restored->getText( 'text' )->toString() );
	}

	/**
	 * @param Doc               $targetDoc Target document.
	 * @param array<int,Buffer> $updates   Updates.
	 * @return void
	 */
	private function assertMergedUpdateCases( Doc $targetDoc, array $updates ): void {
		$cases = array(
			\Yjs\mergeUpdates( $updates ),
			\Yjs\mergeUpdates(
				array(
					\Yjs\mergeUpdates( array_slice( $updates, 2 ) ),
					\Yjs\mergeUpdates( array_slice( $updates, 0, 2 ) ),
				)
			),
			\Yjs\mergeUpdates(
				array(
					\Yjs\mergeUpdates( array_slice( $updates, 2 ) ),
					\Yjs\mergeUpdates( array_slice( $updates, 1, 2 ) ),
					$updates[0],
				)
			),
		);

		foreach ( $cases as $mergedUpdates ) {
			$merged = new Doc( array( 'gc' => false ) );
			\Yjs\applyUpdate( $merged, $mergedUpdates );
			self::assertSame( $targetDoc->getArray()->toArray(), $merged->getArray()->toArray() );
			self::assertSame( \Yjs\encodeStateVector( $merged )->toHexString(), \Yjs\encodeStateVectorFromUpdate( $mergedUpdates )->toHexString() );
			$meta = \Yjs\parseUpdateMeta( $mergedUpdates );
			foreach ( $meta['from'] as $clock ) {
				self::assertSame( 0, $clock );
			}
		}
	}
}
