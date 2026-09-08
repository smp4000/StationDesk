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
- Plattform-Einstellungen unter /admin/platform-settings: Abrechnung, Gläubiger, E-Mail und FinTS.
- Unveränderliche Preis-/Frist- und Gläubigerfassungen; Konflikterkennung bei parallelem Bearbeiten.
- Verschlüsselte Gläubiger-IBAN und SMTP-Passwörter, ohne Rückgabe gespeicherter Geheimnisse im Formular.
- Aktuelle SMTP-/FinTS-Konfiguration mit Änderungszähler und zentralem Audit ohne Geheimniswerte.
- Expliziter Testmail-Button mit Empfängerfeld und gespeichertem SMTP-Profil.
- HTTPS-Verbindungstest im FinTS-Tab mit TLS-Zertifikatsprüfung, ohne Banklogin.

## Nachgewiesene Prüfungen

50 projektspezifische Tests mit 208 Assertions bestanden auf MySQL 8.4.9.
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

- Zwei Kalendertage SEPA-Vorabankündigung sind als einstellbarer Ausgangswert verfügbar;
  ihre Anwendung im Ankündigungs-/Einzugsablauf ist noch umzusetzen.
- Bei Zahlungsverzug erst warnen und nach 30 Tagen Änderungen sperren; Lesen,
  Export und Zahlungsverwaltung bleiben möglich.
- Sechs Monate Exportzugriff nach wirksamem Vertragsende. Das datenbezogene
  Aufbewahrungs-/Löschkonzept bleibt gesondert auszuarbeiten.

Weiterhin umzusetzen:

- Öffentliches Registrierungsformular mit vollständiger Firmen-/Rechnungsanschrift,
  verbindlicher Vertrags- und Mandatsannahme sowie tatsächlichem Bestätigungsversand.
- Anbindung der gespeicherten Einstellungen an Registrierung, Vertragsannahme, SMTP-Versand
  und FinTS-Adapter. Die Einstellungsseite löst keine Netzwerkaktionen aus.
- Modulkatalog und automatisierte Upgrades bereits bereitgestellter Mandanten.
- Periodische Abrechnung, Kündigung, Belege und SEPA-Vorabankündigungen.
- FinTS-Einreichung, SecureGo-Freigabe, Umsatzabgleich und Rücklastschriften.
- TOTP-Recovery, vollständige Livewire-Sicherheitsprüfung und Banking-Integrationstests.
- Rechtstexte, Aufbewahrungsfristen und die noch ungeklärten Regeln aus dem Blueprint.
- Produktive DB-Verbindungen.

FinTS-Vorgabe: VR Bank Fulda, SecureGo plus, eigene Produktregistrierungsnummer vorhanden.
Die Nummer und Bankzugangsdaten sind noch nicht in der Anwendung hinterlegt.
Keine Bankzugriffe, Zahlungsaufträge oder echten E-Mails wurden ausgeführt.

## Plattform-Einstellungen

Die vier Tabs speichern unabhängig und behalten ungespeicherte Eingaben beim Wechsel.
Bruttopreise werden als Dezimaltext eingegeben und ohne Fließkommarechnung in Cent gespeichert.
19 % Steuer sind festgelegt; neue Fassungen überschreiben keine vorhandenen Vertragsdatensätze.
Vorabankündigungsfristen sind zwischen 1 und 365 Kalendertagen eingebbar. Diese technische
Eingabegrenze ersetzt keine Vereinbarung mit dem Zahler.
Gläubigerangaben werden versioniert; die IBAN wird anhand Form und Modulo-97-Prüfsumme
plausibilisiert. Bankseitige Freigabe und vollständige länderspezifische Prüfungen stehen aus.
SMTP unterstützt STARTTLS/SMTPS als Konfigurationsauswahl. FinTS verlangt einen HTTPS-Endpunkt
ohne eingebettete Benutzer-/Passwortangaben; PIN/TAN werden hier nicht gespeichert.
Gespeicherte IBAN und SMTP-Passwort bleiben im Formular leer; leere Eingabe erhält den Wert.
Änderungen benötigen Admin-Guard und eingerichtete TOTP. Versionskonflikte werden sichtbar
zurückgewiesen, ein explizites Neuladen verwirft nur den jeweiligen Bereich.

Die zusätzliche lokale Migration ist ausgeführt. Zehn neue MySQL-/Livewire-Tests mit
43 Assertions prüfen Zugriff, erneute Schreibautorisierung, Validierung, verschlüsselte
Speicherung, Geheimniserhalt, Historie und Konflikte. Vite-Build und Blade-Kompilierung bestehen.
Die authentifizierte visuelle Browserprüfung der vier Tabs steht noch aus.

Im E-Mail-Tab kann ausdrücklich eine Testmail an eine eingegebene Empfängeradresse
gesendet werden. Ungespeicherte SMTP-Änderungen werden dabei nicht verwendet. Der separate
Mailer verlangt TLS und nutzt einen Socket-Timeout von 15 Sekunden. Pro Admin ist höchstens
ein Versuch alle 30 Sekunden erlaubt; der Button ist während der Verarbeitung deaktiviert.
Serverannahme und tatsächliche Zustellung werden in der Erfolgsmeldung unterschieden.
Fehler enthalten keine rohen SMTP-Dialoge oder Zugangsdaten; unklare Antworten lösen keinen
automatischen Wiederholungsversand aus. Audit speichert Anforderung, Fehler oder Annahme
ohne Nachrichtentext, Empfänger oder Passwort. Der automatische Vertragsversand bleibt offen.
14 Einstellungstests mit 65 Assertions bestanden, davon vier zusätzliche Testmail-Prüfungen
mit simuliertem Transport. Kein echter Versand während der Entwicklung ausgeführt.

FinTS-Verbindungstest: gespeicherte Adresse, HEAD-Anfrage, fünf Sekunden Verbindungs-
und 15 Sekunden Gesamtzeitlimit, keine Weiterleitungen, keine Bankzugangsdaten.
Für den Pilot ist ausschließlich https://fints2.atruvia.de/cgi-bin/hbciservlet freigegeben.
Ein erreichbarer HTTPS-Endpunkt einschließlich HTTP-Fehlerstatus bestätigt keinen FinTS-Login.
Pro Admin ist ein Versuch alle 30 Sekunden möglich. 17 Einstellungstests mit 79 Assertions
bestehen, einschließlich drei neuer Prüfungen für Erreichbarkeitsanzeige, interne Zieladressen,
fehlende Konfiguration und erneute Autorisierung. HTTP-Aufrufe sind dabei simuliert.

Die reine Kontenabfrage ist nach ausdrücklicher Freigabe mit nemiah/php-fints 4.1.0
implementiert. Composer ergänzte ausschließlich dieses Paket; keine anderen Paketupdates.
Der Super-Admin gibt VR-NetKey/Alias und PIN im Testformular ein. Angeboten werden nur die
von der Bank gemeldeten entkoppelten Handy-Verfahren und gegebenenfalls Freigabegeräte.
Anmeldung und Kontenabfrage können jeweils eine Handy-Bestätigung verlangen; die Bank
entscheidet darüber. Die Anwendung erzwingt keine künstliche TAN-Anforderung.
Manuelle Statusprüfung berücksichtigt Bankintervalle und maximale Prüfversuche.
Die einzige fachliche Aktion ist GetSEPAAccounts; keine Salden, Umsätze oder Zahlungen.
Ergebnisse zeigen ausschließlich maskierte Kontoreferenzen.

PIN, VR-NetKey, Konfigurationssnapshot und Paketdialog werden authentifiziert verschlüsselt
im zentralen Cache gespeichert, gebunden an Admin und Anwendungssitzung. Zehn Minuten
absolute Fortsetzungsfrist, einmaliger Verbrauch jedes Dialogstands und exklusive Sperre.
Ein Fehler führt nicht zur automatischen Wiederverwendung eines unklaren Bankdialogs.
Abschluss, Fehler und explizites Beenden entfernen den lokalen Zustand; nicht mehr abgefragte
abgelaufene Cachezeilen können bis zur Cachebereinigung verschlüsselt in der Datenbank liegen.
Beim Seitenneuladen kann ein alter Test ausdrücklich beendet werden. Eine bereits angezeigte
Freigabe in SecureGo plus wird dadurch nicht bankseitig widerrufen.
PIN und VR-NetKey werden vor jeder Formularantwort geleert und nicht als Einstellungen gespeichert.
Audit enthält nur den technischen Schritt, niemals Challenges, Konten oder Zugangsdaten.

Sechs zusätzliche Tests prüfen verschlüsselten Zustand, Browser-Geheimnisschutz, Abschluss,
Sessionbindung, veraltete Zustände, Bankwartezeiten, Fehlerbereinigung, Verfahrensfilterung,
erneute Persistierung und den erfolgreichen Kontenabruf ohne unnötige Freigabeaufforderung.
Die Bankadapter sind simuliert; echter Banklogin und SecureGo-plus-Freigabe stehen als
Pilotprüfung mit den persönlich eingegebenen Zugangsdaten aus.

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
