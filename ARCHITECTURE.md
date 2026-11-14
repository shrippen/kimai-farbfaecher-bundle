# Architecture

How this repository is meant to be built. Where the code differs today is listed under
"Known deviations". Change this file in the PR that changes the structure.

## What it does

A Kimai plugin that keeps the colours of customers, projects and activities apart. It
finds colours that look too alike on things used together, proposes better ones, and
writes them only after the user confirmed a plan; every batch can be undone. New
entries get a fitting colour by themselves, and the colour field in Kimai's forms
suggests colours.

## Building blocks

| Path | Its one task |
|---|---|
| `Color/` | Colour math and nothing else: conversions and distance in OKLab (`Oklab`), which colours may be suggested (`ColorStyle`), the candidates on a grid (`CandidatePalette`, `Candidate`). Knows neither Kimai nor the model. |
| `Model/` | The data the plugin works on, as snapshots: every entry with its colour and where it comes from (`ColorNode`), how close two entries are in daily use (`ColorGraph`), a clash, a planned change, the result of a write. |
| `Configuration/` | The plugin's settings, read from Kimai's system configuration, with their defaults. |
| `Service/GraphBuilder.php` | Reads: loads customers, projects, activities and their shared use from the database into the model. |
| `Service/ColorEngine.php` | Judges: finds clashes, ranks candidate colours for one entry. |
| `Service/ColorPlanner.php` | Plans: new colours for many entries at once. |
| `Service/ColorWriter.php` | Writes: sets colours on Kimai's entities and keeps a backup of every batch for undo and restore. |
| `Service/SessionStore.php` | Short-lived state in the user's session (the plan waiting for confirmation). |
| `Controller/` | The pages (overview, plan preview) and actions (apply, undo, restore, suggest). |
| `Command/` | The console command that analyses and plans. |
| `Form/` | The plan form; the free colour field that replaces Kimai's colour list in its edit forms. |
| `EventSubscriber/` | Hooks into Kimai: menu, permissions, page actions, settings page, the "lock colour" field, colour for new entries, stylesheet and script on every page. |
| `Resources/views/`, `Resources/assets/` | Templates and their shared macros; the plugin's stylesheet and the script of the colour field. `Resources/views/_kit/` is the vendored Kimai plugin kit, never edited here. |
| `Resources/translations/` | All texts, German and English. Generated from `dev/translations.php`, never edited by hand; `kpu.*.xlf` are the kit's texts, vendored. |
| `FarbfaecherBundle.php`, `DependencyInjection/`, `Resources/config/` | Registration in Kimai: services and routes. |
| `dev/`, `demo/`, `scripts/`, `tools/` | Not part of the plugin zip: the source of the texts and the end-to-end check in a local Kimai, demo data, release and check scripts. |
| `docs/` | The landing page. |

## Layers

```
 entry points   Controller   Command   EventSubscriber   Form   (templates, script)
                     │  ask for the work, show the answer
                     ▼
 work           Service:  GraphBuilder    ColorEngine, ColorPlanner    ColorWriter
                          (reads Kimai)   (judge, plan)                (writes Kimai)
                          the data passes from left to right, carried by the entry point
                     │
                     ▼
 data           Model  (snapshots: nodes, graph, clashes, changes)
                     │
                     ▼
 math           Color  (OKLab, styles, candidates)

 Configuration: the settings, read by entry points and services
 Model and Color are values: every layer above may read them
```

- An entry point holds no rule and computes nothing it shows: counts, trees and
  positions for a page come from a service.
- Only `GraphBuilder` reads Kimai's database and only `ColorWriter` writes it. The
  judging and planning between them work on the model alone, so they can be tested
  without Kimai. A newly created entry is the exception: its colour is set on the
  entry before Kimai saves it.
- `Color/` imports nothing from the plugin; `Model/` imports only `Color/`.
- A colour of an existing entry changes in three ways only: a plan the user
  confirmed, undo, and restore of a backup. `ColorWriter` saves the backup before it
  writes and leaves a locked colour alone on each of the three ways.
- A rule of the colour calculation stands once: a weight, a threshold, a fallback
  value, the distance of two colours. Judging, planning and the suggestions in the
  colour field use the same one.
- A list of kinds stands once, as an enum in `Model/`: the levels of a clash with
  their emphasis, where a colour comes from. Templates and the script get them from
  PHP and do not spell them out again.
- What the script needs to know (values, texts, levels) it is handed by the server.
- Calculation, planning and writing have tests of their own; the end-to-end check
  calls every action.

## Where new things go

| Change | Touch |
|---|---|
| A rule of how colours are judged or ranked | `Service/ColorEngine.php` (planning: `Service/ColorPlanner.php`); pure math in `Color/`; a test |
| A new setting | `Configuration/FarbfaecherConfiguration.php`, `EventSubscriber/SystemConfigurationSubscriber.php`, its texts in `dev/translations.php`, README |
| Something new known about an entry | `Model/ColorNode.php`, `Service/GraphBuilder.php` |
| A new level of a clash or source of a colour | Its enum in `Model/`, its texts |
| A new text | `dev/translations.php`, which writes `Resources/translations/` |
| Something new on the overview or the plan | A service that computes it, the controller, the template, its texts |
| A new action that writes colours | `Service/ColorWriter.php` (with backup), a route in the controller with permission and token check, a call in `dev/ci.sh` |
| A change to the colour field in Kimai's forms | `Form/`, `Resources/assets/color-field.js` |
| A new hook into Kimai | `EventSubscriber/` |
| A design element that is missing | Knust or the kit in Kante, then sync; never `_kit/` here |
| A release | The steps in `RELEASE.md` |

## Known deviations

- The ranking helper lies in `Color/` and uses the model, so `Color/` and `Model/`
  depend on each other (`AUDIT.md`, finding 4).
- The discount for hidden entries stands in three places (`AUDIT.md`, finding 1); the
  colour distance is computed in three places (`AUDIT.md`, finding 5); thresholds
  and fallback values stand several times, partly with different numbers
  (`AUDIT.md`, finding 6).
- The levels of a clash are spelled out eight times in PHP, templates and script, two
  versions disagree (`AUDIT.md`, finding 2); where a colour comes from is a loose word
  in six files (`AUDIT.md`, finding 8).
- `ColorWriter` writes first and saves the backup after; apply and restore do not
  check the lock, only the planner does (not in `AUDIT.md`; bugs).
- The controller computes the content of the overview itself
  (`AUDIT.md`, finding 9).
- Some functions and parameters nothing uses (`AUDIT.md`, finding 7).
- Calculation, planning and writing have no tests of their own; the end-to-end check
  calls neither apply, undo nor restore (`AUDIT.md`, finding 3).
