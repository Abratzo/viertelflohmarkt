# ViertelFlohmarkt App

Eine webbasierte Open-Source-Plattform für lokale Viertel- und Garagenflohmärkte. Die Anwendung ermöglicht es Nachbarn, ihre Stände unkompliziert über eine interaktive Karte anzumelden, und bietet Administrator:innen ein übersichtliches CMS zur Verwaltung.

## 📱 App-Vorschau

<img src="screenshots/karte.png" width="23%"></img> <img src="screenshots/anmeldung.png" width="23%"></img> <img src="screenshots/karte-cms.png" width="23%"></img> <img src="screenshots/einstellungen.png" width="23%"></img> 

---

## 🚀 Funktionen

### Für Besucher & Teilnehmer
* Interaktive Karte: Übersicht aller freigeschalteten Flohmarktstände auf OpenStreetMap-Basis inkl. Angeboten und Beschreibungen.  
* Intelligente Marker-Verteilung: Automatische Spiralanordnung bei Mehrfach-Anmeldungen unter derselben Adresse zur besseren Lesbarkeit.  
* Info-Points & Wegweiser: Visuelle Hervorhebung von WCs, Essens-/Getränkeständen, Info-Points oder Straßensperrungen – auch mit eigenen Emojis.  
* Komfortable Anmeldung: Online-Formular mit automatischer Adressvalidierung (Nominatim/OpenStreetMap) und WYSIWYG-Texteditor.  
* Selbstverwaltung: Automatisch generierter Lösch-Link per E-Mail für Teilnehmer.
   
### Für Administrator:innen (CMS)
* Verwaltung & Freigabe: Stände per Klick freischalten, deaktivieren, bearbeiten oder manuell für analoge/offline Anmeldungen anlegen.  
* Gebietsbegrenzung: Grafisches Einzeichnen des Flohmarkt-Gebiets auf der Karte (Geofencing mit serverseitiger Validierung).  
* Zeitsteuerung: Automatischer Anmeldeschluss nach Datum/Uhrzeit sowie automatisierte Löschung der Teilnehmerdaten nach dem Flohmarkt.  
* E-Mail-Benachrichtigungen: E-Mail-Updates bei Neuanmeldungen (sofort oder als tägliche Zusammenfassung) und Erinnerungen bei Ablauf der Anmeldefrist.  
* Anpassbares Design: Individuelle Farbwahl für Themes und Begrenzungslinien direkt über das Backend. 

### Sicherheit & Datenschutz (DSGVO)
* Integrierter Bot-Schutz: Kombinierter Spamschutz aus Honeypot, selbst gehosteter Rechenaufgabe (ohne externe Dienste wie Google reCAPTCHA) und IP-Rate-Limiting.  
* Maximale Sicherheit: CSRF-Schutz auf allen Formularen, Session-Sicherheit, HTML-Purifier für Editoren und IP-Sperre bei zu vielen Admin-Login-Versuchen.  
* Ersteinrichtungs-Zwang: Automatische Aufforderung zur Änderung des Standard-Passworts beim ersten Start.  
* Datensparsamkeit: Auf der öffentlichen Karte werden zum Schutz der Privatsphäre nur die Angebote (keine Namen oder E-Mails) angezeigt. 
---

## 📦 Anleitung zum einfachen Selbst-Hosting

Die Anwendung benötigt einen einfachen Webserver mit **PHP** und einer **MySQL- / MariaDB-Datenbank**.

### Voraussetzungen

* Webhosting-Paket oder ein eigener Server (z. B. VPS, Raspberry Pi, XAMPP für lokal)
* PHP ab Version 8.0 inkl. `PDO` und `DOMDocument` Extension
* MySQL oder MariaDB Datenbank

### Schritt-für-Schritt-Installation

1. **Dateien hochladen:**
   Lade alle Projektdateien (`index.php`, `admin.php`, `config.php`, etc.) in das Root-Verzeichnis deines Webservers hoch.

2. **Konfiguration anpassen (`config.php`):**
   Öffne die Datei `config.php` und trage deine Domain sowie deine Datenbank-Zugangsdaten ein:
   ```php
   $app_domain = 'dein-flohmarkt.de'; // Seine Domain (ohne https://)

   $db_host = 'localhost';             // Datenbank-Server
   $db_name = 'dein_datenbank_name';   // Name der Datenbank
   $db_user = 'dein_datenbank_nutzer'; // Datenbank-Benutzername
   $db_pass = 'dein_passwort';         // Datenbank-Passwort[cite: 1]

3. **Tabellen automatisch erstellen lassen:**
Beim ersten Aufruf der Website oder des Admin-Bereichs werden alle benötigten Datenbanktabellen automatisch angelegt.
Erster Login & Admin-Passwort vergeben:

 * Rufe admin.php in deinem Browser auf.
 * Logge dich mit dem initialen Standard-Passwort admin123 ein.
* Das System fordert dich direkt beim ersten Login auf, dein eigenes, sicheres Admin-Passwort festzulegen.

4.  **Flohmarkt konfigurieren:**
    Passe im Admin-Bereich unter Texte & Anmelde-Status sowie Gebiet & Karte die Einstellungen an deinen eigenen Viertel-Flohmarkt an!

### 📄 Lizenz

Dieses Projekt steht unter der MIT-Lizenz.

Du darfst den Code frei verwenden, kopieren, verändern, zusammenführen, veröffentlichen, verbreiten und sowohl für private als auch kommerzielle Zwecke einsetzen – unter der Bedingung, dass der bestehende Urheberrechtsvermerk erhalten bleibt.
