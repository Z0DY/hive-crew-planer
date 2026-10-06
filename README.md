# HIVE Indoor – Crew-Planer

## Dateien
- `index.html` – die komplette App (Timetable, Mein Plan, Crew)
- `api.php` – speichert Personen und Auswahl
- `config.php` – dein Admin-Schlüssel (bei Updates nicht überschreiben)
- `sw.js`, `manifest.webmanifest`, `icons/` – machen die Seite zur installierbaren, offline-fähigen App
- `timetable.json` – Event, Datum und Timetable (für neue Events nur diese Datei anpassen)
- `push_lib.php`, `cron.php` – Erinnerungen per Push-Nachricht
- `data/` – hier legt die App automatisch `state.json` an
- `data/.htaccess` – sperrt den direkten Abruf der Daten (Apache)

## Installation
1. Ordner auf den Webserver hochladen, z. B. nach `https://deine-domain.de/hive/`.
2. Der Ordner `data` muss für PHP beschreibbar sein (`chmod 775 data`, ggf. Eigentümer `www-data`).
3. Voraussetzung: PHP 7.4 oder neuer mit `mbstring` (bei fast jedem Hoster Standard).
4. Link an die Freunde schicken. Jeder legt sich beim ersten Öffnen selbst an.

**nginx statt Apache:** `.htaccess` wird ignoriert. Optional in der Server-Config ergänzen:
`location /hive/data/ { deny all; }`

## Offline-Betrieb (PWA)
- Die App speichert den letzten Stand auf dem Handy und startet auch ohne Empfang.
- Eigene Änderungen ohne Netz landen in einer Warteschlange und werden automatisch gesendet, sobald wieder Verbindung da ist.
- Android (Chrome): Button „App installieren“ oben. Änderungen werden auch bei geschlossener App gesendet.
- iPhone: Button „App installieren“ zeigt die Schritte für Safari („Zum Home-Bildschirm“). Abgleich, sobald die App geöffnet ist.
- Wichtig vor dem Festival: Die App einmal mit Netz öffnen, damit der aktuelle Stand gespeichert ist.

## App aktualisieren
**Per Skript (empfohlen):** In `deploy.config.psd1` einmal Server, Benutzer, Pfad und URL für `test` und `prod` eintragen, dann:
```
.\deploy.ps1          # Test-Server
.\deploy.ps1 prod     # Produktiv-Server (mit Rückfrage; -Yes überspringt sie)
```
oder in VS Code `Strg+Umschalt+B` („Deploy: Test“) bzw. „Terminal > Task ausführen > Deploy: Produktiv“.

- **Test** lädt den aktuellen Stand hoch, auch nicht committete Änderungen an bekannten Dateien.
- **Produktiv** geht nur mit sauberem, committetem Stand und taggt ihn danach als `prod-<Datum>` (`git tag -l "prod-*"` zeigt, was wann live ging).

Das Skript
- lässt `data/` und eine vorhandene `config.php` auf dem Server unangetastet (als root: `data/` gehört danach `www-data`),
- setzt `VERSION` in `sw.js` automatisch (z. B. `hive-20261006-2215-0f0f2f4`),
- prüft am Ende, ob die neue Version online ist.

Voraussetzung: Login per SSH-Schlüssel (sonst fragt es zweimal nach dem Passwort). Gelöschte Dateien werden auf dem Server nicht automatisch entfernt.

**Von Hand:**
1. Neue Dateien hochladen, `data/` und `config.php` nicht überschreiben.
2. In `sw.js` die Zeile `const VERSION = "hive-v3";` ändern (z. B. `hive-v4`).

Die Geräte laden die neue Version beim nächsten Öffnen mit Netz im Hintergrund und zeigen sie beim übernächsten Start.

## Erinnerungen (Push-Nachrichten)
1. In `timetable.json` bei `"date"` den ersten Event-Tag eintragen, z. B. `"date": "2026-11-14"` (mit Anführungszeichen). Ohne Datum werden keine Erinnerungen verschickt.
2. Cronjob einrichten, der jede Minute prüft, welche Acts bald beginnen:
   ```
   echo '* * * * * www-data /usr/bin/php /var/www/hive/cron.php' | sudo tee /etc/cron.d/hive
   ```
   Manuell testen: `sudo -u www-data php /var/www/hive/cron.php -v`
3. Jeder aktiviert die Erinnerungen selbst unter „Mein Plan“. Auf dem iPhone geht das nur in der installierten App.
4. Admin-Test unter „Crew“ (im Admin-Modus): „Test an mich“, „Erinnerung simulieren“, „Test an alle“.
- Einstellungen in `config.php`: `notify_minutes`, `notify_maybe`, `contact`.
- Die Schlüssel für Push liegen in `data/push.json` und werden beim ersten Aufruf automatisch erzeugt. Diese Datei nicht löschen, sonst müssen alle die Erinnerungen neu aktivieren.

## Neues Event
Nur `timetable.json` anpassen (Name, Ort, Datum, Stages, Zeiten), in `sw.js` die `VERSION` hochzählen und für einen frischen Start `data/state.json` löschen. Die Erinnerungs-Anmeldungen in `data/push.json` bleiben dabei erhalten.

## Admin-Zugang (Personen entfernen)
1. In `config.php` das Wort `HIER-GEHEIMES-WORT-EINTRAGEN` durch ein eigenes geheimes Wort ersetzen (mind. 8 Zeichen, Buchstaben, Zahlen, -).
2. 5x schnell auf das HIVE-Logo tippen und das Wort eingeben – oder einmal `https://deine-domain/?admin=DEIN-WORT` öffnen. Das Gerät merkt sich den Admin-Modus. Falsche Eingaben werden vom Server um 1 Sekunde gebremst.
3. Unter „Crew“ erscheinen jetzt die Buttons „Entfernen“. Alle anderen sehen sie nicht und der Server lehnt Löschungen ohne Schlüssel ab.
4. „Admin-Modus beenden“ entfernt den Schlüssel wieder vom Gerät.

## Daten zurücksetzen / sichern
- Sichern: `data/state.json` kopieren.
- Alles zurücksetzen: `data/state.json` löschen.

## Hinweise
- Wer den Link kennt, kann alles sehen und Einträge bearbeiten. Personen entfernen kann nur der Admin.
- Jedes Gerät merkt sich, wer man ist. Auf einem neuen Gerät einfach den eigenen Namen antippen.
- Die Seite aktualisiert sich alle 8 Sekunden automatisch.
