# P01 – Fundament, Registrierung und SEPA

Stand: 08.09.2026. Status: Fundament in Umsetzung nach Bestätigung der Fachentscheidungen;
Bank-/Vertragsdetails bleiben vor abhängiger Implementierung zu klären.
Arbeitsname aus der Vorlage: StationDeck. Arbeitsverzeichnis: StationDesk.

## 1. Bestätigte Anforderungen

- Kompletter Neustart ohne Code aus Rosiplus/Stationpilot4.
- Laravel 13, Filament 5 mit eigenem Theme, MySQL, stancl/tenancy mit eigener Datenbank je Mandant.
- Eine gemeinsame Registrierungs- und Anmeldeseite, keine Kunden-Subdomains.
- Ein Owner pro Mandant, Anmeldung mit E-Mail und Passwort.
- Der Owner wird zugleich erster Mitarbeiter (Chef) mit allen Rechten innerhalb seines Mandanten.
- Getrennte Super-Admin-Konten mit verpflichtender Zwei-Faktor-Authentifizierung.
- Registrierung legt die erste Tankstelle und den Chef als Mitarbeiter an.
- 30 Tage Trial ab erfolgreicher Bereitstellung nach E-Mail-Bestätigung.
- Automatischer Übergang in ein kostenpflichtiges Stationsabo nach dem Trial.
- Vorläufig 1 EUR brutto pro Station und Monat; Preise werden vom Super-Admin gepflegt.
- 19 % Umsatzsteuer, monatliche Vorauszahlung ab Trial-Ende, kündbar zum Ende der laufenden
  Monatsperiode. Neue Preise gelten nur für neue Verträge.
- SEPA-Vorabankündigung: zunächst zwei Kalendertage vor Fälligkeit; die Frist ist im
  Super-Admin einstellbar und muss der jeweils vereinbarten Mandats-/Vertragsfassung entsprechen.
- Bei Zahlungsverzug zunächst warnen. Nach 30 Tagen Zahlungsverzug Änderungen sperren;
  Lesen, Export und Zahlungsverwaltung bleiben ausdrücklich zugänglich.
- Nach wirksamem Vertragsende sechs Monate Exportzugriff gewähren. Diese Zugriffsfrist
  legt keine pauschale Aufbewahrungs- oder Löschfrist für sämtliche Daten fest.
- SEPA-Basislastschrift bei der VR Bank Fulda. Aktualisierte Vorgabe: Einreichung und
  Umsatzabruf direkt über FinTS; dies ersetzt den XML-Upload als primären Übertragungsweg.
- SMTP-Konfiguration im Super-Admin; spätere Partner-Absender als getrennte Zuständigkeit.
- Erster Pilot: Betreiber nutzt das System selbst mit drei Tankstellen.
- Pflichtangaben: Vor-/Nachname, E-Mail/Passwort, Firmenname/Rechnungsanschrift,
  Name/Anschrift der ersten Station; Telefon und USt-IdNr. optional.
- Gläubigerdaten werden vom Super-Admin eingetragen.
- AGB, Datenschutzerklärung, AVV und abgestimmter Mandatstext liegen noch nicht vor.
- Audit-Aufteilung wurde an die Umsetzung delegiert: Plattformaktionen zentral, Kundenaktionen im Mandantenschema.
- Englische technische Bezeichner, deutsche fachliche Kommentare und UI-Texte; relationale Daten in 3NF.
- Erst P01 abschließen und prüfen, danach weitere Phasen.

## 2. Ziel und Grenze dieser Phase

Ein neuer Betreiber kann seine erste Station registrieren, seine E-Mail bestätigen und seinen
bereitgestellten Mandanten als Chef öffnen. Die Plattform verwaltet Provisionierung, Trial,
Stationsabos und die Grundlage einschließlich der vereinbarten SEPA-Abwicklung.

Stations- und Mitarbeiterdaten werden nur für diesen Ablauf eingeführt. Vollständige
Stationsverwaltung, Personalfragebogen, QR/NFC-Login, eigene Mitarbeiterrollen, Schichten,
Kasse und Wettbewerberpreise bleiben ihren späteren Phasen zugeordnet. Chef-Vollzugriff
umfasst niemals fremde Mandanten oder die Plattformverwaltung.

Rechnungsdaten und Forderungen sind für nachvollziehbare Einzüge erforderlich. Gestaltung
und ZUGFeRD-Ausgabe von Rechnungs-PDFs bleiben P08+ zugeordnet. Der konkrete Belegversand
vor echtem Abrechnungsbetrieb ist noch zu entscheiden.

## 3. Architekturvorschlag

- Modularer Laravel-Monolith mit Bereichen Identity, Tenancy, Onboarding, Billing und Audit.
- Zentrale Owner-Identität mit eindeutiger E-Mail und serverseitiger Mandantenzuordnung.
  Ob dieselbe Person später mehrere Mandanten besitzen darf, bleibt eine spätere Entscheidung.
- Separate Super-Admin-Authentifizierung; kein automatischer Zugriff auf Personaldaten.
- Tenant-Kontext ausschließlich aus der authentifizierten Zuordnung, niemals aus frei
  übergebenen tenant_id-Feldern. Zugriff vor Bereitstellung verweigern.
- Zentrale Modelle verwenden ausdrücklich die Landlord-Verbindung. Fachmodelle benötigen
  einen initialisierten Mandanten und dürfen nicht auf die Landlord-Verbindung zurückfallen.
- Datenbanknamen werden aus internen IDs erzeugt, niemals aus Kundeneingaben.
- Pro Mandant eigene DB-Zugangsdaten; Provisionierungszugang getrennt vom Webzugang.
- Queue-Jobs tragen eine geprüfte Mandantenreferenz und räumen den Kontext auch nach Fehlern auf.
- Cache, Dateien, Downloads und Locks werden ebenfalls je Mandant getrennt.
- Zentrale Auth-Sessions werden nach erfolgreicher Anmeldung erneuert; Logout und Widerruf
  verhindern die weitere Nutzung. Passwort-Reset und E-Mail-Bestätigung sind zeitlich begrenzt.
- Datenbankübergreifende Abläufe sind wiederholbare Schritte mit nachvollziehbarem Status,
  keine vermeintliche gemeinsame SQL-Transaktion über mehrere Verbindungen.

## 4. Vorgesehene Entitäten

Dies ist ein Entitätenentwurf; konkrete Migrationen folgen erst nach Klärung der offenen Fachregeln.
Jedes fachliche Feld erhält eine deutsche Erklärung in Migration beziehungsweise Model.

| Ort | Entitäten | Verantwortung |
| --- | --- | --- |
| Landlord | owners, super_admins | Getrennte Identitäten und Authentifizierung |
| Landlord | tenants, provisioning_runs, tenant_migration_runs | Registry und technischer Bereitstellungszustand |
| Landlord | registration_requests | Noch nicht abgeschlossene Registrierung, minimale benötigte Daten |
| Landlord | modules, price_versions | Modulkatalog und zeitlich versionierte Preise |
| Landlord | station_billing_accounts, subscriptions | Zentrale Abrechnungsreferenz pro Station und Vertragsperioden |
| Landlord | creditor_profiles, creditor_profile_versions | Gläubiger und versionierte Einzugsdaten |
| Landlord | bank_accounts, sepa_mandates, mandate_events | Verschlüsselte Bankdaten, Nachweis und Mandatshistorie |
| Landlord | legal_document_versions, agreement_acceptances | Vorgelegte Texte und getrennte Annahmen |
| Landlord | billing_documents, billing_document_lines | Unveränderliche Forderungs- und Positionsdaten |
| Landlord | debit_collections, debit_batches, debit_batch_items | Einzüge und nachvollziehbare XML-Ausgaben |
| Landlord | payment_events, notification_deliveries, audit_events | Zahlungsrückmeldungen, Versandnachweise und Plattformaudit |
| Tenant | stations | Erste Station; fachlicher Stationsstatus bleibt hier zuordenbar |
| Tenant | employees, employee_station_assignments | Chef und seine Stationszuordnung |
| Tenant | audit_events | Fachliche Kundenaktionen |

Zwischen Datenbanken werden stabile IDs und Konsistenzprüfungen verwendet; keine nicht
erzwingbaren Fremdschlüssel behaupten. Die zentrale Abrechnungsreferenz ist keine zweite,
unabhängig editierbare Kopie der Stationsstammdaten. Preispositionen enthalten Betrag,
Währung, Steuersatz und Periodenbezug; Geldberechnung erfolgt ohne Fließkommazahlen.
Vertrags- und Steuerwerte dürfen nicht aus dem 1-EUR-Wunsch abgeleitet werden.

## 5. Registrierung und Fehlerfälle

1. Betreiber erfasst Identität, Firma, erste Station und für SEPA benötigte Angaben.
2. Vor Abschluss werden Preis, Trial-Ende beziehungsweise dessen Berechnungsregel,
   automatische Verlängerung und gültige Vertrags-/Mandatstexte angezeigt.
3. Vertragsannahme und SEPA-Mandat werden getrennt und mit Textversion nachgewiesen.
   Die Datenschutzerklärung wird nicht pauschal als Einwilligung in jede Verarbeitung behandelt.
4. Eine bestätigte E-Mail startet die zentrale Provisionierungsqueue genau einmal.
5. Schema, Migrationen, erste Station, Chef und Stationszuordnung werden in wiederholbaren
   Schritten eingerichtet. Erst danach werden Bereitstellung und Trial-Start festgeschrieben.
6. Ein Retry setzt den Trial nicht zurück und legt weder Station noch Mitarbeiter doppelt an.
7. Der Betreiber sieht Bereitstellungsfortschritt oder einen neutralen Fehlerhinweis.
   Der Super-Admin sieht fehlgeschlagene Schritte und kann einen geprüften Retry auslösen.
8. Abgebrochene oder unbestätigte Registrierungen werden nach einer noch festzulegenden
   Aufbewahrungsfrist entfernt. Kein automatisches Löschen produktiver Mandanten bei Jobfehlern.

## 6. SEPA-Ablauf

- Gläubigerprofil: Firmierung, Anschrift, Gläubiger-ID, Kontoinhaber, IBAN und nötigenfalls BIC.
  Änderungen erzeugen eine neue Version und einen Audit-Eintrag.
- Mandat: eindeutige Referenz, CORE-Verfahren, wiederkehrender Einzug, Zahlerdaten,
  Bankkontoreferenz, Erteilungsdatum, verwendeter Text und Nachweis der Erteilung.
- Widerruf und Änderungen erhalten eigene Ereignisse. Ein widerrufenes Mandat wird nicht
  durch einen allgemeinen Stammdaten-Edit erneut aktiv.
- Erteilungsnachweise sind zugriffsgeschützt. Umfang zusätzlicher Beweisdaten und
  Aufbewahrungszeiten sind mit Bank-/Datenschutzanforderungen abzustimmen.
- Zum Ende des Trials startet der bestätigte kostenpflichtige Vertrag automatisch.
  Bestätigt sind monatliche Vorauszahlung, 19 Prozent Umsatzsteuer, Kündigung zum Ende
  der laufenden Monatsperiode und Preisänderungen ausschließlich für neue Verträge.
- Jede Forderung und jeder Einzug hat eine stabile Identität. Doppelte Scheduler-Ausführung
  und wiederholter Export dürfen keine zusätzliche Forderung oder zweite Zahlung erzeugen.
- Vorabankündigung nennt mindestens Einzug, Betrag und Fälligkeit. Bestätigter Ausgangswert:
  zwei Kalendertage vor Fälligkeit, im Super-Admin einstellbar. Die Anwendung muss die
  dazugehörige vereinbarte Frist berücksichtigen; eine Einstellungsänderung ersetzt keine
  Vereinbarung mit dem Zahler. Versand und Behandlung fehlgeschlagener Zustellung sind
  vor produktivem Einzug zu vervollständigen.
- XML-Export verwendet das von der Bank akzeptierte pain.008-Profil; genaue Version,
  Zeichensatz-/Adressvorgaben und Einreichungsfristen werden noch verifiziert.
- XML wird gegen das passende offizielle XSD und fachliche Summen-/Referenzregeln geprüft.
- Ein Export enthält einen stabilen Batch mit Anzahl, Kontrollsumme und eindeutigen IDs.
  Erneutes Herunterladen liefert denselben Batch, nicht einen neuen Einzugsauftrag.
- Aktualisierung: Einreichung und Kontoumsatzabruf erfolgen über FinTS. Bankdialog,
  TAN-Verfahren, Produktregistrierung, Zugangsdatenablage und Wiederaufnahme nach
  unklarer Bankantwort müssen vor Implementierung des Adapters geklärt werden.
- Export bedeutet nicht Zahlungserfolg. Einreichung, Zahlungseingang, Ablehnung und
  Rücklastschrift werden gesondert erfasst. Der bestätigte Umsatzabruf erfolgt über FinTS;
  die konkrete Zuordnung von Bankrückmeldungen zu Forderungen ist noch umzusetzen.
- Keine automatische erneute Belastung nach Rücklastschrift ohne bestätigte Fachregel.
- Bei ausbleibender Zahlung zuerst warnen; nach 30 Tagen Zahlungsverzug die schreibenden
  Fachaktionen sperren. Lesen, Export und Zahlungsverwaltung bleiben nutzbar. Die Ausnahme
  für Zahlungsverwaltung ist serverseitig von gesperrten Fachänderungen abzugrenzen.
- Der Exportzugriff nach wirksamem Vertragsende beträgt sechs Monate. Eine automatische
  Löschung aller Daten allein aufgrund dieses Fristablaufs ist damit nicht beauftragt.

## 7. Oberflächen und Berechtigungen

Umsetzungsinventar des Fundaments: gemeinsame Owner-Anmeldung unter /owner/login,
Plattformanmeldung unter /admin/login, Stationsübersicht im Owner-Panel und
Bereitstellungsübersicht im Plattformpanel. Zustände: Gast, unbestätigte E-Mail,
laufende/fehlgeschlagene Bereitstellung, bereitgestellter Mandant und fehlende TOTP-Einrichtung.
Die Stationsübersicht zeigt nur tatsächlich vorhandene Stationen. Die Plattformliste zeigt
bereinigte Status-/Fehlercodes und bietet einen expliziten Retry, ohne Kundeninhalte freizugeben.
Theme: Petrol-Navigation, heller warmer Arbeitsbereich, klare Tabellen und abgerundete
Formflächen; Systemschrift ohne externe Font-Anfragen. Einstellungen werden später in
Gläubiger, Abrechnung, SMTP und FinTS gegliedert, Fehler unmittelbar am Feld angezeigt.

- Gemeinsame Registrierung mit klaren Schritten und abschließender Vertragsübersicht.
- Owner-Panel: breite Desktop-Ansicht, Stationsstatus, Trial-Ende, Abonnement und Mandat;
  Einstellungen in Tabs. Mobile Formulare bleiben bedienbar.
- Super-Admin-Panel: Tenants/Provisionierung, Migrationsläufe, Module/Preise,
  Gläubigereinstellungen, Vertragsdokumente, Lastschriftläufe und Plattformaudit.
- Geheimnisse und vollständige IBANs erscheinen nicht in allgemeinen Tabellen oder Logs.
- Export-, Tarif- und Gläubigeränderungen sind eigene serverseitig geschützte Aktionen.
- Passwort und bestätigte TOTP-Einrichtung schützen das Super-Admin-Panel; Recovery-Codes
  werden geschützt gespeichert. MFA-Umgehungen über alternative Routen sind zu testen.
- Kein Offlinebetrieb für Registrierung, Authentifizierung, Mandat oder Bankexport.

## 8. Datenschutz und Betrieb

Die Plattform ist für Kundendaten als Auftragsverarbeiter vorgesehen. Die Rollen für eigene
Verträge, Rechnungen und Zahlungen sind gesondert rechtlich zu prüfen. Aufbewahrung wird nach
Datenkategorie definiert; keine erfundenen pauschalen Löschfristen.

Bankdaten werden verschlüsselt, Schlüssel außerhalb der Datenbank geschützt gehalten.
Backups benötigen ebenfalls Verschlüsselung und einen getesteten Wiederherstellungsablauf.
Audit-Einträge enthalten Akteur, Aktion, Zielreferenz, Zeitpunkt und relevante Änderungen;
keine Passwörter, Tokens oder vollständigen Bankdaten. Schreibrechte werden begrenzt.

Startziel ist Hetzner in der EU mit Laravel Forge. EU-Standorte, Auftragsverarbeitung,
Maildienst, Backups und Unterauftragnehmer sind vor Produktion nachzuweisen.
Bei etwa 50–100 Mandanten anhand gemessener Queue-Zeiten, DB-Verbindungen, Migrationen und
Restore-Dauer eine Trennung von Web, Workern und Datenbank prüfen; danach bei Bedarf
Mandanten auf mehrere DB-Hosts verteilen. In P01 nur dokumentieren.

## 9. Abnahmekriterien und Tests

1. Registrierung mit bestätigter E-Mail erzeugt genau einen Mandanten, eine Station und einen Chef.
2. Wiederholung sowie Abbruch nach jedem Provisionierungsschritt führen nicht zu Duplikaten.
3. Trial beginnt erst nach vollständigem Erfolg, dauert 30 Tage und bleibt bei Retries unverändert.
4. Manipulierte Mandanten-/Stations-IDs und Fremd-Downloads werden zurückgewiesen.
5. Zwei aufeinanderfolgende Queue-Jobs unterschiedlicher Tenants teilen keinen Kontext.
6. Fehlende Tenancy darf niemals Fachmodelle in der Landlord-Datenbank lesen oder schreiben.
7. Chef hat Vollzugriff im eigenen Mandanten und keinen Plattform-/Fremdzugriff.
8. Super-Admin-Routen und Aktionen sind ohne abgeschlossene MFA nicht erreichbar.
9. Preisänderungen überschreiben weder historische Forderungen noch angenommene Vertragswerte.
10. Scheduler-Retries erzeugen weder doppelte Aboperioden noch doppelte Einzüge.
11. Ungültige IBAN, fehlende Gläubigerdaten, widerrufenes Mandat und unzulässige Fälligkeit
    verhindern die Freigabe eines Einzugs.
12. XML besteht XSD-Prüfung, Kontrollsummen und Referenzprüfung; Bankimport separat nachweisen.
13. Ein exportierter Batch gilt nicht als bezahlt; Rücklastschriften bleiben nachvollziehbar.
14. Migrationen und Tenancy werden gegen echte MySQL-Testdatenbanken geprüft.
15. Deutsche Kommentare, Tab-Formulare, Tastaturbedienung und mobile Registrierung werden geprüft.
16. Die Vorabankündigungsfrist startet mit zwei Kalendertagen und ist ausschließlich durch
    den Super-Admin änderbar; vereinbarte Fristen und bereits angekündigte Einzüge bleiben nachvollziehbar.
17. Nach 30 Tagen Zahlungsverzug werden schreibende Fachaktionen auch bei direkten Requests
    zurückgewiesen, während Lesen, Export und Zahlungsverwaltung zugänglich bleiben.
18. Nach wirksamem Vertragsende bleibt der bestätigte Exportzugriff sechs Monate erhalten;
    Exportberechtigung und datenbezogene Aufbewahrungs-/Löschregeln werden getrennt geprüft.

## 10. Offene Entscheidungen vor abhängiger Implementierung

- Präzise Fälligkeits-/Bankarbeitstagsregel; Monatsperioden ab Trial-Ende sind bestätigt.
- Rechtstexte und akzeptierte Online-Mandatserteilung; keine vorgetäuschte rechtliche Freigabe.
- Bankprofil und Einreichungsfristen; die Vorabankündigungsfrist ist mit zwei Kalendertagen
  als einstellbarem Ausgangswert bestätigt, ihre vertragliche Einbindung noch offen.
- SMTP-Anbieterdaten werden später im Super-Admin eingetragen; kein Passwort im Chat.
- Konkrete FinTS-Bankparameter und Freischaltung der benötigten Geschäftsvorfälle.
  Bestätigt sind VR SecureGo plus und eine eigene vorhandene Produktregistrierungsnummer;
  deren Wert wird später in der Konfiguration hinterlegt.
- Konkreter Warnablauf und Nachweis der Zahlungsbereinigung. Die Änderungssperre nach
  30 Tagen sowie die Ausnahmen Lesen, Export und Zahlungsverwaltung sind bestätigt.
- Aufbewahrung, Löschung und Prozess für Plattform-Supportzugriffe auf Kundeninhalte.

## 11. Lokale Bestandsaufnahme und Quellen

Projektordner war leer, keine AGENTS.md in Projekt oder geprüften übergeordneten Verzeichnissen.
Standard-PHP im PATH: XAMPP PHP 8.2.12; erfüllt Laravel 13 nicht.
Separat vorhanden und ausgeführt: C:/php84/php.exe, PHP 8.4.24.
XAMPP-Datenbankprogramm: MariaDB 10.4.32, nicht die gewünschte MySQL-Laufzeit.
C:/mysql8 enthält MySQL 8.4.9 (Versionsaufruf geprüft); Laufzustand und Projektzugang sind noch zu prüfen.
Composer 2.9.5 und Node 24.13.1 sind vorhanden. Es wurde keine Laufzeit umkonfiguriert.

- Laravel 13, PHP >= 8.3: https://laravel.com/framework/docs/13.x/deployment
- Filament 5: https://filamentphp.com/docs/5.x/introduction/installation
- Tenancy-Dokumentation: https://v4.tenancyforlaravel.com/introduction/
  Paketversion wird erst nach Prüfung der tatsächlichen stabilen Composer-Kompatibilität gewählt.
- VR Bank Fulda, XML-Import und Lastschriftanleitungen:
  https://www.vrbankfulda.de/banking-und-vertraege/banking/partnerportale-und-support/onlinebanking-anleitungen.html
- VR Bank Fulda, SEPA und Mandatsangaben:
  https://www.vrbankfulda.de/privatkunden/girokonto-und-bezahlen/produkte/girokonto-neu/sepa.html
- Bundesbank, insbesondere Akzeptanz von Internetmandaten durch die Inkassostelle:
  https://www.bundesbank.de/dynamic/action/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/sepa/613964/fragen-und-antworten-zu-sepa

Diese Quellen ersetzen keine Bestätigung der konkreten Bankvereinbarung oder rechtliche Prüfung.

## 12. Fortsetzung

### Bankenstamm und gewünschter IBAN-Generator

Beauftragt sind Bundesbank-CSV-Import, Bankzuordnung und darauf aufbauende IBAN-Ermittlung.
Die öffentliche CSV stellt 13 Felder bereit, jedoch keine IBAN-Regelkennung. Die erweiterte
Datei mit Feld 14 und die Regeln sind laut Bundesbank nur über NExt erhältlich. Der Nutzer
hat keinen NExt-Zugang und wünscht ausdrücklich beide Wege: vorhandene IBAN prüfen sowie
Kontonummer und BLZ zur Standardberechnung verwenden. Die Berechnung wird als ungeprüfter
Standard-IBAN-Vorschlag gekennzeichnet und berücksichtigt keine institutsindividuellen
Sonderregeln oder nationalen Kontonummer-Prüfverfahren. Keine Bankbestätigung behaupten.
Der unabhängig mögliche Bankenstamm verwendet versionierte Vollimporte mit SHA-256,
Gültigkeitszeitraum und Importzeitpunkt. Alle Datensätze einschließlich Filialen und
Löschkennzeichen bleiben nachvollziehbar; die BLZ-Suche nutzt aktive führende Datensätze.
Importfehler rollen den gesamten Import zurück. Wiederholter identischer Import ist idempotent.
Der Import erfolgt per CLI oder über die Admin-Seite /admin/bank-directory-import mit
CSV-Upload und Gültigkeitszeitraum. Die Seite zeigt den aktiven Stand und die letzten
20 Importe; Uploads werden nach Verarbeitung entfernt und der Admin im Audit erfasst.
Die Bankauswahl im Gläubiger-Tab
ist auf Super-Admins mit MFA begrenzt. Die Auswahl ergänzt BIC und zeigt Bankname/Ort.
Das Ergebnis wird erst durch eine eigene Aktion in den Gläubigerentwurf übernommen;
die dauerhafte Speicherung bleibt getrennt.
Gültigkeitsdatum, führende Nullen, Kodierung, Löschungen, doppelte Datensätze, ungültige Zeilen
und Rollen werden getestet. CSV-Dateien bleiben außerhalb von Git; nur Importcode wird versioniert.

### Aktueller Umsetzungsschritt: Plattform-Einstellungen

Die bestätigten Einstellungsbereiche werden unter /admin/platform-settings mit vier
einzeln speicherbaren Tabs umgesetzt. Ausschließlich authentifizierte Super-Admins mit
eingerichteter TOTP dürfen laden und speichern; Owner, Gäste und manipulierte Bereichsnamen
werden abgewiesen. Fehler stehen am Feld, Speicherbestätigung und Versionskonflikte sichtbar.
Abrechnung enthält den monatlichen Stationsbruttopreis (Startwert 1 EUR, 19 % Steuer) und
die Vorabankündigungsfrist (Startwert zwei Kalendertage). Neue Preisversionen ändern keine
bestehenden Vertragswerte. Gläubiger: Firma, Anschrift, Gläubiger-ID, Kontoinhaber, IBAN, BIC.
Beide Bereiche erhalten unveränderliche Versionen mit Akteur und Zeitpunkt.
SMTP enthält Server, Port, TLS-Verfahren, Benutzer, Passwort und Absender. FinTS enthält
Bankname, Bankleitzahl, HTTPS-Endpunkt und eigene Produktnummer. PIN/TAN und Bankdialoge
folgen mit dem Adapter nach Klärung der offenen Bankparameter.

Fortgeschriebener Stand: Die ausdrücklich beauftragte Konten-Leseabfrage verwendet nach
Freigabe nemiah/php-fints 4.1.0. VR-NetKey/PIN werden nur im kurzlebigen, verschlüsselten
Testdialog benötigt. Bankseitige Handy-Freigabe wird unterstützt, falls die Bank sie fordert.
Der Testdialog ist an Admin und Sitzung gebunden, zehn Minuten fortsetzbar und gegen
parallele Verwendung desselben Zustands geschützt. Ergebnis nur maskierte Kontoreferenzen.
Lastschrifteinreichung und Kontoumsatzabruf gehören weiterhin zu den offenen Integrationen.
SMTP und FinTS sind aktuelle Konfigurationen mit Änderungszähler; alte SMTP-Passwörter
werden nicht historisiert. Geheimnisse und IBAN werden verschlüsselt gespeichert;
leere Geheimnisfelder beim Bearbeiten behalten bestehende Werte. Audit enthält nur
Bereich und Datensatzreferenz. Gleichzeitige Bearbeitungen führen zu einem sichtbaren
Konflikt statt unbemerktem Überschreiben. Keine Netzwerkaktionen beim Speichern.
Tests: Rollen-/MFA-Sperren, Validierung, Verschlüsselung, Geheimniserhalt, Historie,
unveränderte Vertragswerte und konkurrierende Änderungen auf isoliertem MySQL.

Der aktuelle implementierte Umfang und die expliziten Lücken stehen in P01-status.md.
FinTS ersetzt den ursprünglich vorgesehenen manuellen Upload als Hauptweg. Frühere
XML-Exportdetails dieses Blueprints sind damit nur noch Format-/Nachvollziehbarkeitsanforderungen,
keine Festlegung auf einen manuellen Einreichungsablauf.

Bestätigte Folgeentscheidungen: zwei Kalendertage einstellbare Vorabankündigung,
Warnung vor einer Änderungssperre nach 30 Tagen Zahlungsverzug, fortbestehender Lese-,
Export- und Zahlungsverwaltungszugriff während dieser Sperre sowie sechs Monate
Exportzugriff nach wirksamem Vertragsende. Diese Regeln sind bislang geplant,
noch nicht als Zahlungs- oder Zugriffsautomatik implementiert.
Es bestehen weiterhin keine veröffentlichten Vertrags-/Mandatstexte. Die lokale
MySQL-8.4-Instanz auf Port 3307 ist mit der zentralen Datenbank stationdesk eingerichtet.
Die Einrichtung erfolgte mit dem bestätigten lokalen Root-Zugang; Webprozess und Worker
verwenden eigene Konten. Das lokale Super-Admin-Konto ist vorhanden; Produktivbetrieb bleibt offen.
