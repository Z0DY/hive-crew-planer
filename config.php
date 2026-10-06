<?php
// Einstellungen für den HIVE Crew-Planer. Diese Datei wird bei App-Updates NICHT überschrieben.
return [
  // Admin-Zugang: eigenes geheimes Wort (mind. 8 Zeichen, Buchstaben, Zahlen, -).
  // Aktivieren: einmal https://deine-domain/?admin=DEIN-WORT öffnen.
  'admin_key' => 'HIER-GEHEIMES-WORT-EINTRAGEN',

  // Erinnerungen: wie viele Minuten vor Beginn eines Acts?
  'notify_minutes' => 15,
  // Auch bei "vielleicht" erinnern? (true/false)
  'notify_maybe' => false,
  // Kontaktadresse für die Push-Dienste von Apple/Google (Pflichtangabe, wird nicht veröffentlicht)
  'contact' => 'mailto:hive@z0dy.de',
];
