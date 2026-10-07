# HIVE Indoor – Crew-Planer

## Dateien
- `index.html` – die komplette App (Timetable, Mein Plan, Crew, Chat)
- `api.php` – speichert Personen, Auswahl und Chat-Nachrichten
- `config.php` – dein Admin-Schlüssel (bei Updates nicht überschreiben)
- `sw.js`, `manifest.webmanifest`, `app-icons/` – machen die Seite zur installierbaren, offline-fähigen App (nicht `icons/` nennen: den Pfad belegt Apache standardmäßig für eigene Symbole)
- `timetable.json` – Event, Datum und Timetable (für neue Events nur diese Datei anpassen)
- `push_lib.php`, `cron.php` – Erinnerungen per Push-Nachricht
- `data/` – hier legt die App automatisch `state.json`, `chat.json` und `push.json` an
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
- setzt `VERSION` in `sw.js` und `APP_VERSION` in `index.html` automatisch (z. B. `hive-20261006-2215-0f0f2f4`, im Admin-Modus unter „Crew“ sichtbar),
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

## Crew-Chat
- Ein gemeinsamer Chat für alle unter „Chat“. Die Zahl am Tab zeigt ungelesene Nachrichten (gelb = du wurdest erwähnt).
- `@Name` erwähnt jemanden, `@alle` die ganze Crew. Wer unter „Mein Plan“ die Erinnerungen aktiviert hat, bekommt dann eine Push-Nachricht. Andere Chat-Nachrichten lösen keine Push-Nachricht aus.
- Ohne Netz geschriebene Nachrichten werden gesendet, sobald wieder Verbindung da ist.
- Eigene Nachrichten kann jeder löschen, im Admin-Modus alle.
- Fotos: Kamera-Button links neben dem Eingabefeld. Das Handy verkleinert das Foto vor dem Senden (max. 1280 px, ca. 150–300 KB) und entfernt dabei Standort- und Kameradaten. Im Chat erscheint eine kleine Vorschau, antippen zeigt es groß.
- Gespeichert werden die letzten 300 Nachrichten in `data/chat.json` und höchstens 150 Fotos in `data/photos/` (ältere werden automatisch gelöscht). Chat leeren: `data/chat.json` und `data/photos/` löschen.

## Während des Events
- **Jetzt-Übersicht:** Ab 1 Stunde vor Beginn zeigt die Timetable oben pro Stage, was gerade läuft und was als Nächstes kommt, mit der Crew laut Plan. Eine rote Linie markiert die aktuelle Uhrzeit, die Timetable öffnet dort. Vergangene Acts sind blass, laufende als LIVE markiert.
- **Testen vor dem Event:** `https://deine-domain/?jetzt=21:15` tut so, als wäre es am Event-Tag 21:15 Uhr (gilt bis zum Schließen des Tabs). `?jetzt=aus` beendet die Testzeit.
- **Überschneidungen:** Bei zwei zugesagten Acts gleichzeitig schlägt der Act-Dialog eine Aufteilung vor (z. B. erste Hälfte hier, dann Wechsel), mit wem man jeweils dort ist.
- **Kalender:** Unter „Mein Plan“ übernimmt „In Kalender übernehmen“ alle Acts als Termine, mit Wecker vor den Acts mit ✔ (Vorlauf wie `notify_minutes`). Der Wecker kommt vom Handy und klingelt auch ohne Empfang. Auf dem iPhone wird der Plan als Kalender-Abo (`webcal://`) eingebunden, weil Web-Apps dort keine Kalender-Dateien öffnen können: beim Abonnieren „Hinweise entfernen“ ausschalten, Änderungen kommen dann automatisch nach. Android/PC laden eine `.ics`-Datei.
- **Dunkles Design:** folgt automatisch der Handy-Einstellung, im Profil (oben rechts) auch fest auf Hell oder Dunkel stellbar.

## Neues Event
Nur `timetable.json` anpassen (Name, Ort, Datum, Stages, Zeiten), in `sw.js` die `VERSION` hochzählen und für einen frischen Start `data/state.json`, `data/chat.json` und `data/photos/` löschen. Die Erinnerungs-Anmeldungen in `data/push.json` bleiben dabei erhalten.

## Admin-Zugang (Personen entfernen)
1. In `config.php` das Wort `HIER-GEHEIMES-WORT-EINTRAGEN` durch ein eigenes geheimes Wort ersetzen (mind. 8 Zeichen, Buchstaben, Zahlen, -).
2. 5x schnell auf das HIVE-Logo tippen und das Wort eingeben – oder einmal `https://deine-domain/?admin=DEIN-WORT` öffnen. Das Gerät merkt sich den Admin-Modus. Falsche Eingaben werden vom Server um 1 Sekunde gebremst.
3. Unter „Crew“ erscheinen jetzt die Buttons „Entfernen“. Alle anderen sehen sie nicht und der Server lehnt Löschungen ohne Schlüssel ab.
4. „Admin-Modus beenden“ entfernt den Schlüssel wieder vom Gerät.
5. Die Admin-Leiste zeigt außerdem, welche Version auf dem Gerät läuft und ob auf dem Server schon eine neuere liegt („Jetzt aktualisieren“ lädt sie sofort).

## Daten zurücksetzen / sichern
- Sichern: `data/state.json` kopieren.
- Alles zurücksetzen: `data/state.json` löschen.

## Hinweise
- Wer den Link kennt, kann alles sehen und Einträge bearbeiten. Personen entfernen kann nur der Admin.
- Jedes Gerät merkt sich, wer man ist. Auf einem neuen Gerät einfach den eigenen Namen antippen.
- Eigene Farbe ändern: oben rechts aufs Profil tippen. 30 Farben, jede gibt es nur einmal (ab der 31. Person werden Farben doppelt vergeben).
- Die Seite aktualisiert sich alle 9 Sekunden automatisch, bei offenem Chat alle 3 Sekunden.
