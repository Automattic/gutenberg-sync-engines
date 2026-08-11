<?php
/**
 * Relative position tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Utils\Doc;
use Yjs\Utils\UndoManager;

/**
 * Translated coverage for yjs/tests/relativePositions.tests.js.
 */
final class RelativePositionsTest extends TranslatedTestCase {
	public function testRelativePositionCase1(): void {
		$ytext = ( new Doc() )->getText();
		$ytext->insert( 0, '1' );
		$ytext->insert( 0, 'abc' );
		$ytext->insert( 0, 'z' );
		$ytext->insert( 0, 'y' );
		$ytext->insert( 0, 'x' );
		$this->checkRelativePositions( $ytext );
	}

	public function testRelativePositionCase2(): void {
		$ytext = ( new Doc() )->getText();
		$ytext->insert( 0, 'abc' );
		$this->checkRelativePositions( $ytext );
	}

	public function testRelativePositionCase3(): void {
		$ytext = ( new Doc() )->getText();
		$ytext->insert( 0, 'abc' );
		$ytext->insert( 0, '1' );
		$ytext->insert( 0, 'xyz' );
		$this->checkRelativePositions( $ytext );
	}

	public function testRelativePositionCase4(): void {
		$ytext = ( new Doc() )->getText();
		$ytext->insert( 0, '1' );
		$this->checkRelativePositions( $ytext );
	}

	public function testRelativePositionCase5(): void {
		$ytext = ( new Doc() )->getText();
		$ytext->insert( 0, '2' );
		$ytext->insert( 0, '1' );
		$this->checkRelativePositions( $ytext );
	}

	public function testRelativePositionCase6(): void {
		$ytext = ( new Doc() )->getText();
		$rpos  = \Yjs\createRelativePositionFromTypeIndex( $ytext, 0 );
		$abs   = \Yjs\createAbsolutePositionFromRelativePosition( $rpos, $ytext->doc );
		self::assertSame( 0, $abs->index );
	}

	public function testRelativePositionCase7(): void {
		$docA  = new Doc();
		$textA = $docA->getText( 'text' );
		$textA->insert( 0, 'abcde' );
		$relativePosition = \Yjs\createRelativePositionFromTypeIndex( $textA, 2 );

		$withFollow    = \Yjs\createAbsolutePositionFromRelativePosition( $relativePosition, $docA, true );
		$withoutFollow = \Yjs\createAbsolutePositionFromRelativePosition( $relativePosition, $docA, false );

		self::assertSame( 2, $withFollow->index );
		self::assertSame( 2, $withoutFollow->index );
	}

	public function testRelativePositionAssociationDifference(): void {
		$ydoc  = new Doc();
		$ytext = $ydoc->getText();
		$ytext->insert( 0, '2' );
		$ytext->insert( 0, '1' );
		$rposRight = \Yjs\createRelativePositionFromTypeIndex( $ytext, 1, 0 );
		$rposLeft  = \Yjs\createRelativePositionFromTypeIndex( $ytext, 1, -1 );
		$ytext->insert( 1, 'x' );

		$posRight = \Yjs\createAbsolutePositionFromRelativePosition( $rposRight, $ydoc );
		$posLeft  = \Yjs\createAbsolutePositionFromRelativePosition( $rposLeft, $ydoc );

		self::assertSame( 2, $posRight->index );
		self::assertSame( 1, $posLeft->index );
	}

	public function testRelativePositionWithUndo(): void {
		$ydoc  = new Doc();
		$ytext = $ydoc->getText();
		$ytext->insert( 0, 'hello world' );
		$rpos = \Yjs\createRelativePositionFromTypeIndex( $ytext, 1 );
		$um   = new UndoManager( $ytext );
		$ytext->delete( 0, 6 );
		self::assertSame( 0, \Yjs\createAbsolutePositionFromRelativePosition( $rpos, $ydoc )->index );
		$um->undo();
		self::assertSame( 1, \Yjs\createAbsolutePositionFromRelativePosition( $rpos, $ydoc )->index );
		self::assertSame( 6, \Yjs\createAbsolutePositionFromRelativePosition( $rpos, $ydoc, false )->index );

		$ydocClone = new Doc();
		\Yjs\applyUpdate( $ydocClone, \Yjs\encodeStateAsUpdate( $ydoc ) );
		self::assertSame( 6, \Yjs\createAbsolutePositionFromRelativePosition( $rpos, $ydocClone )->index );
		self::assertSame( 6, \Yjs\createAbsolutePositionFromRelativePosition( $rpos, $ydocClone, false )->index );
	}

	/**
	 * @param \Yjs\Types\YText $ytext Text.
	 * @return void
	 */
	private function checkRelativePositions( \Yjs\Types\YText $ytext ): void {
		for ( $i = 0; $i < $ytext->length; $i++ ) {
			for ( $assoc = -1; $assoc < 2; $assoc++ ) {
				$rpos    = \Yjs\createRelativePositionFromTypeIndex( $ytext, $i, $assoc );
				$decoded = \Yjs\decodeRelativePosition( \Yjs\encodeRelativePosition( $rpos ) );
				$absPos  = \Yjs\createAbsolutePositionFromRelativePosition( $decoded, $ytext->doc );
				self::assertSame( $i, $absPos->index );
				self::assertSame( $assoc, $absPos->assoc );
				self::assertTrue( \Yjs\compareRelativePositions( $decoded, \Yjs\decodeRelativePosition( \Yjs\encodeRelativePosition( $decoded ) ) ) );
			}
		}
	}
}
