<?php
/**
 * Translated Yjs test helper functions.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Support;

use PHPUnit\Framework\Assert;
use Yjs\Lib0\Prng;
use Yjs\Types\AbstractType;
use Yjs\Types\YXmlElement;

/**
 * @param mixed             $tc             Test case.
 * @param array<string,int> $conf           Configuration.
 * @param callable|null     $initTestObject Object initializer.
 * @return array<string,mixed>
 */
function init( $tc, array $conf = array(), ?callable $initTestObject = null ) {
	$users          = $conf['users'] ?? 5;
	$result         = array( 'users' => array() );
	$gen            = is_object( $tc ) && isset( $tc->prng ) ? $tc->prng : Prng::create( 0 );
	$testConnector  = new TestConnector( $gen );
	$testObjects    = array();
	$initTestObject = $initTestObject ?? static function ( TestYInstance $y ) {
		unset( $y );
		return null;
	};

	$result['testConnector'] = $testConnector;
	for ( $i = 0; $i < $users; $i++ ) {
		$y           = $testConnector->createY( $i );
		$y->clientID = $i;

		$result['users'][]      = $y;
		$result[ 'array' . $i ] = $y->getArray( 'array' );
		$result[ 'map' . $i ]   = $y->getMap( 'map' );
		$result[ 'xml' . $i ]   = $y->get( 'xml', YXmlElement::class );
		$result[ 'text' . $i ]  = $y->getText( 'text' );
		$testObjects[]          = $initTestObject( $y );
	}

	$testConnector->syncAll();
	$result['testObjects'] = $testObjects;
	return $result;
}

/**
 * @param array<int,mixed> $users Users.
 * @return void
 */
function compare( array $users ): void {
	foreach ( $users as $user ) {
		$user->connect();
	}
	while ( $users[0]->tc->flushAllMessages() ) {
		continue;
	}

	$firstArray       = $users[0]->getArray( 'array' )->toJSON();
	$firstXml         = $users[0]->get( 'xml', YXmlElement::class )->toString();
	$firstText        = normalizeDeltaForCompare( $users[0]->getText( 'text' )->toDelta() );
	$firstStateVector = \Yjs\encodeStateVector( $users[0] )->toHexString();
	$firstUpdate      = \Yjs\encodeStateAsUpdate( $users[0] )->toHexString();

	Assert::assertSame(
		$users[0]->getArray( 'array' )->toArray(),
		iterator_to_array( $users[0]->getArray( 'array' ) )
	);

	foreach ( $users as $user ) {
		Assert::assertNull( $user->store->pendingDs );
		Assert::assertNull( $user->store->pendingStructs );
		Assert::assertEquals( $firstArray, $user->getArray( 'array' )->toJSON() );
		Assert::assertSame( $firstXml, $user->get( 'xml', YXmlElement::class )->toString() );
		Assert::assertSame( $user->getArray( 'array' )->length, count( $user->getArray( 'array' )->toArray() ) );
		Assert::assertSame( deltaTextLength( $user->getText( 'text' )->toDelta() ), $user->getText( 'text' )->length );
		Assert::assertSame( $firstText, normalizeDeltaForCompare( $user->getText( 'text' )->toDelta() ) );
		Assert::assertSame( $firstStateVector, \Yjs\encodeStateVector( $user )->toHexString() );
		Assert::assertSame( $firstUpdate, \Yjs\encodeStateAsUpdate( $user )->toHexString() );
	}
}

/**
 * @param array<int,array<string,mixed>> $delta Delta.
 * @return int
 */
function deltaTextLength( array $delta ): int {
	$length = 0;
	foreach ( $delta as $op ) {
		if ( array_key_exists( 'insert', $op ) ) {
			$length += is_string( $op['insert'] ) ? strlen( $op['insert'] ) : 1;
		}
	}
	return $length;
}

/**
 * @param array<int,array<string,mixed>> $delta Delta.
 * @return array<int,array<string,mixed>>
 */
function normalizeDeltaForCompare( array $delta ): array {
	return array_map(
		static function ( array $op ): array {
			if ( array_key_exists( 'insert', $op ) && $op['insert'] instanceof AbstractType ) {
				$op['insert'] = $op['insert']->toJSON();
			}
			return normalizeJsonValue( $op );
		},
		$delta
	);
}

/**
 * @param mixed $value Value.
 * @return mixed
 */
function normalizeJsonValue( $value ) {
	if ( $value instanceof AbstractType ) {
		return $value->toJSON();
	}
	if ( $value instanceof \stdClass ) {
		return normalizeJsonValue( get_object_vars( $value ) );
	}
	if ( is_array( $value ) ) {
		$result = array();
		foreach ( $value as $key => $item ) {
			$result[ $key ] = normalizeJsonValue( $item );
		}
		return $result;
	}
	return $value;
}

/**
 * @param mixed            $tc             Test case.
 * @param array<int,mixed> $mods           Random modifications.
 * @param int              $iterations     Iteration count.
 * @param callable|null    $initTestObject Object initializer.
 * @return array<string,mixed>
 */
function applyRandomTests( $tc, array $mods, int $iterations, ?callable $initTestObject = null ) {
	$gen           = is_object( $tc ) && isset( $tc->prng ) ? $tc->prng : Prng::create( 0 );
	$result        = init( $tc, array( 'users' => 5 ), $initTestObject );
	$testConnector = $result['testConnector'];
	$users         = $result['users'];
	$testObjects   = $result['testObjects'];

	for ( $i = 0; $i < $iterations; $i++ ) {
		if ( Prng::int32( $gen, 0, 100 ) <= 2 ) {
			if ( Prng::bool( $gen ) ) {
				$testConnector->disconnectRandom();
			} else {
				$testConnector->reconnectRandom();
			}
		} elseif ( Prng::int32( $gen, 0, 100 ) <= 1 ) {
			$testConnector->flushAllMessages();
		} elseif ( Prng::int32( $gen, 0, 100 ) <= 50 ) {
			$testConnector->flushRandomMessage();
		}

		$userIndex = Prng::int32( $gen, 0, count( $users ) - 1 );
		$test      = Prng::oneOf( $gen, $mods );
		$test( $users[ $userIndex ], $gen, $testObjects[ $userIndex ] );
	}

	compare( $users );
	return $result;
}
