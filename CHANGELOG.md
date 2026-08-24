# Changelog

Notable changes to this plugin, newest first. Granularity is deliberately
coarse: record bug fixes, new features, and material changes — not every
commit. Add entries under **Unreleased** as part of the change itself; move
them under a version heading when a version ships.

## Unreleased

### Changed

-   Real-time collaboration is now turned on by the **Real-time
    collaboration** Gutenberg experiment instead of the Settings → Writing
    checkbox, following the framework
    ([WordPress/gutenberg#80658](https://github.com/WordPress/gutenberg/pull/80658)).
    Collaboration is off until that experiment is enabled, and the old
    `wp_collaboration_enabled` option is deleted on upgrade. Settings →
    Collaboration now says so when collaboration is off.

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
-   This changelog.

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
