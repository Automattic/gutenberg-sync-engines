<?php
/**
 * Top-level public API aliases.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

$aliases = array(
	'Yjs\Doc'               => 'Yjs\Utils\Doc',
	'Yjs\Transaction'       => 'Yjs\Utils\Transaction',
	'Yjs\ID'                => 'Yjs\Utils\ID',
	'Yjs\Snapshot'          => 'Yjs\Utils\Snapshot',
	'Yjs\RelativePosition'  => 'Yjs\Utils\RelativePosition',
	'Yjs\AbsolutePosition'  => 'Yjs\Utils\AbsolutePosition',
	'Yjs\AbstractConnector' => 'Yjs\Utils\AbstractConnector',
	'Yjs\UndoManager'       => 'Yjs\Utils\UndoManager',
	'Yjs\StackItem'         => 'Yjs\Utils\StackItem',
	'Yjs\PermanentUserData' => 'Yjs\Utils\PermanentUserData',
	'Yjs\StructStore'       => 'Yjs\Utils\StructStore',
	'Yjs\DeleteSet'         => 'Yjs\Utils\DeleteSet',
	'Yjs\DSEncoderV1'       => 'Yjs\Utils\DSEncoderV1',
	'Yjs\DSEncoderV2'       => 'Yjs\Utils\DSEncoderV2',
	'Yjs\DSDecoderV1'       => 'Yjs\Utils\DSDecoderV1',
	'Yjs\DSDecoderV2'       => 'Yjs\Utils\DSDecoderV2',
	'Yjs\UpdateEncoderV1'   => 'Yjs\Utils\UpdateEncoderV1',
	'Yjs\UpdateEncoderV2'   => 'Yjs\Utils\UpdateEncoderV2',
	'Yjs\UpdateDecoderV1'   => 'Yjs\Utils\UpdateDecoderV1',
	'Yjs\UpdateDecoderV2'   => 'Yjs\Utils\UpdateDecoderV2',
	'Yjs\YEvent'            => 'Yjs\Utils\YEvent',
	'Yjs\AbstractStruct'    => 'Yjs\Structs\AbstractStruct',
	'Yjs\Item'              => 'Yjs\Structs\Item',
	'Yjs\GC'                => 'Yjs\Structs\GC',
	'Yjs\Skip'              => 'Yjs\Structs\Skip',
	'Yjs\ContentBinary'     => 'Yjs\Structs\ContentBinary',
	'Yjs\ContentDeleted'    => 'Yjs\Structs\ContentDeleted',
	'Yjs\ContentDoc'        => 'Yjs\Structs\ContentDoc',
	'Yjs\ContentEmbed'      => 'Yjs\Structs\ContentEmbed',
	'Yjs\ContentFormat'     => 'Yjs\Structs\ContentFormat',
	'Yjs\ContentJSON'       => 'Yjs\Structs\ContentJSON',
	'Yjs\ContentAny'        => 'Yjs\Structs\ContentAny',
	'Yjs\ContentString'     => 'Yjs\Structs\ContentString',
	'Yjs\ContentType'       => 'Yjs\Structs\ContentType',
	'Yjs\AbstractType'      => 'Yjs\Types\AbstractType',
	'Yjs\YArray'            => 'Yjs\Types\YArray',
	'Yjs\YMap'              => 'Yjs\Types\YMap',
	'Yjs\YText'             => 'Yjs\Types\YText',
	'Yjs\YXmlText'          => 'Yjs\Types\YXmlText',
	'Yjs\YXmlHook'          => 'Yjs\Types\YXmlHook',
	'Yjs\YXmlElement'       => 'Yjs\Types\YXmlElement',
	'Yjs\YXmlFragment'      => 'Yjs\Types\YXmlFragment',
	'Yjs\YXmlEvent'         => 'Yjs\Types\YXmlEvent',
	'Yjs\YMapEvent'         => 'Yjs\Types\YMapEvent',
	'Yjs\YArrayEvent'       => 'Yjs\Types\YArrayEvent',
	'Yjs\YTextEvent'        => 'Yjs\Types\YTextEvent',
);

foreach ( $aliases as $alias => $target ) {
	if ( ! class_exists( $alias, false ) ) {
		class_alias( $target, $alias );
	}
}
