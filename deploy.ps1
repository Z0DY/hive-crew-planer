# HIVE Crew-Planer – Veröffentlichen per SSH
# Aufruf:  .\deploy.ps1            (Ziel "test")
#          .\deploy.ps1 prod       (anderes Ziel aus deploy.config.psd1)
#
# - Lädt den aktuellen Stand hoch, inkl. noch nicht committeter Änderungen an bekannten Dateien.
# - config.php wird nur beim allerersten Deploy hochgeladen, danach nie überschrieben.
# - data/ (state.json, push.json) bleibt unangetastet.
# - VERSION in sw.js wird automatisch gesetzt, damit alle Geräte die neuen Dateien laden.
param([string]$Target = "test")
$ErrorActionPreference = "Stop"

$cfgAll = Import-PowerShellDataFile (Join-Path $PSScriptRoot "deploy.config.psd1")
$cfg = $cfgAll[$Target]
if (-not $cfg) { throw "Ziel '$Target' fehlt in deploy.config.psd1 (vorhanden: $($cfgAll.Keys -join ', '))" }
if (-not $cfg.Port) { $cfg.Port = 22 }

$git = (Get-Command git -ErrorAction SilentlyContinue).Source
if (-not $git) { $git = "$env:ProgramFiles\Git\cmd\git.exe" }
function Git { & $git -C $PSScriptRoot @args; if ($LASTEXITCODE) { throw "git $args fehlgeschlagen" } }

# Stand bestimmen: HEAD, oder ein temporärer Commit mit den lokalen Änderungen
$rev = Git stash create
$dirty = [bool]$rev
if (-not $dirty) { $rev = "HEAD" }
$hash = (Git rev-parse --short $rev).Trim()
$version = "hive-" + (Get-Date -Format "yyyyMMdd-HHmm") + "-" + $hash
$untracked = Git ls-files --others --exclude-standard
if ($dirty) { Write-Warning "Nicht committete Änderungen werden mit hochgeladen." }
if ($untracked) { Write-Warning ("Neue, noch nicht hinzugefügte Dateien werden NICHT hochgeladen:`n  " + ($untracked -join "`n  ")) }

$bundle = Join-Path $env:TEMP "hive-deploy.tgz"
Git archive --format=tar.gz -o $bundle $rev

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
rm -f '$remoteTmp'
"@ -replace "`r", ""
$b64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($script))

Write-Host "Lade $version nach ${dest}:$path ..." -ForegroundColor Cyan
& scp -q -P $cfg.Port $bundle "${dest}:$remoteTmp"
if ($LASTEXITCODE) { throw "Upload fehlgeschlagen" }
& ssh -p $cfg.Port $dest "echo $b64 | base64 -d | sh"
if ($LASTEXITCODE) { throw "Entpacken auf dem Server fehlgeschlagen" }
Remove-Item $bundle

# Prüfen, ob die neue Version ausgeliefert wird
if ($cfg.Url) {
  $sw = (Invoke-WebRequest -UseBasicParsing -Headers @{ "Cache-Control" = "no-cache" } ($cfg.Url.TrimEnd("/") + "/sw.js?nocache=$hash")).Content
  if ($sw -match [regex]::Escape($version)) { Write-Host "Online: $($cfg.Url) ($version)" -ForegroundColor Green }
  else { Write-Warning "Hochgeladen, aber $($cfg.Url)/sw.js zeigt noch nicht $version (Cache/Pfad prüfen)." }
} else {
  Write-Host "Fertig: $version" -ForegroundColor Green
}
