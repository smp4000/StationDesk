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

Inzwischen fachlich bestätigt, aber noch nicht implementiert:

- Zwei Kalendertage SEPA-Vorabankündigung, im Super-Admin einstellbar.
- Bei Zahlungsverzug erst warnen und nach 30 Tagen Änderungen sperren; Lesen,
  Export und Zahlungsverwaltung bleiben möglich.
- Sechs Monate Exportzugriff nach wirksamem Vertragsende. Das datenbezogene
  Aufbewahrungs-/Löschkonzept bleibt gesondert auszuarbeiten.

Weiterhin umzusetzen:

- Öffentliches Registrierungsformular mit vollständiger Firmen-/Rechnungsanschrift,
  verbindlicher Vertrags- und Mandatsannahme sowie tatsächlichem Bestätigungsversand.
- Super-Admin-Einstellungen für Gläubiger, SMTP, FinTS und versionierte Preise.
- Modulkatalog und automatisierte Upgrades bereits bereitgestellter Mandanten.
- Periodische Abrechnung, Kündigung, Belege und SEPA-Vorabankündigungen.
- FinTS-Einreichung, SecureGo-Freigabe, Umsatzabgleich und Rücklastschriften.
- TOTP-Recovery, vollständige Livewire-Sicherheitsprüfung und Banking-Integrationstests.
- Rechtstexte, Aufbewahrungsfristen und die noch ungeklärten Regeln aus dem Blueprint.
- Produktive DB-Verbindungen.

FinTS-Vorgabe: VR Bank Fulda, SecureGo plus, eigene Produktregistrierungsnummer vorhanden.
Die Nummer und Bankzugangsdaten sind noch nicht in der Anwendung hinterlegt.
Keine Bankzugriffe, Zahlungsaufträge oder echten E-Mails wurden ausgeführt.

## Lokale Verwendung

PHP 8.4.24: C:/php84/php.exe. Standard-PHP im PATH ist das ungeeignete XAMPP-PHP 8.2.12.
MySQL 8.4.9: C:/mysql8/bin, lokale Instanz auf Port 3307, Datenbank stationdesk.
Die fünf zentralen Migrationen sind ausgeführt. Die normale .env enthält den eigenen
Nutzer stationdesk_app mit SELECT, INSERT, UPDATE und DELETE ausschließlich auf stationdesk.
Der bestätigte lokale Root-Zugang wurde nur für die Einrichtung und Migration verwendet.
Vorhandene Datenbanken und Konten wurden nicht verändert.
APP_KEY dauerhaft schützen; bei bestehenden verschlüsselten Daten niemals neu erzeugen.

Die separate, ignorierte .env.worker enthält zusätzlich den Zugang stationdesk_provision.
Dieser besitzt DDL-/DML-Rechte mit GRANT OPTION ausschließlich für das maskierte
Mandantenschema-Muster sd\\_t\\_% sowie das von CREATE USER benötigte globale
Nutzerverwaltungsrecht. Dieses globale Recht ist nicht auf einen Nutzernamenspräfix
einschränkbar; der Worker ist daher ein privilegierter lokaler Verwaltungsprozess.
Es bestehen keine globalen ALL-Rechte und keine Datenrechte auf fremde Projektdatenbanken.
Bei Änderungen gemeinsamer Einstellungen müssen .env und .env.worker synchron gepflegt werden.
Beide Dateien einschließlich APP_KEY und Passwörtern bleiben außerhalb von Git.

Verifiziert: Migrationsstatus mit dem Anwendungskonto, tatsächliche MySQL-Grants,
Workerstart mit leerer Warteschlange und erfolgreichem Ende sowie HTTP 200 für /admin/login.
Die lokale Einrichtung ersetzt keinen vollständigen Provisionierungstest mit diesen Konten.
Die bestehenden drei Preisberechnungstests bestehen weiterhin.

Die MySQL-Instanz wird bei Bedarf mit ihrer vorhandenen Konfiguration gestartet:

~~~powershell
Start-Process -FilePath 'C:\mysql8\bin\mysqld.exe' -ArgumentList '--defaults-file=C:\mysql8\my.ini' -WindowStyle Hidden
~~~

Nur starten, wenn Port 3307 noch nicht belegt ist. Es wurde kein Windows-Autostart eingerichtet.
Das vorhandene Start-Batch prüft pauschal auf mysqld.exe und überspringt MySQL 8 bei laufendem XAMPP.

Startseite: /. Owner: /owner/login. Plattform: /admin/login.
Die Startseite bezeichnet die Registrierung ausdrücklich als noch nicht geöffnet.
Eine Start-/Login-Vorschau ist mit dem lokalen file-Sessiontreiber ohne DB-Zugang möglich.

~~~powershell
& 'C:\php84\php.exe' artisan serve --host=127.0.0.1 --port=8088
~~~

Persönlichen Super-Admin lokal mit verdeckter Passworteingabe anlegen:

~~~powershell
& 'C:\php84\php.exe' artisan stationdeck:create-super-admin
~~~

Alternativ steht der ausdrücklich aufzurufende LocalSuperAdminSeeder bereit:

~~~powershell
& 'C:\php84\php.exe' artisan db:seed --class=LocalSuperAdminSeeder --no-interaction
~~~

Er verlangt APP_ENV=local sowie LOCAL_SUPER_ADMIN_NAME, LOCAL_SUPER_ADMIN_EMAIL und
LOCAL_SUPER_ADMIN_PASSWORD in der privaten .env. Es gibt kein voreingestelltes Passwort.
Bestehende Konten einschließlich Passwort und TOTP bleiben bei Wiederholung unverändert.
Der normale DatabaseSeeder legt weiterhin keine Konten an. Die erstmalige Anlage wird
gemeinsam mit einem Auditereignis in einer Transaktion gespeichert.
Das gewünschte lokale Konto existiert; das angegebene Passwort wurde gegen seinen Hash geprüft.
Drei zusätzliche Tests mit acht Assertions prüfen Hashing, Wiederholbarkeit einschließlich
TOTP-Erhalt, fehlende Passwortkonfiguration und Ablehnung in Produktion auch mit --force.

Zukünftige zentrale Migrationen benötigen einen gesonderten Verwaltungszugang nur im
Migrationsprozess, da der normale Anwendungsnutzer absichtlich keine DDL-Rechte besitzt.
Nur der separate Provisionierungsworker lädt PROVISION_DB_USERNAME und
PROVISION_DB_PASSWORD aus .env.worker. Kein DDL-/Nutzerverwaltungszugang
im Webprozess. Die Produktionsberechtigungen sind noch zu prüfen.

~~~powershell
& 'C:\php84\php.exe' artisan queue:work provisioning --env=worker --queue=provisioning --timeout=120 --tries=3
~~~

Die Tests erwarten stationdesk_test in der eigens eingerichteten temporären MySQL-Instanz;
niemals .env.testing auf Produktivdaten richten. Testkonfiguration und -daten sind ignoriert.

~~~powershell
& 'C:\php84\php.exe' artisan test --compact
~~~

Frontend: npm ci --ignore-scripts, anschließend npm run build und php artisan filament:assets.
Bei der beobachteten Zertifikatskette hilft NODE_USE_SYSTEM_CA=1 im Installationsprozess;
TLS-Zertifikatsprüfung bleibt eingeschaltet.
