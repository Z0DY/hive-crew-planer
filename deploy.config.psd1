# Ziele für deploy.ps1. Keine Passwörter hier eintragen – Login per SSH-Schlüssel.
@{
  test = @{
    Host = "HOST-EINTRAGEN"          # z. B. hive-test.example.de oder IP
    User = "USER-EINTRAGEN"          # SSH-Benutzer
    Port = 22
    Path = "/var/www/hive"           # Ordner auf dem Server
    Url  = "https://URL-EINTRAGEN"   # Adresse der App, für den Abschluss-Check
  }
}
