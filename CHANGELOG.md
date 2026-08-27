# Changelog

Notable changes to this plugin, newest first. Granularity is deliberately
coarse: record bug fixes, new features, and material changes — not every
commit. Add entries under **Unreleased** as part of the change itself; move
them under a version heading when a version ships.

## Unreleased

### Added

-   A prototype flow for reviewing conflicting edits, for UI design work.
    A block whose edits were set aside is replaced in place by a
    recovery-style card, the way an invalid block is: a "Review conflict"
    action above a preview of the conflict with add/remove highlighting,
    and the block's content is read-only until the conflict is reviewed.
    The action opens a dialog comparing "Your version" with the "Current
    version", each highlighted against the shared text both started
    from; either can be restored into the merged result, which is edited
    in its own small block editor with text formatting controls, and
    Accept writes that result into the block and clears the set-aside
    items. Escalations no longer raise editor notices; the in-place card
    and the sidebar panel are the only surfaces. The compared texts are
    pre-set placeholder content for now. Real conflicts from any engine
    open the flow; supplying the real texts is follow-up engine work.

### Fixed

-   Switching the editor to the code view crashed with an invalid React
    element type, as did a block's "Edit as HTML" view and blocks built
    on the plain-text component. The bundled Gutenberg build's CommonJS
    interop handed the auto-sizing textarea dependency over as a module
    object instead of the component; the three usage sites now unwrap
    both shapes.

-   De-rtc sessions could silently stop syncing after a failed network
    request: the polling transport's recovery step seized the slot of a
    save that was still in flight on the separate save lane, and the
    session froze — no further saves, no incoming content — leaving the
    two editors permanently showing different posts
    ([#39](https://github.com/Automattic/gutenberg-sync-engines/issues/39)).

### Changed

-   Real-time collaboration is now turned on by the **Real-time
    collaboration** Gutenberg experiment instead of the Settings → Writing
    checkbox, following the framework
    ([WordPress/gutenberg#80658](https://github.com/WordPress/gutenberg/pull/80658)).
    Collaboration is off until that experiment is enabled, and the old
    `wp_collaboration_enabled` option is deleted on upgrade. Settings →
    Collaboration now says so when collaboration is off.
-   Conflict review plumbing moved into the framework: an engine now hands
    `createSyncManager` a `review` source and the manager drives the review
    panel, cards, and notices from it. The plugin's review-manager decorator
    (a workaround for the manager dropping the review handlers) is deleted;
    the de-rtc adapter composes the plain manager.

### Added

-   Release automation: a "Create release PR" workflow (pick patch, minor, or
    major) opens a version-bump PR; merging it into trunk triggers a release
    workflow that builds and publishes a ready-to-install plugin zip as a
    GitHub release.
-   The release zip is self-contained: it bundles the pinned, built Gutenberg
    plugin, and the plugin now loads that bundled copy automatically when no
    other Gutenberg is present.
-   Polling interval setting on Settings → Collaboration for the HTTP
    short-polling transport (1-25 seconds; 0 keeps the defaults). Sets how
    often each editor asks the server for updates while collaborating; solo
    editing keeps its slower default unless the chosen interval is longer.
-   The storage backend is now swappable end to end. The framework gains a
    `wp_get_sync_storage()` factory behind a `__unstable_wp_sync_storage` filter, and
    every plugin code path obtains storage through it (via
    `gutenberg_sync_engines_storage()`), so a drop-in plugin can substitute
    Redis or another backend in one place. The framework storage also gains
    a non-creating lookup (`peek_room_engine` — looking at a room no longer
    creates it) and a real `reset_room()`; three plugin code paths that each
    re-implemented the non-creating lookup by hand (including one raw SQL
    delete) now use them. The storage interface documents the contract a
    substitute must uphold.
-   The per-room lock and the compare-and-swap primitive each gained a
    drop-in backend seam: implement `WP_Sync_Lock_Backend` or
    `WP_Sync_CAS_Backend` and return it from the `wp_sync_lock_backend` /
    `wp_sync_cas_backend` filter (for example, memcached locks). The
    interfaces document the correctness rules; the options-table
    implementations remain the defaults.
-   This changelog.

### Removed

-   `restoreProposalWithChanges()` (modify-before-adopt): API-only, never
    called by anything. To return together with its review-panel UI when a
    "suggested edits" feature starts.
-   Dead code from the retired client-compaction era: the client's handling
    of the old `compaction_request` response field (no server sends it),
    the four per-engine "this can never happen" compaction stubs (the
    framework codec members are optional now), the `sync_step1`/`sync_step2`
    update-type constants on both sides, a `require` of a file that does
    not exist in the short-polling server, and the unused
    `getBaseDocument()` session accessor.

## Pre-release history

The plugin's first published GitHub release is 0.1.0, produced by the
release workflow. The milestones below predate it: they were internal
version numbers on paper, never packaged or tagged, and were retired when
release automation landed (the plugin sits at 0.0.0 until that first
release ships).

### V1 loop (August 2026)

-   DE-RTC commit cadence setting (seconds; 0 commits on every settle).
-   DE-RTC Stage 2: sessions commit through the ordinary autosave endpoint;
    the transport carries advisories (~200-byte announce rows), not documents.
-   Fixed a DE-RTC commit hold that dropped the tail of a typing burst when
    the burst straddled a commit round trip.
-   Fixed intent-log losing an edit made during the join round trip on an
    empty room.
-   Collaborative undo in all three engines; cross-engine conflict review
    (intent-log manager, DE-RTC parked proposals in the framework review
    panel); shared genesis property seeding.
-   The websocket e2e suite now runs against the real websocket transport.
-   `composer lint` clean; zero-warning baseline.

### Engines, transports, diagnostics (August 2026)

-   Third engine: `de-rtc` (Distributed Editing's save-centric model; merge
    core ported verbatim from wordpress-develop).
-   Second engine: `yjs-server` (server-authoritative CRDT on vendored
    y-php); the client-merging `yjs-relay` engine was retired.
-   Transports: `http-polling` (default), `http-long-polling`, `websocket`.
-   Diagnostics: `npm run doctor`, the `window.wpSync` wire inspector,
    `wp collaboration rooms`, session capture/replay, the browser fuzzer,
    and the benchmark harnesses.

### Initial split from Gutenberg (August 2026)

-   Initial split from the Gutenberg framework: this plugin owns all engines
    and transports; the framework keeps the engine-neutral substrate. First
    engine: `intent-log`. Settings → Collaboration screen for choosing the
    active engine and transport.
