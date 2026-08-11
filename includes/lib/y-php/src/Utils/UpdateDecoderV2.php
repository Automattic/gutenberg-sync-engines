<?php
/**
 * Update decoder V2.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

use Yjs\Lib0\Buffer;
use Yjs\Lib0\Decoder;
use Yjs\Lib0\Decoding;
use Yjs\Lib0\IntDiffOptRleDecoder;
use Yjs\Lib0\RleDecoder;
use Yjs\Lib0\StringDecoder;
use Yjs\Lib0\UintOptRleDecoder;

/**
 * Port of UpdateDecoderV2 from yjs/src/utils/UpdateDecoder.js.
 */
class UpdateDecoderV2 extends DSDecoderV2 {
	/**
	 * @var array<int,string>
	 */
	private array $keys = array();

	/**
	 * @var IntDiffOptRleDecoder
	 */
	private IntDiffOptRleDecoder $keyClockDecoder;

	/**
	 * @var UintOptRleDecoder
	 */
	private UintOptRleDecoder $clientDecoder;

	/**
	 * @var IntDiffOptRleDecoder
	 */
	private IntDiffOptRleDecoder $leftClockDecoder;

	/**
	 * @var IntDiffOptRleDecoder
	 */
	private IntDiffOptRleDecoder $rightClockDecoder;

	/**
	 * @var RleDecoder
	 */
	private RleDecoder $infoDecoder;

	/**
	 * @var StringDecoder
	 */
	private StringDecoder $stringDecoder;

	/**
	 * @var RleDecoder
	 */
	private RleDecoder $parentInfoDecoder;

	/**
	 * @var UintOptRleDecoder
	 */
	private UintOptRleDecoder $typeRefDecoder;

	/**
	 * @var UintOptRleDecoder
	 */
	private UintOptRleDecoder $lenDecoder;

	/**
	 * @param Decoder $decoder Decoder.
	 */
	public function __construct( Decoder $decoder ) {
		parent::__construct( $decoder );
		Decoding::readVarUint( $decoder );
		$this->keyClockDecoder   = new IntDiffOptRleDecoder( Decoding::readVarUint8Array( $decoder ) );
		$this->clientDecoder     = new UintOptRleDecoder( Decoding::readVarUint8Array( $decoder ) );
		$this->leftClockDecoder  = new IntDiffOptRleDecoder( Decoding::readVarUint8Array( $decoder ) );
		$this->rightClockDecoder = new IntDiffOptRleDecoder( Decoding::readVarUint8Array( $decoder ) );
		$this->infoDecoder       = new RleDecoder(
			Decoding::readVarUint8Array( $decoder ),
			static fn ( Decoder $inner ): int => Decoding::readUint8( $inner )
		);
		$this->stringDecoder     = new StringDecoder( Decoding::readVarUint8Array( $decoder ) );
		$this->parentInfoDecoder = new RleDecoder(
			Decoding::readVarUint8Array( $decoder ),
			static fn ( Decoder $inner ): int => Decoding::readUint8( $inner )
		);
		$this->typeRefDecoder    = new UintOptRleDecoder( Decoding::readVarUint8Array( $decoder ) );
		$this->lenDecoder        = new UintOptRleDecoder( Decoding::readVarUint8Array( $decoder ) );
	}

	/**
	 * @return ID
	 */
	public function readLeftID(): ID {
		return new ID( $this->clientDecoder->read(), $this->leftClockDecoder->read() );
	}

	/**
	 * @return ID
	 */
	public function readRightID(): ID {
		return new ID( $this->clientDecoder->read(), $this->rightClockDecoder->read() );
	}

	/**
	 * @return int
	 */
	public function readClient(): int {
		return $this->clientDecoder->read();
	}

	/**
	 * @return int
	 */
	public function readInfo(): int {
		return (int) $this->infoDecoder->read();
	}

	/**
	 * @return string
	 */
	public function readString(): string {
		return $this->stringDecoder->read();
	}

	/**
	 * @return bool
	 */
	public function readParentInfo(): bool {
		return 1 === $this->parentInfoDecoder->read();
	}

	/**
	 * @return int
	 */
	public function readTypeRef(): int {
		return $this->typeRefDecoder->read();
	}

	/**
	 * @return int
	 */
	public function readLen(): int {
		return $this->lenDecoder->read();
	}

	/**
	 * @return mixed
	 */
	public function readAny() {
		return Decoding::readAny( $this->restDecoder );
	}

	/**
	 * @return Buffer
	 */
	public function readBuf(): Buffer {
		return Decoding::readVarUint8Array( $this->restDecoder );
	}

	/**
	 * @return mixed
	 */
	public function readJSON() {
		return Decoding::readAny( $this->restDecoder );
	}

	/**
	 * @return string
	 */
	public function readKey(): string {
		$keyClock = $this->keyClockDecoder->read();
		if ( $keyClock < count( $this->keys ) ) {
			return $this->keys[ $keyClock ];
		}
		$key          = $this->stringDecoder->read();
		$this->keys[] = $key;
		return $key;
	}
}
