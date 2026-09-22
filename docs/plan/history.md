# History worth keeping

`CHANGELOG.md` says what shipped. `AGENTS.md` says how to work in this
repo. This file says **why the code is shaped the way it is, and what
has already been tried and failed**, so nobody spends a week
rediscovering it.

Nothing here is a to-do. Everything here is closed. If a line stops
being useful for deciding what to do next, delete it.

## Where this project came from

The plugin was split out of Gutenberg in two steps. First Gutenberg kept
a generic shell for collaborative editing and shipped no engines and no
transports; every engine and transport lived here. Then (September 2026)
the shell moved here too: Gutenberg now ships no collaboration code at
all, only one small hook in its data layer that lets a single sync
manager follow entity records as they load, change and save. Everything
else, the sync core, the storage, the presence and review screens, the
post lock handling and the settings, lives in this plugin. Without this
plugin active, collaborative editing is simply off and WordPress falls
back to locking the post. The copy of Gutenberg in `gutenberg/` is
pinned to a version that carries the hook and nothing more.

Three engines were built so they could be compared under identical
conditions, with the intention of eventually picking one. The public
write-up of that comparison is the [sync engines
rundown](https://collaborativeediting.wordpress.com/2026/08/21/sync-engines-rundown/).

## Decisions that shape the code today

Each of these was contested at the time. Knowing the reason saves you
from undoing it by accident.

**Gutenberg gets one hook, not a framework.** The first split left
Gutenberg with a large engine-neutral shell that could not be sent
upstream and had to be kept in step with the plugin. The second split
replaced it with one registration point in core-data (the entity sync
seam) and moved everything else here, with plugin-owned PHP names so
that a Gutenberg release which still ships the old experiment cannot
collide with the plugin. The cost is that the plugin now bundles its own
Yjs and its own copy of the collaboration screens; the gain is that the
Gutenberg change is small enough to be reviewed and to be reverted alone.

**The post lock is suppressed on the server, and only where the server
is sure.** Gutenberg used to hide the lock modal in the editor whenever
collaboration was on. Now the plugin's PHP decides per screen whether
collaboration can run (enabled, engine and transport announced, post
type allowed, no legacy meta box) and only then marks the post unlocked
in the editor settings and drops the lock error from the heartbeat. The
second user never takes the lock. If collaboration fails after the page
loaded (an engine the browser cannot provide, an oversized document),
the plugin puts the lock modal back through the editor's own action, so
"no sync and no lock" cannot happen.

**de-rtc saves and syncs through the same path.** A person's edits go
to the server through the ordinary autosave endpoint, not through the
sync channel. The sync channel carries only short notices. This was the
point of the whole engine: if collaborating is just saving, then
anything that can save a post can collaborate, including scripts.

**de-rtc's sync channel must never carry the document.** It used to,
and long sessions ran the server out of memory as messages grew with
the post. Now a notice carries a version number and a fingerprint, and
a client that already matches downloads nothing. If you are ever
tempted to add a document-sized field to that channel, this is the
history that says do not.

**yjs-server stores each block's saved HTML alongside the block.** The
server used to rebuild a block's HTML from what it saw when the post
was first opened, so anything changed later, like an alignment class,
never showed up in the saved post. Now each block carries its own saved
HTML, refreshed when its settings change. **The text inside that copy
is never read** — the live shared text always wins. That rule is what
keeps a stale copy from overwriting someone's typing, and there is a
test whose only job is to prove it.

**de-rtc's commit rhythm defaults to as-soon-as-possible.** A setting
exists to slow it to the ten-second rhythm the original design
described, which measurably cuts requests and bandwidth. The default
stays fast so all three engines feel the same. Slowing it down is a
good recommendation for a small host and a bad default for everyone.

**Review decisions travel over their own web address, but only for
posts and pages.** That matches where de-rtc saves through the normal
save path. Everything else still sends decisions through the sync
channel. Keeping the two splits identical means there is one rule to
remember instead of two.

**Conflicts are resolved on a card attached to the block**, not in the
sidebar. The sidebar lists them but cannot resolve them, except for
conflicts whose block no longer exists, which would otherwise be
impossible to clear.

**intent-log summarises its history every 500 changes**, not every 100.
The smaller number was crossing mid-typing and throwing away edits that
were still in flight. 500 is sized for how much a person writes in one
sitting.

## Dead ends — do not retry these without new information

**Fixing the mid-typing scramble by changing when history is trimmed.**
Three attempts, all recorded, all partial. Raising the threshold made
it rarer, not gone. Deferring the trim while someone is typing did not
close it either. The remaining fix is to stop the cause: a table
keystroke currently writes three history entries instead of one. See
[#37](https://github.com/Automattic/gutenberg-sync-engines/issues/37).

**Recovering a lost edit by asking the editor for the post's current
state.** The obvious call for this returns nothing for some block
shapes. The working approach is to keep a reference to the last block
tree the editor handed us and re-read that.

**Syncing buffered work the moment the first message arrives.** The
first message is only the first of a burst, and the rest of someone's
history arrives right behind it. Doing the work immediately duplicated
every saved block. It has to wait until the burst is over and then
check whether it is still needed.

**Reading the soak test's per-minute numbers as rates.** They are
running totals. A whole investigation was spent chasing a runaway
request rate that did not exist. Subtract before concluding.

**Making the bundled Automerge library do real work** has not been
tried and is not obviously worth trying. The reasons for and against
are in [#44](https://github.com/Automattic/gutenberg-sync-engines/issues/44).

## How the v1 work was run, and what was worth keeping

Roughly thirty cycles of one bounded task each, on their own branches,
against a fixed written scope. Three practices earned their place and
are now part of how the loop works:

- **Whoever does the work does not get to declare it done.** A separate
  reviewer with no knowledge of the reasoning checked the change
  against its stated acceptance. It caught a test filter that matched
  no tests and therefore passed while testing nothing.
- **Run the stated check exactly as written**, before claiming success.
  Paraphrasing it is how the above got missed the first time.
- **Three failed attempts means stop and write down what you learned.**
  Every dead end above came from that rule. None of them cost a fourth
  attempt.
