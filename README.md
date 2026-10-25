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
- **Neu zuordnen:** Schalter „Beim Speichern alle Artikel neu zuordnen“ – läuft im Hintergrund in Paketen zu 200 Artikeln.

## Installation

1. Plugin über den Nova Updater installieren (oder ZIP aus dem Release unter *Erweiterungen → Meine Erweiterungen → Erweiterung hochladen*).
2. Installieren und aktivieren.

## Einstellungen

*Erweiterungen → Meine Erweiterungen → Nova Kategorie-Automatik → Konfigurieren* (nur „Alle Verkaufskanäle“):

| Einstellung | Bedeutung |
|---|---|
| **Aktiv** | Automatik ein/aus. |
| **Zusätzlich: Eigenschaften mit dem Teileart-Code** | Optional, falls die Codes (auch) als Eigenschaften ankommen. |
| **Diese Eigenschaften im Shop verbergen** | Standard an: kein Filter, nicht auf der Produktseite. |
| **Beim Speichern alle Artikel neu zuordnen** | Einmalig nach dem Anlegen oder Ändern von Codes einschalten und speichern; springt von selbst zurück. |

**Reihenfolge beim Einrichten:** Kategorien anlegen und Codes eintragen → Eigenschaften wählen → *Aktiv* einschalten → *Neu zuordnen* einschalten und speichern.

## Grenzen

- Die Zuordnung erfolgt beim Speichern eines Artikels. Ändert sich nur der Code an einer Kategorie, bitte *Neu zuordnen* auslösen.
- Varianten: Eigenschaften und Kategorien werden am Hauptartikel ausgewertet; Varianten erben die Kategorien.
- Die Neuzuordnung braucht eine laufende Nachrichtenwarteschlange (Admin-Worker oder `messenger:consume`).

## Häufige Probleme und Lösungen

### Codes kommen als Eigenschaft nur bei wenigen Artikeln an (weclapp)

**Ursache:** weclapp legt für ein Auswahlfeld mit vielen Werten die Shopware-Ausprägungen nur schleppend an; Artikel, deren Ausprägung (noch) fehlt, bekommen die Eigenschaft nicht

**Lösung:** In der weclapp-Shop-Anbindung die Felder „Teileart“ und „Weitere Teilearten“ statt auf eine Eigenschaft auf die **Artikelattribute** `nova_teileart` und `nova_weitere_teilearten` zuordnen (ab 1.1.0). Textwerte brauchen keine Ausprägungen


### Nach „Neu zuordnen“ passiert nichts

**Ursache:** Die Nachrichtenwarteschlange wird nicht abgearbeitet (z. B. Admin-Worker aus, kein Cronjob für `messenger:consume`)

**Lösung:** Administration eine Weile geöffnet lassen (Admin-Worker) oder den Worker per Cronjob laufen lassen

### Artikel erscheint nicht in der Kategorie

**Ursache:** Der Eigenschaftswert beginnt nicht mit dem Code (z. B. „Bremsbacken 621“) oder die Eigenschaft ist in der Konfiguration nicht gewählt

**Lösung:** Werte im Format „621 Bremsbacken“ anlegen und die Eigenschaft in der Konfiguration auswählen; danach den Artikel speichern oder *Neu zuordnen*

## Lizenz

MIT
