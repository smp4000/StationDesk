# P01 – Umsetzungsstand

Stand: 09.09.2026. P01 ist in Arbeit, nicht abgeschlossen und nicht für Produktion freigegeben.

## Implementiert

- Laravel 13.31, Filament 5.8, stancl/tenancy 3.10; Versionen in composer.lock.
- Zentrale Owner- und Super-Admin-Identitäten, getrennte Guards und Passwort-Reset-Tabellen.
- Verpflichtende TOTP-Einrichtung im Plattformpanel; keine öffentliche Super-Admin-Registrierung.
- Eigene MySQL-Datenbank und eingeschränkter Laufzeitnutzer pro provisioniertem Mandanten.
- Tenant-Kontext, Cache-/Dateitrennung und Bereinigung auch bei Initialisierungsfehlern.
- Zentraler Queue-Auftrag nach E-Mail-Bestätigungsereignis.
- Wiederholbare Anlage von erster Station, Chef, Stationszuordnung und 30-Tage-Trial.
- Lokale Registrierung unter /owner/register mit Chef, Firmen-/Rechnungsanschrift und erster Tankstelle.
- Atomare Registrierung mit intern erzeugten Mandanten-/Stations-IDs und Preis-Snapshot; keine Bereitstellung vor E-Mail-Bestätigung.
- Bestätigungsmail und begrenzter Neuversand über das gespeicherte Plattform-SMTP-Profil; sichere Fehleranzeige bei Versandproblemen.
- Owner-Passwortwiederherstellung über denselben SMTP-Absender und das gemeinsame HTML-Mail-Layout mit Klartextalternative.
- Neutrale Reset-Rückmeldung für vorhandene/unbekannte Adressen; zeitlich begrenzte Einmal-Tokens im getrennten Owner-Broker und mindestens zwölf Zeichen für neue Passwörter.
- Dauerhafte Testkennzeichnung in Registrierungsauftrag und Abo; lokale Registrierungen sind keine kostenpflichtigen Vertragsabschlüsse.
- Automatisch aktualisierte Bereitstellungsseite, eigener Fehlerzustand und Trial-Ende in der Stationsübersicht.
- Fehlgeschlagene Bereitstellung mit bereinigten Fehlercodes und administrativem Retry.
- Owner-Stationsübersicht, eigene Anmeldeseiten und eigenes responsives Theme.
- Brutto-/Nettoberechnung mit Centbeträgen und 19 Prozent Umsatzsteuer.
- Owner-Einstellungen /owner/settings mit Tabs Darstellung und Abos & Laufzeiten; /owner/subscriptions bleibt direkt erreichbar.
- Persönliche Farbauswahl am Owner: Petrol, Aral-Blau, Esso-Rot, Shell-Gelb, bft-Weiß/Grau und OIL!-Grün/Lila; feste Paletten mit eigener Speicherung und Audit.
- Aboübersicht: gespeicherter Stationspreis, Trial-Ende, simulierte Monatsperiode und Kündigungstermin.
- Lokale Testkündigung mit Modal-Bestätigung, erneut geprüftem Endtermin, Zeilensperre, eindeutigem Kündigungsnachweis und zentralem Audit.
- Trial-Kündigung zum Trial-Ende; danach Kündigung zum Ende der laufenden Monatsperiode. Keine Datenlöschung durch Kündigung.
- Rücknahme vor dem Vertragsende und Reaktivierung danach mit expliziter Modal-Bestätigung; ursprünglicher Monatsrhythmus, Preis und Trial bleiben erhalten.
- Mehrere Kündigungs-/Rücknahmezyklen mit unveränderten Nachweisen, idempotenter Wiederholung und Ablehnung veralteter Dialoge.
- Interaktiver CLI-Befehl stationdeck:create-super-admin mit verdeckter Passworteingabe.
- Plattform-Einstellungen unter /admin/platform-settings: Abrechnung, Gläubiger, E-Mail und FinTS.
- Unveränderliche Preis-/Frist- und Gläubigerfassungen; Konflikterkennung bei parallelem Bearbeiten.
- Verschlüsselte Gläubiger-IBAN und SMTP-Passwörter, ohne Rückgabe gespeicherter Geheimnisse im Formular.
- Aktuelle SMTP-/FinTS-Konfiguration mit Änderungszähler und zentralem Audit ohne Geheimniswerte.
- Expliziter Testmail-Button mit Empfängerfeld und gespeichertem SMTP-Profil.
- HTTPS-Verbindungstest im FinTS-Tab mit TLS-Zertifikatsprüfung, ohne Banklogin.
- Versionierter Bundesbank-Bankenstamm aus öffentlicher CSV mit Bank-/BIC-Zuordnung.
- IBAN-Hilfe: Standardvorschlag aus Kontonummer/BLZ und Prüfung vorhandener deutscher IBAN.

## Nachgewiesene Prüfungen

87 projektspezifische Tests mit 462 Assertions bestanden auf MySQL 8.4.9.
Die persönlichen Farben werden auf getrennten Benutzerkonten geprüft, einschließlich
gespeicherter Auswahl nach erneuter Anfrage und Ablehnung beliebiger CSS-Werte.
Rücknahme und Reaktivierung bewahren auch nach einer mehrmonatigen Pause den Monatsanker;
erneute Kündigungen, alte Bestätigungen, Fremdzugriffe und Produktionssperren sind geprüft.
Der Abo-Dialog wird inzwischen über echte getrennte HTTP-/Livewire-Anfragen geprüft.
Die persistente Middleware beendet ihren Kontext vor der Aktion; Stationsabfragen in
Aktionen und Rendern verwenden deshalb zusätzlich begrenzte, frisch autorisierte
Owner-Kontexte. Ein bereits bestehender fremder Kontext wird abgewiesen. Der frühere
Dialogtest mit dauerhaft offenem Testkontext wurde ersetzt, weil er diesen Fehler verdeckte.
Abo-/Periodentests prüfen Monatsanker einschließlich Schaltjahr,
Trial-Kündigung, Monatskündigung, unveränderte Endtermine bei Wiederholung, überholte
Dialogbestätigung, fremde Verträge, Produktionssperre und den echten Livewire-Modalablauf.
Sechs zusätzliche Wiederherstellungstests prüfen Owner-/Admin-Trennung, Reset-Anfrage,
Tokenverbrauch und Ablauf, Passwortwechsel mit Remember-Token-Erneuerung, SMTP-Ausfälle
sowie übereinstimmende HTML-/Textlinks. Auch der nach der Mailgestaltung noch offene
datenbankgestützte Versandtest wurde erfolgreich nachgeholt.
Die tatsächliche lokale Registrierung ist inzwischen bestätigt und erfolgreich bereitgestellt;
der Provisionierungsworker läuft und seine Warteschlange war bei der Prüfung leer.
Geprüft wurden Daten-/Cache-/Dateitrennung, direkte Fremd-SQL-Abfragen, fehlende DDL-Rechte
des Tenant-Nutzers, wiederverwendete Models nach Kontextwechsel, Bootstrapfehler,
Trial-Idempotenz, Worker-Retries, E-Mail-Bestätigung, Guard-Trennung, TOTP-Einrichtung,
Panelzugriff und Cent-Rundung. Vite-Produktionsbuild erfolgreich.
Die Registrierung wurde zusätzlich im lokalen Browser geöffnet und auf vollständige Formularbereiche geprüft.
Elf Registrierungstests prüfen unter anderem manipulierte/fremde/abgelaufene Bestätigungslinks,
E-Mail-Eindeutigkeit, Preis-Snapshot, Produktionssperre, SMTP-Ausfall und den Ablauf bis zur
provisionierten ersten Station samt Chef und stabilem Trial. SMTP-Versand wurde dabei mit
einem Speichertransport geprüft; keine echte Bestätigungsmail wurde durch diese Tests versendet.
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

- Öffentliche Freigabe der vorhandenen lokalen Registrierung mit verbindlicher Vertrags- und Mandatsannahme.
- Anbindung der gespeicherten Einstellungen an Vertragsannahme und echten FinTS-Zahlungsverkehr.
  Speichern allein löst keine Netzwerkaktionen aus; die ausdrücklichen Testaktionen tun dies.
- Modulkatalog und automatisierte Upgrades bereits bereitgestellter Mandanten.
- Echte periodische Abrechnung, produktive Kündigungsabwicklung, Belege und SEPA-Vorabankündigungen.
  Die lokale Periodenanzeige und Testkündigung erzeugen keine Forderungen oder Einzüge.
- FinTS-Einreichung, SecureGo-Freigabe, Umsatzabgleich und Rücklastschriften.
- TOTP-Recovery, vollständige Livewire-Sicherheitsprüfung und Banking-Integrationstests.
- Rechtstexte, Aufbewahrungsfristen und die noch ungeklärten Regeln aus dem Blueprint.
- Produktive DB-Verbindungen.

FinTS-Vorgabe: VR Bank Fulda, SecureGo plus, eigene Produktregistrierungsnummer vorhanden.
Die eigene Produktnummer kann in den Plattform-Einstellungen gepflegt werden; Bankzugangsdaten
werden für die zeitlich begrenzten Testdialoge erfasst. Keine Zahlungsaufträge wurden vom Agenten
ausgeführt. Die automatisierten Mailprüfungen senden nicht extern; die vom Nutzer ausgelöste
Registrierung hat bereits eine echte Bestätigungsmail geliefert.

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

### Nach Änderungen an Admin-Seiten

Ein veralteter Filament-Komponentencache kann neue Seiten im Webprozess ausblenden,
während artisan route:list sie bereits korrekt auflistet: Filament 5 berücksichtigt
diesen Cache nur außerhalb der Konsole. Bei der Importseite führte das zum Fehler
„Route [filament.admin.pages.bank-directory-import] not defined“ in den Einstellungen.
Nach Hinzufügen oder Umbenennen von Panel-Seiten daher die UI-Caches erneuern:

~~~powershell
& 'C:\php84\php.exe' 'C:\ProgramData\ComposerSetup\bin\composer.phar' run clear-ui-cache
~~~

Der Befehl entfernt gezielt Filament-Komponenten-, Routen- und Ansichtscaches.
Anwendungsdaten, Sitzungen, Datenbankschlüssel und laufende Bankdialoge bleiben erhalten.
Anschließend die tatsächliche Webroute prüfen; eine reine Konsolenprüfung genügt hier nicht.
Am lokalen Server localhost:8000 wurde die Importseite nach Bereinigung mit HTTP 302
zur Admin-Anmeldung erreicht, statt wegen einer fehlenden Route abzubrechen.

### Bundesbank-CSV und IBAN-Hilfe

Die öffentliche Bundesbank-CSV vom 07.09.2026 bis 06.12.2026 wurde lokal importiert:
13.760 Datensätze, Importnummer 1. BLZ 53060180 ergibt VR Bank Fulda, BIC GENODE51FUL.
Quelle: https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/bankleitzahlen/download-bankleitzahlen-602592
Die heruntergeladene Datei liegt ausschließlich im ignorierten .runtime-Verzeichnis.
Folgeimporte sind nach Download der CSV im Admin-Menü „Bankenstamm / CSV-Import“ unter
/admin/bank-directory-import möglich: ungepackte CSV auswählen, Gültigkeitsbeginn und
-ende aus der Bundesbank-Veröffentlichung eintragen und importieren. Die Seite zeigt den
aktiven Stand sowie die letzten 20 Importe. Identische Dateien werden nicht dupliziert;
zukünftige Stände gelten erst ab ihrem Datum. Bei gleicher Gültigkeit entscheidet die
jüngste Importnummer. Temporäre Uploads werden nach der Verarbeitung entfernt.
Der Import verwendet dieselben Prüfungen wie die CLI und protokolliert den ausführenden
Super-Admin. Drei neue Tests prüfen Upload, Wiederholung, Audit, Fehlerfälle und MFA-/Rollenschutz.

Alternativ bleibt die CLI verfügbar:

~~~powershell
& 'C:\php84\php.exe' artisan stationdeck:import-banks 'PFAD-ZUR-CSV' --from=YYYY-MM-DD --until=YYYY-MM-DD
~~~

Der Gültigkeitszeitraum muss der Bundesbank-Veröffentlichung entnommen werden. Dateiformat,
13 Spalten, UTF-8/Windows-1252, führende Nullen und eindeutige Datensatznummern werden geprüft.
Leere PAN ist zulässig. Neue Vollstände werden transaktional gespeichert; identische Importe
werden nicht dupliziert. Ungültige Dateien erzeugen keinen Teilbestand. Führende Datensätze
und Filialen bleiben unterscheidbar; gelöschte BLZ werden nicht angeboten, angekündigte
Löschungen werden angezeigt. Außerhalb eines gültigen Importzeitraums ist keine Zuordnung möglich.

Im Gläubiger-Tab kann aus BLZ und Kontonummer ein Standard-IBAN-Vorschlag berechnet werden.
Der Nutzer hat diese Variante trotz fehlender NExt-Regeln ausdrücklich gewählt. Keine
bankbezogenen Sonderregeln oder nationalen Kontonummer-Prüfmethoden werden angewendet.
Das Ergebnis ist mit den Bankunterlagen abzugleichen. Alternativ vorhandene deutsche IBAN
auf Struktur und Modulo-97-Prüfsumme prüfen. Beide Varianten zeigen Bank/BIC aus der CSV;
sie bestätigen weder Kontoexistenz noch Kontoinhaberschaft. Erst „Ins Formular übernehmen“
füllt IBAN/BIC im Gläubigerentwurf; dauerhaftes Speichern bleibt separat.
Fünf zusätzliche Tests prüfen Import, Idempotenz, Kodierung, führende Nullen, atomare Fehler,
Berechnung, IBAN-Prüfung, gelöschte/abgelaufene Banken und erneute Autorisierung.
Regelgrenzen: https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/iban-regeln

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
Die Startseite verlinkt lokal auf die Testregistrierung. Außerhalb von local/testing bleiben
Registrierungsroute und Formularaktionen gesperrt, bis Vertrags- und Mandatstexte eingebunden sind.
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
