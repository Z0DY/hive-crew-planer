# Ziele für deploy.ps1. Keine Passwörter hier eintragen – Login per SSH-Schlüssel.
@{
  test = @{
    Host = "100.66.2.1"          # z. B. hive-test.example.de oder IP
    User = "root"          # SSH-Benutzer
    Port = 22
    Path = "/var/www/hive-test"           # Ordner auf dem Server
    Url  = "https://hive-test.z0dy.de"   # Adresse der App, für den Abschluss-Check
  }
}
