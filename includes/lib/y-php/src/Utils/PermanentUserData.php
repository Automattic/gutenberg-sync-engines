<?php
/**
 * Permanent user data.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

use Yjs\Lib0\Buffer;
use Yjs\Lib0\Decoding;
use Yjs\Types\YArray;
use Yjs\Types\YMap;

/**
 * Port of yjs/src/utils/PermanentUserData.js.
 */
class PermanentUserData {
	/**
	 * @var YMap
	 */
	public YMap $yusers;

	/**
	 * @var Doc
	 */
	public Doc $doc;

	/**
	 * @var array<int,string>
	 */
	public array $clients = array();

	/**
	 * @var array<string,DeleteSet>
	 */
	public array $dss = array();

	/**
	 * @param Doc       $doc       Document.
	 * @param YMap|null $storeType Store type.
	 */
	public function __construct( Doc $doc, ?YMap $storeType = null ) {
		$this->yusers = $storeType ?? $doc->getMap( 'users' );
		$this->doc    = $doc;

		$this->yusers->observe(
			function ( $event ): void {
				foreach ( $event->keysChanged as $userDescription ) {
					$user = $this->yusers->get( (string) $userDescription );
					if ( $user instanceof YMap ) {
						$this->initUser( $user, (string) $userDescription );
					}
				}
			}
		);
		$this->yusers->forEach(
			function ( $user, string $userDescription ): void {
				if ( $user instanceof YMap ) {
					$this->initUser( $user, $userDescription );
				}
			}
		);
	}

	/**
	 * @param YMap   $user            User map.
	 * @param string $userDescription User description.
	 * @return void
	 */
	private function initUser( YMap $user, string $userDescription ): void {
		$ds  = $user->get( 'ds' );
		$ids = $user->get( 'ids' );
		if ( ! $ds instanceof YArray || ! $ids instanceof YArray ) {
			return;
		}

		$addClientId = function ( $clientid ) use ( $userDescription ): void {
			$this->clients[ (int) $clientid ] = $userDescription;
		};
		$ds->observe(
			function ( $event ) use ( $userDescription ): void {
				foreach ( $event->changes['added'] as $item ) {
					foreach ( $item->content->getContent() as $encodedDs ) {
						if ( $encodedDs instanceof Buffer ) {
							$this->dss[ $userDescription ] = \Yjs\mergeDeleteSets(
								array(
									$this->dss[ $userDescription ] ?? \Yjs\createDeleteSet(),
									\Yjs\readDeleteSet( new DSDecoderV1( Decoding::createDecoder( $encodedDs ) ) ),
								)
							);
						}
					}
				}
			}
		);

		$deleteSets = array();
		foreach ( $ds->map( static fn ( $encodedDs ) => $encodedDs ) as $encodedDs ) {
			if ( $encodedDs instanceof Buffer ) {
				$deleteSets[] = \Yjs\readDeleteSet( new DSDecoderV1( Decoding::createDecoder( $encodedDs ) ) );
			}
		}
		$this->dss[ $userDescription ] = \Yjs\mergeDeleteSets( $deleteSets );

		$ids->observe(
			static function ( $event ) use ( $addClientId ): void {
				foreach ( $event->changes['added'] as $item ) {
					foreach ( $item->content->getContent() as $clientid ) {
						$addClientId( $clientid );
					}
				}
			}
		);
		$ids->forEach( $addClientId );
	}

	/**
	 * @param Doc                 $doc             Document.
	 * @param int                 $clientid        Client id.
	 * @param string              $userDescription User description.
	 * @param array<string,mixed> $conf            Configuration.
	 * @return void
	 */
	public function setUserMapping( Doc $doc, int $clientid, string $userDescription, array $conf = array() ): void {
		$filter = $conf['filter'] ?? static function ( Transaction $transaction, DeleteSet $ds ): bool {
			unset( $transaction, $ds );
			return true;
		};
		$users  = $this->yusers;
		$user   = $users->get( $userDescription );
		if ( ! $user instanceof YMap ) {
			$user = new YMap();
			$user->set( 'ids', new YArray() );
			$user->set( 'ds', new YArray() );
			$users->set( $userDescription, $user );
		}
		$ids = $user->get( 'ids' );
		if ( $ids instanceof YArray ) {
			$ids->push( array( $clientid ) );
		}

		$doc->on(
			'afterTransaction',
			function ( Transaction $transaction ) use ( &$user, $userDescription, $users, $filter ): void {
				$userOverwrite = $users->get( $userDescription );
				if ( $userOverwrite instanceof YMap && $userOverwrite !== $user ) {
					$user = $userOverwrite;
				}
				$yds = $user->get( 'ds' );
				$ds  = $transaction->deleteSet;
				if ( $yds instanceof YArray && $transaction->local && count( $ds->clients ) > 0 && $filter( $transaction, $ds ) ) {
					$encoder = new DSEncoderV1();
					\Yjs\writeDeleteSet( $encoder, $ds );
					$yds->push( array( $encoder->toUint8Array() ) );
				}
			}
		);
	}

	/**
	 * @param int $clientid Client id.
	 * @return string|null
	 */
	public function getUserByClientId( int $clientid ): ?string {
		return $this->clients[ $clientid ] ?? null;
	}

	/**
	 * @param ID $id ID.
	 * @return string|null
	 */
	public function getUserByDeletedId( ID $id ): ?string {
		foreach ( $this->dss as $userDescription => $ds ) {
			if ( \Yjs\isDeleted( $ds, $id ) ) {
				return $userDescription;
			}
		}
		return null;
	}
}
