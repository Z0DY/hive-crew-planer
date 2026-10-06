<?php
// HIVE Crew-Planer – Web Push ohne externe Bibliotheken
// VAPID (RFC 8292) + Verschlüsselung aes128gcm (RFC 8291). Benötigt nur die PHP-Erweiterung openssl.
// Diese Datei definiert nur Funktionen und gibt beim direkten Aufruf nichts aus.

function hive_config() {
  static $cfg = null;
  if ($cfg === null) { $c = is_file(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : []; $cfg = is_array($c) ? $c : []; }
  return $cfg;
}
function b64u_enc($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec($s) { $s = strtr((string)$s, '-_', '+/'); return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

// ---------- Timetable ----------
function tt_load() {
  $tt = json_decode((string)@file_get_contents(__DIR__ . '/timetable.json'), true);
  return is_array($tt) ? $tt : null;
}
// Liefert alle Sets mit Startzeit als Unix-Zeitstempel (wenn ein Datum gesetzt ist)
function tt_sets($tt) {
  $out = [];
  $tz = new DateTimeZone($tt['timezone'] ?? 'Europe/Berlin');
  $dayStart = $tt['dayStart'] ?? '15:00';
  foreach ($tt['stages'] ?? [] as $st) {
    foreach ($st['sets'] as $i => $s) {
      [$a, $b, $artist] = $s;
      $ts = null;
      if (!empty($tt['date'])) {
        $d = DateTime::createFromFormat('Y-m-d H:i', $tt['date'] . ' ' . $a, $tz);
        if ($d && strcmp($a, $dayStart) < 0) $d->modify('+1 day');
        $ts = $d ? $d->getTimestamp() : null;
      }
      $out[$st['id'] . '-' . ($i + 1)] = ['id' => $st['id'] . '-' . ($i + 1), 'stage' => $st['name'], 'start' => $a, 'end' => $b, 'artist' => $artist, 'ts' => $ts, 'order' => strcmp($a, $dayStart) < 0 ? '1' . $a : '0' . $a];
    }
  }
  return $out;
}

// ---------- Speicher für Abos (data/push.json) ----------
function push_open() {
  $fp = @fopen(__DIR__ . '/data/push.json', 'c+');
  if (!$fp) throw new Exception('data/push.json nicht beschreibbar');
  flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  $d = $raw ? json_decode($raw, true) : null;
  if (!is_array($d)) $d = [];
  $d += ['vapid' => null, 'subs' => [], 'sent' => []];
  if (!$d['vapid']) $d['vapid'] = vapid_generate();
  return [$fp, $d];
}
function push_close($fp, $d) {
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
}
function push_users($d) { return array_values(array_unique(array_column($d['subs'], 'user'))); }

// ---------- Kryptografie ----------
function ec_new() {
  $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  if (!$k) throw new Exception('openssl: EC-Schlüssel konnte nicht erzeugt werden');
  return $k;
}
function ec_pub_raw($k) {
  $e = openssl_pkey_get_details($k)['ec'];
  return "\x04" . str_pad($e['x'], 32, "\0", STR_PAD_LEFT) . str_pad($e['y'], 32, "\0", STR_PAD_LEFT);
}
function ec_pub_from_raw($raw) {
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
  return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}
function vapid_generate() {
  $k = ec_new(); openssl_pkey_export($k, $pem);
  return ['private' => $pem, 'public' => b64u_enc(ec_pub_raw($k))];
}
function der_to_raw_sig($der) {             // ECDSA-Signatur DER -> r||s (je 32 Byte)
  $p = 2;
  if (ord($der[1]) & 0x80) $p += ord($der[1]) & 0x7f;
  $p++; $lr = ord($der[$p++]); $r = substr($der, $p, $lr); $p += $lr;
  $p++; $ls = ord($der[$p++]); $s = substr($der, $p, $ls);
  return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}
function vapid_auth($endpoint, $vapid) {
  $u = parse_url($endpoint);
  $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
  $contact = hive_config()['contact'] ?? 'mailto:hive@z0dy.de';
  $h = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
  $c = b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $contact], JSON_UNESCAPED_SLASHES));
  openssl_sign("$h.$c", $sig, openssl_pkey_get_private($vapid['private']), OPENSSL_ALGO_SHA256);
  return "vapid t=$h.$c." . b64u_enc(der_to_raw_sig($sig)) . ', k=' . $vapid['public'];
}
function push_encrypt($payload, $p256dh, $auth) {
  $ua = b64u_dec($p256dh); $authSecret = b64u_dec($auth);
  if (strlen($ua) !== 65 || strlen($authSecret) < 16) throw new Exception('Ungültige Abo-Schlüssel');
  $eph = ec_new(); $as = ec_pub_raw($eph);
  $ecdh = openssl_pkey_derive(ec_pub_from_raw($ua), $eph, 32);
  if ($ecdh === false) throw new Exception('ECDH fehlgeschlagen');
  $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
  $ikm = hash_hmac('sha256', "WebPush: info\0" . $ua . $as . "\x01", $prkKey, true);
  $salt = random_bytes(16);
  $prk = hash_hmac('sha256', $ikm, $salt, true);
  $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
  $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
  $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
  return $salt . pack('N', 4096) . chr(65) . $as . $ct . $tag;
}

// Sendet eine Nachricht an ein Abo. Rückgabe: HTTP-Status (201 = ok, 404/410 = Abo ungültig, 0 = keine Verbindung)
function push_send($sub, array $msg, $vapid, $ttl = 900) {
  try { $body = push_encrypt(json_encode($msg, JSON_UNESCAPED_UNICODE), $sub['p256dh'], $sub['auth']); }
  catch (Exception $e) { return 400; }
  $headers = [
    'Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm',
    'TTL: ' . (int)$ttl, 'Urgency: high', 'Authorization: ' . vapid_auth($sub['endpoint'], $vapid),
    'Content-Length: ' . strlen($body),
  ];
  $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'ignore_errors' => true, 'timeout' => 10]]);
  $http_response_header = [];
  @file_get_contents($sub['endpoint'], false, $ctx);
  $resp = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
  $status = 0;
  foreach ($resp as $line) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
  return $status;
}

// Schickt Nachrichten an mehrere Abos und entfernt abgelaufene. $build($sub) liefert die Nachricht oder null.
function push_many(array &$d, array $subs, callable $build, $ttl = 900) {
  $r = ['devices' => 0, 'ok' => 0, 'failed' => 0, 'removed' => 0];
  $dead = [];
  foreach ($subs as $sub) {
    $msg = $build($sub); if (!$msg) continue;
    $r['devices']++;
    $st = push_send($sub, $msg, $d['vapid'], $ttl);
    if ($st >= 200 && $st < 300) $r['ok']++;
    else { $r['failed']++; if ($st === 404 || $st === 410) $dead[] = $sub['id']; }
  }
  if ($dead) {
    $d['subs'] = array_values(array_filter($d['subs'], fn($s) => !in_array($s['id'], $dead, true)));
    $r['removed'] = count($dead);
  }
  return $r;
}

// Text einer Erinnerung: wer kommt noch mit?
function reminder_message($set, $state, $uid, $minutes, $prefix = '') {
  $names = [];
  foreach ($state['users'] as $u) {
    if ($u['id'] === $uid) continue;
    if (($state['picks'][$u['id']][$set['id']] ?? null) === 'yes') $names[] = $u['name'];
  }
  $who = $names ? 'Mit: ' . implode(', ', $names) : 'Bisher gehst du allein hin';
  return [
    'title' => $prefix . $set['artist'] . ' in ' . $minutes . ' Min.',
    'body' => $set['stage'] . ', ' . $set['start'] . '–' . $set['end'] . "\n" . $who,
    'tag' => 'set-' . $set['id'], 'url' => './#plan',
  ];
}
