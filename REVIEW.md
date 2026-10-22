# Review guide

Every PR is reviewed before it is merged (rule in `agent.md`): by PR-Agent
(owner, 22.10.2026); a new Claude agent checks the **Wichtig** of earlier
rounds, and reviews when PR-Agent fails. The
reviewer knows nothing of the session that wrote the change: it gets the
PR's intent, its diff and the repository, and this file. The owner does not read code; this review is the second reader.

## What failure looks like here

Farbfächer is a Kimai plugin that finds colour clashes between customers,
projects and activities and rewrites their colours. A bug that matters is
one the user loses data to or sees:

- **Writes colours wrongly:** colours written without the user's
  confirmation, a locked colour ("Farbe sperren") changed, a state not
  backed up in `var/data/farbfaecher/`, a restore or undo that overwrites
  a colour changed since.
- **Computes wrongly:** ΔE in OKLab, contrast or colour conversions; clash
  severity not weighted by shared use or groups; the palette (Kimai colour
  list or free) or its size ignored.
- **Opens too much:** a controller, API or export without the
  `farbfaecher` permission or the ownership check it needs; data of one
  user reaching another.
- **Looks broken:** own colours or copies of kit macros instead of Knust
  and the kit; a text missing in de or en.
- **Ships what it must not:** `demo/` or `dev/` in the release zip.
- **Lies:** README, CHANGELOG or comments that say something the code
  does not do.

## What to check in particular

- Translations are generated from `dev/translations.php`; an edit in
  `Resources/translations/` without it is a bug.
- `Resources/views/_kit/` changes only through the kit's `sync.sh`.
- New behaviour has a test (`dev/ci.sh` installs the zip into Kimai 2.67
  and checks the pages and the analysis).
- Bug fixes: does the fix remove the cause, with a test that failed before?
- `agent.md` code rules: braces always, early return, enums not bools,
  private by default (widening needs the owner's approval).

## What the owner confirms here

"What is published" (rule in `agent.md`) means here: a release (tag
`vX.Y.Z`, `.github/workflows/release.yml`, the zip), and a landing page in
`docs/` once there is one (today `docs/` holds only screenshots).

## Skip

- `Resources/views/_kit/` (vendored kit), `Resources/translations/`
  (generated; check only that they match their source).
- `docs/shots/`, images.

## How to report

- Every finding names file and line and the concrete input or state that
  goes wrong. No guess without evidence; if unsure, say so.
- Each finding gets one label: **Wichtig** (a bug from the list above,
  must be fixed or answered before the merge), **Kleinigkeit** (optional,
  at most five) or **Bestand** (the bug was there before this PR; not
  blocking).
- The report starts with the count, e.g. "2 Wichtig, 1 Kleinigkeit" (the
  session puts the heading above it). The count of **Wichtig** holds every
  one still open, also from an earlier round, e.g. "1 Wichtig (offen aus
  Runde 1)". Only when none is open it says plainly: "Nichts zu beheben."
- In a later round: say for each open **Wichtig** of the last round whether
  it is fixed, or whether its answer is accepted, with the reason. PR-Agent
  sees no earlier round: it reports only its own findings with their labels,
  and a new Claude agent closes the open ones (`agent.md`).
- Findings in German, code names as they are.
