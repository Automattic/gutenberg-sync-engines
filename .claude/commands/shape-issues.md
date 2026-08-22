---
description: Investigate issues labelled agent:needs shaping and rewrite them into the shaped-issue format
---

You are shaping issues for this repo. Someone filed a report in their
own words. Your job is to work out what is actually going on and
rewrite it so that anyone could pick it up.

You may be given issue numbers. If not, take everything that needs
shaping, oldest first:

```bash
gh issue list --label "agent:needs shaping" --state open
```

Read `plan/README.md` for the rules and
`.github/ISSUE_TEMPLATE/shaped-issue.md` for the shape.

## For each issue

**1. Read it as filed.** `gh issue view <n> --comments`. The reporter's
words are evidence. Do not discard them because they are imprecise —
imprecision is often the symptom. "It goes weird when we both type" is
a real observation.

**2. Investigate before writing.** This is the part that earns the
label change. Read the code, run the reproduction, check the tests. Two
places to look first:

- `plan/history.md` — is this a known dead end, or already explained?
- `docs/` — is this behaving as designed? Some things are deliberate,
  like conflicts resolving silently in yjs-server.

**3. Decide what it actually is**, and say so plainly:

- **A real bug we can act on** → shape it, label `agent:ready`.
- **Real, but a human must decide something first** → shape it, put the
  missing decision near the top, label `agent:needs decision`. Say who
  decides and what the options are.
- **Working as designed** → explain why, in plain language, link the
  doc that says so, and close it. Be gracious: a report that turns out
  to be a misunderstanding is still a documentation problem.
- **Already fixed** → say in which change, and close it.
- **A duplicate** → link the original and close.
- **Not enough to go on** → ask one specific question in a comment and
  leave the label alone. Ask for the smallest thing that would unblock
  you, not a list.

**4. Write the body** to the template's five sections. The example
matters most: numbered steps, real text, what you see, what you
expected. If you reproduced it, use what you actually saw. If you could
not reproduce it, say so honestly and give the closest thing you have,
such as the automated test that does trigger it.

Keep the reporter's own description of the symptom where you can. They
described what it looks like from outside, which is exactly the part
you are least able to invent.

**5. Check it before sending.**

```bash
node plan/bin/check.mjs draft.md
```

Fix what it flags. The jargon check is eager, so use judgement, but the
default is to take its advice — it is catching you writing like the
inside of the codebase.

**6. Update the issue.**

```bash
gh issue edit <n> --title "<plain-language title>" --body-file draft.md \
  --add-label "agent:ready" --remove-label "agent:needs shaping"
```

Then add a short comment saying what you found and what changed, so the
reporter sees a human-readable answer rather than silently rewritten
text. Two or three sentences.

## Rules

- **Editing issues is allowed. Creating them is not**, unless the user
  asked for that specific issue to be filed. Shaping means improving
  what exists. If your investigation turns up a *different* problem,
  say so in your report at the end and let the user decide.
- **Never close an issue you are unsure about.** Closing is a judgement
  with a person on the other end. If it is borderline, shape it and
  raise the question in your report instead.
- **Do not invent reproduction steps.** If you did not run them, say
  which parts are inferred.
- **Do not fix the bug.** Shaping and fixing are different jobs, and
  mixing them means nobody reviewed the shape. The fix happens in a
  loop cycle, on a branch, against the issue you just wrote.
- **Write plainly.** Same rule as everything else here: if the word is
  in `docs/glossary.md`, it does not go above the notes section.

## At the end

Report what you did, in plain sentences: how many you shaped, which
ones you closed and why, which need a decision from the user and what
that decision is. Anything you could not reproduce, say so — an
unreproducible report is a finding, not a failure.
