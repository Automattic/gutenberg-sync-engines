# Differences from JavaScript Yjs

y-php mirrors the JS API as closely as PHP allows, and the wire format is byte-identical. The differences below are language-level: how values, callbacks, and binary data look in PHP. Anything not listed here behaves as documented for [JS Yjs](https://docs.yjs.dev/).

## Naming and imports

- Method and function names keep the upstream camelCase (`getText`, `applyUpdate`, `toJSON`), deviating from WordPress naming conventions deliberately so the API matches the JS docs.
- JS module exports map to PHP as follows:

  ```js
  import * as Y from 'yjs'
  const doc = new Y.Doc()
  Y.applyUpdate(doc, update)
  ```

  ```php
  use Yjs\Doc;
  use function Yjs\applyUpdate;

  $doc = new Doc();
  applyUpdate( $doc, $update );
  ```

  Every exported class has a short alias in the `Yjs\` namespace (`Yjs\YText`, `Yjs\UndoManager`, ...), and every exported function exists both as a namespace function and as a static method on the `Yjs\Yjs` facade.

## Binary data: `Uint8Array` becomes `Lib0\Buffer`

Everywhere JS Yjs uses `Uint8Array` (updates, state vectors, encoded snapshots, `ContentBinary` values), y-php uses `Yjs\Lib0\Buffer`, an immutable-by-convention wrapper around a PHP binary string. Convert at the edges of your application:

```php
Buffer::fromBinaryString( $bytes );  $buffer->toBinaryString();
Buffer::fromBase64( $b64 );          $buffer->toBase64();
Buffer::fromByteArray( $ints );      $buffer->toByteArray();
$buffer->byteLength();
```

## Value mapping

- **`undefined` has no PHP equivalent.** When decoding a value a JS client stored as `undefined`, y-php yields the singleton `Yjs\Lib0\UndefinedValue::getInstance()`. PHP `null` maps to JS `null`. This matters only if your JS code stores `undefined` explicitly, which is rare.
- **`bigint`** values decode to `Yjs\Lib0\BigInt64` (a decimal-string carrier), since PHP ints are limited to signed 64-bit.
- **Plain objects and arrays.** JS objects become PHP associative arrays and JS arrays become PHP list arrays when stored as values. `YMap::toJSON()` returns `stdClass` so that empty maps serialize as `{}` rather than `[]`.
- **Numbers.** An `int` encodes as a JS integer and a `float` as a JS float, using the same type-dispatch rules as lib0, so values round-trip with identical bytes. Note that PHP distinguishes `1` from `1.0` where JS does not; both decode fine in JS.

## Strings and text indices

PHP strings are byte sequences, but Yjs text positions count **UTF-16 code units** to match JS. `YText` indices, lengths, and deltas follow the JS convention exactly, including the edge case where splitting a surrogate pair produces U+FFFD replacement characters on both sides, identical to JS behavior.

## Callbacks and events

- Observers receive PHP callables; any `callable` works (closures, `[$obj, 'method']`, ...). To unobserve you must pass the same callable instance, so keep a reference to the closure:

  ```php
  $handler = function ( $event ): void { /* ... */ };
  $ytype->observe( $handler );
  $ytype->unobserve( $handler );
  ```

- `YMapEvent::$keysChanged` is a plain PHP array (a list of changed key names).
- `YEvent::$changes['added']` and `['deleted']` are `SplObjectStorage` instances (PHP's identity sets), standing in for JS `Set` objects keyed by object identity.
- Doc events use `on()` / `off()` / `once()` as in JS. The `update` event callback receives `( Buffer $update, $origin, Doc $doc, Transaction $transaction )`.

## Constructors and options

Options objects become associative arrays:

```php
$doc = new Doc( array( 'gc' => false, 'guid' => 'my-doc' ) );

$undoManager = new UndoManager(
    $ytext,
    array(
        'captureTimeout' => 0,
        'trackedOrigins' => array( 'local' ),
    )
);
```

Formatting attributes and delta operations are associative arrays as well: `$ytext->insert( 0, 'hi', array( 'bold' => true ) )`.

## Things that do not exist in the PHP port

- **Network providers** (y-websocket, y-webrtc, y-indexeddb): out of scope; only the `y-protocols/sync` message codec is ported as `Yjs\Protocols\Sync`.
- **Awareness** (presence/cursors) from y-protocols.
- **`Object.freeze` dev-mode protection:** PHP arrays are copy-on-write values, so the JS dev-mode guard against mutating returned objects has no equivalent.

## Performance notes

PHP executes Yjs operations slower than V8. The port includes the same search-marker optimization as JS for list types, but for hot server paths prefer:

- `mergeUpdates()` / `diffUpdate()` for storage compaction; they work directly on update bytes without building a document.
- Applying many updates inside a single `transact()` call to amortize transaction cleanup.
- Persisting incremental updates (from the `update` event) and compacting periodically, rather than re-encoding the full document on every change.
