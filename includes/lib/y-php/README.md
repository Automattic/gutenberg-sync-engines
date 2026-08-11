# y-php

A PHP port of [Yjs](https://github.com/yjs/yjs) (v13.6.31), the CRDT framework for building collaborative applications. y-php is designed for server-side use, particularly in WordPress, and is **fully wire-compatible with JavaScript Yjs clients**: updates produced by a browser running Yjs can be applied by this library, and updates produced by this library can be applied by a browser, byte for byte.

```php
use Yjs\Doc;
use function Yjs\applyUpdate;
use function Yjs\encodeStateAsUpdate;

// Decode a document a browser client produced.
$doc = new Doc();
applyUpdate( $doc, $updateFromBrowser );

// Edit it on the server.
$doc->getText( 'title' )->insert( 0, 'Hello from PHP' );

// Send the result back. JS clients can apply these bytes directly.
$update = encodeStateAsUpdate( $doc );
```

## Features

- All core shared types: `YText` (rich text with formatting and embeds), `YArray`, `YMap`, `YXmlFragment` / `YXmlElement` / `YXmlText` / `YXmlHook`
- Full update lifecycle: `applyUpdate`, `encodeStateAsUpdate`, `encodeStateVector`, `mergeUpdates`, `diffUpdate`, plus the V2 encoding variants
- Snapshots, relative positions, subdocuments, `UndoManager`, `PermanentUserData`
- The Yjs sync protocol (`Yjs\Protocols\Sync`) for implementing a sync endpoint
- Byte-level conformance with JS Yjs, verified by fixture tests generated from the real JavaScript implementation

The API mirrors JS Yjs closely, including camelCase method names, so the [Yjs documentation](https://docs.yjs.dev/) is a useful companion. PHP-specific differences are listed in [docs/differences-from-yjs.md](docs/differences-from-yjs.md).

## Requirements

- PHP 7.4 or later (chosen for WordPress compatibility)
- No PHP extensions beyond the defaults (`json`, `mbstring` not required; strings are handled as byte sequences)

## Installation

The package is not yet published on Packagist. Install it from a local path or VCS repository:

```json
{
    "repositories": [
        { "type": "path", "url": "../y-php" }
    ],
    "require": {
        "yjs/y-php": "*"
    }
}
```

Then:

```bash
composer install
```

Composer's autoloader loads both the `Yjs\` classes and the `Yjs\` namespace functions (such as `Yjs\applyUpdate`) automatically.

## Quick start

```php
use Yjs\Doc;

$doc = new Doc();

// Root-level shared types are created on first access, keyed by name.
$ytext = $doc->getText( 'title' );
$ytext->insert( 0, 'Hello, world' );

$ymap = $doc->getMap( 'settings' );
$ymap->set( 'theme', 'dark' );
$ymap->set( 'fontSize', 14 );

$yarray = $doc->getArray( 'tags' );
$yarray->push( array( 'crdt', 'collaboration' ) );

echo $ytext->toString();        // "Hello, world"
echo $ymap->get( 'theme' );     // "dark"
print_r( $yarray->toArray() );  // ["crdt", "collaboration"]
```

## Core concepts

### Documents and updates

A `Yjs\Doc` holds shared types and tracks every change as a compact binary **update**. Updates are commutative and idempotent: they can be applied in any order, more than once, and all documents that have seen the same set of updates converge to the same state.

Binary data is carried by `Yjs\Lib0\Buffer`, a thin wrapper around a PHP binary string (the equivalent of `Uint8Array` in JS):

```php
use Yjs\Lib0\Buffer;

$buffer = Buffer::fromBinaryString( $rawBytes );   // e.g. from an HTTP request body
$buffer = Buffer::fromBase64( $base64 );           // e.g. from JSON transport
$buffer = Buffer::fromByteArray( array( 1, 2 ) );  // from a plain int array

$rawBytes = $buffer->toBinaryString();             // for storage in a BLOB column
$base64   = $buffer->toBase64();                   // for JSON transport
```

### Syncing two documents

```php
use Yjs\Doc;
use function Yjs\applyUpdate;
use function Yjs\encodeStateAsUpdate;
use function Yjs\encodeStateVector;

$docA = new Doc();
$docA->getText( 'title' )->insert( 0, 'Hello, world' );

// Full sync: encode everything docA knows, apply it to docB.
$docB = new Doc();
applyUpdate( $docB, encodeStateAsUpdate( $docA ) );

echo $docB->getText( 'title' )->toString(); // "Hello, world"

// Differential sync: only send what docB is missing.
$stateVectorB = encodeStateVector( $docB );
$docA->getText( 'title' )->insert( 12, '!' );

$diff = encodeStateAsUpdate( $docA, $stateVectorB );
applyUpdate( $docB, $diff );

echo $docB->getText( 'title' )->toString(); // "Hello, world!"
```

A **state vector** is a small summary of what a document has seen. Passing one to `encodeStateAsUpdate()` produces a minimal diff instead of the full document.

### Working with updates without a Doc

For server-side storage you often want to combine or trim stored updates without paying the cost of instantiating a full document:

```php
use function Yjs\mergeUpdates;
use function Yjs\diffUpdate;
use function Yjs\encodeStateVectorFromUpdate;

// Compact many stored updates into one equivalent update.
$merged = mergeUpdates( array( $update1, $update2, $update3 ) );

// Compute the part of an update a client is missing.
$missing = diffUpdate( $merged, $clientStateVector );

// Derive a state vector directly from update bytes.
$stateVector = encodeStateVectorFromUpdate( $merged );
```

### Persistence

Updates are plain bytes, so persistence is storage-agnostic. A typical WordPress pattern:

```php
// Store (e.g. in a custom table with a BLOB/LONGBLOB column).
$bytes = encodeStateAsUpdate( $doc )->toBinaryString();

// Load.
$doc = new Doc();
applyUpdate( $doc, Buffer::fromBinaryString( $bytes ) );
```

Appending incoming updates as rows and periodically compacting them with `mergeUpdates()` avoids decoding the document on every write.

## Observing changes

Shared types support the same observer API as JS Yjs:

```php
$ymap = $doc->getMap( 'state' );

$ymap->observe(
    function ( \Yjs\YMapEvent $event ): void {
        // List of changed keys.
        print_r( $event->keysChanged );

        // $event->target is the YMap, $event->transaction the transaction.
    }
);

$ymap->set( 'status', 'published' ); // observer fires with ['status']
```

`observeDeep()` fires for changes anywhere in a type's subtree and receives an array of events. `YTextEvent` and `YArrayEvent` expose a `delta` describing the change.

The document itself emits events via `on()` / `off()`:

```php
use Yjs\Lib0\Buffer;

$doc->on(
    'update',
    function ( Buffer $update, $origin ): void {
        // Fires after every transaction with the incremental update bytes.
        // Broadcast these to other clients or append them to storage.
    }
);
```

Other notable events: `afterTransaction`, `subdocs`, `destroy`.

## Transactions

Group changes into a single transaction to produce one update and one round of events. The optional second argument is an **origin** value that observers can use to identify the source of a change:

```php
$doc->transact(
    function () use ( $ytext, $ymap ): void {
        $ytext->insert( 0, 'a' );
        $ymap->set( 'edited', true );
    },
    'server-import' // arbitrary origin, visible as $transaction->origin
);
```

`applyUpdate()` accepts an origin as its third argument, which is useful for distinguishing remote changes from local ones in observers.

## Rich text

`YText` supports formatting attributes, embeds, and Quill-style deltas:

```php
$ytext = $doc->getText( 'article' );

$ytext->insert( 0, 'Hello', array( 'bold' => true ) );
$ytext->format( 0, 2, array( 'italic' => true ) );

print_r( $ytext->toDelta() );
// [
//   ['insert' => 'He', 'attributes' => ['italic' => true, 'bold' => true]],
//   ['insert' => 'llo', 'attributes' => ['bold' => true]],
// ]

$ytext->applyDelta(
    array(
        array( 'retain' => 5 ),
        array( 'insert' => ', world' ),
    )
);
```

## XML types

The XML types model an XML tree (as used by editor bindings such as ProseMirror):

```php
use Yjs\YXmlElement;
use Yjs\YXmlText;

$fragment  = $doc->getXmlFragment( 'prosemirror' );
$paragraph = new YXmlElement( 'paragraph' );

$fragment->insert( 0, array( $paragraph ) );
$paragraph->insert( 0, array( new YXmlText( 'Hello' ) ) );

echo $fragment->toString(); // "<paragraph>Hello</paragraph>"
```

## Undo / redo

```php
use Yjs\UndoManager;

$ytext = $doc->getText( 'body' );
$undoManager = new UndoManager( $ytext );

$ytext->insert( 0, 'draft' );

$undoManager->undo();
echo $ytext->toString(); // ""

$undoManager->redo();
echo $ytext->toString(); // "draft"
```

`UndoManager` accepts a single type, a `Doc`, or an array of scopes, plus options such as `captureTimeout` and `trackedOrigins`. See the [Yjs UndoManager docs](https://docs.yjs.dev/api/undo-manager) for the semantics; the PHP API matches.

## Talking to JavaScript clients

Because the wire format is byte-identical, interop is just a matter of moving bytes. The simplest transport is base64 over HTTP:

```php
// Receive an update from a browser (e.g. sent as base64 in JSON).
applyUpdate( $doc, Buffer::fromBase64( $request['update'] ) );

// Respond with the server's changes.
$response = array( 'update' => encodeStateAsUpdate( $doc, $clientStateVector )->toBase64() );
```

On the JS side:

```js
import * as Y from 'yjs'
import { fromBase64, toBase64 } from 'lib0/buffer'

Y.applyUpdate(ydoc, fromBase64(response.update))
const update = toBase64(Y.encodeStateAsUpdate(ydoc))
```

For stateful sync (e.g. a WebSocket-style exchange), `Yjs\Protocols\Sync` implements the standard y-protocols sync handshake. See [docs/js-interop.md](docs/js-interop.md) for a full walkthrough, including the sync protocol and data-type mapping between PHP and JS.

## Update encodings: V1 and V2

Both Yjs wire encodings are supported. The unsuffixed functions (`applyUpdate`, `encodeStateAsUpdate`, `mergeUpdates`, ...) use the V1 format, which is what the JS ecosystem (y-websocket and most providers) uses by default. Each has a `V2` variant (`applyUpdateV2`, `encodeStateAsUpdateV2`, ...), and `convertUpdateFormatV1ToV2()` / `convertUpdateFormatV2ToV1()` convert between them. Use V1 unless you know your clients use V2.

## API reference

The public API mirrors `yjs`'s exports one-to-one:

- **Classes** are available under short aliases in the `Yjs\` namespace: `Yjs\Doc`, `Yjs\YText`, `Yjs\YArray`, `Yjs\YMap`, `Yjs\YXmlElement`, `Yjs\UndoManager`, `Yjs\Snapshot`, `Yjs\RelativePosition`, and so on. (The canonical classes live in `Yjs\Types\`, `Yjs\Utils\`, and `Yjs\Structs\`.)
- **Functions** are namespace functions: `Yjs\applyUpdate()`, `Yjs\encodeStateAsUpdate()`, `Yjs\snapshot()`, `Yjs\createRelativePositionFromTypeIndex()`, etc. They are also available as static methods on the `Yjs\Yjs` facade class (`Yjs\Yjs::applyUpdate(...)`) if you prefer not to import functions.

Since the semantics match JS Yjs, the upstream [API documentation](https://docs.yjs.dev/api/shared-types) applies. The PHP-specific deviations (binary strings vs `Uint8Array`, `null` vs `undefined`, identity sets, etc.) are documented in [docs/differences-from-yjs.md](docs/differences-from-yjs.md).

## What is not included

- **Network providers.** There is no equivalent of y-websocket or y-webrtc; y-php is the document engine, and the transport is up to your application. `Yjs\Protocols\Sync` provides the message encoding for the standard sync handshake.
- **Awareness** (presence, cursors) from y-protocols is not ported.

## Development

```bash
composer install
composer test       # PHPUnit: unit tests + conformance fixtures
composer lint       # PHPCS (WordPress Coding Standards)
composer lint:fix   # PHPCBF auto-fixes
```

The conformance suite asserts byte-for-byte equality against fixtures generated from the real JavaScript Yjs. To regenerate fixtures you need the sibling `yjs` checkout with its dependencies installed:

```bash
node tools/gen-fixtures.mjs
```

See [docs/development.md](docs/development.md) for the testing architecture and how to work on the port itself. The original migration plan lives in [plan/](plan/).

## License

MIT
