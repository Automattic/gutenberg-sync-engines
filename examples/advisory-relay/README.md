# Advisory relay (bring your own WebSocket server)

A small WebSocket relay for the plugin's advisory channel, for hosts that
cannot run the plugin's own PHP daemon (`wp collaboration sync-server`)
or already run WebSocket infrastructure elsewhere. It runs anywhere
Node 20+ runs, needs no database and no connection to WordPress, and is
short enough to copy or port to another language. The message formats
and the ticket it checks are described in
[`docs/plan/advisory-channel.md`](../../docs/plan/advisory-channel.md)
under "Bring your own relay".

What it does: editor tabs on short polling open one socket each. The
relay tells the tabs in a room who is present and passes "I saved a
change, go and poll" notices between them. It never sees post content
and never writes anything. Every read and write of the post stays on
the WordPress REST endpoint.

What it cannot do: tell tabs about changes made by something that is
not a tab (a script, WP-CLI). The plugin's daemon does that with a
database scan; with a relay, the heartbeat's head-cursor check covers
it (up to 10 seconds for a focused tab, up to 2 minutes for a hidden
one).

## Setup

1. Choose a secret (32 or more random bytes, e.g. `openssl rand -hex 32`).
2. On the WordPress side, in `wp-config.php`:

    ```php
    define( 'WP_SYNC_WEBSOCKET_TICKET_SECRET', '<the secret>' );
    add_filter( 'wp_sync_websocket_url', fn() => 'wss://relay.example.com' );
    ```

    Then choose "WebSocket to the sync daemon" as the advisory channel
    under Settings → Collaboration (the transport stays short polling).
    With a secret configured, the plugin mints signed, two-minute tickets
    instead of one-time tokens, and the plugin's own daemon accepts them
    too.

3. Run the relay with the same secret and the page origin(s):

    ```bash
    npm install ws
    WP_SYNC_WEBSOCKET_TICKET_SECRET='<the secret>' \
    ALLOWED_ORIGINS='https://example.com' \
    PORT=8790 node relay.mjs
    ```

    Terminate TLS in front of it; plaintext `ws://` must not leave a dev
    box. `GET /health` answers `200 OK`.

Environment: `WP_SYNC_WEBSOCKET_TICKET_SECRET` (required),
`ALLOWED_ORIGINS` (required, comma-separated), `PORT` (8790), `HOST`
(0.0.0.0), `BLOG_ID` (optional; refuse tickets for any other site).

## Trying it locally

The websocket e2e suite runs this relay against the tests site with a
fixed test secret (`npm run test:e2e:websocket -- advisory-relay`). To
try it by hand against the dev site, start the relay with
`ALLOWED_ORIGINS=http://localhost:8888`, activate the same secret on the
site (a constant in `.wp-env.override.json`'s `config`, or the
`wp_sync_websocket_ticket_secret` filter), and point
`wp_sync_websocket_url` at `ws://localhost:8790`.
