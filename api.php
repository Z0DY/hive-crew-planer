<?php
// HIVE Crew-Planer – API mit JSON-Dateispeicher (offline-fähig: Versionsabgleich + Sammel-Updates)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/push_lib.php';
$ADMIN_KEY = (string)(hive_config()['admin_key'] ?? '');
function is_admin($key) {
  global $ADMIN_KEY;
  return strlen($ADMIN_KEY) >= 8 && $ADMIN_KEY !== 'HIER-GEHEIMES-WORT-EINTRAGEN' && hash_equals($ADMIN_KEY, (string)$key);
}
class ApiError extends Exception {}
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE); exit; }
function clean_name($s) { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$s)), 0, 24); }
// Reihenfolge, in der neue Personen Farben bekommen (Index in USER_COLORS, index.html): erst die unterschiedlichsten
const COLOR_ORDER = [0, 11, 5, 1, 15, 9, 19, 24, 3, 29, 12, 7, 18, 2, 14, 21, 4, 10, 23, 27, 17, 6, 13, 22, 26, 8, 20, 16, 25, 28];
function user_exists($state, $uid) { return in_array($uid, array_column($state['users'], 'id'), true); }

// ---------- Chat (eigene Datei data/chat.json, damit Nachrichten nicht den Plan-Stand aufblähen) ----------
// Jede Änderung bekommt eine fortlaufende seq. Geräte holen nur, was nach ihrer letzten seq kam.
// Gelöschte Nachrichten bleiben als {id, seq, del} stehen, damit auch andere Geräte sie entfernen.
const CHAT_KEEP = 300;
function chat_open() {
  $fp = @fopen(__DIR__ . '/data/chat.json', 'c+');
  if (!$fp) throw new Exception('data/chat.json nicht beschreibbar');
  flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  $c = $raw ? json_decode($raw, true) : null;
  if (!is_array($c)) $c = [];
  $c += ['epoch' => bin2hex(random_bytes(4)), 'seq' => 0, 'base' => 0, 'msgs' => []];
  return [$fp, $c];
}
function chat_close($fp, $c) {
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
}
function chat_read() {
  $c = null;
  if ($fp = @fopen(__DIR__ . '/data/chat.json', 'r')) {
    flock($fp, LOCK_SH); $c = json_decode(stream_get_contents($fp), true); flock($fp, LOCK_UN); fclose($fp);
  }
  return is_array($c) ? $c : ['epoch' => '', 'seq' => 0, 'base' => 0, 'msgs' => []];
}
// Änderungen seit $since. Komplett neu, wenn das Gerät einen anderen Chat kennt (Datei gelöscht) oder zu weit zurückliegt.
function chat_delta($c, $since, $epoch) {
  $full = $epoch !== $c['epoch'] || $since < $c['base'] || $since > $c['seq'];
  $msgs = array_filter($c['msgs'], fn($m) => $full ? empty($m['del']) : $m['seq'] > $since);
  return ['epoch' => $c['epoch'], 'seq' => $c['seq'], 'full' => $full, 'msgs' => array_values($msgs)];
}
// ---------- Kalender-Export (.ics) für "Mein Plan" ----------
// Acts mit ✔ bekommen einen Wecker (notify_minutes vorher) – der klingelt auch ohne Empfang. "Vielleicht" ohne Wecker.
function ics_text($s) { return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], (string)$s); }
function ics_fold($line) {   // Zeilen max. 75 Byte, nicht mitten in einem UTF-8-Zeichen trennen
  $out = '';
  while (strlen($line) > 74) {
    $cut = 74; while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
    $out .= substr($line, 0, $cut) . "\r\n "; $line = substr($line, $cut);
  }
  return $out . $line;
}
function ics_calendar($state, $uid) {
  $tt = tt_load();
  if (!$tt || empty($tt['date'])) return null;
  $picks = $state['picks'][$uid] ?? [];
  $minutes = (int)(hive_config()['notify_minutes'] ?? 15);
  $names = array_column($state['users'], 'name', 'id');
  $stamp = gmdate('Ymd\THis\Z');
  $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//HIVE Crew-Planer//DE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'X-WR-CALNAME:' . ics_text($tt['title'] ?? 'Event'),
        'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H'];   // als Abo (iPhone): stündlich aktualisieren
  foreach (tt_sets($tt) as $id => $s) {
    $p = $picks[$id] ?? null;
    if (!$p || !$s['ts']) continue;
    $dur = strtotime('2000-01-01 ' . $s['end']) - strtotime('2000-01-01 ' . $s['start']);
    if ($dur <= 0) $dur += 86400;   // über Mitternacht
    $with = [];
    foreach ($state['picks'] as $other => $pp) if ($other !== $uid && ($pp[$id] ?? null) === 'yes' && isset($names[$other])) $with[] = $names[$other];
    array_push($L, 'BEGIN:VEVENT', "UID:$uid-$id@hive-crew-planer", "DTSTAMP:$stamp",
      'DTSTART:' . gmdate('Ymd\THis\Z', $s['ts']), 'DTEND:' . gmdate('Ymd\THis\Z', $s['ts'] + $dur),
      'SUMMARY:' . ics_text(($p === 'maybe' ? '(vielleicht) ' : '') . $s['artist']),
      'LOCATION:' . ics_text($s['stage'] . (!empty($tt['location']) ? ', ' . $tt['location'] : '')),
      'DESCRIPTION:' . ics_text($with ? 'Mit: ' . implode(', ', $with) : 'Bisher gehst du allein hin'));
    if ($p === 'yes') array_push($L, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_text($s['artist']), "TRIGGER:-PT{$minutes}M", 'END:VALARM');
    $L[] = 'END:VEVENT';
  }
  $L[] = 'END:VCALENDAR';
  return implode("\r\n", array_map('ics_fold', $L)) . "\r\n";
}

// Fotos: data/photos/<id>.jpg (max. 1280 px) und <id>_t.jpg (Vorschau). Das Handy verkleinert vorher.
const PHOTO_KEEP = 150;
function photo_path($id, $thumb = false) { return __DIR__ . '/data/photos/' . $id . ($thumb ? '_t' : '') . '.jpg'; }
function photo_delete($id) { @unlink(photo_path($id)); @unlink(photo_path($id, true)); }
function photo_decode($b64, $max) {   // nur echte JPEG-Bilder annehmen
  $bin = base64_decode((string)$b64, true);
  if ($bin === false || strlen($bin) > $max || substr($bin, 0, 3) !== "\xFF\xD8\xFF") return null;
  $info = @getimagesizefromstring($bin);
  if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] > 4096 || $info[1] > 4096) return null;
  return [$bin, $info[0], $info[1]];
}
function clean_text($s) {
  $s = preg_replace('/[^\P{Cc}\n]/u', '', str_replace(["\r\n", "\r"], "\n", (string)$s)) ?? '';   // Steuerzeichen raus, Zeilenumbrüche bleiben
  return mb_substr(preg_replace("/\n{3,}/", "\n\n", trim($s)), 0, 500);
}
// Wer wird mit @Name erwähnt? Längere Namen zuerst, damit "@Max Müller" nicht zusätzlich "Max" trifft.
function chat_mentions($state, $text, $uid) {
  if (preg_match('/@alle(?![\p{L}\p{N}])/iu', $text))   // @alle: die ganze Crew außer dem Absender
    return array_values(array_filter(array_column($state['users'], 'id'), fn($id) => $id !== $uid));
  $users = $state['users'];
  usort($users, fn($a, $b) => mb_strlen($b['name']) - mb_strlen($a['name']));
  $at = [];
  foreach ($users as $u) {
    $re = '/@' . preg_quote($u['name'], '/') . '(?![\p{L}\p{N}])/iu';
    if (!preg_match($re, $text)) continue;
    if ($u['id'] !== $uid) $at[] = $u['id'];
    $text = preg_replace($re, ' ', $text);
  }
  return $at;
}
function chat_say(&$c, $state, $in) {
  $uid = preg_replace('/[^a-z0-9]/', '', (string)($in['user'] ?? ''));
  $user = null;
  foreach ($state['users'] as $u) if ($u['id'] === $uid) $user = $u;
  if (!$user) throw new ApiError('Person nicht gefunden.', 404);
  $id = substr(preg_replace('/[^a-z0-9]/', '', (string)($in['id'] ?? '')), 0, 40);
  if (strlen($id) < 6) throw new ApiError('Nachricht ohne Kennung.');
  foreach ($c['msgs'] as $m) if ($m['id'] === $id) return null;   // schon angekommen (Warteschlange erneut gesendet)
  $text = clean_text($in['text'] ?? '');
  $photo = null;
  if (!empty($in['photo'])) {
    $full = photo_decode($in['photo'], 1500000); $thumb = photo_decode($in['thumb'] ?? '', 200000);
    if (!$full || !$thumb) throw new ApiError('Foto ungültig.');
    if (!is_dir(dirname(photo_path($id)))) @mkdir(dirname(photo_path($id)), 0775, true);
    if (@file_put_contents(photo_path($id), $full[0]) === false || @file_put_contents(photo_path($id, true), $thumb[0]) === false)
      throw new Exception('data/photos nicht beschreibbar');
    $photo = [$full[1], $full[2]];
  }
  if ($text === '' && !$photo) throw new ApiError('Leere Nachricht.');
  $msg = ['id' => $id, 'seq' => ++$c['seq'], 'user' => $uid, 'name' => $user['name'], 'text' => $text, 'ts' => time()];
  if ($photo) $msg['photo'] = $photo;
  if ($at = chat_mentions($state, $text, $uid)) $msg['at'] = $at;
  $c['msgs'][] = $msg;
  while (count($c['msgs']) > CHAT_KEEP) {
    $old = array_shift($c['msgs']); $c['base'] = max($c['base'], $old['seq']);
    if (!empty($old['photo'])) photo_delete($old['id']);
  }
  // Zu viele Fotos: die ältesten entfernen, die Nachricht bleibt mit Hinweis stehen
  $withPhoto = array_keys(array_filter($c['msgs'], fn($m) => !empty($m['photo'])));
  foreach (array_slice($withPhoto, 0, max(0, count($withPhoto) - PHOTO_KEEP)) as $k) {
    photo_delete($c['msgs'][$k]['id']);
    unset($c['msgs'][$k]['photo']); $c['msgs'][$k]['gone'] = 1; $c['msgs'][$k]['seq'] = ++$c['seq'];
  }
  return $msg;
}
function chat_unsay(&$c, $in) {
  $uid = preg_replace('/[^a-z0-9]/', '', (string)($in['user'] ?? ''));
  $id = preg_replace('/[^a-z0-9]/', '', (string)($in['id'] ?? ''));
  foreach ($c['msgs'] as &$m) {
    if ($m['id'] !== $id || !empty($m['del'])) continue;
    if ($m['user'] !== $uid && !is_admin($in['key'] ?? '')) throw new ApiError('Du kannst nur deine eigenen Nachrichten löschen.', 403);
    if (!empty($m['photo'])) photo_delete($id);
    $m = ['id' => $id, 'seq' => ++$c['seq'], 'del' => 1];
    return;
  }
}
function chat_push_message($m) {
  $text = mb_strlen($m['text']) > 160 ? mb_substr($m['text'], 0, 159) . '…' : $m['text'];
  if (!empty($m['photo'])) $text = '📷 Foto' . ($text !== '' ? ': ' . $text : '');
  return ['title' => '💬 ' . $m['name'] . ' im Crew-Chat', 'body' => $text, 'tag' => 'chat', 'url' => './#chat'];
}

// ---------- Lageplan (Karte) ----------
// Der Admin lädt ihn als JPEG hoch. Jede Version bekommt eine neue Kennung v, die Datei heißt danach -> lange cachen.
// Positionen: $state['pos'][uid] = [x, y, Zeit] mit x/y als Anteil von Bildbreite/-höhe (0–1).
const MAP_MAX = 4000000;   // Byte, entspricht ca. 5,3 MB base64
const POS_MAX_AGE = 3 * 3600;   // ältere Positionen aus der Offline-Warteschlange verwerfen
function map_path($v) { return __DIR__ . '/data/map-' . $v . '.jpg'; }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['map'])) {
  $v = preg_replace('/[^a-z0-9]/', '', (string)$_GET['map']);
  if ($v === '' || !is_file(map_path($v))) { http_response_code(404); exit; }
  header('Content-Type: image/jpeg');
  header('Cache-Control: public, max-age=31536000, immutable');
  header('X-Content-Type-Options: nosniff');
  header('Content-Length: ' . filesize(map_path($v)));
  readfile(map_path($v)); exit;
}

// Foto ausliefern (ohne den Plan-Speicher zu sperren). Name = Nachrichten-ID, ändert sich nie -> lange cachen.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['photo'])) {
  $pid = preg_replace('/[^a-z0-9]/', '', (string)$_GET['photo']);
  $f = photo_path($pid, isset($_GET['thumb']));
  if ($pid === '' || !is_file($f)) { http_response_code(404); exit; }
  header('Content-Type: image/jpeg');
  header('Cache-Control: public, max-age=31536000, immutable');
  header('X-Content-Type-Options: nosniff');
  header('Content-Length: ' . filesize($f));
  readfile($f); exit;
}

$file = __DIR__ . '/data/state.json';
if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
$fp = @fopen($file, 'c+');
if (!$fp) fail('Speicher nicht beschreibbar – Schreibrechte für den Ordner "data" prüfen.', 500);
flock($fp, LOCK_EX);
$raw = stream_get_contents($fp);
$state = $raw ? json_decode($raw, true) : null;
if (!is_array($state)) $state = ['rev' => 0, 'users' => [], 'picks' => []];
if (!is_array($state['picks'])) $state['picks'] = [];
// Einmalig: Farbnummern aus der alten 12er-Palette auf die neue Reihenfolge umstellen (sonst lauter Nachbarfarben)
if (($state['palette'] ?? 0) < 2) {
  foreach ($state['users'] as &$u) $u['color'] = COLOR_ORDER[($u['color'] ?? 0) % count(COLOR_ORDER)];
  unset($u);
  $state['palette'] = 2; $state['rev']++;
  $out = $state; if (empty($out['picks'])) $out['picks'] = new stdClass();
  ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); fflush($fp);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['vapid'])) {
  flock($fp, LOCK_UN); fclose($fp);
  try { [$pfp, $pd] = push_open(); push_close($pfp, $pd); }
  catch (Exception $e) { fail('Benachrichtigungen nicht verfügbar: ' . $e->getMessage(), 500); }
  echo json_encode(['publicKey' => $pd['vapid']['public'], 'minutes' => (int)(hive_config()['notify_minutes'] ?? 15)]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  flock($fp, LOCK_UN); fclose($fp);
  if (isset($_GET['ics'])) {
    $uid = preg_replace('/[^a-z0-9]/', '', (string)$_GET['ics']);
    if (!user_exists($state, $uid)) fail('Person nicht gefunden.', 404);
    $ics = ics_calendar($state, $uid);
    if ($ics === null) fail('In timetable.json fehlt das Event-Datum.', 404);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="hive-plan.ics"');
    echo $ics; exit;
  }
  $out = isset($_GET['rev']) && (int)$_GET['rev'] === (int)$state['rev'] ? ['unchanged' => true, 'rev' => $state['rev']] : $state + ['serverTime' => time()];
  if (isset($_GET['chat'])) $out['chat'] = chat_delta(chat_read(), (int)$_GET['chat'], (string)($_GET['epoch'] ?? ''));
  echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}

function apply_op(&$state, $in) {
  $uid = preg_replace('/[^a-z0-9]/', '', (string)($in['user'] ?? ''));
  switch ($in['action'] ?? '') {
    case 'join':
      $name = clean_name($in['name'] ?? '');
      if ($name === '') throw new ApiError('Bitte einen Namen eingeben.');
      foreach ($state['users'] as $u) if (mb_strtolower($u['name']) === mb_strtolower($name)) throw new ApiError('Diesen Namen gibt es schon – tippe ihn in der Liste an.');
      if (count($state['users']) >= 40) throw new ApiError('Maximal 40 Personen.');
      $id = bin2hex(random_bytes(5));
      // erste freie Farbe in gestreuter Reihenfolge; sind alle 30 vergeben, die am seltensten genutzte
      $use = array_count_values(array_column($state['users'], 'color'));
      $color = COLOR_ORDER[0];
      foreach (COLOR_ORDER as $c) if (($use[$c] ?? 0) < ($use[$color] ?? 0)) $color = $c;
      $state['users'][] = ['id' => $id, 'name' => $name, 'color' => $color];
      return ['id' => $id];
    case 'rename':
      $name = clean_name($in['name'] ?? '');
      if ($name === '') throw new ApiError('Bitte einen Namen eingeben.');
      foreach ($state['users'] as &$u) if ($u['id'] === $uid) { $u['name'] = $name; return ['ok' => true]; }
      throw new ApiError('Person nicht gefunden.', 404);
    case 'color':   // eigene Farbe wählen (0–29, siehe USER_COLORS in index.html), jede Farbe gibt es nur einmal
      $color = (int)($in['color'] ?? -1);
      if ($color < 0 || $color >= count(COLOR_ORDER)) throw new ApiError('Unbekannte Farbe.');
      foreach ($state['users'] as $u) if ($u['id'] !== $uid && ($u['color'] ?? null) === $color) throw new ApiError('Diese Farbe hat schon ' . $u['name'] . '.', 409);
      foreach ($state['users'] as &$u) if ($u['id'] === $uid) { $u['color'] = $color; return ['ok' => true]; }
      throw new ApiError('Person nicht gefunden.', 404);
    case 'delete':
      if (!is_admin($in['key'] ?? '')) throw new ApiError('Nur der Admin kann Personen entfernen.', 403);
      $state['users'] = array_values(array_filter($state['users'], fn($u) => $u['id'] !== $uid));
      unset($state['picks'][$uid], $state['pos'][$uid]);
      return ['ok' => true];
    case 'pos':   // eigener Standort auf dem Lageplan; ohne x/y = ausblenden
      if (!user_exists($state, $uid)) throw new ApiError('Person nicht gefunden.', 404);
      if (!isset($in['x'], $in['y'])) { unset($state['pos'][$uid]); return ['ok' => true]; }
      $x = (float)$in['x']; $y = (float)$in['y'];
      if (empty($state['map']) || $x < 0 || $x > 1 || $y < 0 || $y > 1) throw new ApiError('Position außerhalb des Lageplans.');
      // Zeitpunkt des Antippens: aus der Offline-Warteschlange kommt die Position evtl. erst viel später an
      $now = time(); $t = min((int)($in['t'] ?? $now), $now);
      if ($t < $now - POS_MAX_AGE || ($state['pos'][$uid][2] ?? 0) > $t) return ['ok' => true];   // zu alt oder schon eine neuere da
      $state['pos'][$uid] = [round($x, 4), round($y, 4), $t];
      return ['ok' => true];
    case 'map':   // Lageplan hochladen oder entfernen (Admin). Alte Positionen passen dann nicht mehr und werden gelöscht.
      if (!is_admin($in['key'] ?? '')) throw new ApiError('Nur der Admin kann den Lageplan ändern.', 403);
      $old = $state['map']['v'] ?? null;
      unset($state['map'], $state['pos']);
      if (!empty($in['photo'])) {
        $img = photo_decode($in['photo'], MAP_MAX);
        if (!$img) throw new ApiError('Bild ungültig oder zu groß.');
        $v = bin2hex(random_bytes(4));
        if (@file_put_contents(map_path($v), $img[0]) === false) throw new Exception('data/ nicht beschreibbar');
        $state['map'] = ['v' => $v, 'w' => $img[1], 'h' => $img[2]];
      }
      if ($old) @unlink(map_path($old));
      return ['ok' => true];
    case 'pick':
      $set = preg_replace('/[^a-z0-9-]/', '', (string)($in['set'] ?? ''));
      if ($set === '') throw new ApiError('Act fehlt.');
      if (!user_exists($state, $uid)) throw new ApiError('Person nicht gefunden.', 404);
      $status = $in['status'] ?? '';
      if ($status === 'yes' || $status === 'maybe') $state['picks'][$uid][$set] = $status;
      else unset($state['picks'][$uid][$set]);
      if (empty($state['picks'][$uid])) unset($state['picks'][$uid]);
      return ['ok' => true];
    default:
      throw new ApiError('Unbekannte Aktion.');
  }
}

function push_action(&$state, &$pd, $in) {
  $uid = preg_replace('/[^a-z0-9]/', '', (string)($in['user'] ?? ''));
  switch ($in['action']) {
    case 'subscribe':
      $sub = $in['sub'] ?? [];
      $ep = (string)($sub['endpoint'] ?? '');
      if (!preg_match('#^https://#', $ep) || strlen($ep) > 1000) throw new ApiError('Ungültiges Abo.');
      if (!user_exists($state, $uid)) throw new ApiError('Person nicht gefunden.', 404);
      $id = substr(sha1($ep), 0, 12);
      $pd['subs'] = array_values(array_filter($pd['subs'], fn($s) => $s['id'] !== $id));
      $pd['subs'][] = ['id' => $id, 'user' => $uid, 'endpoint' => $ep,
        'p256dh' => (string)($sub['keys']['p256dh'] ?? ''), 'auth' => (string)($sub['keys']['auth'] ?? ''), 'created' => time()];
      return ['ok' => true];
    case 'unsubscribe':
      $id = substr(sha1((string)($in['endpoint'] ?? '')), 0, 12);
      $pd['subs'] = array_values(array_filter($pd['subs'], fn($s) => $s['id'] !== $id));
      return ['ok' => true];
    case 'testpush':
      if (!is_admin($in['key'] ?? '')) throw new ApiError('Nur der Admin kann Tests senden.', 403);
      $target = ($in['target'] ?? 'me') === 'all' ? 'all' : 'me';
      $subs = $target === 'all' ? $pd['subs'] : array_values(array_filter($pd['subs'], fn($s) => $s['user'] === $uid));
      if (!$subs) throw new ApiError($target === 'me'
        ? 'Auf deinen Geräten sind keine Erinnerungen aktiviert. Aktiviere sie zuerst unter „Mein Plan“.'
        : 'Bisher hat niemand Erinnerungen aktiviert.');
      $tt = tt_load(); $sets = $tt ? tt_sets($tt) : [];
      uasort($sets, fn($a, $b) => strcmp($a['order'], $b['order']));
      $kind = ($in['kind'] ?? 'simple') === 'reminder' ? 'reminder' : 'simple';
      $minutes = (int)(hive_config()['notify_minutes'] ?? 15);
      $r = push_many($pd, $subs, function ($sub) use ($kind, $sets, $state, $minutes) {
        if ($kind === 'simple') return ['title' => 'Test: Benachrichtigungen funktionieren', 'body' => 'Du bekommst Erinnerungen ' . $minutes . ' Min. vor deinen Acts.', 'tag' => 'test', 'url' => './'];
        $picks = $state['picks'][$sub['user']] ?? [];
        $set = null;
        foreach ($sets as $s) if (($picks[$s['id']] ?? null) === 'yes') { $set = $s; break; }
        if (!$set) $set = reset($sets);
        return $set ? reminder_message($set, $state, $sub['user'], $minutes, 'TEST: ') : null;
      }, 300);
      $r['people'] = count(array_unique(array_column($subs, 'user')));
      return ['report' => $r];
  }
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';
$changed = true;
$cfp = null; $chat = null; $chatChanged = false; $mentions = [];
$openChat = function () use (&$cfp, &$chat) { if (!$cfp) [$cfp, $chat] = chat_open(); };   // Sperre: erst state.json, dann chat.json
try {
  if ($action === 'unsay') {
    $openChat(); chat_unsay($chat, $in);
    $chatChanged = true; $result = ['ok' => true]; $changed = false;
  } elseif ($action === 'checkadmin') {
    if (!is_admin($in['key'] ?? '')) throw new ApiError('Admin-Schlüssel ungültig.', 403);
    $result = ['ok' => true]; $changed = false;
  } elseif ($action === 'subscribe' || $action === 'unsubscribe' || $action === 'testpush') {
    [$pfp, $pd] = push_open();
    try { $result = push_action($state, $pd, $in); }
    finally { $state['notify'] = push_users($pd); push_close($pfp, $pd); }
  } elseif ($action === 'batch') {
    // Sammel-Update aus der Offline-Warteschlange: nur pick/rename/pos/say, fehlerhafte Einträge werden übersprungen
    $done = []; $skipped = []; $changed = false;
    foreach (array_slice((array)($in['ops'] ?? []), 0, 500) as $op) {
      $oid = substr(preg_replace('/[^a-zA-Z0-9-]/', '', (string)($op['id'] ?? '')), 0, 40);
      $act = $op['action'] ?? '';
      if (!in_array($act, ['pick', 'rename', 'pos', 'say'], true)) { $skipped[] = $oid; continue; }
      try {
        if ($act === 'say') {
          $openChat();
          if ($m = chat_say($chat, $state, $op)) { $chatChanged = true; if (!empty($m['at'])) $mentions[] = $m; }
        } else { apply_op($state, $op); $changed = true; }
        $done[] = $oid;
      } catch (ApiError $e) { $skipped[] = $oid; }
    }
    $result = ['done' => $done, 'skipped' => $skipped];
  } else {
    $result = apply_op($state, $in);
  }
  if ($action === 'delete') {   // Abos einer entfernten Person mit löschen
    $uidDel = preg_replace('/[^a-z0-9]/', '', (string)($in['user'] ?? ''));
    [$pfp, $pd] = push_open();
    $pd['subs'] = array_values(array_filter($pd['subs'], fn($s) => $s['user'] !== $uidDel));
    $state['notify'] = push_users($pd); push_close($pfp, $pd);
  }
} catch (ApiError $e) {
  if ($cfp) { flock($cfp, LOCK_UN); fclose($cfp); }
  flock($fp, LOCK_UN); fclose($fp);
  if ($e->getCode() === 403) sleep(1);   // falscher Admin-Schlüssel: Durchprobieren ausbremsen (erst nach dem Entsperren)
  fail($e->getMessage(), $e->getCode() ?: 400);
} catch (Exception $e) {
  if ($cfp) { flock($cfp, LOCK_UN); fclose($cfp); }
  flock($fp, LOCK_UN); fclose($fp);
  fail('Serverfehler: ' . $e->getMessage(), 500);
}

if ($changed) {
  $state['rev']++;
  $out = $state; if (empty($out['picks'])) $out['picks'] = new stdClass();
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  fflush($fp);
}
if ($cfp) { if ($chatChanged) chat_close($cfp, $chat); else { flock($cfp, LOCK_UN); fclose($cfp); } }
flock($fp, LOCK_UN); fclose($fp);
$result['state'] = $state + ['serverTime' => time()];
if (empty($result['state']['picks'])) $result['state']['picks'] = new stdClass();
if (array_key_exists('chat', $in)) $result['chat'] = chat_delta($chat ?? chat_read(), (int)$in['chat'], (string)($in['epoch'] ?? ''));
echo json_encode($result, JSON_UNESCAPED_UNICODE);

// Erwähnte Personen benachrichtigen – erst nach der Antwort, damit der Absender nicht auf Apple/Google warten muss
if ($mentions) {
  ignore_user_abort(true);
  if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
  try {
    [$pfp, $pd] = push_open();
    foreach ($mentions as $m) {
      $subs = array_values(array_filter($pd['subs'], fn($s) => in_array($s['user'], $m['at'], true)));
      if ($subs) push_many($pd, $subs, fn() => chat_push_message($m), 3600);
    }
    push_close($pfp, $pd);
  } catch (Exception $e) {}
}
