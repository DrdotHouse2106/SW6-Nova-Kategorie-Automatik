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
