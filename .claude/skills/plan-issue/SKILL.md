---
name: plan-issue
description: Write or revise an issue in plan/issues/ — the product-focused, plain-language format this repo uses for bugs and features. Use when the user wants to file a bug, propose a feature, capture a finding as future work, or rewrite an existing note or finding into the plan format. Also use before opening a GitHub issue for this repo, since the Markdown file is the source of truth and the issue is a mirror.
---

# Writing an issue

Read `plan/README.md` first. It holds the rules; this skill is how
you apply them.

## The one thing that matters

The person reading the top of this file may be meeting this project
today. They might be a new engineer, a designer, or the person who
reported the bug. Write the title, the problem, and the example for
them.

The precise technical account is not lost. It goes under "Notes for
whoever picks this up", where it belongs and where it is welcome in
full detail.

If you find yourself compressing three ideas into one sentence with
dashes and parentheses, stop and write three sentences.

## Steps

**1. Check it is one thing.** If the title needs "and", split it into
two issues. Two small issues always beat one with two halves.

**2. Get the facts.** Read the code, run the failing command, or read
the record in `plan/history.md`, `LOOP.md` and git history. An issue built on a guess wastes
the time of whoever picks it up. If something is genuinely unknown, say
so in the notes rather than inventing it.

**3. Pick the next number.** Look at `plan/issues/` and take the
next free one. Name the file `NNNN-short-slug.md`, where the slug is a
few words from the title.

**4. Copy `plan/TEMPLATE.md` and fill it in.** Write the example
last if that helps — a concrete example often shows you that the
problem statement was vague.

**5. Set the status honestly.**

- `shaping` — the problem is real but nobody has decided what to build.
  Say which decision is missing, near the top.
- `ready` — someone could pick this up today and start.

Most new issues are `shaping`. That is fine and it is useful information.
An issue marked `ready` that is not really ready wastes someone's
afternoon.

**6. Run the checker.**

```bash
npm run plan:check
```

It reports our invented words used above the notes section, missing
sections, and examples with no numbered steps. The jargon check reads
`docs/glossary.md`, so it is eager on purpose. Look at each word it
flags. Usually the fix is to say what the word means. Sometimes the
sentence belongs in the notes instead.

**7. Update the changelog when an issue ships**, not when it is filed.
`AGENTS.md` explains the rule.

## Turning an existing note into an issue

When writing up an old finding or a `LOOP.md` diagnosis, the source
text is written for people who already know the system. Do not
paraphrase it. Work out what a person using the editor would actually
experience, lead with that, and keep the original technical account in
the notes.

Ask yourself: what would the bug report have said if a writer using
WordPress had filed it? That is your title.

## Do not open the GitHub issue

The Markdown file is the source of truth and the issue is a mirror. The
mirror is pushed by a separate script, and creating or updating issues
is an outward-facing action that the user has to ask for by name every
time. Write the file. Tell the user it is ready to mirror. Stop there.
