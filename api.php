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
function user_exists($state, $uid) { return in_array($uid, array_column($state['users'], 'id'), true); }

$file = __DIR__ . '/data/state.json';
if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
$fp = @fopen($file, 'c+');
if (!$fp) fail('Speicher nicht beschreibbar – Schreibrechte für den Ordner "data" prüfen.', 500);
flock($fp, LOCK_EX);
$raw = stream_get_contents($fp);
$state = $raw ? json_decode($raw, true) : null;
if (!is_array($state)) $state = ['rev' => 0, 'users' => [], 'picks' => []];
if (!is_array($state['picks'])) $state['picks'] = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['vapid'])) {
  flock($fp, LOCK_UN); fclose($fp);
  try { [$pfp, $pd] = push_open(); push_close($pfp, $pd); }
  catch (Exception $e) { fail('Benachrichtigungen nicht verfügbar: ' . $e->getMessage(), 500); }
  echo json_encode(['publicKey' => $pd['vapid']['public'], 'minutes' => (int)(hive_config()['notify_minutes'] ?? 15)]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  flock($fp, LOCK_UN); fclose($fp);
  if (isset($_GET['rev']) && (int)$_GET['rev'] === (int)$state['rev']) { echo json_encode(['unchanged' => true, 'rev' => $state['rev']]); exit; }
  echo json_encode($state + ['serverTime' => time()], JSON_UNESCAPED_UNICODE); exit;
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
      $used = array_column($state['users'], 'color'); $color = 0;
      while (in_array($color, $used, true) && $color < 40) $color++;
      $state['users'][] = ['id' => $id, 'name' => $name, 'color' => $color];
      return ['id' => $id];
    case 'rename':
      $name = clean_name($in['name'] ?? '');
      if ($name === '') throw new ApiError('Bitte einen Namen eingeben.');
      foreach ($state['users'] as &$u) if ($u['id'] === $uid) { $u['name'] = $name; return ['ok' => true]; }
      throw new ApiError('Person nicht gefunden.', 404);
    case 'delete':
      if (!is_admin($in['key'] ?? '')) throw new ApiError('Nur der Admin kann Personen entfernen.', 403);
      $state['users'] = array_values(array_filter($state['users'], fn($u) => $u['id'] !== $uid));
      unset($state['picks'][$uid]);
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
try {
  if ($action === 'checkadmin') {
    if (!is_admin($in['key'] ?? '')) throw new ApiError('Admin-Schlüssel ungültig.', 403);
    $result = ['ok' => true]; $changed = false;
  } elseif ($action === 'subscribe' || $action === 'unsubscribe' || $action === 'testpush') {
    [$pfp, $pd] = push_open();
    try { $result = push_action($state, $pd, $in); }
    finally { $state['notify'] = push_users($pd); push_close($pfp, $pd); }
  } elseif ($action === 'batch') {
    // Sammel-Update aus der Offline-Warteschlange: nur pick/rename, fehlerhafte Einträge werden übersprungen
    $done = []; $skipped = [];
    foreach (array_slice((array)($in['ops'] ?? []), 0, 500) as $op) {
      $oid = substr(preg_replace('/[^a-zA-Z0-9-]/', '', (string)($op['id'] ?? '')), 0, 40);
      if (!in_array($op['action'] ?? '', ['pick', 'rename'], true)) { $skipped[] = $oid; continue; }
      try { apply_op($state, $op); $done[] = $oid; } catch (ApiError $e) { $skipped[] = $oid; }
    }
    $result = ['done' => $done, 'skipped' => $skipped]; $changed = count($done) > 0;
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
  flock($fp, LOCK_UN); fclose($fp);
  if ($e->getCode() === 403) sleep(1);   // falscher Admin-Schlüssel: Durchprobieren ausbremsen (erst nach dem Entsperren)
  fail($e->getMessage(), $e->getCode() ?: 400);
} catch (Exception $e) {
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
flock($fp, LOCK_UN); fclose($fp);
$result['state'] = $state + ['serverTime' => time()];
if (empty($result['state']['picks'])) $result['state']['picks'] = new stdClass();
echo json_encode($result, JSON_UNESCAPED_UNICODE);
