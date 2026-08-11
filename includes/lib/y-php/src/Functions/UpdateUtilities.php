<?php
/**
 * Update utility namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param array<int,Lib0\Buffer> $updates Updates.
 * @return Lib0\Buffer
 */
function mergeUpdates( array $updates ): Lib0\Buffer {
	return mergeUpdatesV2( $updates, Utils\UpdateDecoderV1::class, Utils\UpdateEncoderV1::class );
}

/**
 * @param array<int,Lib0\Buffer> $updates  Updates.
 * @param string                 $YDecoder Decoder class.
 * @param string                 $YEncoder Encoder class.
 * @return Lib0\Buffer
 */
function mergeUpdatesV2( array $updates, string $YDecoder = Utils\UpdateDecoderV2::class, string $YEncoder = Utils\UpdateEncoderV2::class ): Lib0\Buffer {
	if ( 1 === count( $updates ) ) {
		return $updates[0];
	}
	$updateDecoders     = array_map(
		static fn ( Lib0\Buffer $update ) => new $YDecoder( Lib0\Decoding::createDecoder( $update ) ),
		$updates
	);
	$lazyStructDecoders = array_map(
		static fn ( object $decoder ): Utils\LazyStructReader => new Utils\LazyStructReader( $decoder, true ),
		$updateDecoders
	);
	$currWrite          = null;
	$updateEncoder      = new $YEncoder();
	$lazyStructEncoder  = new Utils\LazyStructWriter( $updateEncoder );

	while ( true ) {
		$lazyStructDecoders = array_values(
			array_filter(
				$lazyStructDecoders,
				static fn ( Utils\LazyStructReader $dec ): bool => null !== $dec->curr
			)
		);
		usort(
			$lazyStructDecoders,
			static function ( Utils\LazyStructReader $dec1, Utils\LazyStructReader $dec2 ): int {
				if ( $dec1->curr->id->client === $dec2->curr->id->client ) {
					$clockDiff = $dec1->curr->id->clock - $dec2->curr->id->clock;
					if ( 0 === $clockDiff ) {
						if ( get_class( $dec1->curr ) === get_class( $dec2->curr ) ) {
							return 0;
						}
						return $dec1->curr instanceof Structs\Skip ? 1 : -1;
					}
					return $clockDiff;
				}
				return $dec2->curr->id->client - $dec1->curr->id->client;
			}
		);
		if ( 0 === count( $lazyStructDecoders ) ) {
			break;
		}
		$currDecoder = $lazyStructDecoders[0];
		$firstClient = $currDecoder->curr->id->client;
		if ( null !== $currWrite ) {
			$curr     = $currDecoder->curr;
			$iterated = false;
			while (
				null !== $curr &&
				$curr->id->clock + $curr->length <= $currWrite['struct']->id->clock + $currWrite['struct']->length &&
				$curr->id->client >= $currWrite['struct']->id->client
			) {
				$curr     = $currDecoder->next();
				$iterated = true;
			}
			if (
				null === $curr ||
				$curr->id->client !== $firstClient ||
				( $iterated && $curr->id->clock > $currWrite['struct']->id->clock + $currWrite['struct']->length )
			) {
				continue;
			}

			if ( $firstClient !== $currWrite['struct']->id->client ) {
				writeStructToLazyStructWriter( $lazyStructEncoder, $currWrite['struct'], $currWrite['offset'] );
				$currWrite = array(
					'struct' => $curr,
					'offset' => 0,
				);
				$currDecoder->next();
			} elseif ( $currWrite['struct']->id->clock + $currWrite['struct']->length < $curr->id->clock ) {
				if ( $currWrite['struct'] instanceof Structs\Skip ) {
					$currWrite['struct']->length = $curr->id->clock + $curr->length - $currWrite['struct']->id->clock;
				} else {
					writeStructToLazyStructWriter( $lazyStructEncoder, $currWrite['struct'], $currWrite['offset'] );
					$diff      = $curr->id->clock - $currWrite['struct']->id->clock - $currWrite['struct']->length;
					$struct    = new Structs\Skip( createID( $firstClient, $currWrite['struct']->id->clock + $currWrite['struct']->length ), $diff );
					$currWrite = array(
						'struct' => $struct,
						'offset' => 0,
					);
				}
			} else {
				$diff = $currWrite['struct']->id->clock + $currWrite['struct']->length - $curr->id->clock;
				if ( $diff > 0 ) {
					if ( $currWrite['struct'] instanceof Structs\Skip ) {
						$currWrite['struct']->length -= $diff;
					} else {
						$curr = sliceStruct( $curr, $diff );
					}
				}
				if ( ! $currWrite['struct']->mergeWith( $curr ) ) {
					writeStructToLazyStructWriter( $lazyStructEncoder, $currWrite['struct'], $currWrite['offset'] );
					$currWrite = array(
						'struct' => $curr,
						'offset' => 0,
					);
					$currDecoder->next();
				}
			}
		} else {
			$currWrite = array(
				'struct' => $currDecoder->curr,
				'offset' => 0,
			);
			$currDecoder->next();
		}
		for ( $next = $currDecoder->curr; null !== $next && $next->id->client === $firstClient && $next->id->clock === $currWrite['struct']->id->clock + $currWrite['struct']->length && ! $next instanceof Structs\Skip; $next = $currDecoder->next() ) {
			writeStructToLazyStructWriter( $lazyStructEncoder, $currWrite['struct'], $currWrite['offset'] );
			$currWrite = array(
				'struct' => $next,
				'offset' => 0,
			);
		}
	}
	if ( null !== $currWrite ) {
		writeStructToLazyStructWriter( $lazyStructEncoder, $currWrite['struct'], $currWrite['offset'] );
	}
	finishLazyStructWriting( $lazyStructEncoder );
	$dss = array_map(
		static fn ( object $decoder ): Utils\DeleteSet => readDeleteSet( $decoder ),
		$updateDecoders
	);
	writeDeleteSet( $updateEncoder, mergeDeleteSets( $dss ) );
	return $updateEncoder->toUint8Array();
}

/**
 * @param Lib0\Buffer $update Update.
 * @return array{from:array<int,int>,to:array<int,int>}
 */
function parseUpdateMeta( Lib0\Buffer $update ): array {
	return parseUpdateMetaV2( $update, Utils\UpdateDecoderV1::class );
}

/**
 * @param Lib0\Buffer $update   Update.
 * @param string      $YDecoder Decoder class.
 * @return array{from:array<int,int>,to:array<int,int>}
 */
function parseUpdateMetaV2( Lib0\Buffer $update, string $YDecoder = Utils\UpdateDecoderV2::class ): array {
	$from          = array();
	$to            = array();
	$updateDecoder = new Utils\LazyStructReader( new $YDecoder( Lib0\Decoding::createDecoder( $update ) ), false );
	$curr          = $updateDecoder->curr;
	if ( null !== $curr ) {
		$currClient          = $curr->id->client;
		$currClock           = $curr->id->clock;
		$from[ $currClient ] = $currClock;
		for ( ; null !== $curr; $curr = $updateDecoder->next() ) {
			if ( $currClient !== $curr->id->client ) {
				$to[ $currClient ]         = $currClock;
				$from[ $curr->id->client ] = $curr->id->clock;
				$currClient                = $curr->id->client;
			}
			$currClock = $curr->id->clock + $curr->length;
		}
		$to[ $currClient ] = $currClock;
	}
	return array(
		'from' => $from,
		'to'   => $to,
	);
}

/**
 * @param Lib0\Buffer $update Update.
 * @return Lib0\Buffer
 */
function encodeStateVectorFromUpdate( Lib0\Buffer $update ): Lib0\Buffer {
	return encodeStateVectorFromUpdateV2( $update, Utils\DSEncoderV1::class, Utils\UpdateDecoderV1::class );
}

/**
 * @param Lib0\Buffer $update   Update.
 * @param string      $YEncoder Encoder class.
 * @param string      $YDecoder Decoder class.
 * @return Lib0\Buffer
 */
function encodeStateVectorFromUpdateV2( Lib0\Buffer $update, string $YEncoder = Utils\DSEncoderV2::class, string $YDecoder = Utils\UpdateDecoderV2::class ): Lib0\Buffer {
	$encoder       = new $YEncoder();
	$updateDecoder = new Utils\LazyStructReader( new $YDecoder( Lib0\Decoding::createDecoder( $update ) ), false );
	$curr          = $updateDecoder->curr;
	if ( null !== $curr ) {
		$size         = 0;
		$currClient   = $curr->id->client;
		$stopCounting = 0 !== $curr->id->clock;
		$currClock    = $stopCounting ? 0 : $curr->id->clock + $curr->length;
		for ( ; null !== $curr; $curr = $updateDecoder->next() ) {
			if ( $currClient !== $curr->id->client ) {
				if ( 0 !== $currClock ) {
					++$size;
					Lib0\Encoding::writeVarUint( $encoder->restEncoder, $currClient );
					Lib0\Encoding::writeVarUint( $encoder->restEncoder, $currClock );
				}
				$currClient   = $curr->id->client;
				$currClock    = 0;
				$stopCounting = 0 !== $curr->id->clock;
			}
			if ( $curr instanceof Structs\Skip ) {
				$stopCounting = true;
			}
			if ( ! $stopCounting ) {
				$currClock = $curr->id->clock + $curr->length;
			}
		}
		if ( 0 !== $currClock ) {
			++$size;
			Lib0\Encoding::writeVarUint( $encoder->restEncoder, $currClient );
			Lib0\Encoding::writeVarUint( $encoder->restEncoder, $currClock );
		}
		$enc = Lib0\Encoding::createEncoder();
		Lib0\Encoding::writeVarUint( $enc, $size );
		Lib0\Encoding::writeBinaryEncoder( $enc, $encoder->restEncoder );
		$encoder->restEncoder = $enc;
		return $encoder->toUint8Array();
	}
	Lib0\Encoding::writeVarUint( $encoder->restEncoder, 0 );
	return $encoder->toUint8Array();
}

/**
 * @param Lib0\Buffer $update Update.
 * @param Lib0\Buffer $sv     State vector.
 * @return Lib0\Buffer
 */
function diffUpdate( Lib0\Buffer $update, Lib0\Buffer $sv ): Lib0\Buffer {
	return diffUpdateV2( $update, $sv, Utils\UpdateDecoderV1::class, Utils\UpdateEncoderV1::class );
}

/**
 * @param Lib0\Buffer $update   Update.
 * @param Lib0\Buffer $sv       State vector.
 * @param string      $YDecoder Decoder class.
 * @param string      $YEncoder Encoder class.
 * @return Lib0\Buffer
 */
function diffUpdateV2( Lib0\Buffer $update, Lib0\Buffer $sv, string $YDecoder = Utils\UpdateDecoderV2::class, string $YEncoder = Utils\UpdateEncoderV2::class ): Lib0\Buffer {
	$state            = decodeStateVector( $sv );
	$encoder          = new $YEncoder();
	$lazyStructWriter = new Utils\LazyStructWriter( $encoder );
	$decoder          = new $YDecoder( Lib0\Decoding::createDecoder( $update ) );
	$reader           = new Utils\LazyStructReader( $decoder, false );
	while ( null !== $reader->curr ) {
		$curr       = $reader->curr;
		$currClient = $curr->id->client;
		$svClock    = $state[ $currClient ] ?? 0;
		if ( $reader->curr instanceof Structs\Skip ) {
			$reader->next();
			continue;
		}
		if ( $curr->id->clock + $curr->length > $svClock ) {
			writeStructToLazyStructWriter( $lazyStructWriter, $curr, (int) Lib0\Math::max( $svClock - $curr->id->clock, 0 ) );
			$reader->next();
			while ( null !== $reader->curr && $reader->curr->id->client === $currClient ) {
				writeStructToLazyStructWriter( $lazyStructWriter, $reader->curr, 0 );
				$reader->next();
			}
		} else {
			while ( null !== $reader->curr && $reader->curr->id->client === $currClient && $reader->curr->id->clock + $reader->curr->length <= $svClock ) {
				$reader->next();
			}
		}
	}
	finishLazyStructWriting( $lazyStructWriter );
	writeDeleteSet( $encoder, readDeleteSet( $decoder ) );
	return $encoder->toUint8Array();
}

/**
 * @param Structs\AbstractStruct $left Struct.
 * @param int                    $diff Split offset.
 * @return Structs\AbstractStruct
 */
function sliceStruct( Structs\AbstractStruct $left, int $diff ): Structs\AbstractStruct {
	if ( $left instanceof Structs\GC ) {
		return new Structs\GC( createID( $left->id->client, $left->id->clock + $diff ), $left->length - $diff );
	}
	if ( $left instanceof Structs\Skip ) {
		return new Structs\Skip( createID( $left->id->client, $left->id->clock + $diff ), $left->length - $diff );
	}
	if ( $left instanceof Structs\Item ) {
		return new Structs\Item(
			createID( $left->id->client, $left->id->clock + $diff ),
			null,
			createID( $left->id->client, $left->id->clock + $diff - 1 ),
			null,
			$left->rightOrigin,
			$left->parent,
			$left->parentSub,
			$left->content->splice( $diff )
		);
	}
	Lib0\Error::unexpectedCase();
}

/**
 * @param Utils\LazyStructWriter $lazyWriter Lazy writer.
 * @return void
 */
function flushLazyStructWriter( Utils\LazyStructWriter $lazyWriter ): void {
	if ( $lazyWriter->written > 0 ) {
		$lazyWriter->clientStructs[]      = array(
			'written'     => $lazyWriter->written,
			'restEncoder' => Lib0\Encoding::toUint8Array( $lazyWriter->encoder->restEncoder ),
		);
		$lazyWriter->encoder->restEncoder = Lib0\Encoding::createEncoder();
		$lazyWriter->written              = 0;
	}
}

/**
 * @param Utils\LazyStructWriter $lazyWriter Lazy writer.
 * @param Structs\AbstractStruct $struct     Struct.
 * @param int                    $offset     Offset.
 * @return void
 */
function writeStructToLazyStructWriter( Utils\LazyStructWriter $lazyWriter, Structs\AbstractStruct $struct, int $offset ): void {
	if ( $lazyWriter->written > 0 && $lazyWriter->currClient !== $struct->id->client ) {
		flushLazyStructWriter( $lazyWriter );
	}
	if ( 0 === $lazyWriter->written ) {
		$lazyWriter->currClient = $struct->id->client;
		$lazyWriter->encoder->writeClient( $struct->id->client );
		Lib0\Encoding::writeVarUint( $lazyWriter->encoder->restEncoder, $struct->id->clock + $offset );
	}
	$struct->write( $lazyWriter->encoder, $offset );
	++$lazyWriter->written;
}

/**
 * @param Utils\LazyStructWriter $lazyWriter Lazy writer.
 * @return void
 */
function finishLazyStructWriting( Utils\LazyStructWriter $lazyWriter ): void {
	flushLazyStructWriter( $lazyWriter );
	$restEncoder = $lazyWriter->encoder->restEncoder;
	Lib0\Encoding::writeVarUint( $restEncoder, count( $lazyWriter->clientStructs ) );
	foreach ( $lazyWriter->clientStructs as $partStructs ) {
		Lib0\Encoding::writeVarUint( $restEncoder, $partStructs['written'] );
		Lib0\Encoding::writeUint8Array( $restEncoder, $partStructs['restEncoder'] );
	}
}

/**
 * @param Lib0\Buffer $update           Update.
 * @param callable    $blockTransformer Transformer.
 * @param string      $YDecoder         Decoder class.
 * @param string      $YEncoder         Encoder class.
 * @return Lib0\Buffer
 */
function convertUpdateFormat( Lib0\Buffer $update, callable $blockTransformer, string $YDecoder, string $YEncoder ): Lib0\Buffer {
	$updateDecoder = new $YDecoder( Lib0\Decoding::createDecoder( $update ) );
	$lazyDecoder   = new Utils\LazyStructReader( $updateDecoder, false );
	$updateEncoder = new $YEncoder();
	$lazyWriter    = new Utils\LazyStructWriter( $updateEncoder );
	for ( $curr = $lazyDecoder->curr; null !== $curr; $curr = $lazyDecoder->next() ) {
		writeStructToLazyStructWriter( $lazyWriter, $blockTransformer( $curr ), 0 );
	}
	finishLazyStructWriting( $lazyWriter );
	writeDeleteSet( $updateEncoder, readDeleteSet( $updateDecoder ) );
	return $updateEncoder->toUint8Array();
}

/**
 * @param Lib0\Buffer $update Update.
 * @return Lib0\Buffer
 */
function convertUpdateFormatV1ToV2( Lib0\Buffer $update ): Lib0\Buffer {
	return convertUpdateFormat( $update, static fn ( $block ) => $block, Utils\UpdateDecoderV1::class, Utils\UpdateEncoderV2::class );
}

/**
 * @param Lib0\Buffer $update Update.
 * @return Lib0\Buffer
 */
function convertUpdateFormatV2ToV1( Lib0\Buffer $update ): Lib0\Buffer {
	return convertUpdateFormat( $update, static fn ( $block ) => $block, Utils\UpdateDecoderV2::class, Utils\UpdateEncoderV1::class );
}

/**
 * @param Lib0\Buffer              $update Update.
 * @param array<string,mixed>|null $opts   Obfuscator options.
 * @return Lib0\Buffer
 */
function obfuscateUpdate( Lib0\Buffer $update, ?array $opts = null ): Lib0\Buffer {
	return convertUpdateFormat( $update, createObfuscator( $opts ?? array() ), Utils\UpdateDecoderV1::class, Utils\UpdateEncoderV1::class );
}

/**
 * @param Lib0\Buffer              $update Update.
 * @param array<string,mixed>|null $opts   Obfuscator options.
 * @return Lib0\Buffer
 */
function obfuscateUpdateV2( Lib0\Buffer $update, ?array $opts = null ): Lib0\Buffer {
	return convertUpdateFormat( $update, createObfuscator( $opts ?? array() ), Utils\UpdateDecoderV2::class, Utils\UpdateEncoderV2::class );
}

/**
 * @param array<string,mixed> $opts Options.
 * @return callable
 */
function createObfuscator( array $opts ): callable {
	$formatting           = array_key_exists( 'formatting', $opts ) ? (bool) $opts['formatting'] : true;
	$subdocs              = array_key_exists( 'subdocs', $opts ) ? (bool) $opts['subdocs'] : true;
	$yxml                 = array_key_exists( 'yxml', $opts ) ? (bool) $opts['yxml'] : true;
	$i                    = 0;
	$mapKeyCache          = array();
	$nodeNameCache        = array();
	$formattingKeyCache   = array();
	$formattingValueCache = array( 'N;' => null );
	return static function ( $block ) use ( &$i, &$mapKeyCache, &$nodeNameCache, &$formattingKeyCache, &$formattingValueCache, $formatting, $subdocs, $yxml ) {
		if ( $block instanceof Structs\GC || $block instanceof Structs\Skip ) {
			return $block;
		}
		if ( ! $block instanceof Structs\Item ) {
			Lib0\Error::unexpectedCase();
		}
		$content = $block->content;
		if ( $content instanceof Structs\ContentType ) {
			if ( $yxml ) {
				$type = $content->type;
				if ( $type instanceof Types\YXmlElement ) {
					$type->nodeName = obfuscatorSetIfUndefined( $nodeNameCache, $type->nodeName, 'node-' . $i );
				}
				if ( $type instanceof Types\YXmlHook ) {
					$type->hookName = obfuscatorSetIfUndefined( $nodeNameCache, $type->hookName, 'hook-' . $i );
				}
			}
		} elseif ( $content instanceof Structs\ContentAny ) {
			$content->arr = array_map( static fn (): int => $i, $content->arr );
		} elseif ( $content instanceof Structs\ContentBinary ) {
			$content->content = Lib0\Buffer::fromByteArray( array( $i ) );
		} elseif ( $content instanceof Structs\ContentDoc ) {
			if ( $subdocs ) {
				$content->opts      = new \stdClass();
				$content->doc->guid = (string) $i;
			}
		} elseif ( $content instanceof Structs\ContentEmbed ) {
			$content->embed = new \stdClass();
		} elseif ( $content instanceof Structs\ContentFormat ) {
			if ( $formatting ) {
				$content->key = obfuscatorSetIfUndefined( $formattingKeyCache, $content->key, (string) $i );
				$valueKey     = obfuscatorValueKey( $content->value );
				if ( ! array_key_exists( $valueKey, $formattingValueCache ) ) {
					$formattingValueCache[ $valueKey ] = (object) array( 'i' => $i );
				}
				$content->value = $formattingValueCache[ $valueKey ];
			}
		} elseif ( $content instanceof Structs\ContentJSON ) {
			$content->arr = array_map( static fn (): int => $i, $content->arr );
		} elseif ( $content instanceof Structs\ContentString ) {
			$content->str = str_repeat( (string) ( $i % 10 ), $content->getLength() );
		} elseif ( ! $content instanceof Structs\ContentDeleted ) {
			Lib0\Error::unexpectedCase();
		}
		if ( null !== $block->parentSub ) {
			$block->parentSub = obfuscatorSetIfUndefined( $mapKeyCache, $block->parentSub, (string) $i );
		}
		++$i;
		return $block;
	};
}

/**
 * @param array<string,string> $cache Cache.
 * @param string               $key   Key.
 * @param string               $value Value.
 * @return string
 */
function obfuscatorSetIfUndefined( array &$cache, string $key, string $value ): string {
	if ( ! array_key_exists( $key, $cache ) ) {
		$cache[ $key ] = $value;
	}
	return $cache[ $key ];
}

/**
 * @param mixed $value Value.
 * @return string
 */
function obfuscatorValueKey( $value ): string {
	if ( is_object( $value ) ) {
		return 'o:' . spl_object_hash( $value );
	}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		return serialize( $value );
}
