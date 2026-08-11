<?php
/**
 * Translated Yjs TestConnector helper.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Support;

use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoder;
use Yjs\Lib0\Encoding;
use Yjs\Lib0\Prng;
use Yjs\Lib0\Xoroshiro128plus;
use Yjs\Protocols\Sync;

/**
 * PHP port of tests/testHelper.js TestConnector.
 */
class TestConnector {
	/**
	 * @var \SplObjectStorage<TestYInstance,null>
	 */
	public \SplObjectStorage $allConns;

	/**
	 * @var \SplObjectStorage<TestYInstance,null>
	 */
	public \SplObjectStorage $onlineConns;

	/**
	 * @var Xoroshiro128plus
	 */
	public Xoroshiro128plus $prng;

	/**
	 * @param mixed $gen PRNG.
	 */
	public function __construct( $gen ) {
		$this->allConns    = new \SplObjectStorage();
		$this->onlineConns = new \SplObjectStorage();
		$this->prng        = $gen;
	}

	/**
	 * @param int $clientID Client id.
	 * @return TestYInstance
	 */
	public function createY( int $clientID ): TestYInstance {
		return new TestYInstance( $this, $clientID );
	}

	/**
	 * @param TestYInstance    $sender  Sender.
	 * @param \Yjs\Lib0\Buffer $message Message bytes.
	 * @return void
	 */
	public function broadcastMessage( TestYInstance $sender, \Yjs\Lib0\Buffer $message ): void {
		if ( ! $this->onlineConns->contains( $sender ) ) {
			return;
		}
		foreach ( $this->onlineConns as $remote ) {
			if ( $remote !== $sender ) {
				$remote->_receive( $message, $sender );
			}
		}
	}

	/**
	 * @return bool
	 */
	public function flushRandomMessage() {
		$conns = array_values(
			array_filter(
				self::storageToArray( $this->onlineConns ),
				static fn ( TestYInstance $conn ): bool => 0 < self::receivingCount( $conn )
			)
		);
		if ( array() === $conns ) {
			return false;
		}

		$receiver = Prng::oneOf( $this->prng, $conns );
		$pairs    = array();
		foreach ( $receiver->receiving as $sender ) {
			$messages = $receiver->receiving[ $sender ];
			if ( 0 < count( $messages ) ) {
				$pairs[] = array( $sender, $messages );
			}
		}
		if ( array() === $pairs ) {
			return $this->flushRandomMessage();
		}

		$pair     = Prng::oneOf( $this->prng, $pairs );
		$sender   = $pair[0];
		$messages = $pair[1];
		$message  = array_shift( $messages );
		if ( array() === $messages ) {
			$receiver->receiving->detach( $sender );
		} else {
			$receiver->receiving[ $sender ] = $messages;
		}
		if ( null === $message ) {
			return $this->flushRandomMessage();
		}

		$encoder = Encoding::createEncoder();
		Sync::readSyncMessage( Decoding::createDecoder( $message ), $encoder, $receiver, $receiver->tc );
		if ( 0 < Encoding::length( $encoder ) ) {
			$sender->_receive( Encoding::toUint8Array( $encoder ), $receiver );
		}
		return true;
	}

	/**
	 * @return bool
	 */
	public function flushAllMessages() {
		$didSomething = false;
		while ( $this->flushRandomMessage() ) {
			$didSomething = true;
		}
		return $didSomething;
	}

	/**
	 * @return void
	 */
	public function reconnectAll() {
		foreach ( $this->allConns as $conn ) {
			$conn->connect();
		}
	}

	/**
	 * @return void
	 */
	public function disconnectAll() {
		foreach ( $this->allConns as $conn ) {
			$conn->disconnect();
		}
	}

	/**
	 * @return void
	 */
	public function syncAll() {
		$this->reconnectAll();
		$this->flushAllMessages();
	}

	/**
	 * @return bool
	 */
	public function disconnectRandom() {
		if ( 0 === self::storageCount( $this->onlineConns ) ) {
			return false;
		}
		Prng::oneOf( $this->prng, self::storageToArray( $this->onlineConns ) )->disconnect();
		return true;
	}

	/**
	 * @return bool
	 */
	public function reconnectRandom() {
		$reconnectable = array();
		foreach ( $this->allConns as $conn ) {
			if ( ! $this->onlineConns->contains( $conn ) ) {
				$reconnectable[] = $conn;
			}
		}
		if ( array() === $reconnectable ) {
			return false;
		}
		Prng::oneOf( $this->prng, $reconnectable )->connect();
		return true;
	}

	/**
	 * @param \SplObjectStorage $storage Storage.
	 * @return array<int,object>
	 */
	private static function storageToArray( \SplObjectStorage $storage ): array {
		$array = array();
		foreach ( $storage as $object ) {
			$array[] = $object;
		}
		return $array;
	}

	/**
	 * @param \SplObjectStorage $storage Storage.
	 * @return int
	 */
	private static function storageCount( \SplObjectStorage $storage ): int {
		$count = 0;
		foreach ( $storage as $_object ) {
			++$count;
		}
		return $count;
	}

	/**
	 * @param TestYInstance $conn Connection.
	 * @return int
	 */
	private static function receivingCount( TestYInstance $conn ): int {
		$count = 0;
		foreach ( $conn->receiving as $sender ) {
			$count += count( $conn->receiving[ $sender ] );
		}
		return $count;
	}
}
