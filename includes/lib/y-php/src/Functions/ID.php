<?php
/**
 * ID namespace functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs;

/**
 * @param int $client Client id.
 * @param int $clock  Clock.
 * @return Utils\ID
 */
function createID( int $client, int $clock ): Utils\ID {
	return new Utils\ID( $client, $clock );
}

/**
 * @param Utils\ID|null $a Left ID.
 * @param Utils\ID|null $b Right ID.
 * @return bool
 */
function compareIDs( ?Utils\ID $a, ?Utils\ID $b ): bool {
	return $a === $b || ( null !== $a && null !== $b && $a->client === $b->client && $a->clock === $b->clock );
}

/**
 * @return int
 */
function generateNewClientId(): int {
	return Lib0\Random::uint32();
}

/**
 * @param Lib0\Encoder $encoder Encoder.
 * @param Utils\ID     $id      ID.
 * @return void
 */
function writeID( Lib0\Encoder $encoder, Utils\ID $id ): void {
	Lib0\Encoding::writeVarUint( $encoder, $id->client );
	Lib0\Encoding::writeVarUint( $encoder, $id->clock );
}

/**
 * @param Lib0\Decoder $decoder Decoder.
 * @return Utils\ID
 */
function readID( Lib0\Decoder $decoder ): Utils\ID {
	return createID(
		Lib0\Decoding::readVarUint( $decoder ),
		Lib0\Decoding::readVarUint( $decoder )
	);
}
