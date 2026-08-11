<?php
/**
 * YText unit and fuzz tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Lib0\Prng;
use Yjs\Tests\Support\TestYInstance;
use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Types\AbstractType;
use Yjs\Types\YMap;
use Yjs\Utils\Doc;

use function Yjs\Tests\Support\applyRandomTests;
use function Yjs\Tests\Support\compare;

/**
 * Translated coverage for yjs/tests/y-text.tests.js.
 */
final class YTextTest extends TranslatedTestCase {
	/**
	 * @var int
	 */
	private int $charCounter = 0;

	public function testDeltaBug(): void {
		$doc  = $this->doc( 1 );
		$text = $doc->getText();
		$text->applyDelta(
			array(
				array(
					'insert'     => "\n\n",
					'attributes' => array(
						'table-cell-line' => array( 'cell' => 'a' ),
						'block-id'        => 'block-a',
					),
				),
				array( 'insert' => 'Content after table' ),
				array(
					'insert'     => "\n",
					'attributes' => array( 'block-id' => 'block-b' ),
				),
			)
		);
		$text->applyDelta(
			array(
				array( 'retain' => 1 ),
				array(
					'retain'     => 1,
					'attributes' => array(
						'table-cell-line' => null,
						'list'            => array(
							'cell' => 'a',
							'list' => 'bullet',
						),
					),
				),
			)
		);

		$this->assertDeltaSame(
			array(
				array(
					'insert'     => "\n",
					'attributes' => array(
						'table-cell-line' => array( 'cell' => 'a' ),
						'block-id'        => 'block-a',
					),
				),
				array(
					'insert'     => "\n",
					'attributes' => array(
						'block-id' => 'block-a',
						'list'     => array(
							'cell' => 'a',
							'list' => 'bullet',
						),
					),
				),
				array( 'insert' => 'Content after table' ),
				array(
					'insert'     => "\n",
					'attributes' => array( 'block-id' => 'block-b' ),
				),
			),
			$text->toDelta()
		);
	}

	public function testDeltaBug2(): void {
		$doc  = $this->doc( 1 );
		$text = $doc->getText();
		$text->applyDelta(
			array(
				array( 'insert' => 'ab' ),
				array(
					'insert'     => 'cd',
					'attributes' => array( 'bold' => true ),
				),
			)
		);
		$text->applyDelta(
			array(
				array( 'retain' => 1 ),
				array(
					'retain'     => 2,
					'attributes' => array( 'bold' => true ),
				),
			)
		);

		$this->assertDeltaSame(
			array(
				array( 'insert' => 'a' ),
				array(
					'insert'     => 'bcd',
					'attributes' => array( 'bold' => true ),
				),
			),
			$text->toDelta()
		);
	}

	public function testDeltaAfterConcurrentFormatting(): void {
		$setup = \Yjs\Tests\Support\init( $this, array( 'users' => 2 ) );
		$text0 = $setup['text0'];
		$text1 = $setup['text1'];

		$text0->insert( 0, 'hello' );
		$text1->insert( 0, 'abc' );
		$setup['testConnector']->syncAll();
		$text0->format( 0, 3, array( 'bold' => true ) );
		$text1->format( 3, 2, array( 'bold' => null ) );

		compare( $setup['users'] );
		self::assertSame( $text0->toDelta(), $text1->toDelta() );
	}

	public function testBasicInsertAndDelete(): void {
		$setup  = \Yjs\Tests\Support\init( $this, array( 'users' => 2 ) );
		$text0  = $setup['text0'];
		$deltas = array();
		$text0->observe(
			static function ( $event ) use ( &$deltas ): void {
				$deltas[] = $event->delta;
			}
		);

		$text0->insert( 0, 'abc' );
		$this->assertDeltaSame( array( array( 'insert' => 'abc' ) ), $text0->toDelta() );
		$this->assertDeltaSame( array( array( 'insert' => 'abc' ) ), $deltas[0] );

		$text0->delete( 0, 1 );
		$this->assertDeltaSame( array( array( 'delete' => 1 ) ), $deltas[1] );
		$text0->delete( 1, 1 );
		$this->assertDeltaSame( array( array( 'retain' => 1 ), array( 'delete' => 1 ) ), $deltas[2] );
		$text0->delete( 0, 1 );
		$this->assertDeltaSame( array(), $text0->toDelta() );
		compare( $setup['users'] );
	}

	public function testBasicFormat(): void {
		$setup  = \Yjs\Tests\Support\init( $this, array( 'users' => 2 ) );
		$text0  = $setup['text0'];
		$deltas = array();
		$text0->observe(
			static function ( $event ) use ( &$deltas ): void {
				$deltas[] = $event->delta;
			}
		);

		$text0->insert( 0, 'abc', array( 'bold' => true ) );
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => 'abc',
					'attributes' => array( 'bold' => true ),
				),
			),
			$text0->toDelta()
		);
		$text0->delete( 0, 1 );
		$this->assertDeltaSame( array( array( 'delete' => 1 ) ), $deltas[1] );
		$text0->insert( 0, 'z', array( 'bold' => true ) );
		$text0->insert( 0, 'y' );
		$text0->format( 1, 1, array( 'bold' => null ) );
		$this->assertDeltaSame(
			array(
				array( 'insert' => 'yz' ),
				array(
					'insert'     => 'bc',
					'attributes' => array( 'bold' => true ),
				),
			),
			$text0->toDelta()
		);
		compare( $setup['users'] );
	}

	public function testFalsyFormats(): void {
		$setup  = \Yjs\Tests\Support\init( $this, array( 'users' => 2 ) );
		$text0  = $setup['text0'];
		$deltas = array();
		$text0->observe(
			static function ( $event ) use ( &$deltas ): void {
				$deltas[] = $event->delta;
			}
		);

		$text0->insert( 0, 'abcde', array( 'falsy' => false ) );
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => 'abcde',
					'attributes' => array( 'falsy' => false ),
				),
			),
			$text0->toDelta()
		);
		$text0->format( 1, 3, array( 'falsy' => true ) );
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => 'a',
					'attributes' => array( 'falsy' => false ),
				),
				array(
					'insert'     => 'bcd',
					'attributes' => array( 'falsy' => true ),
				),
				array(
					'insert'     => 'e',
					'attributes' => array( 'falsy' => false ),
				),
			),
			$text0->toDelta()
		);
		$this->assertDeltaSame(
			array(
				array( 'retain' => 1 ),
				array(
					'retain'     => 3,
					'attributes' => array( 'falsy' => true ),
				),
			),
			$deltas[1]
		);
		compare( $setup['users'] );
	}

	public function testMultilineFormat(): void {
		$text = $this->doc( 1 )->getText();
		$text->insert( 0, "a\nb\nc" );
		$text->format( 1, 3, array( 'header' => 1 ) );
		$this->assertDeltaSame(
			array(
				array( 'insert' => 'a' ),
				array(
					'insert'     => "\nb\n",
					'attributes' => array( 'header' => 1 ),
				),
				array( 'insert' => 'c' ),
			),
			$text->toDelta()
		);
	}

	public function testNotMergeEmptyLinesFormat(): void {
		$text = $this->doc( 1 )->getText();
		$text->applyDelta(
			array(
				array(
					'insert'     => "\n",
					'attributes' => array( 'h' => 1 ),
				),
				array(
					'insert'     => "\n",
					'attributes' => array( 'h' => 2 ),
				),
			)
		);
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => "\n",
					'attributes' => array( 'h' => 1 ),
				),
				array(
					'insert'     => "\n",
					'attributes' => array( 'h' => 2 ),
				),
			),
			$text->toDelta()
		);
	}

	public function testPreserveAttributesThroughDelete(): void {
		$text = $this->doc( 1 )->getText();
		$text->insert( 0, 'abc', array( 'bold' => true ) );
		$text->delete( 1, 1 );
		$text->insert( 1, 'X' );
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => 'aXc',
					'attributes' => array( 'bold' => true ),
				),
			),
			$text->toDelta()
		);
	}

	public function testGetDeltaWithEmbeds(): void {
		$text = $this->doc( 1 )->getText();
		$text->insert( 0, 'a', array( 'bold' => true ) );
		$text->insertEmbed( 1, array( 'image' => 'imageSrc.png' ), array( 'width' => 100 ) );
		$text->insert( 2, 'b', array( 'bold' => true ) );
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => 'a',
					'attributes' => array( 'bold' => true ),
				),
				array(
					'insert'     => array( 'image' => 'imageSrc.png' ),
					'attributes' => array( 'width' => 100 ),
				),
				array(
					'insert'     => 'b',
					'attributes' => array( 'bold' => true ),
				),
			),
			$text->toDelta()
		);
	}

	public function testTypesAsEmbed(): void {
		$text = $this->doc( 1 )->getText();
		$map  = new YMap( array( array( 'key', 'val' ) ) );
		$text->insertEmbed( 0, $map );
		$delta = $text->toDelta();
		self::assertInstanceOf( YMap::class, $delta[0]['insert'] );
		self::assertEquals( (object) array( 'key' => 'val' ), $delta[0]['insert']->toJSON() );
	}

	public function testSnapshot(): void {
		$doc           = new Doc( array( 'gc' => false ) );
		$doc->clientID = 1;
		$text          = $doc->getText();
		$text->insert( 0, 'abcd' );
		$snapshot1 = \Yjs\snapshot( $doc );
		$text->delete( 1, 1 );
		$text->insert( 1, 'x' );
		$snapshot2 = \Yjs\snapshot( $doc );

		$this->assertDeltaSame( array( array( 'insert' => 'abcd' ) ), $text->toDelta( $snapshot1 ) );
		$this->assertDeltaSame( array( array( 'insert' => 'axcd' ) ), $text->toDelta( $snapshot2 ) );
		$this->assertDeltaSame(
			array(
				array( 'insert' => 'a' ),
				array(
					'insert'     => 'b',
					'attributes' => array( 'ychange' => array( 'type' => 'removed' ) ),
				),
				array(
					'insert'     => 'x',
					'attributes' => array( 'ychange' => array( 'type' => 'added' ) ),
				),
				array( 'insert' => 'cd' ),
			),
			$text->toDelta( $snapshot2, $snapshot1 )
		);
	}

	public function testSnapshotDeleteAfter(): void {
		$doc           = new Doc( array( 'gc' => false ) );
		$doc->clientID = 1;
		$text          = $doc->getText();
		$text->insert( 0, 'abcd' );
		$snapshot = \Yjs\snapshot( $doc );
		$text->delete( 3, 1 );
		$this->assertDeltaSame( array( array( 'insert' => 'abcd' ) ), $text->toDelta( $snapshot ) );
	}

	public function testToJson(): void {
		$text = $this->doc( 1 )->getText();
		$text->insert( 0, 'hello' );
		self::assertSame( 'hello', $text->toJSON() );
	}

	public function testToDeltaEmbedAttributes(): void {
		$text = $this->doc( 1 )->getText();
		$text->insert( 0, 'a', array( 'bold' => true ) );
		$text->insertEmbed( 1, array( 'image' => 'imageSrc.png' ), array( 'width' => 100 ) );
		$text->insert( 2, 'b', array( 'bold' => true ) );
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => 'a',
					'attributes' => array( 'bold' => true ),
				),
				array(
					'insert'     => array( 'image' => 'imageSrc.png' ),
					'attributes' => array( 'width' => 100 ),
				),
				array(
					'insert'     => 'b',
					'attributes' => array( 'bold' => true ),
				),
			),
			$text->toDelta()
		);
	}

	public function testToDeltaEmbedNoAttributes(): void {
		$text = $this->doc( 1 )->getText();
		$text->insert( 0, 'a', array( 'bold' => true ) );
		$text->insertEmbed( 1, array( 'image' => 'imageSrc.png' ) );
		$text->insert( 2, 'b', array( 'bold' => true ) );
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => 'a',
					'attributes' => array( 'bold' => true ),
				),
				array( 'insert' => array( 'image' => 'imageSrc.png' ) ),
				array(
					'insert'     => 'b',
					'attributes' => array( 'bold' => true ),
				),
			),
			$text->toDelta()
		);
	}

	public function testFormattingRemoved(): void {
		$text = $this->doc( 1 )->getText();
		$text->insert( 0, 'abc', array( 'bold' => true ) );
		$text->format( 0, 3, array( 'bold' => null ) );
		$this->assertDeltaSame( array( array( 'insert' => 'abc' ) ), $text->toDelta() );
		self::assertSame( 0, \Yjs\cleanupYTextFormatting( $text ) );
	}

	public function testFormattingRemovedInMidText(): void {
		$text = $this->doc( 1 )->getText();
		$text->insert( 0, 'abcd', array( 'bold' => true ) );
		$text->format( 1, 2, array( 'bold' => null ) );
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => 'a',
					'attributes' => array( 'bold' => true ),
				),
				array( 'insert' => 'bc' ),
				array(
					'insert'     => 'd',
					'attributes' => array( 'bold' => true ),
				),
			),
			$text->toDelta()
		);
	}

	public function testFormattingDeltaUnnecessaryAttributeChange(): void {
		$text   = $this->doc( 1 )->getText();
		$deltas = array();
		$text->observe(
			static function ( $event ) use ( &$deltas ): void {
				$deltas[] = $event->delta;
			}
		);
		$text->insert( 0, 'abc', array( 'bold' => true ) );
		$text->format( 0, 3, array( 'bold' => true ) );
		$filtered = array_values(
			array_filter(
				$deltas,
				static fn ( array $delta ): bool => array() !== $delta
			)
		);
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => 'abc',
					'attributes' => array( 'bold' => true ),
				),
			),
			$filtered[0]
		);
		self::assertCount( 1, $filtered );
	}

	public function testInsertAndDeleteAtRandomPositions(): void {
		$text     = $this->doc( 1 )->getText();
		$expected = '';
		for ( $i = 0; $i < 80; $i++ ) {
			if ( '' === $expected || Prng::bool( $this->prng ) ) {
				$insert = (string) $i . Prng::word( $this->prng, 1, 4 );
				$pos    = Prng::int32( $this->prng, 0, strlen( $expected ) );
				$text->insert( $pos, $insert );
				$expected = substr( $expected, 0, $pos ) . $insert . substr( $expected, $pos );
			} else {
				$pos = Prng::int32( $this->prng, 0, strlen( $expected ) - 1 );
				$len = Prng::int32( $this->prng, 1, min( 3, strlen( $expected ) - $pos ) );
				$text->delete( $pos, $len );
				$expected = substr( $expected, 0, $pos ) . substr( $expected, $pos + $len );
			}
			self::assertSame( $expected, $text->toString() );
		}
	}

	public function testAppendChars(): void {
		$text = $this->doc( 1 )->getText();
		for ( $i = 0; $i < 50; $i++ ) {
			$text->insert( $text->length, 'x' );
		}
		self::assertSame( str_repeat( 'x', 50 ), $text->toString() );
	}

	public function testBestCase(): void {
		$text = $this->doc( 1 )->getText();
		$text->insert( 0, str_repeat( 'a', 100 ) );
		$text->format( 0, 100, array( 'bold' => true ) );
		$this->assertDeltaSame(
			array(
				array(
					'insert'     => str_repeat( 'a', 100 ),
					'attributes' => array( 'bold' => true ),
				),
			),
			$text->toDelta()
		);
	}

	public function testLargeFragmentedDocument(): void {
		$text = $this->doc( 1 )->getText();
		for ( $i = 0; $i < 100; $i++ ) {
			$text->insert( $text->length, 'a' );
		}
		for ( $i = 0; $i < 50; $i++ ) {
			$text->delete( $i, 1 );
		}
		self::assertSame( 50, $text->length );
		self::assertSame( 0, \Yjs\cleanupYTextFormatting( $text ) );
	}

	public function testIncrementalUpdatesPerformanceOnLargeFragmentedDocument(): void {
		$doc1 = $this->doc( 1 );
		$text = $doc1->getText();
		for ( $i = 0; $i < 80; $i++ ) {
			$text->insert( $text->length, 'a' );
		}
		for ( $i = 0; $i < 30; $i++ ) {
			$text->delete( $i, 1 );
		}
		$doc2 = $this->doc( 2 );
		\Yjs\applyUpdate( $doc2, \Yjs\encodeStateAsUpdate( $doc1 ) );
		self::assertSame( $doc1->getText()->toString(), $doc2->getText()->toString() );
		self::assertSame( \Yjs\encodeStateAsUpdate( $doc1 )->toHexString(), \Yjs\encodeStateAsUpdate( $doc2 )->toHexString() );
	}

	public function testSplitSurrogateCharacter(): void {
		$text = $this->doc( 1 )->getText();
		$text->insert( 0, '🙂' );
		$text->insert( 1, '_' );
		self::assertSame( '�_�', $text->toString() );
	}

	public function testSearchMarkerBug1(): void {
		$setup = \Yjs\Tests\Support\init( $this, array( 'users' => 2 ) );
		$text0 = $setup['text0'];
		$text1 = $setup['text1'];
		$text0->insert( 0, 'asda' );
		$text0->insert( 1, '_' );
		$setup['testConnector']->syncAll();
		self::assertSame( $text0->toString(), $text1->toString() );
		self::assertSame( 'a_sda', $text0->toString() );
		compare( $setup['users'] );
	}

	public function testFormattingBug(): void {
		$doc1  = $this->doc( 1 );
		$text1 = $doc1->getText();
		$text1->insert( 0, 'Attack ships on fire off the shoulder of Orion.' );
		$doc2  = $this->doc( 2 );
		$text2 = $doc2->getText();
		\Yjs\applyUpdate( $doc2, \Yjs\encodeStateAsUpdate( $doc1 ) );
		$text1->format( 13, 7, array( 'bold' => true ) );
		\Yjs\applyUpdate( $doc2, \Yjs\encodeStateAsUpdate( $doc1 ) );
		$text1->format( 16, 4, array( 'bold' => null ) );
		\Yjs\applyUpdate( $doc2, \Yjs\encodeStateAsUpdate( $doc1 ) );

		$expected = array(
			array( 'insert' => 'Attack ships ' ),
			array(
				'insert'     => 'on ',
				'attributes' => array( 'bold' => true ),
			),
			array( 'insert' => 'fire off the shoulder of Orion.' ),
		);
		$this->assertDeltaSame( $expected, $text1->toDelta() );
		$this->assertDeltaSame( $expected, $text2->toDelta() );
	}

	public function testDeleteFormatting(): void {
		$doc  = $this->doc( 1 );
		$text = $doc->getText();
		$text->insert( 0, 'abcdef' );
		$text->format( 0, 6, array( 'bold' => true ) );
		$text->format( 2, 2, array( 'bold' => null ) );
		$doc2 = $this->doc( 2 );
		\Yjs\applyUpdate( $doc2, \Yjs\encodeStateAsUpdate( $doc ) );
		$text->delete( 1, 4 );
		\Yjs\applyUpdate( $doc2, \Yjs\encodeStateAsUpdate( $doc ) );
		$expected = array(
			array(
				'insert'     => 'af',
				'attributes' => array( 'bold' => true ),
			),
		);
		$this->assertDeltaSame( $expected, $text->toDelta() );
		$this->assertDeltaSame( $expected, $doc2->getText()->toDelta() );
	}

	public function testRepeatGenerateTextChanges5(): void {
		$this->runTextChanges( 5 );
	}

	public function testRepeatGenerateTextChanges30(): void {
		$this->runTextChanges( 30 );
	}

	public function testRepeatGenerateTextChanges40(): void {
		$this->runTextChanges( 40 );
	}

	public function testRepeatGenerateTextChanges50(): void {
		$this->runTextChanges( 50 );
	}

	public function testRepeatGenerateTextChanges70(): void {
		$this->runTextChanges( 70 );
	}

	public function testRepeatGenerateTextChanges90(): void {
		$this->runTextChanges( 90 );
	}

	public function testRepeatGenerateTextChanges300(): void {
		$this->runTextChanges( 300 );
	}

	public function testRepeatGenerateQuillChanges1(): void {
		$this->runQuillChanges( 1 );
	}

	public function testRepeatGenerateQuillChanges2(): void {
		$this->runQuillChanges( 2 );
	}

	public function testRepeatGenerateQuillChanges2Repeat(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->runQuillChanges( 2 );
		}
	}

	public function testRepeatGenerateQuillChanges3(): void {
		$this->runQuillChanges( 3 );
	}

	public function testRepeatGenerateQuillChanges30(): void {
		$this->runQuillChanges( 30 );
	}

	public function testRepeatGenerateQuillChanges40(): void {
		$this->runQuillChanges( 40 );
	}

	public function testRepeatGenerateQuillChanges70(): void {
		$this->runQuillChanges( 70 );
	}

	public function testRepeatGenerateQuillChanges100(): void {
		$this->runQuillChanges( 100 );
	}

	public function testRepeatGenerateQuillChanges300(): void {
		$this->runQuillChanges( 300 );
	}

	private function doc( int $clientID ): Doc {
		$doc           = new Doc();
		$doc->clientID = $clientID;
		return $doc;
	}

	/**
	 * @param array<int,array<string,mixed>> $expected Expected delta.
	 * @param array<int,array<string,mixed>> $actual   Actual delta.
	 * @return void
	 */
	private function assertDeltaSame( array $expected, array $actual ): void {
		self::assertSame( $this->normalizeDelta( $expected ), $this->normalizeDelta( $actual ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $delta Delta.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalizeDelta( array $delta ): array {
		return array_map(
			function ( array $op ): array {
				return $this->normalizeValue( $op );
			},
			$delta
		);
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function normalizeValue( $value ) {
		if ( $value instanceof AbstractType ) {
			return $value->toJSON();
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

	private function runTextChanges( int $iterations ): void {
		$result = applyRandomTests( $this, $this->textChanges(), $iterations );
		self::assertSame( 0, \Yjs\cleanupYTextFormatting( $result['users'][0]->getText( 'text' ) ) );
	}

	private function runQuillChanges( int $iterations ): void {
		$result = applyRandomTests( $this, $this->quillChanges(), $iterations );
		$this->assertStableDeltas( $result['users'] );
		self::assertSame( 0, \Yjs\cleanupYTextFormatting( $result['users'][0]->getText( 'text' ) ) );
	}

	/**
	 * @param array<int,TestYInstance> $users Users.
	 * @return void
	 */
	private function assertStableDeltas( array $users ): void {
		foreach ( $users as $user ) {
			self::assertSame( $this->normalizeDelta( $user->getText( 'text' )->toDelta() ), $this->normalizeDelta( $user->getText( 'text' )->toDelta() ) );
		}
	}

	/**
	 * @return array<int,callable>
	 */
	private function textChanges(): array {
		return array(
			function ( TestYInstance $y, $gen ): void {
				$ytext     = $y->getText( 'text' );
				$insertPos = Prng::int32( $gen, 0, $ytext->length );
				$counter   = $this->charCounter++;
				$text      = (string) $counter . Prng::word( $gen );
				$prevText  = $ytext->toString();
				$ytext->insert( $insertPos, $text );
				self::assertSame( substr( $prevText, 0, $insertPos ) . $text . substr( $prevText, $insertPos ), $ytext->toString() );
			},
			function ( TestYInstance $y, $gen ): void {
				$ytext      = $y->getText( 'text' );
				$contentLen = strlen( $ytext->toString() );
				$insertPos  = Prng::int32( $gen, 0, $contentLen );
				$overwrite  = min( Prng::int32( $gen, 0, $contentLen - $insertPos ), 2 );
				$prevText   = $ytext->toString();
				$ytext->delete( $insertPos, $overwrite );
				self::assertSame( substr( $prevText, 0, $insertPos ) . substr( $prevText, $insertPos + $overwrite ), $ytext->toString() );
			},
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function marks(): array {
		return array(
			array( 'bold' => true ),
			array( 'italic' => true ),
			array(
				'italic' => true,
				'color'  => '#888',
			),
		);
	}

	/**
	 * @return array<int,callable>
	 */
	private function quillChanges(): array {
		return array(
			function ( TestYInstance $y, $gen ): void {
				$ytext     = $y->getText( 'text' );
				$insertPos = Prng::int32( $gen, 0, $ytext->length );
				$choices   = array_merge( array( null ), $this->marks() );
				$attrs     = Prng::oneOf( $gen, $choices );
				$counter   = $this->charCounter++;
				$text      = (string) $counter . Prng::word( $gen );
				$ytext->insert( $insertPos, $text, $attrs );
			},
			function ( TestYInstance $y, $gen ): void {
				$ytext     = $y->getText( 'text' );
				$insertPos = Prng::int32( $gen, 0, $ytext->length );
				if ( Prng::bool( $gen ) ) {
					$ytext->insertEmbed( $insertPos, array( 'image' => 'https://example.com/image.png' ) );
				} else {
					$map = new YMap( array( array( Prng::word( $gen, 1, 4 ), Prng::word( $gen, 1, 4 ) ) ) );
					$ytext->insertEmbed( $insertPos, $map );
				}
			},
			function ( TestYInstance $y, $gen ): void {
				$ytext      = $y->getText( 'text' );
				$contentLen = strlen( $ytext->toString() );
				$insertPos  = Prng::int32( $gen, 0, $contentLen );
				$overwrite  = min( Prng::int32( $gen, 0, $contentLen - $insertPos ), 2 );
				$ytext->delete( $insertPos, $overwrite );
			},
			function ( TestYInstance $y, $gen ): void {
				$ytext      = $y->getText( 'text' );
				$contentLen = strlen( $ytext->toString() );
				$insertPos  = Prng::int32( $gen, 0, $contentLen );
				$overwrite  = min( Prng::int32( $gen, 0, $contentLen - $insertPos ), 2 );
				$ytext->format( $insertPos, $overwrite, Prng::oneOf( $gen, $this->marks() ) );
			},
			function ( TestYInstance $y, $gen ): void {
				$ytext      = $y->getText( 'text' );
				$contentLen = strlen( $ytext->toString() );
				$currentPos = max( 0, Prng::int32( $gen, 0, max( 0, $contentLen - 1 ) ) );
				$ops        = $currentPos > 0 ? array( array( 'retain' => $currentPos ) ) : array();
				for ( $i = 0; $i < 4 && $currentPos < $contentLen; $i++ ) {
					$choice = Prng::int32( $gen, 0, 2 );
					if ( 0 === $choice ) {
						$retain = min( Prng::int32( $gen, 0, $contentLen - $currentPos ), 5 );
						$ops[]  = array(
							'retain'     => $retain,
							'attributes' => Prng::oneOf( $gen, $this->marks() ),
						);
						$currentPos += $retain;
					} elseif ( 1 === $choice ) {
						$attrs = Prng::oneOf( $gen, array_merge( array( null ), $this->marks() ) );
						$op    = array( 'insert' => Prng::word( $gen, 1, 3 ) );
						if ( null !== $attrs ) {
							$op['attributes'] = $attrs;
						}
						$ops[] = $op;
					} else {
						$delLen = min( Prng::int32( $gen, 0, $contentLen - $currentPos ), 10 );
						$ops[]  = array( 'delete' => $delLen );
						$currentPos += $delLen;
					}
				}
				$ytext->applyDelta( $ops );
			},
		);
	}
}
