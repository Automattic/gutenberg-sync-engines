# Changelog

Notable changes to this plugin, newest first. Granularity is deliberately
coarse: record bug fixes, new features, and material changes — not every
commit. Add entries under **Unreleased** as part of the change itself; move
them under a version heading when a version ships.

## Unreleased

### Added

-   `npm run bench` is the single entry point for the benchmarks, and
    by default prints a host cost report: what real-time collaboration
    adds to a server, one engine per run, as two
    baseline/sync/delta/delta-% tables (editing and idle) of the
    numbers a hosting provider sizes for — requests per minute, network
    traffic, server CPU, PHP worker share, peak PHP memory,
    options-cache invalidations, database queries, and database disk I/O (data-file
    reads/writes and fsyncs from the database server's own counters) —
    plus storage held per collaborative post and a derived
    editors-per-worker capacity estimate. The baseline is the workflow
    the plugin replaces: the same people writing the same document in
    series — type, save, hand off — with the plugin deactivated, so
    the delta isolates what live collaboration itself costs; a
    measurement mu-plugin (community-harness model, mounted by the
    wp-env configs) measures every request the editor windows make,
    plugin active or not, which is what makes the server-side columns
    true over-baseline deltas. The engine matrix and transport
    benchmark sit behind `suite=engines` and `suite=transport`; the
    soak and replay lanes are debugging/analysis tools run directly
    from `tests/debugging/`. The community-harness compatibility
    statement (what matches, what diverges and why) is in
    `tests/benchmarks/README.md`
    ([#60](https://github.com/Automattic/gutenberg-sync-engines/issues/60)).

### Fixed

-   Under the de-rtc engine, the per-block "who last edited this" record
    credited every block in the document to whoever made the latest
    edit, instead of only the blocks that edit actually changed. The
    record compared blocks in a form that included the temporary id the
    editor's parser assigns to each block on every read, so no two
    readings of the same block ever looked equal. Blocks now compare by
    their saved form, through the one comparison the engine's undo and
    merge paths already share. Nothing on screen reads this record yet.

-   Under the de-rtc engine, a long-running collaboration session could
    lose every accepted edit the moment a save request looked at the
    room. The engine watches the saved post for outside changes, and its
    guard against re-applying an old copy only remembered the last 20
    versions — so once a session that had not saved yet ran long enough,
    the post's own untouched starting content stopped looking familiar,
    was mistaken for brand-new outside work, and the room was rolled all
    the way back to it. The room now permanently remembers the content it
    was created from. Found by the engine benchmark's editing-session
    scenarios, which now run in CI so this class of failure fails a pull
    request instead of waiting for someone to run the benchmark by hand
    ([#70](https://github.com/Automattic/gutenberg-sync-engines/issues/70)).

-   Under the default intent-log engine, the "Edit HTML" window on a
    just-added Custom HTML block closed by itself about a second after
    opening. The first sync push after creating a block handed the
    editor a copy of the block under a fresh internal id, which
    remounted it and reset any on-screen state local to that block
    (the open window, focus, open dropdowns). The push now reuses the
    editor's own id for blocks whose collaboration identity was just
    assigned, so nothing remounts

    ([#66](https://github.com/Automattic/gutenberg-sync-engines/issues/66)).
-   Pressing Ctrl+C did not stop the websocket sync server started by
    `npm run rtc:ws`, even though the server said it would: the terminal
    just sat there and the server kept running. Two things were in the
    way. The container took over the terminal, so Ctrl+C never reached
    the script that knows how to shut it down; and inside the container
    the server is the first process, where the system ignores a stop
    request unless the program has explicitly asked to hear about it.
    Both are fixed — the script now sees Ctrl+C and stops the server, and
    the server listens for stop requests wherever PHP allows it, closing
    open connections and freeing the port on the way out.

-   Under the de-rtc engine, once a reviewer approved content an
    unprivileged author's edit would otherwise have stripped (a script
    tag, a custom embed), that author's later edits anywhere else in the
    post were thrown away and set aside for review instead of landing —
    the whole post froze for them. Every submitted proposal is checked
    document-wide, and a false-alarm mismatch between that whole-document
    check and each block's own individually-clean check was being treated
    as "cannot tell what's risky here", escalating harmless edits along
    with it. Approving content now also pins the exact bytes as approved,
    so later edits that carry it along recognize it instead of re-parking
    it every time
    ([#64](https://github.com/Automattic/gutenberg-sync-engines/issues/64)).

-   `wp collaboration rooms inspect --materialize` crashed with a fatal
    PHP error when the site's collaboration method had been switched
    since a room's data was written, because it rebuilt the room's
    content with the site's currently configured method rather than the
    method that room's data actually belongs to. It now resolves the
    room's own recorded method first, and prints a clear message instead
    of crashing when that method isn't available
    ([#56](https://github.com/Automattic/gutenberg-sync-engines/issues/56)).

-   Under the yjs-server engine, a Group block (or other container) could
    come back after a reload as WordPress's "unexpected or invalid
    content" recovery screen with nothing to recover, its contents gone
    from the saved post. The room's starting snapshot only carried the
    attributes written in the block markup, omitting registered defaults
    like the Group wrapper's tag name; an editor that adopted those
    blocks then saved the Group as an empty self-closing block, dropping
    every child. The snapshot now fills those defaults in (and strips
    them back out when rebuilding post content, so saved content is
    unchanged)
    ([#38](https://github.com/Automattic/gutenberg-sync-engines/issues/38)).

-   Fast typing under the intent-log engine could freeze the typist's
    browser tab for many seconds — table cells and captions made it
    worst — so the other person's window stopped receiving the end of
    what was typed, and tests timed out clicking elements that were
    plainly on screen. The engine deep-copied its document once per
    received change through the page's `structuredClone`, which
    WordPress's script polyfill replaces with a far slower version; the
    engine now uses its own plain-data copy and never touches the global
    ([#37](https://github.com/Automattic/gutenberg-sync-engines/issues/37)).

-   De-rtc sessions could silently stop syncing after a failed network
    request: the polling transport's recovery step seized the slot of a
    save that was still in flight on the separate save lane, and the
    session froze — no further saves, no incoming content — leaving the
    two editors permanently showing different posts
    ([#39](https://github.com/Automattic/gutenberg-sync-engines/issues/39)).

### Changed

-   Adopt and Reject decisions now travel only over their own REST route,
    for every content type. The server rejects the older way (folded in
    with ordinary sync messages) and the browser no longer falls back to
    it; a decision that fails to send reopens in the review panel so it
    can be retried
    ([#40](https://github.com/Automattic/gutenberg-sync-engines/issues/40)).
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
