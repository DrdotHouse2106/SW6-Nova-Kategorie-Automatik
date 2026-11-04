# 1.2.2
- Einstellige Kategorie-Codes werden wieder angewendet (1.2.1 hat sie ignoriert und damit Sammelkategorien wie „Alle Teile“ mit `1,2,3,4,5,6,7,9` aus der Verwaltung genommen). Sie werden beim Start einer Neuzuordnung weiterhin als Warnung im Ereignisprotokoll gemeldet

# 1.2.1
- Nach einem Plugin-Update wird nicht mehr automatisch der ganze Katalog neu zugeordnet – nur noch beim Update von einer Version vor 1.2.0 (dort haben sich die Regeln für Varianten geändert). So ändert ein Update keine Zuordnungen unbeaufsichtigt, etwa wenn das ERP gerade leere Teileart-Felder liefert. Neuzuordnen wie gewohnt über „Beim Speichern alle Artikel neu zuordnen“
- Bricht eine Neuzuordnung wegen eines Fehlers ab (z. B. Datenbank-Zeitüberschreitung), steht das jetzt als Fehler mit Ursache und Fortschritt im Ereignisprotokoll, und die Sperre wird sofort freigegeben (bisher erst nach 30 Minuten, ohne Eintrag)
- Kategorie-Codes brauchen mindestens **zwei Ziffern**. Ein einstelliger Code wie `1` erfasste als Präfix alle Artikel von 1 bis 1999; solche Codes werden ignoriert (die Kategorie wird dann nicht mehr verwaltet, vorhandene Zuordnungen bleiben stehen) und beim Start einer Neuzuordnung als Warnung gemeldet

# 1.2.0
- Neuzuordnung verwendet immer die aktuellen Kategorie-Codes: Der Hintergrund-Worker hielt bisher die Codes vom ersten Lauf im Speicher und ordnete nach späteren Änderungen mit alten Codes zu
- Nach dem Update startet das Plugin einmal automatisch eine Neuzuordnung aller Artikel mit den aktuellen Codes (nur wenn „Aktiv“ eingeschaltet ist)
- Es läuft höchstens eine Neuzuordnung: Erneutes Speichern der Konfiguration mit unveränderten Codes startet keine zweite; nach geänderten Codes löst eine neue Neuzuordnung die laufende ab
- Neuzuordnung blättert nach fortlaufender Artikelnummer statt Versatz, damit beim Löschen von Artikeln keine übersprungen werden
- Beim Speichern eines Artikels wird nur noch der Kategoriebaum neu indexiert (statt Preise, Bestand, Suche usw.), und Artikel, Übersetzung und Eigenschaften eines Speichervorgangs werden gemeinsam einmal ausgewertet – schnellerer weclapp-Abgleich
- Zuordnungen werden mit Systemrechten geschrieben: Integrationen oder Benutzer ohne Recht auf Produkt-Kategorien erhalten beim Speichern keinen Fehler mehr
- Varianten werden über ihren Hauptartikel ausgewertet (ihre Codes zählen für den Hauptartikel) und erhalten keine eigenen Zuordnungen mehr; so erben sie wie beschrieben die Kategorien des Hauptartikels
- Protokoll: Entfernte Zuordnungen, Artikel ohne Teileart-Code, die aus Kategorien entfernt wurden (Warnung, z. B. bei vom ERP geleerten Feldern), sowie Start und Ende der Neuzuordnung stehen unter Einstellungen → System → Ereignisprotokoll

# 1.1.2
- Eigenes Plugin-Icon (bisher versehentlich das Icon von Nova Upload)

# 1.1.1
- Konfiguration in der deutschen Administration jetzt auf Deutsch (Texte waren ohne Sprachkennung und erschienen deshalb englisch)

# 1.1.0
- Codes jetzt auch (und bevorzugt) aus Produkt-Zusatzfeldern „Teileart“ (nova_teileart) und „Weitere Teilearten“ (nova_weitere_teilearten) – z. B. von weclapp als Artikelattribut übertragen. Keine Eigenschafts-Ausprägungen nötig, im Shop unsichtbar
- Eigenschaften sind nur noch optional

# 1.0.0
- Erste Version: feste Kategoriezuordnung anhand eines Teileart-Codes (Zusatzfeld an der Kategorie) und der Eigenschaften „Teileart“/„Weitere Teilearten“, Eigenschaften im Shop verborgen, Neuzuordnung im Hintergrund
