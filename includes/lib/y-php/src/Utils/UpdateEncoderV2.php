<?php
/**
 * Update encoder V2.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

use Yjs\Lib0\Buffer;
use Yjs\Lib0\Encoding;
use Yjs\Lib0\IntDiffOptRleEncoder;
use Yjs\Lib0\RleEncoder;
use Yjs\Lib0\StringEncoder;
use Yjs\Lib0\UintOptRleEncoder;

/**
 * Port of UpdateEncoderV2 from yjs/src/utils/UpdateEncoder.js.
 */
class UpdateEncoderV2 extends DSEncoderV2 {
	/**
	 * @var array<string,int>
	 */
	private array $keyMap = array();

	/**
	 * @var int
	 */
	private int $keyClock = 0;

	/**
	 * @var IntDiffOptRleEncoder
	 */
	private IntDiffOptRleEncoder $keyClockEncoder;

	/**
	 * @var UintOptRleEncoder
	 */
	private UintOptRleEncoder $clientEncoder;

	/**
	 * @var IntDiffOptRleEncoder
	 */
	private IntDiffOptRleEncoder $leftClockEncoder;

	/**
	 * @var IntDiffOptRleEncoder
	 */
	private IntDiffOptRleEncoder $rightClockEncoder;

	/**
	 * @var RleEncoder
	 */
	private RleEncoder $infoEncoder;

	/**
	 * @var StringEncoder
	 */
	private StringEncoder $stringEncoder;

	/**
	 * @var RleEncoder
	 */
	private RleEncoder $parentInfoEncoder;

	/**
	 * @var UintOptRleEncoder
	 */
	private UintOptRleEncoder $typeRefEncoder;

	/**
	 * @var UintOptRleEncoder
	 */
	private UintOptRleEncoder $lenEncoder;

	public function __construct() {
		parent::__construct();
		$this->keyClockEncoder   = new IntDiffOptRleEncoder();
		$this->clientEncoder     = new UintOptRleEncoder();
		$this->leftClockEncoder  = new IntDiffOptRleEncoder();
		$this->rightClockEncoder = new IntDiffOptRleEncoder();
		$this->infoEncoder       = new RleEncoder(
			static function ( \Yjs\Lib0\Encoder $encoder, int $value ): void {
				Encoding::writeUint8( $encoder, $value );
			}
		);
		$this->stringEncoder     = new StringEncoder();
		$this->parentInfoEncoder = new RleEncoder(
			static function ( \Yjs\Lib0\Encoder $encoder, int $value ): void {
				Encoding::writeUint8( $encoder, $value );
			}
		);
		$this->typeRefEncoder    = new UintOptRleEncoder();
		$this->lenEncoder        = new UintOptRleEncoder();
	}

	/**
	 * @return Buffer
	 */
	public function toUint8Array(): Buffer {
		$encoder = Encoding::createEncoder();
		Encoding::writeVarUint( $encoder, 0 );
		Encoding::writeVarUint8Array( $encoder, $this->keyClockEncoder->toUint8Array() );
		Encoding::writeVarUint8Array( $encoder, $this->clientEncoder->toUint8Array() );
		Encoding::writeVarUint8Array( $encoder, $this->leftClockEncoder->toUint8Array() );
		Encoding::writeVarUint8Array( $encoder, $this->rightClockEncoder->toUint8Array() );
		Encoding::writeVarUint8Array( $encoder, $this->infoEncoder->toUint8Array() );
		Encoding::writeVarUint8Array( $encoder, $this->stringEncoder->toUint8Array() );
		Encoding::writeVarUint8Array( $encoder, $this->parentInfoEncoder->toUint8Array() );
		Encoding::writeVarUint8Array( $encoder, $this->typeRefEncoder->toUint8Array() );
		Encoding::writeVarUint8Array( $encoder, $this->lenEncoder->toUint8Array() );
		Encoding::writeUint8Array( $encoder, Encoding::toUint8Array( $this->restEncoder ) );
		return Encoding::toUint8Array( $encoder );
	}

	/**
	 * @param ID $id ID.
	 * @return void
	 */
	public function writeLeftID( ID $id ): void {
		$this->clientEncoder->write( $id->client );
		$this->leftClockEncoder->write( $id->clock );
	}

	/**
	 * @param ID $id ID.
	 * @return void
	 */
	public function writeRightID( ID $id ): void {
		$this->clientEncoder->write( $id->client );
		$this->rightClockEncoder->write( $id->clock );
	}

	/**
	 * @param int $client Client id.
	 * @return void
	 */
	public function writeClient( int $client ): void {
		$this->clientEncoder->write( $client );
	}

	/**
	 * @param int $info Unsigned 8-bit integer.
	 * @return void
	 */
	public function writeInfo( int $info ): void {
		$this->infoEncoder->write( $info );
	}

	/**
	 * @param string $s String.
	 * @return void
	 */
	public function writeString( string $s ): void {
		$this->stringEncoder->write( $s );
	}

	/**
	 * @param bool $isYKey Whether the parent info is a Y key.
	 * @return void
	 */
	public function writeParentInfo( bool $isYKey ): void {
		$this->parentInfoEncoder->write( $isYKey ? 1 : 0 );
	}

	/**
	 * @param int $info Type ref.
	 * @return void
	 */
	public function writeTypeRef( int $info ): void {
		$this->typeRefEncoder->write( $info );
	}

	/**
	 * @param int $len Length.
	 * @return void
	 */
	public function writeLen( int $len ): void {
		$this->lenEncoder->write( $len );
	}

	/**
	 * @param mixed $any Value.
	 * @return void
	 */
	public function writeAny( $any ): void {
		Encoding::writeAny( $this->restEncoder, $any );
	}

	/**
	 * @param Buffer $buf Buffer.
	 * @return void
	 */
	public function writeBuf( Buffer $buf ): void {
		Encoding::writeVarUint8Array( $this->restEncoder, $buf );
	}

	/**
	 * @param mixed $embed JSON-serializable embed.
	 * @return void
	 */
	public function writeJSON( $embed ): void {
		Encoding::writeAny( $this->restEncoder, $embed );
	}

	/**
	 * @param string $key Key.
	 * @return void
	 */
	public function writeKey( string $key ): void {
		if ( array_key_exists( $key, $this->keyMap ) ) {
			$this->keyClockEncoder->write( $this->keyMap[ $key ] );
			return;
		}
		$this->keyClockEncoder->write( $this->keyClock++ );
		$this->stringEncoder->write( $key );
	}
}
