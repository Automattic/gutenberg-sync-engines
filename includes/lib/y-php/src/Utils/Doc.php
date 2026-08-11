<?php
/**
 * Doc API.
 *
 * @package Yjs
 */

declare(strict_types=1);

namespace Yjs\Utils;

/**
 * Yjs document.
 */
class Doc extends \Yjs\Lib0\Observable {
	use \Yjs\NotImplementedTrait;

	/**
	 * @var bool
	 */
	public bool $gc;

	/**
	 * @var callable
	 */
	public $gcFilter;

	/**
	 * @var int
	 */
	public int $clientID;

	/**
	 * @var string
	 */
	public string $guid;

	/**
	 * @var string|null
	 */
	public ?string $collectionid;

	/**
	 * @var array<string,\Yjs\Types\AbstractType>
	 */
	public array $share = array();

	/**
	 * @var StructStore
	 */
	public StructStore $store;

	/**
	 * @var mixed
	 */
	public $_transaction = null;

	/**
	 * @var array<int,mixed>
	 */
	public array $_transactionCleanups = array();

	/**
	 * @var \SplObjectStorage<Doc,null>
	 */
	public \SplObjectStorage $subdocs;

	/**
	 * @var \Yjs\Structs\Item|null
	 */
	public ?\Yjs\Structs\Item $_item = null;

	/**
	 * @var bool
	 */
	public bool $shouldLoad;

	/**
	 * @var bool
	 */
	public bool $autoLoad;

	/**
	 * @var mixed
	 */
	public $meta;

	/**
	 * @var bool
	 */
	public bool $isLoaded = false;

	/**
	 * @var bool
	 */
	public bool $isSynced = false;

	/**
	 * @var bool
	 */
	public bool $isDestroyed = false;

	/**
	 * @param array|\stdClass|null $opts Configuration options.
	 */
	public function __construct( $opts = null ) {
		$options = $this->normalizeOpts( $opts );

		$this->gc           = $options['gc'] ?? true;
		$this->gcFilter     = $options['gcFilter'] ?? static fn (): bool => true;
		$this->clientID     = \Yjs\Lib0\Random::uint32();
		$this->guid         = isset( $options['guid'] ) ? (string) $options['guid'] : self::randomGuid();
		$this->collectionid = isset( $options['collectionid'] ) ? (string) $options['collectionid'] : null;
		$this->store        = new StructStore();
		$this->subdocs      = new \SplObjectStorage();
		$this->shouldLoad   = $options['shouldLoad'] ?? true;
		$this->autoLoad     = $options['autoLoad'] ?? false;
		$this->meta         = $options['meta'] ?? null;

		$this->on(
			'sync',
			function ( $isSynced = true ): void {
				if ( false === $isSynced ) {
					$this->isSynced = false;
					return;
				}
				$this->isSynced = true;
				if ( ! $this->isLoaded ) {
					$this->emit( 'load', array( $this ) );
				}
			}
		);
		$this->on(
			'load',
			function (): void {
				$this->isLoaded = true;
			}
		);
	}

	public function load(): void {
		$item = $this->_item;
		if ( null !== $item && ! $this->shouldLoad ) {
			\Yjs\transact(
				$item->parent->doc,
				function ( Transaction $transaction ): void {
					$transaction->subdocsLoaded->attach( $this );
				}
			);
		}
		$this->shouldLoad = true;
	}

	public function getSubdocs(): \SplObjectStorage {
		return $this->subdocs;
	}

	public function getSubdocGuids(): array {
		$guids = array();
		foreach ( $this->subdocs as $doc ) {
			$guids[ $doc->guid ] = $doc->guid;
		}
		return array_values( $guids );
	}

	public function transact( callable $f, $origin = null ) {
		return \Yjs\transact( $this, $f, $origin );
	}

	public function get( string $name, string $TypeConstructor = \Yjs\Types\AbstractType::class ) {
		if ( ! array_key_exists( $name, $this->share ) ) {
			$type = new $TypeConstructor();
			$type->_integrate( $this, null );
			$this->share[ $name ] = $type;
			return $type;
		}

		$type   = $this->share[ $name ];
		$constr = get_class( $type );
		if ( \Yjs\Types\AbstractType::class !== $TypeConstructor && $constr !== $TypeConstructor ) {
			if ( \Yjs\Types\AbstractType::class === $constr ) {
				$newType       = new $TypeConstructor();
				$newType->_map = $type->_map;
				foreach ( $type->_map as $item ) {
					for ( $n = $item; null !== $n; $n = $n->left ) {
						$n->parent = $newType;
					}
				}
				$newType->_start = $type->_start;
				for ( $n = $newType->_start; null !== $n; $n = $n->right ) {
					$n->parent = $newType;
				}
				$newType->_length        = $type->_length;
				$newType->_searchMarker  = $type->_searchMarker;
				$newType->_hasFormatting = $type->_hasFormatting;
				$this->share[ $name ]    = $newType;
				$newType->_integrate( $this, null );
				return $newType;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( 'Type with the name ' . $name . ' has already been defined with a different constructor' );
		}
		return $type;
	}

	public function getArray( string $name = '' ): \Yjs\Types\YArray {
		return $this->get( $name, \Yjs\Types\YArray::class );
	}

	public function getText( string $name = '' ): \Yjs\Types\YText {
		return $this->get( $name, \Yjs\Types\YText::class );
	}

	public function getMap( string $name = '' ): \Yjs\Types\YMap {
		return $this->get( $name, \Yjs\Types\YMap::class );
	}

	public function getXmlElement( string $name = '' ): \Yjs\Types\YXmlElement {
		return $this->get( $name, \Yjs\Types\YXmlElement::class );
	}

	public function getXmlFragment( string $name = '' ): \Yjs\Types\YXmlFragment {
		return $this->get( $name, \Yjs\Types\YXmlFragment::class );
	}

	public function toJSON(): \stdClass {
		$doc = new \stdClass();
		foreach ( $this->share as $key => $value ) {
			$doc->{$key} = $value->toJSON();
		}
		return $doc;
	}

	/**
	 * @return void
	 */
	public function destroy(): void {
		$this->isDestroyed = true;
		foreach ( $this->subdocs as $subdoc ) {
			$subdoc->destroy();
		}
		$item = $this->_item;
		if ( null !== $item ) {
			$this->_item = null;
			$content     = $item->content;
			if ( $content instanceof \Yjs\Structs\ContentDoc ) {
				$options               = get_object_vars( $content->opts );
				$options['guid']       = $this->guid;
				$options['shouldLoad'] = false;
				$content->doc          = new self( $options );
				$content->doc->_item   = $item;
				\Yjs\transact(
					$item->parent->doc,
					function ( Transaction $transaction ) use ( $item, $content ): void {
						if ( ! $item->deleted ) {
							$transaction->subdocsAdded->attach( $content->doc );
						}
						$transaction->subdocsRemoved->attach( $this );
					},
					null,
					true
				);
			}
		}
		$this->emit( 'destroyed', array( true ) );
		$this->emit( 'destroy', array( $this ) );
		parent::destroy();
	}

	/**
	 * @param array|\stdClass|null $opts Options.
	 * @return array<string,mixed>
	 */
	private function normalizeOpts( $opts ): array {
		if ( null === $opts ) {
			return array();
		}
		if ( $opts instanceof \stdClass ) {
			return get_object_vars( $opts );
		}
		if ( is_array( $opts ) ) {
			return $opts;
		}
		return array();
	}

	/**
	 * @return string
	 */
	private static function randomGuid(): string {
		return sprintf(
			'%08x-%04x-%04x-%04x-%012x',
			random_int( 0, 0xFFFFFFFF ),
			random_int( 0, 0xFFFF ),
			random_int( 0, 0xFFFF ),
			random_int( 0, 0xFFFF ),
			random_int( 0, 0xFFFFFFFFFFFF )
		);
	}
}
