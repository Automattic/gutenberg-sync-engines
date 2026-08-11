<?php
/**
 * Debugging namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param Lib0\Buffer $update Update.
 * @return void
 */
function logUpdate( Lib0\Buffer $update ): void {
	logUpdateV2( $update, Utils\UpdateDecoderV1::class );
}

/**
 * @param Lib0\Buffer $update   Update.
 * @param string      $YDecoder Decoder class.
 * @return void
 */
function logUpdateV2( Lib0\Buffer $update, string $YDecoder = Utils\UpdateDecoderV2::class ): void {
	decodeUpdateV2( $update, $YDecoder );
}

/**
 * @param Types\AbstractType $type Type to inspect.
 * @return void
 */
function logType( Types\AbstractType $type ): void {
	$children = array();
	$content  = array();
	for ( $n = $type->_start; null !== $n; $n = $n->right ) {
		$children[] = describeTypeChild( $n );
		if ( ! $n->deleted ) {
			$content[] = describeTypeContent( $n->content );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	echo 'Children: ' . json_encode( $children, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	echo 'Children content: ' . json_encode( $content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
}

/**
 * @param Structs\Item $item Item.
 * @return array<string,mixed>
 */
function describeTypeChild( Structs\Item $item ): array {
	return array(
		'class'     => get_class( $item ),
		'id'        => array(
			'client' => $item->id->client,
			'clock'  => $item->id->clock,
		),
		'length'    => $item->length,
		'deleted'   => $item->deleted,
		'countable' => $item->countable,
		'parentSub' => $item->parentSub,
		'content'   => describeTypeContent( $item->content ),
	);
}

/**
 * @param object $content Content instance.
 * @return array<string,mixed>
 */
function describeTypeContent( object $content ): array {
	$description = array( 'class' => get_class( $content ) );
	$properties  = get_object_vars( $content );
	if ( array() !== $properties ) {
		$description['properties'] = normalizeLogValue( $properties );
	}
	if ( method_exists( $content, 'getContent' ) ) {
		$description['content'] = normalizeLogValue( $content->getContent() );
	}
	return $description;
}

/**
 * @param mixed $value Value.
 * @return mixed
 */
function normalizeLogValue( $value ) {
	if ( $value instanceof Types\AbstractType ) {
		return array(
			'class' => get_class( $value ),
			'json'  => normalizeLogValue( $value->toJSON() ),
		);
	}
	if ( $value instanceof Utils\Doc ) {
		return array(
			'class' => get_class( $value ),
			'guid'  => $value->guid,
		);
	}
	if ( $value instanceof Lib0\Buffer ) {
		return array(
			'class' => get_class( $value ),
			'hex'   => $value->toHexString(),
		);
	}
	if ( $value instanceof \stdClass ) {
		return normalizeLogValue( get_object_vars( $value ) );
	}
	if ( is_array( $value ) ) {
		$result = array();
		foreach ( $value as $key => $item ) {
			$result[ $key ] = normalizeLogValue( $item );
		}
		return $result;
	}
	return $value;
}
