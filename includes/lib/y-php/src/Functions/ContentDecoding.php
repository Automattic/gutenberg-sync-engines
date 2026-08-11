<?php
/**
 * Struct content decoding namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param object $decoder Decoder.
 * @param int    $info    Item info byte.
 * @return object
 */
function readItemContent( object $decoder, int $info ): object {
	switch ( $info & Lib0\Binary::BITS5 ) {
		case 1:
			return readContentDeleted( $decoder );
		case 2:
			return readContentJSON( $decoder );
		case 3:
			return readContentBinary( $decoder );
		case 4:
			return readContentString( $decoder );
		case 5:
			return readContentEmbed( $decoder );
		case 6:
			return readContentFormat( $decoder );
		case 7:
			return readContentType( $decoder );
		case 8:
			return readContentAny( $decoder );
		case 9:
			return readContentDoc( $decoder );
	}
	Lib0\Error::unexpectedCase();
}

/**
 * @param object $decoder Decoder.
 * @return Structs\ContentDeleted
 */
function readContentDeleted( object $decoder ): Structs\ContentDeleted {
	return new Structs\ContentDeleted( $decoder->readLen() );
}

/**
 * @param object $decoder Decoder.
 * @return Structs\ContentJSON
 */
function readContentJSON( object $decoder ): Structs\ContentJSON {
	$len = $decoder->readLen();
	$arr = array();
	for ( $i = 0; $i < $len; $i++ ) {
		$value = $decoder->readString();
		if ( 'undefined' === $value ) {
			$arr[] = Lib0\UndefinedValue::getInstance();
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode
			$decoded = json_decode( $value );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				throw Lib0\Error::create( 'Unexpected JSON decode failure.' );
			}
			$arr[] = $decoded;
		}
	}
	return new Structs\ContentJSON( $arr );
}

/**
 * @param object $decoder Decoder.
 * @return Structs\ContentBinary
 */
function readContentBinary( object $decoder ): Structs\ContentBinary {
	return new Structs\ContentBinary( $decoder->readBuf() );
}

/**
 * @param object $decoder Decoder.
 * @return Structs\ContentString
 */
function readContentString( object $decoder ): Structs\ContentString {
	return new Structs\ContentString( $decoder->readString() );
}

/**
 * @param object $decoder Decoder.
 * @return Structs\ContentEmbed
 */
function readContentEmbed( object $decoder ): Structs\ContentEmbed {
	return new Structs\ContentEmbed( $decoder->readJSON() );
}

/**
 * @param object $decoder Decoder.
 * @return Structs\ContentFormat
 */
function readContentFormat( object $decoder ): Structs\ContentFormat {
	return new Structs\ContentFormat( $decoder->readKey(), $decoder->readJSON() );
}

/**
 * @param object $decoder Decoder.
 * @return Structs\ContentType
 */
function readContentType( object $decoder ): Structs\ContentType {
	switch ( $decoder->readTypeRef() ) {
		case 0:
			return new Structs\ContentType( readYArray( $decoder ) );
		case 1:
			return new Structs\ContentType( readYMap( $decoder ) );
		case 2:
			return new Structs\ContentType( readYText( $decoder ) );
		case 3:
			return new Structs\ContentType( readYXmlElement( $decoder ) );
		case 4:
			return new Structs\ContentType( readYXmlFragment( $decoder ) );
		case 5:
			return new Structs\ContentType( readYXmlHook( $decoder ) );
		case 6:
			return new Structs\ContentType( readYXmlText( $decoder ) );
	}
	Lib0\Error::unexpectedCase();
}

/**
 * @param object $decoder Decoder.
 * @return Structs\ContentAny
 */
function readContentAny( object $decoder ): Structs\ContentAny {
	$len = $decoder->readLen();
	$arr = array();
	for ( $i = 0; $i < $len; $i++ ) {
		$arr[] = $decoder->readAny();
	}
	return new Structs\ContentAny( $arr );
}

/**
 * @param object $decoder Decoder.
 * @return Structs\ContentDoc
 */
function readContentDoc( object $decoder ): Structs\ContentDoc {
	return new Structs\ContentDoc( Structs\ContentDoc::createDocFromOpts( $decoder->readString(), $decoder->readAny() ) );
}

/**
 * @param object $decoder Decoder.
 * @return Types\YArray
 */
function readYArray( object $decoder ): Types\YArray {
	unset( $decoder );
	return new Types\YArray();
}

/**
 * @param object $decoder Decoder.
 * @return Types\YMap
 */
function readYMap( object $decoder ): Types\YMap {
	unset( $decoder );
	return new Types\YMap();
}

/**
 * @param object $decoder Decoder.
 * @return Types\YText
 */
function readYText( object $decoder ): Types\YText {
	unset( $decoder );
	return new Types\YText();
}

/**
 * @param object $decoder Decoder.
 * @return Types\YXmlElement
 */
function readYXmlElement( object $decoder ): Types\YXmlElement {
	return new Types\YXmlElement( $decoder->readKey() );
}

/**
 * @param object $decoder Decoder.
 * @return Types\YXmlFragment
 */
function readYXmlFragment( object $decoder ): Types\YXmlFragment {
	unset( $decoder );
	return new Types\YXmlFragment();
}

/**
 * @param object $decoder Decoder.
 * @return Types\YXmlHook
 */
function readYXmlHook( object $decoder ): Types\YXmlHook {
	return new Types\YXmlHook( $decoder->readKey() );
}

/**
 * @param object $decoder Decoder.
 * @return Types\YXmlText
 */
function readYXmlText( object $decoder ): Types\YXmlText {
	unset( $decoder );
	return new Types\YXmlText();
}
