<?php
/**
 * Real browser-client interop conformance tests.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Buffer;
use Yjs\Types\AbstractType;
use Yjs\Types\YMap;
use Yjs\Types\YXmlElement;
use Yjs\Types\YXmlText;
use Yjs\Utils\Doc;

/**
 * Verifies captured browser updates and browser-validated PHP updates.
 */
final class RealClientInteropTest extends TestCase {
	public function testBrowserGeneratedUpdateDecodesInPhp(): void {
		$fixture = $this->fixture( 'browser-generated.json' );
		$update  = $this->update( $fixture['updateFile'] );
		$doc     = new Doc();

		\Yjs\applyUpdate( $doc, $update );

		self::assertSame( $fixture['updateHex'], $update->toHexString(), 'captured update bytes' );
		self::assertSame( $fixture['stateVectorHex'], \Yjs\encodeStateVector( $doc )->toHexString(), 'state vector' );
		self::assertSame( $fixture['updateHex'], \Yjs\encodeStateAsUpdate( $doc )->toHexString(), 're-encoded update' );
		self::assertSame( $fixture['expected'], $this->docState( $doc ) );
	}

	public function testPhpGeneratedUpdateAppliesInBrowserAndDecodesInPhp(): void {
		$fixture       = $this->fixture( 'php-generated-browser-applied.json' );
		$capturedBytes = $this->update( $fixture['updateFile'] );
		$sourceDoc     = $this->createPhpInteropDoc();
		$sourceUpdate  = \Yjs\encodeStateAsUpdate( $sourceDoc );
		$roundTripDoc  = new Doc();

		\Yjs\applyUpdate( $roundTripDoc, $capturedBytes );

		self::assertSame( $fixture['updateHex'], $capturedBytes->toHexString(), 'captured PHP update bytes' );
		self::assertSame( $fixture['updateHex'], $sourceUpdate->toHexString(), 'fresh PHP update bytes' );
		self::assertSame( $fixture['stateVectorHex'], \Yjs\encodeStateVector( $roundTripDoc )->toHexString(), 'state vector' );
		self::assertSame( $fixture['expected'], $this->docState( $roundTripDoc ) );
		self::assertSame( $fixture['expected'], $this->docState( $sourceDoc ) );
	}

	private function createPhpInteropDoc(): Doc {
		$doc           = new Doc( array( 'guid' => 'm6-php-doc' ) );
		$doc->clientID = 701;

		$object         = new \stdClass();
		$object->nested = array( 'p', 'h', 'p' );
		$doc->getArray( 'array' )->insert( 0, array( 'php', 11, $object ) );

		$map = $doc->getMap( 'map' );
		$map->set( 'title', 'from php' );
		$nested = new YMap();
		$nested->set( 'flag', false );
		$nested->set( 'count', 4 );
		$map->set( 'nested', $nested );

		$text = $doc->getText( 'text' );
		$text->insert( 0, 'PHP says hello' );
		$text->format(
			4,
			4,
			array(
				'strong' => true,
				'color'  => '#c50',
			)
		);

		$xml     = $doc->getXmlFragment( 'xml' );
		$section = new YXmlElement( 'section' );
		$section->setAttribute( 'data-client', 'php' );
		$xmlText = new YXmlText();
		$xmlText->insert( 0, 'server' );
		$section->insert( 0, array( $xmlText ) );
		$xml->insert( 0, array( $section ) );

		return $doc;
	}

	/**
	 * @param Doc $doc Document.
	 * @return array<string,mixed>
	 */
	private function docState( Doc $doc ): array {
		return array(
			'array' => $this->normalizeValue( $doc->getArray( 'array' )->toJSON() ),
			'map'   => $this->normalizeValue( $doc->getMap( 'map' )->toJSON() ),
			'text'  => $this->normalizeValue( $doc->getText( 'text' )->toDelta() ),
			'xml'   => $doc->getXmlFragment( 'xml' )->toString(),
		);
	}

	/**
	 * @param string $name Fixture file name.
	 * @return array<string,mixed>
	 */
	private function fixture( string $name ): array {
		$path = $this->fixturePath( $name );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			self::fail( 'Unable to read fixture ' . $name );
		}
		return $data;
	}

	private function update( string $name ): Buffer {
		$path = $this->fixturePath( $name );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = file_get_contents( $path );
		if ( false === $data ) {
			self::fail( 'Unable to read update ' . $name );
		}
		return Buffer::fromBinaryString( $data );
	}

	private function fixturePath( string $name ): string {
		return dirname( __DIR__ ) . '/fixtures/real-client/' . $name;
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
