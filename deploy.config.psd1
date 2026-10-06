# Ziele für deploy.ps1. Keine Passwörter hier eintragen – Login per SSH-Schlüssel.
@{
  # Test-Server: .\deploy.ps1
  test = @{
    Host = "100.66.2.1"                  # Server (Name oder IP)
    User = "root"                        # SSH-Benutzer
    Port = 22
    Path = "/var/www/hive-test"          # Ordner auf dem Server
    Url  = "https://hive-test.z0dy.de"   # Adresse der App, für den Abschluss-Check
  }
  # Produktiv-Server: .\deploy.ps1 prod
  prod = @{
    Host = "100.66.2.1"                  # wie Test – anpassen, falls anderer Server
    User = "root"
    Port = 22
    Path = "/var/www/hive"              # z. B. /var/www/hive
    Url  = "https://hive.z0dy.de"       # z. B. https://hive.z0dy.de
  }
}
