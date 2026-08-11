# Interoperating with JavaScript Yjs clients

y-php produces and consumes the exact Yjs binary wire format. Any update, state vector, or snapshot encoded by JS Yjs (v13) can be decoded by y-php and vice versa, byte for byte. This document covers the practical details of moving data between a PHP server and JS clients.

## Transporting bytes

Updates are binary. Pick the representation that fits your transport:

| Transport | PHP side | JS side |
|---|---|---|
| Raw HTTP body (`application/octet-stream`) | `Buffer::fromBinaryString( $body )` / `$buffer->toBinaryString()` | `new Uint8Array(await res.arrayBuffer())` |
| Base64 in JSON | `Buffer::fromBase64( $field )` / `$buffer->toBase64()` | `fromBase64(field)` / `toBase64(update)` from `lib0/buffer` |
| Database BLOB | `toBinaryString()` into a `LONGBLOB` column | n/a |

Raw bytes are about 33% smaller than base64; base64 is easier to embed in JSON and to log.

## Stateless sync over HTTP

The simplest server integration needs no protocol library at all. The client sends its state vector, the server replies with the diff (and applies whatever the client sent):

**Client (JS):**

```js
import * as Y from 'yjs'
import { toBase64, fromBase64 } from 'lib0/buffer'

const res = await fetch('/wp-json/myplugin/v1/sync', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    stateVector: toBase64(Y.encodeStateVector(ydoc)),
    update: toBase64(Y.encodeStateAsUpdate(ydoc, serverStateVector)),
  }),
})

const { update } = await res.json()
Y.applyUpdate(ydoc, fromBase64(update), 'server')
```

**Server (PHP):**

```php
use Yjs\Doc;
use Yjs\Lib0\Buffer;
use function Yjs\applyUpdate;
use function Yjs\encodeStateAsUpdate;

$doc = load_document_from_storage( $post_id ); // your code

// Apply the client's changes.
applyUpdate( $doc, Buffer::fromBase64( $body['update'] ), 'client' );

// Reply with everything the client is missing.
$diff = encodeStateAsUpdate( $doc, Buffer::fromBase64( $body['stateVector'] ) );

persist_document( $post_id, $doc ); // your code

wp_send_json( array( 'update' => $diff->toBase64() ) );
```

This exchange is convergent regardless of ordering or repetition, so it tolerates retries and concurrent writers by construction.

## The y-protocols sync handshake

If your clients use a provider that speaks the standard sync protocol (the `y-protocols/sync` message format used by y-websocket), `Yjs\Protocols\Sync` implements the same messages:

| Constant | Value | Meaning |
|---|---|---|
| `Sync::MESSAGE_YJS_SYNC_STEP1` | 0 | "Here is my state vector, send me what I'm missing" |
| `Sync::MESSAGE_YJS_SYNC_STEP2` | 1 | "Here is the diff you asked for" |
| `Sync::MESSAGE_YJS_UPDATE` | 2 | "Here is an incremental update" |

A server handling an incoming sync message:

```php
use Yjs\Lib0\Buffer;
use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;
use Yjs\Protocols\Sync;

function handle_sync_message( Buffer $message, \Yjs\Doc $doc ): ?Buffer {
    $decoder = Decoding::createDecoder( $message );
    $encoder = Encoding::createEncoder();

    // Reads the message, applies updates to $doc, and writes any reply
    // (a step1 message gets a step2 reply) into $encoder.
    Sync::readSyncMessage( $decoder, $encoder, $doc, 'remote' );

    if ( Encoding::hasContent( $encoder ) ) {
        return Encoding::toUint8Array( $encoder );
    }

    return null;
}
```

And initiating sync from the PHP side:

```php
$encoder = Encoding::createEncoder();
Sync::writeSyncStep1( $encoder, $doc );
$message = Encoding::toUint8Array( $encoder ); // send to the peer
```

Note that providers such as y-websocket wrap sync messages in an outer envelope (a message-type varint distinguishing sync from awareness). y-php implements the sync payload; the envelope, connection handling, and awareness are up to your integration.

## Data type mapping

Values stored in shared types cross the language boundary as follows:

| JS value | PHP value | Notes |
|---|---|---|
| `string` | `string` | UTF-8 bytes. Text indices count UTF-16 code units, matching JS (see below). |
| `number` (integer, abs ≤ 2^31 - 1) | `int` | |
| `number` (float) | `float` | IEEE-754 double on both sides. |
| `boolean` | `bool` | |
| `null` | `null` | |
| `undefined` | `Yjs\Lib0\UndefinedValue::getInstance()` | A singleton sentinel, since PHP has no `undefined`. Rare in practice. |
| `bigint` | `Yjs\Lib0\BigInt64` | Carries the value as a decimal string. |
| `Array` | list `array` | |
| plain `Object` | associative `array` | Insertion order is preserved in both languages. |
| `Uint8Array` | `Yjs\Lib0\Buffer` | |
| `Y.Map` / `Y.Array` / ... | `Yjs\YMap` / `Yjs\YArray` / ... | Nested shared types work as in JS. |

### String indices are UTF-16 code units

JS strings are UTF-16, and Yjs text positions count UTF-16 code units. y-php matches this exactly: `YText::insert( $index, ... )`, `delete()`, `format()`, and `$ytext->length` all measure UTF-16 code units, **not** bytes and **not** Unicode code points. For ASCII content these are identical, but an emoji such as 🎉 counts as 2 units. Use this convention when computing indices in PHP (e.g. via `mb_strlen( $s, 'UTF-16' )` arithmetic or by mirroring the client's offsets).

## Verifying interop

The conformance suite in `tests/Conformance/` replays fixtures generated by the real JS implementation (`tools/gen-fixtures.mjs`) and asserts:

- **Decode:** applying JS-produced update bytes yields the same document state.
- **Encode:** performing the same operations in PHP produces byte-identical updates, state vectors, and snapshots.
- **Round-trip:** decode followed by re-encode reproduces the input bytes.

If you suspect an interop issue, reduce it to a fixture: capture the update bytes from the JS side (`toBase64(update)`), load them in a PHP test with `Buffer::fromBase64()`, and compare states. `Yjs\logUpdate( $update )` prints a decoded view of an update's structs for debugging.
