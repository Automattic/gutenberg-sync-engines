<?php
/**
 * Translated Yjs TestYInstance helper.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Tests\Support;

use Yjs\Lib0\Buffer;
use Yjs\Lib0\Encoding;
use Yjs\Protocols\Sync;
use Yjs\Utils\Doc;

/**
 * PHP port of tests/testHelper.js TestYInstance.
 */
class TestYInstance extends Doc {
	/**
	 * @var int
	 */
	public int $userID;

	/**
	 * @var TestConnector
	 */
	public TestConnector $tc;

	/**
	 * @var \SplObjectStorage<TestYInstance,array<int,Buffer>>
	 */
	public \SplObjectStorage $receiving;

	/**
	 * @var array<int,Buffer>
	 */
	public array $updates = array();

	/**
	 * @param TestConnector $testConnector Test connector.
	 * @param int           $clientID      Client id.
	 */
	public function __construct( TestConnector $testConnector, int $clientID ) {
		parent::__construct();
		$this->userID    = $clientID;
		$this->clientID  = $clientID;
		$this->tc        = $testConnector;
		$this->receiving = new \SplObjectStorage();

		$testConnector->allConns->attach( $this );

		$this->on(
			'update',
			function ( Buffer $update, $origin ): void {
				if ( $origin !== $this->tc ) {
					$encoder = Encoding::createEncoder();
					Sync::writeUpdate( $encoder, $update );
					$this->tc->broadcastMessage( $this, Encoding::toUint8Array( $encoder ) );
				}
				$this->updates[] = $update;
			}
		);
		$this->connect();
	}

	/**
	 * @return void
	 */
	public function disconnect(): void {
		$this->receiving = new \SplObjectStorage();
		$this->tc->onlineConns->detach( $this );
	}

	/**
	 * @return void
	 */
	public function connect(): void {
		if ( ! $this->tc->onlineConns->contains( $this ) ) {
			$this->tc->onlineConns->attach( $this );

			$encoder = Encoding::createEncoder();
			Sync::writeSyncStep1( $encoder, $this );
			$this->tc->broadcastMessage( $this, Encoding::toUint8Array( $encoder ) );

			foreach ( $this->tc->onlineConns as $remoteYInstance ) {
				if ( $remoteYInstance !== $this ) {
					$encoder = Encoding::createEncoder();
					Sync::writeSyncStep1( $encoder, $remoteYInstance );
					$this->_receive( Encoding::toUint8Array( $encoder ), $remoteYInstance );
				}
			}
		}
	}

	/**
	 * @param Buffer        $message      Message bytes.
	 * @param TestYInstance $remoteClient Remote client.
	 * @return void
	 */
	public function _receive( $message, $remoteClient ): void {
		$messages   = $this->receiving->contains( $remoteClient ) ? $this->receiving[ $remoteClient ] : array();
		$messages[] = $message;
		$this->receiving->attach( $remoteClient, $messages );
	}
}
