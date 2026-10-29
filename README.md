# Nova Kategorie-Automatik

Shopware-6-Plugin: ordnet Artikel **fest** den Kategorien zu, deren **Teileart-Code** zu einer Eigenschaft des Artikels passt – ohne dynamische Produktgruppen. Die Codes bleiben im Shop unsichtbar.

*English version: [README.en.md](README.en.md)*

## Funktionen

- **Code an der Kategorie:** Zusatzfeld „Teileart-Code“ (z. B. `621`) im Reiter *Zusatzfelder* der Kategorie. Mehrere Codes mit Komma. Der Kategoriename bleibt ohne Code („Bremsbacken“).
- **Code am Artikel:** in den Produkt-Zusatzfeldern **„Teileart“** (`nova_teileart`) und **„Weitere Teilearten“** (`nova_weitere_teilearten`, mehrere Werte mit Komma), z. B. von weclapp als Artikelattribut übertragen. Werte wie `621 Bremsbacken`, maßgeblich sind die führenden Ziffern. Optional zusätzlich über Eigenschaften.
- **Feste Zuordnung beim Speichern:** Wird ein Artikel oder eine seiner Eigenschaften gespeichert (weclapp-Abgleich, Administration, Import), ordnet das Plugin ihn den passenden Kategorien zu und entfernt veraltete Zuordnungen.
- **Ein Code passt auch als Anfang:** Kategorie-Code `62` erfasst alle Artikel mit 620–629. Meist reicht es, nur die unterste Ebene zu codieren – übergeordnete Kategorien zeigen die Artikel ihrer Unterkategorien in Shopware ohnehin mit.
- **Nur Kategorien mit Code werden verwaltet.** Zuordnungen zu allen anderen Kategorien (alte Bäume, dynamische Gruppen, von Hand gesetzte) bleiben unangetastet.
- **Codes unsichtbar:** Die gewählten Eigenschaften werden auf „nicht im Filter“ und „nicht auf der Produktseite“ gestellt.
- **Neu zuordnen:** Schalter „Beim Speichern alle Artikel neu zuordnen“ – läuft im Hintergrund in Paketen zu 200 Artikeln, immer mit den aktuellen Codes. Es läuft höchstens eine Neuzuordnung gleichzeitig. Nach jedem Plugin-Update startet sie einmal von selbst.
- **Protokoll:** Entfernte Zuordnungen, Artikel ohne Code, die aus Kategorien entfernt wurden (Warnung), sowie Start und Ende der Neuzuordnung stehen unter *Einstellungen → System → Ereignisprotokoll*.

## Installation

1. Plugin über den Nova Updater installieren (oder ZIP aus dem Release unter *Erweiterungen → Meine Erweiterungen → Erweiterung hochladen*).
2. Installieren und aktivieren.

## Einstellungen

*Erweiterungen → Meine Erweiterungen → Nova Kategorie-Automatik → Konfigurieren* (nur „Alle Verkaufskanäle“):

| Einstellung | Bedeutung |
|---|---|
| **Aktiv** | Automatik ein/aus. |
| **Zusätzlich: Eigenschaften mit dem Teileart-Code** | Optional, falls die Codes (auch) als Eigenschaften ankommen. |
| **Diese Eigenschaften im Shop verbergen** | Standard an: kein Filter, nicht auf der Produktseite. Beim Ausschalten oder Abwählen einer Eigenschaft wird nichts zurückgestellt – dann „Im Produktfilter anzeigen“/„Auf Produktdetailseite anzeigen“ bei der Eigenschaft selbst wieder einschalten. |
| **Beim Speichern alle Artikel neu zuordnen** | Einmalig nach dem Anlegen oder Ändern von Codes einschalten und speichern. Der gespeicherte Wert springt sofort zurück, die Maske zeigt das erst nach dem Neuladen der Seite. Erneutes Speichern mit unveränderten Codes startet keine zweite Neuzuordnung, solange eine läuft; nach geänderten Codes löst eine neue die laufende ab. |

**Reihenfolge beim Einrichten:** Kategorien anlegen und Codes eintragen → Eigenschaften wählen → *Aktiv* einschalten → *Neu zuordnen* einschalten und speichern.

## Grenzen

- Die Zuordnung erfolgt beim Speichern eines Artikels. Ändert sich nur der Code an einer Kategorie, bitte *Neu zuordnen* auslösen.
- Varianten: Codes und Kategorien werden am Hauptartikel ausgewertet (Codes von Varianten zählen für den Hauptartikel); Varianten erben die Kategorien. Eigene Zuordnungen von Varianten aus Versionen vor 1.2.0 werden nur abgebaut, wenn sie nicht mehr passen.
- Liefert das ERP leere Teileart-Felder, wird der Artikel aus den Kategorien mit Code entfernt (Warnung im Ereignisprotokoll).
- Die Neuzuordnung braucht eine laufende Nachrichtenwarteschlange (Admin-Worker oder `messenger:consume`).

## Häufige Probleme und Lösungen

### Codes kommen als Eigenschaft nur bei wenigen Artikeln an (weclapp)

**Ursache:** weclapp legt für ein Auswahlfeld mit vielen Werten die Shopware-Ausprägungen nur schleppend an; Artikel, deren Ausprägung (noch) fehlt, bekommen die Eigenschaft nicht

**Lösung:** In der weclapp-Shop-Anbindung die Felder „Teileart“ und „Weitere Teilearten“ statt auf eine Eigenschaft auf die **Artikelattribute** `nova_teileart` und `nova_weitere_teilearten` zuordnen (ab 1.1.0). Textwerte brauchen keine Ausprägungen


### Konfiguration erscheint auf Englisch (bis 1.1.0)

**Ursache:** In der `config.xml` gilt ein Text ohne `lang`-Angabe als englischer Standardtext; die deutschen Texte hatten keine Angabe `lang="de-DE"`

**Lösung:** Auf 1.1.1 aktualisieren. Die Einstellungen sind unverändert, nur die Beschriftung ist jetzt deutsch („Reassign all products on save“ = „Beim Speichern alle Artikel neu zuordnen“)

### Nach „Neu zuordnen“ passiert nichts

**Ursache:** Die Nachrichtenwarteschlange wird nicht abgearbeitet (z. B. Admin-Worker aus, kein Cronjob für `messenger:consume`)

**Lösung:** Administration eine Weile geöffnet lassen (Admin-Worker) oder den Worker per Cronjob laufen lassen

### Artikel erscheint nicht in der Kategorie

**Ursache:** Der Eigenschaftswert beginnt nicht mit dem Code (z. B. „Bremsbacken 621“) oder die Eigenschaft ist in der Konfiguration nicht gewählt

**Lösung:** Werte im Format „621 Bremsbacken“ anlegen und die Eigenschaft in der Konfiguration auswählen; danach den Artikel speichern oder *Neu zuordnen*

## Lizenz

MIT
