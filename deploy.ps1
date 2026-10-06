# HIVE Crew-Planer – Veröffentlichen per SSH
# Aufruf:  .\deploy.ps1            Test-Server (Standard)
#          .\deploy.ps1 prod       Produktiv-Server
#          .\deploy.ps1 prod -Yes  ohne Rückfrage
#
# Test:      lädt den aktuellen Stand hoch, inkl. noch nicht committeter Änderungen an bekannten Dateien.
# Produktiv: nur committete Stände, mit Rückfrage; danach wird der Stand als prod-<Datum> getaggt.
# Immer:
# - config.php wird nur beim allerersten Deploy hochgeladen, danach nie überschrieben.
# - data/ (state.json, push.json) bleibt unangetastet.
# - VERSION in sw.js wird automatisch gesetzt, damit alle Geräte die neuen Dateien laden.
param([ValidateSet("test", "prod")][string]$Target = "test", [switch]$Yes)
$ErrorActionPreference = "Stop"

$cfgAll = Import-PowerShellDataFile (Join-Path $PSScriptRoot "deploy.config.psd1")
$cfg = $cfgAll[$Target]
if (-not $cfg) { throw "Ziel '$Target' fehlt in deploy.config.psd1" }
if (-not $cfg.Port) { $cfg.Port = 22 }
if ("$($cfg.Host)$($cfg.User)$($cfg.Path)$($cfg.Url)" -like "*EINTRAGEN*") { throw "Bitte zuerst in deploy.config.psd1 die Daten für '$Target' eintragen." }
$prod = $Target -eq "prod"
if ($prod -and $cfgAll.test -and $cfg.Host -eq $cfgAll.test.Host -and $cfg.Path.TrimEnd("/") -eq $cfgAll.test.Path.TrimEnd("/")) {
  throw "Produktiv und Test zeigen auf denselben Ordner – bitte deploy.config.psd1 prüfen."
}

$git = (Get-Command git -ErrorAction SilentlyContinue).Source
if (-not $git) { $git = "$env:ProgramFiles\Git\cmd\git.exe" }
function Git { & $git -C $PSScriptRoot @args; if ($LASTEXITCODE) { throw "git $args fehlgeschlagen" } }

# Stand bestimmen: HEAD, oder (nur Test) ein temporärer Commit mit den lokalen Änderungen
$rev = Git stash create
$dirty = [bool]$rev
$untracked = Git ls-files --others --exclude-standard
if ($prod -and ($dirty -or $untracked)) {
  throw "Produktiv-Deploy nur mit sauberem Stand. Erst alles committen (git status zeigt, was offen ist)."
}
if (-not $dirty) { $rev = "HEAD" }
$hash = (Git rev-parse --short $rev).Trim()
$stamp = Get-Date -Format "yyyyMMdd-HHmm"
$version = "hive-$stamp-$hash"
if ($dirty) { Write-Warning "Nicht committete Änderungen werden mit hochgeladen." }
if ($untracked) { Write-Warning ("Neue, noch nicht hinzugefügte Dateien werden NICHT hochgeladen:`n  " + ($untracked -join "`n  ")) }

if ($prod -and -not $Yes) {
  Write-Host "PRODUKTIV: $(Git log -1 --format='%h %s' HEAD)" -ForegroundColor Yellow
  Write-Host "        -> $($cfg.User)@$($cfg.Host):$($cfg.Path)" -ForegroundColor Yellow
  if ((Read-Host "Wirklich veröffentlichen? (j/n)") -notmatch '^(j|ja|y|yes)$') { Write-Host "Abgebrochen."; exit 1 }
}

$bundle = Join-Path $env:TEMP "hive-deploy.tgz"
Git -c tar.umask=0022 archive --format=tar.gz -o $bundle $rev   # Dateien 644, Ordner 755

$dest = "$($cfg.User)@$($cfg.Host)"
$remoteTmp = "/tmp/hive-deploy-$hash.tgz"
$path = $cfg.Path.TrimEnd("/")

# Auf dem Server: entpacken (config.php nur, wenn noch keine da ist), VERSION setzen, aufräumen
$script = @"
set -e
mkdir -p '$path/data'
cd '$path'
if [ -f config.php ]; then tar -xzf '$remoteTmp' --no-same-owner --exclude=config.php; else tar -xzf '$remoteTmp' --no-same-owner; echo 'config.php neu angelegt - Admin-Schluessel dort eintragen!'; fi
sed -i 's/^const VERSION = .*/const VERSION = "$version";/' sw.js
if [ "`$(id -u)" = 0 ] && id www-data >/dev/null 2>&1; then chown -R www-data:www-data data; fi
rm -f '$remoteTmp'
"@ -replace "`r", ""
$b64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($script))

Write-Host "Lade $version nach ${dest}:$path ..." -ForegroundColor Cyan
& scp -q -P $cfg.Port $bundle "${dest}:$remoteTmp"
if ($LASTEXITCODE) { throw "Upload fehlgeschlagen" }
& ssh -p $cfg.Port $dest "echo $b64 | base64 -d | sh"
if ($LASTEXITCODE) { throw "Entpacken auf dem Server fehlgeschlagen" }
Remove-Item $bundle

# Produktiv-Stand merken, damit man jederzeit sieht (und zurückholen kann), was live ist
if ($prod) {
  $tag = "prod-$stamp"
  Git tag -a $tag -m "Produktiv veröffentlicht: $version" $rev
  Write-Host "Getaggt als $tag" -ForegroundColor Cyan
}

# Prüfen, ob die neue Version ausgeliefert wird
if ($cfg.Url) {
  $sw = (Invoke-WebRequest -UseBasicParsing -Headers @{ "Cache-Control" = "no-cache" } ($cfg.Url.TrimEnd("/") + "/sw.js?nocache=$hash")).Content
  if ($sw -match [regex]::Escape($version)) { Write-Host "Online: $($cfg.Url) ($version)" -ForegroundColor Green }
  else { Write-Warning "Hochgeladen, aber $($cfg.Url)/sw.js zeigt noch nicht $version (Cache/Pfad prüfen)." }
} else {
  Write-Host "Fertig: $version" -ForegroundColor Green
}
