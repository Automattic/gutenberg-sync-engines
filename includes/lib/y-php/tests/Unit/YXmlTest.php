<?php
/**
 * YXml unit and fuzz tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Unit;

use Yjs\Lib0\Prng;
use Yjs\Lib0\UndefinedValue;
use Yjs\Tests\Support\TestYInstance;
use Yjs\Tests\Support\TranslatedTestCase;
use Yjs\Types\YMap;
use Yjs\Types\YXmlElement;
use Yjs\Types\YXmlEvent;
use Yjs\Types\YXmlText;
use Yjs\Utils\Doc;

use function Yjs\Tests\Support\applyRandomTests;
use function Yjs\Tests\Support\compare;
use function Yjs\Tests\Support\init;

/**
 * Ported assertions from yjs/tests/y-xml.tests.js.
 */
final class YXmlTest extends TranslatedTestCase {
	/**
	 * @var int
	 */
	private int $counter = 0;

	public function testCustomTypings(): void {
		$ydoc = new Doc();
		$ymap = $ydoc->getMap();
		$yxml = $ymap->set( 'yxml', new YXmlElement( 'test' ) );

		self::assertInstanceOf( YXmlElement::class, $yxml );
		self::assertSame( UndefinedValue::getInstance(), $yxml->getAttribute( 'num' ) );
		self::assertSame( UndefinedValue::getInstance(), $yxml->getAttribute( 'str' ) );
		self::assertSame( array(), $yxml->getAttributes() );
	}

	public function testSetProperty(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$xml0   = $result['xml0'];
		$xml1   = $result['xml1'];

		$xml0->setAttribute( 'height', '10' );
		self::assertSame( '10', $xml0->getAttribute( 'height' ) );
		$result['testConnector']->flushAllMessages();
		self::assertSame( '10', $xml1->getAttribute( 'height' ) );
		compare( $result['users'] );
	}

	public function testHasProperty(): void {
		$result = init( $this, array( 'users' => 2 ) );
		$xml0   = $result['xml0'];
		$xml1   = $result['xml1'];

		$xml0->setAttribute( 'height', '10' );
		self::assertTrue( $xml0->hasAttribute( 'height' ) );
		$result['testConnector']->flushAllMessages();
		self::assertTrue( $xml1->hasAttribute( 'height' ) );

		$xml0->removeAttribute( 'height' );
		self::assertFalse( $xml0->hasAttribute( 'height' ) );
		$result['testConnector']->flushAllMessages();
		self::assertFalse( $xml1->hasAttribute( 'height' ) );
		compare( $result['users'] );
	}

	public function testEvents(): void {
		$result      = init( $this, array( 'users' => 2 ) );
		$xml0        = $result['xml0'];
		$xml1        = $result['xml1'];
		$event       = null;
		$remoteEvent = null;

		$xml0->observe(
			static function ( YXmlEvent $e ) use ( &$event ): void {
				$event = $e;
			}
		);
		$xml1->observe(
			static function ( YXmlEvent $e ) use ( &$remoteEvent ): void {
				$remoteEvent = $e;
			}
		);

		$xml0->setAttribute( 'key', 'value' );
		$this->assertXmlAttributeChanged( $event, 'key' );
		$result['testConnector']->flushAllMessages();
		$this->assertXmlAttributeChanged( $remoteEvent, 'key' );

		$xml0->removeAttribute( 'key' );
		$this->assertXmlAttributeChanged( $event, 'key' );
		$result['testConnector']->flushAllMessages();
		$this->assertXmlAttributeChanged( $remoteEvent, 'key' );

		$xml0->insert( 0, array( new YXmlText( 'some text' ) ) );
		self::assertInstanceOf( YXmlEvent::class, $event );
		self::assertTrue( $event->childListChanged );
		$result['testConnector']->flushAllMessages();
		self::assertInstanceOf( YXmlEvent::class, $remoteEvent );
		self::assertTrue( $remoteEvent->childListChanged );

		$xml0->delete( 0 );
		self::assertTrue( $event->childListChanged );
		$result['testConnector']->flushAllMessages();
		self::assertTrue( $remoteEvent->childListChanged );
		compare( $result['users'] );
	}

	public function testTreewalker(): void {
		$result     = init( $this, array( 'users' => 3 ) );
		$users      = $result['users'];
		$xml0       = $result['xml0'];
		$paragraph1 = new YXmlElement( 'p' );
		$paragraph2 = new YXmlElement( 'p' );
		$text1      = new YXmlText( 'init' );
		$text2      = new YXmlText( 'text' );

		$paragraph1->insert( 0, array( $text1, $text2 ) );
		$xml0->insert( 0, array( $paragraph1, $paragraph2, new YXmlElement( 'img' ) ) );

		$allParagraphs = $xml0->querySelectorAll( 'p' );
		self::assertCount( 2, $allParagraphs );
		self::assertSame( $paragraph1, $allParagraphs[0] );
		self::assertSame( $paragraph2, $allParagraphs[1] );
		self::assertSame( $paragraph1, $xml0->querySelector( 'p' ) );
		compare( $users );
	}

	public function testYtextAttributes(): void {
		$ydoc  = new Doc();
		$ytext = $ydoc->get( '', YXmlText::class );
		$event = null;
		$keys  = null;
		$ytext->observe(
			static function ( $e ) use ( &$event, &$keys ): void {
				$event = $e;
				$keys  = $e->changes['keys'];
			}
		);

		$ytext->setAttribute( 'test', 42 );
		self::assertSame( 42, $ytext->getAttribute( 'test' ) );
		self::assertSame( array( 'test' => 42 ), $ytext->getAttributes() );
		self::assertNotNull( $event );
		self::assertSame( 'add', $keys['test']['action'] );
		self::assertSame( UndefinedValue::getInstance(), $keys['test']['oldValue'] );
	}

	public function testSiblings(): void {
		$ydoc   = new Doc();
		$yxml   = $ydoc->getXmlFragment();
		$first  = new YXmlText();
		$second = new YXmlElement( 'p' );

		$yxml->insert( 0, array( $first, $second ) );
		self::assertSame( $second, $first->nextSibling );
		self::assertSame( $first, $second->prevSibling );
		self::assertSame( $yxml, $first->parent );
		self::assertNull( $yxml->parent );
		self::assertSame( $first, $yxml->firstChild );
	}

	public function testInsertafter(): void {
		$ydoc   = new Doc();
		$yxml   = $ydoc->getXmlFragment();
		$first  = new YXmlText();
		$second = new YXmlElement( 'p' );
		$third  = new YXmlElement( 'p' );

		$deepsecond1 = new YXmlElement( 'span' );
		$deepsecond2 = new YXmlText();
		$second->insertAfter( null, array( $deepsecond1 ) );
		$second->insertAfter( $deepsecond1, array( $deepsecond2 ) );

		$yxml->insertAfter( null, array( $first, $second ) );
		$yxml->insertAfter( $second, array( $third ) );

		self::assertSame( 3, $yxml->length );
		self::assertSame( $deepsecond1, $second->get( 0 ) );
		self::assertSame( $deepsecond2, $second->get( 1 ) );
		self::assertSame( array( $first, $second, $third ), $yxml->toArray() );

		$this->expectException( \RuntimeException::class );
		$el = new YXmlElement( 'p' );
		$el->insertAfter( $deepsecond1, array( new YXmlText() ) );
	}

	public function testClone(): void {
		$ydoc   = new Doc();
		$yxml   = $ydoc->getXmlFragment();
		$first  = new YXmlText( 'text' );
		$second = new YXmlElement( 'p' );
		$third  = new YXmlElement( 'p' );

		$yxml->push( array( $first, $second, $third ) );
		self::assertSame( array( $first, $second, $third ), $yxml->toArray() );
		$cloneYxml = $yxml->clone();
		$ydoc->getArray( 'copyarr' )->insert( 0, array( $cloneYxml ) );
		self::assertSame( 3, $cloneYxml->length );
		self::assertSame( $yxml->toJSON(), $cloneYxml->toJSON() );
	}

	public function testFormattingBug(): void {
		$ydoc  = new Doc();
		$yxml  = $ydoc->get( '', YXmlText::class );
		$delta = array(
			array(
				'insert'     => 'A',
				'attributes' => array(
					'em'     => array(),
					'strong' => array(),
				),
			),
			array(
				'insert'     => 'B',
				'attributes' => array( 'em' => array() ),
			),
			array(
				'insert'     => 'C',
				'attributes' => array(
					'em'     => array(),
					'strong' => array(),
				),
			),
		);
		$yxml->applyDelta( $delta );
		self::assertSame( $delta, $yxml->toDelta() );
		self::assertSame( '<em><strong>A</strong></em><em>B</em><em><strong>C</strong></em>', $yxml->toString() );
	}

	public function testElement(): void {
		$ydoc   = new Doc();
		$yxmlEl = $ydoc->getXmlElement();
		$text1  = new YXmlText( 'text1' );
		$text2  = new YXmlText( 'text2' );
		$yxmlEl->insert( 0, array( $text1, $text2 ) );
		self::assertSame( array( $text1, $text2 ), $yxmlEl->toArray() );
		self::assertSame( '<undefined>text1text2</undefined>', $yxmlEl->toString() );
	}

	public function testRepeatGeneratingYxmlTests10(): void {
		$this->runXmlRandomTests( 10 );
	}

	public function testRepeatGeneratingYxmlTests40(): void {
		$this->runXmlRandomTests( 40 );
	}

	public function testRepeatGeneratingYxmlTests100(): void {
		$this->runXmlRandomTests( 100 );
	}

	public function testRepeatGeneratingYxmlTests300(): void {
		$this->runXmlRandomTests( 300 );
	}

	private function assertXmlAttributeChanged( ?YXmlEvent $event, string $key ): void {
		self::assertInstanceOf( YXmlEvent::class, $event );
		self::assertArrayHasKey( $key, $event->attributesChanged );
	}

	private function runXmlRandomTests( int $iterations ): void {
		$result = applyRandomTests( $this, $this->xmlTransactions(), $iterations );
		$first  = $result['users'][0]->get( 'xml', YXmlElement::class )->toString();
		foreach ( $result['users'] as $user ) {
			self::assertSame( $first, $user->get( 'xml', YXmlElement::class )->toString() );
		}
	}

	/**
	 * @return array<int,callable>
	 */
	private function xmlTransactions(): array {
		return array(
			function ( TestYInstance $user, $gen ): void {
				$key   = Prng::oneOf( $gen, array( 'one', 'two', 'class' ) );
				$value = (string) ( $this->counter++ ) . Prng::word( $gen, 1, 4 );
				$user->get( 'xml', YXmlElement::class )->setAttribute( $key, $value );
			},
			static function ( TestYInstance $user, $gen ): void {
				$key = Prng::oneOf( $gen, array( 'one', 'two', 'class' ) );
				$user->get( 'xml', YXmlElement::class )->removeAttribute( $key );
			},
			function ( TestYInstance $user, $gen ): void {
				$xml = $user->get( 'xml', YXmlElement::class );
				$pos = Prng::int32( $gen, 0, $xml->length );
				$xml->insert( $pos, array( new YXmlText( (string) ( $this->counter++ ) . Prng::word( $gen, 1, 4 ) ) ) );
			},
			function ( TestYInstance $user, $gen ): void {
				$xml     = $user->get( 'xml', YXmlElement::class );
				$pos     = Prng::int32( $gen, 0, $xml->length );
				$element = new YXmlElement( Prng::oneOf( $gen, array( 'p', 'span', 'h1' ) ) );
				$element->setAttribute( 'data-id', (string) $this->counter++ );
				if ( Prng::bool( $gen ) ) {
					$element->insert( 0, array( new YXmlText( Prng::word( $gen, 1, 5 ) ) ) );
				}
				$xml->insert( $pos, array( $element ) );
			},
			static function ( TestYInstance $user, $gen ): void {
				$xml = $user->get( 'xml', YXmlElement::class );
				if ( $xml->length > 0 ) {
					$pos = Prng::int32( $gen, 0, $xml->length - 1 );
					$xml->delete( $pos, 1 );
				}
			},
			static function ( TestYInstance $user, $gen ): void {
				$xml   = $user->get( 'xml', YXmlElement::class );
				$texts = array_values(
					array_filter(
						$xml->toArray(),
						static fn ( $child ): bool => $child instanceof YXmlText && $child->length > 0
					)
				);
				if ( array() !== $texts ) {
					$text = Prng::oneOf( $gen, $texts );
					$text->format( 0, $text->length, array( Prng::oneOf( $gen, array( 'em', 'strong' ) ) => array() ) );
				}
			},
		);
	}
}
