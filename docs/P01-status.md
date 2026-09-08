# P01 – Umsetzungsstand

Stand: 08.09.2026. P01 ist in Arbeit, nicht abgeschlossen und nicht für Produktion freigegeben.

## Implementiert

- Laravel 13.31, Filament 5.8, stancl/tenancy 3.10; Versionen in composer.lock.
- Zentrale Owner- und Super-Admin-Identitäten, getrennte Guards und Passwort-Reset-Tabellen.
- Verpflichtende TOTP-Einrichtung im Plattformpanel; keine öffentliche Super-Admin-Registrierung.
- Eigene MySQL-Datenbank und eingeschränkter Laufzeitnutzer pro provisioniertem Mandanten.
- Tenant-Kontext, Cache-/Dateitrennung und Bereinigung auch bei Initialisierungsfehlern.
- Zentraler Queue-Auftrag nach E-Mail-Bestätigungsereignis.
- Wiederholbare Anlage von erster Station, Chef, Stationszuordnung und 30-Tage-Trial.
- Fehlgeschlagene Bereitstellung mit bereinigten Fehlercodes und administrativem Retry.
- Owner-Stationsübersicht, eigene Anmeldeseiten und eigenes responsives Theme.
- Brutto-/Nettoberechnung mit Centbeträgen und 19 Prozent Umsatzsteuer.
- Interaktiver CLI-Befehl stationdeck:create-super-admin mit verdeckter Passworteingabe.

## Nachgewiesene Prüfungen

24 projektspezifische Tests mit 76 Assertions bestanden auf MySQL 8.4.9.
Geprüft wurden Daten-/Cache-/Dateitrennung, direkte Fremd-SQL-Abfragen, fehlende DDL-Rechte
des Tenant-Nutzers, wiederverwendete Models nach Kontextwechsel, Bootstrapfehler,
Trial-Idempotenz, Worker-Retries, E-Mail-Bestätigung, Guard-Trennung, TOTP-Einrichtung,
Panelzugriff und Cent-Rundung. Vite-Produktionsbuild erfolgreich.
Startseite und Owner-Anmeldung wurden zusätzlich im Browser visuell geprüft;
die Anmeldung auch bei 390 Pixel Breite. Für die Fachpanels fehlen noch Browserprüfungen
mit vollständig eingerichteten echten Pilotkonten.

Die Testinstanz liegt ausschließlich in .runtime/mysql-tests auf Port 3308 und enthält
Testdaten. Der dortige lokale Root-Zugang ist keine Produktionskonfiguration.
Die vorhandene XAMPP/MariaDB-Instanz auf Port 3306 wurde nicht verändert.

## Noch offen

- Öffentliches Registrierungsformular mit vollständiger Firmen-/Rechnungsanschrift,
  verbindlicher Vertrags- und Mandatsannahme sowie tatsächlichem Bestätigungsversand.
- Super-Admin-Einstellungen für Gläubiger, SMTP, FinTS und versionierte Preise.
- Modulkatalog und automatisierte Upgrades bereits bereitgestellter Mandanten.
- Periodische Abrechnung, Kündigung, Belege und SEPA-Vorabankündigungen.
- FinTS-Einreichung, SecureGo-Freigabe, Umsatzabgleich und Rücklastschriften.
- TOTP-Recovery, vollständige Livewire-Sicherheitsprüfung und Banking-Integrationstests.
- Rechtstexte, Aufbewahrungsfristen und die noch ungeklärten Regeln aus dem Blueprint.
- Dauerhafte lokale/produktive DB-Verbindungen mit geeigneten Konten.

FinTS-Vorgabe: VR Bank Fulda, SecureGo plus, eigene Produktregistrierungsnummer vorhanden.
Die Nummer und Bankzugangsdaten sind noch nicht in der Anwendung hinterlegt.
Keine Bankzugriffe, Zahlungsaufträge oder echten E-Mails wurden ausgeführt.

## Lokale Verwendung

PHP 8.4.24: C:/php84/php.exe. Standard-PHP im PATH ist das ungeeignete XAMPP-PHP 8.2.12.
MySQL 8.4.9: C:/mysql8/bin. DB-Zugang in .env vor echter Nutzung separat einrichten.
APP_KEY dauerhaft schützen; bei bestehenden verschlüsselten Daten niemals neu erzeugen.

Startseite: /. Owner: /owner/login. Plattform: /admin/login.
Die Startseite bezeichnet die Registrierung ausdrücklich als noch nicht geöffnet.
Eine Start-/Login-Vorschau ist mit dem lokalen file-Sessiontreiber ohne DB-Zugang möglich.

~~~powershell
& 'C:\php84\php.exe' artisan serve --host=127.0.0.1 --port=8088
~~~

Nach Einrichtung einer eigenen zentralen Datenbank und passenden Migrationszugangs:

~~~powershell
& 'C:\php84\php.exe' artisan migrate --database=central
& 'C:\php84\php.exe' artisan stationdeck:create-super-admin
~~~

Nur der separate Provisionierungsworker erhält PROVISION_DB_USERNAME und
PROVISION_DB_PASSWORD über seine Prozessumgebung. Kein DDL-/Nutzerverwaltungszugang
im Webprozess. Der Worker benötigt Berechtigungen für die ausschließlich intern erzeugten
Schemanamen und Nutzer. Die Produktionsberechtigungen sind noch zu prüfen.

~~~powershell
& 'C:\php84\php.exe' artisan queue:work provisioning --queue=provisioning --timeout=120 --tries=3
~~~

Die Tests erwarten stationdesk_test in der eigens eingerichteten temporären MySQL-Instanz;
niemals .env.testing auf Produktivdaten richten. Testkonfiguration und -daten sind ignoriert.

~~~powershell
& 'C:\php84\php.exe' artisan test --compact
~~~

Frontend: npm ci --ignore-scripts, anschließend npm run build und php artisan filament:assets.
Bei der beobachteten Zertifikatskette hilft NODE_USE_SYSTEM_CA=1 im Installationsprozess;
TLS-Zertifikatsprüfung bleibt eingeschaltet.
