# Changelog

Each release section is also its release note (GitHub and Gitea) and its entry under "Changes" on the
landing page.

## [0.1.0] — 2026-09-26

### English

The first release: Farbfächer finds colors in Kimai that are hard to tell apart and suggests colors for customers, projects and activities that stay distinct and still belong together.

#### New
- Clash analysis by perceived distance (OKLab), weighted by how often two entries are booked in the same weeks and whether they share a group.
- Suggestions "related" (projects in their customer's hue, activities close to their project) or "maximally distinct", from Kimai's color list or the free color space, with a size limit for the palette.
- Preview with bulk selection, undo for 15 minutes, and a history to restore every earlier state.
- Free color picker with clash hints in the customer, project and activity forms and in the API; new entries without a color get one automatically; "lock color" keeps a color as it is.
- Interface after the shared Kimai plugin UI kit and Knust.
- A full palette never hands out the same color twice: a clash costs more than a crowded palette.
#### Note
- Needs Kimai 2.67 or newer and PHP 8.1 or newer. Install into `var/plugins/FarbfaecherBundle` and run `bin/console kimai:reload`; no migration.
- Each release zip is tested in Kimai 2.67 before it is attached.

All changes: https://github.com/shrippen/kimai-farbfaecher-bundle/commits/v0.1.0

### Deutsch

Die erste Version: Farbfächer findet Farben in Kimai, die sich schwer unterscheiden lassen, und schlägt Farben für Kunden, Projekte und Tätigkeiten vor, die sich unterscheiden und trotzdem zusammengehören.

#### Neu
- Clash-Analyse nach wahrgenommenem Abstand (OKLab), gewichtet danach, wie oft zwei Einträge in denselben Wochen gebucht werden und ob sie in derselben Gruppe liegen.
- Vorschläge „zusammenhängend“ (Projekte im Farbton ihres Kunden, Tätigkeiten nahe ihrem Projekt) oder „maximal unterscheidbar“, aus Kimais Farbliste oder dem freien Farbraum, mit einstellbarer Größe der Palette.
- Vorschau mit Sammelauswahl, Rückgängig für 15 Minuten und ein Verlauf, aus dem sich jeder frühere Stand wiederherstellen lässt.
- Freier Farbwähler mit Clash-Hinweisen in den Formularen für Kunden, Projekte und Tätigkeiten und in der API; neue Einträge ohne Farbe bekommen automatisch eine; „Farbe sperren“ lässt eine Farbe, wie sie ist.
- Oberfläche nach dem gemeinsamen UI-Kit für Kimai-Plugins und Knust.
- Eine volle Palette vergibt keine Farbe doppelt: ein Clash wiegt schwerer als Gedränge.
#### Hinweis
- Braucht Kimai 2.67 oder neuer und PHP 8.1 oder neuer. Nach `var/plugins/FarbfaecherBundle` installieren und `bin/console kimai:reload` ausführen; keine Migration.
- Jedes Release-Zip wird vor dem Anhängen in Kimai 2.67 getestet.

Alle Änderungen: https://github.com/shrippen/kimai-farbfaecher-bundle/commits/v0.1.0
