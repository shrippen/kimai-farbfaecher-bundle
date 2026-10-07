# Roadmap — Kimai Farbfächer

## Update-Hinweis (Kit 0.8)

Ein Hinweis für Admins, wenn es ein neueres Release gibt, weil Kimai-Plugins von Hand kopiert werden und sonst niemand davon erfährt.
Format und Regeln: `shrippen.github.io/overview/VERSIONS.md`.

- [ ] Kit auf 0.8 bringen: `../Kante/kimai/kit/bin/sync.sh .`
- [ ] `{{ kit.update_hint('kimai-farbfaecher', <version>, {enabled: …}) }}` auf der Einstellungs- oder Übersichtsseite; Version aus `composer.json` (`version`)
- [ ] Einstellung „Nach Updates suchen“ (Standard an); im Demo-Modus immer aus
- [ ] README: was abgerufen wird (`https://shrippen.github.io/versions.json` ohne Parameter, höchstens einmal am Tag, nur im Browser von Nutzern mit dem Recht `plugins`)
- [ ] Nach jedem Release `python3 ../shrippen.github.io/overview/tools/build-versions.py` und `docs/versions.json` dort committen
