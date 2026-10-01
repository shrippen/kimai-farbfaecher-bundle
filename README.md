# Farbfächer

Kimai-2.67-Plugin (`shrippen/kimai-farbfaecher-bundle`): findet Farb-Clashes zwischen Kunden, Projekten und Tätigkeiten
und schlägt Farben vor, die sich unterscheiden und trotzdem zusammengehören. Der Name kommt vom Farbfächer, mit dem
Gestalter Farben nebeneinanderlegen.

## Funktionen

- **Übersicht** (Administration → Farbfächer):
  - Clashes nach Schwere (Hinweis, Warnung, kritisch). Gemessen wird der wahrgenommene Abstand ΔE im OKLab-Raum.
  - Wie schwer ein Clash wiegt, hängt davon ab, ob zwei Einträge in denselben Wochen gebucht werden oder in derselben Gruppe liegen.
  - Dazu eine Farbkarte (Farbton × Helligkeit), eine Übersicht nach Kunde und der Verlauf aller Änderungen.
- **Vorschlag mit Vorschau:**
  - Umfang: Clashes beheben, fehlende Farben ergänzen oder alles neu färben.
  - Strategie *zusammenhängend*: Projekte im Farbton ihres Kunden, Tätigkeiten nahe ihrem Projekt.
  - Strategie *maximal unterscheidbar*: jede Farbe möglichst weit weg von allen anderen.
  - Übernehmen per Sammelauswahl, danach 15 Minuten „Rückgängig“. Jeder Stand wird gesichert und lässt sich aus dem Verlauf wiederherstellen.
- **Palette:** Vorschläge lehnen sich an Kimais Farbliste an (`theme.color_choices`), mit Knust also an Gruvbox. Alternativ gibt es den freien Farbraum.
- **Größe der Palette:** Optional vergibt Farbfächer nur N Farben (0 = unbegrenzt). Gewählt werden die N Farben mit dem größten gegenseitigen Abstand, bei der Kimai-Farbliste zuerst deren Farben. Weniger Farben sind deutlicher unterscheidbar, wiederholen sich aber eher.
- **Farbfeld** in Kunden-, Projekt- und Tätigkeitsformularen:
  - Freier Farbwähler statt fester Liste, Clash-Hinweise beim Tippen, Vorschläge per Klick.
  - Gilt auch für die REST-API, externe Clients können jeden Hex-Wert setzen.
- **Automatische Farbe** für neue Einträge ohne Farbe, auch über die API.
- **„Farbe sperren“** pro Eintrag: Farbfächer ändert diese Farbe nie.

Einstellungen: System → Einstellungen → Farbfächer. Berechtigung: `farbfaecher` (Admin, Super-Admin).

## Installation

```bash
cp -r . /opt/kimai/var/plugins/FarbfaecherBundle
bin/console kimai:reload
```

Keine Migration: Die Sperre ist ein Kimai-Meta-Feld, Einstellungen liegen in Kimais Konfiguration, Sicherungen in
`var/data/farbfaecher/`.

## Entwicklung

```bash
dev/reset.sh            # frisches Kimai 2.67 mit Beispieldaten: http://localhost:8093
dev/reset.sh --knust    # dazu das Knust-Theme aus ../Kimai Knust
docker compose -f dev/compose.yaml exec --user www-data kimai \
    bin/console kimai:farbfaecher:analyze --plan clashes      # Vorschau auf der Konsole, schreibt nichts
```

Oberfläche nach [kimai-plugin-ui](../Kimai%20Plugin%20UI/GUIDELINES.md) (Kit 0.4 in `Resources/views/_kit/`, nur per
`bin/sync.sh` aktualisieren) und den Regeln von [Knust](https://github.com/shrippen/Kante/blob/main/kimai/knust/PLUGINS.md): Farbpunkte `kpu-mark`,
Schwere `kpu-tier`, Zahlen `kpu-num`, keine festen Farben außer Entitätsfarben.

## CI und Release

`.github/workflows/` läuft auf Gitea (git.arianw.de) und auf dem GitHub-Spiegel; Gitea nutzt `.github/workflows`,
solange es kein `.gitea/workflows` gibt.

- **CI** (jeder Push, Pull Request): PHP-Lint 8.1–8.4, JS-Syntax, Übersetzungen reproduzierbar aus
  `dev/translations.php`, dann das Plugin-ZIP in ein frisches Kimai 2.67 mit MariaDB installieren und testen (`dev/ci.sh`).
- **Release** (Tag `vX.Y.Z`): Version = `composer.json`, CHANGELOG-Überschrift `## X.Y.Z – <Datum>`
  (nicht „unveröffentlicht“). Baut `FarbfaecherBundle-X.Y.Z.zip`, testet genau dieses ZIP in Kimai, legt das Release
  mit den CHANGELOG-Notizen an (oder nutzt ein im Gitea-UI angelegtes) und hängt das ZIP an.

```bash
git tag v0.1.0 && git push origin v0.1.0     # Gitea spiegelt den Tag nach GitHub, beide bauen ihr Release
```

`dev/ci.sh <kimai-dir> <zip>` läuft auch lokal gegen ein installiertes Kimai (`DATABASE_URL` setzen).

## Aufbau

```
GraphBuilder      Kunden, Projekte, Tätigkeiten + gemeinsame Nutzung (Zeiteinträge je Benutzer und Woche)
    │
ColorEngine       Clashes (ΔE < Schwelle, gewichtet), Kandidaten je Strategie aus ColorStyle
    │               ColorStyle: frei oder aus der Kimai-Farbliste abgeleitet (Farbtöne, Helligkeit, Sättigung)
ColorPlanner      Greedy-Zuteilung + ein Verbesserungsdurchlauf; RankingContext hält die Grundabstände
    │             inkrementell, damit auch Hunderte Einträge in Sekunden gehen
ColorWriter       schreibt Farben, sichert jeden Stand als JSON, stellt nur Unverändertes wieder her
```

## Grenzen

- In kleinen Farbpunkten sind etwa 20–30 Farben sicher unterscheidbar. Bei mehr Einträgen trennt Farbfächer die
  Paare, die zusammen genutzt werden oder nebeneinanderstehen. Der Rest bleibt als „Hinweis“.
- *Zusammenhängend* und *maximal unterscheidbar* widersprechen sich: Geschwister im selben Farbton liegen enger beieinander.
- Die Palette „Kimai-Farbliste“ ist kleiner als der freie Farbraum, dadurch bleiben eher Clashes übrig. Eine begrenzte Palettengröße verstärkt das: Bei mehr Einträgen als Farben wiederholen sich Farben, die Analyse zeigt das als Clashes.
- Farben werden direkt gespeichert, Kimais Update-Events laufen dabei nicht.

## Lizenz

GPL-3.0-or-later, siehe [LICENSE](LICENSE).
