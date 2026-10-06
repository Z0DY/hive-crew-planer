<?php
// HIVE Crew-Planer – Erinnerungen versenden. Läuft jede Minute per Cron (siehe README).
// Schickt jedem, der bei einem Act ✔ gesetzt hat, eine Push-Nachricht X Minuten vor Beginn.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/push_lib.php';

$cfg = hive_config();
$lead = (int)($cfg['notify_minutes'] ?? 15);
$withMaybe = !empty($cfg['notify_maybe']);
$verbose = in_array('-v', $argv, true);
$log = function ($m) use ($verbose) { if ($verbose) echo date('H:i:s') . " $m\n"; };

$tt = tt_load();
if ($tt) date_default_timezone_set($tt['timezone'] ?? 'Europe/Berlin');
if (!$tt || empty($tt['date'])) { $log('Kein Event-Datum in timetable.json – nichts zu tun.'); exit(0); }
$now = time();
$due = array_filter(tt_sets($tt), fn($s) => $s['ts'] && $s['ts'] - $lead * 60 <= $now && $now < $s['ts']);
if (!$due) { $log('Keine Acts im Erinnerungsfenster.'); exit(0); }

// Reihenfolge der Sperren wie in api.php: erst state.json, dann push.json
$sfp = fopen(__DIR__ . '/data/state.json', 'c+'); flock($sfp, LOCK_EX);
$state = json_decode(stream_get_contents($sfp), true) ?: ['rev' => 0, 'users' => [], 'picks' => []];
if (!is_array($state['picks'] ?? null)) $state['picks'] = [];
[$pfp, $pd] = push_open();

$before = push_users($pd);
$total = ['devices' => 0, 'ok' => 0, 'failed' => 0, 'removed' => 0];
foreach ($due as $set) {
  $subs = array_filter($pd['subs'], function ($s) use ($state, $set, $pd, $withMaybe) {
    $p = $state['picks'][$s['user']][$set['id']] ?? null;
    return ($p === 'yes' || ($withMaybe && $p === 'maybe')) && !isset($pd['sent'][$s['id'] . '|' . $set['id']]);
  });
  if (!$subs) continue;
  $mins = max(1, (int)round(($set['ts'] - $now) / 60));
  $ok = 0; $dead = [];
  foreach ($subs as $sub) {
    $st = push_send($sub, reminder_message($set, $state, $sub['user'], $mins), $pd['vapid'], max(60, $set['ts'] - $now));
    $total['devices']++;
    if ($st >= 200 && $st < 300) { $ok++; $total['ok']++; }
    else $total['failed']++;
    if ($st === 404 || $st === 410) $dead[] = $sub['id'];
    // Erfolg oder endgültiger Fehler: erledigt. Keine Verbindung / Serverfehler / 429: nächste Minute erneut versuchen
    if (($st >= 200 && $st < 300) || ($st >= 400 && $st < 500 && $st !== 429)) $pd['sent'][$sub['id'] . '|' . $set['id']] = $now;
    else $log("{$set['artist']}: Gerät nicht erreichbar (Status $st), neuer Versuch beim nächsten Lauf");
  }
  if ($dead) { $pd['subs'] = array_values(array_filter($pd['subs'], fn($s) => !in_array($s['id'], $dead, true))); $total['removed'] += count($dead); }
  $log("{$set['artist']}: $ok/" . count($subs) . " gesendet");
}
$pd['sent'] = array_filter($pd['sent'], fn($t) => $t > $now - 3 * 86400);   // alte Einträge aufräumen

$after = push_users($pd);
if ($after !== $before) {   // abgelaufene Abos entfernt -> Glocken in der Crew-Ansicht aktualisieren
  $state['notify'] = $after; $state['rev']++;
  if (empty($state['picks'])) $state['picks'] = new stdClass();
  ftruncate($sfp, 0); rewind($sfp); fwrite($sfp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); fflush($sfp);
}
push_close($pfp, $pd);
flock($sfp, LOCK_UN); fclose($sfp);
$log("Fertig: {$total['ok']} gesendet, {$total['failed']} fehlgeschlagen, {$total['removed']} Abos entfernt.");
