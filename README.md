# ViertelFlohmarkt App


Eine webbasierte Open-Source-Plattform für lokale Viertel- und Garagenflohmärkte. Die Anwendung ermöglicht es Nachbarn, ihre Stände unkompliziert über eine interaktive Karte anzumelden, und bietet Administratoren ein übersichtliches CMS zur Verwaltung.

## 📱 App-Vorschau
<style>
    .photo-grid {
    display: grid;
    /* Standard für Desktop: Exakt 4 Spalten nebeneinander */
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
}

.photo-item img {
    width: 100%;
    height: auto;
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    display: block;
}

/* Automatischer Sprung bei kleineren Bildschirmen / Handys */
@media (max-width: 900px) {
    .photo-grid {
        /* Ab 900px Bildschirmbreite wechselt es automatisch zu 2x2 */
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 500px) {
    .photo-grid {
        /* Auf Handys unter 500px springt es automatisch auf 1 Bild pro Zeile untereinander */
        grid-template-columns: 1fr;
    }
}
</style>
<div class="photo-grid">
    <div class="photo-item"><img src="screenshots/karte.png" alt="Karte"></div>
    <div class="photo-item"><img src="screenshots/anmedlung.png" alt="Anmeldung"></div>
    <div class="photo-item"><img src="screenshots/karte-cms.png" alt="Admin CMS"></div>
    <div class="photo-item"><img src="screenshots/einstellungen.png" alt="Ansicht"></div>
</div>

---

## 🚀 Funktionen

* **Interaktive Karte:** Zeigt alle freigeschalteten Flohmarktstände auf einer OpenStreetMap-Karte inklusive Beschreibung und Adresse an.
* **Stand-Anmeldung:** Komfortable Online-Anmeldung für Teilnehmer mit automatischer Adressvalidierung (Nominatim/OpenStreetMap).
* **Automatischer Anmeldeschluss:** Zeitgesteuerte oder manuelle Deaktivierung der Anmeldung.
* **Admin-Bereich (CMS):** Freigabe, Deaktivierung und Löschung von Ständen, Anpassung von Texten (Impressum, Datenschutz) sowie Definition des erlaubten PLZ-Gebiets und eines farbigen Begrenzungs-Polygons.
* **Datenschutz & Sicherheit:** Token-basierte Stand-Löschung durch die Teilnehmer selbst.

---

## 📦 Anleitung zum einfachen Selbst-Hosting

Die Anwendung benötigt einen einfachen Webserver mit **PHP** und einer **MySQL- / MariaDB-Datenbank**.

### Voraussetzungen

* Webhosting-Paket oder ein eigener Server (z. B. VPS, Raspberry Pi, XAMPP für lokal)
* PHP ab Version 7.4 / 8.x
* MySQL oder MariaDB Datenbank

### Schritt-für-Schritt-Installation

1. **Dateien hochladen:**
Lade alle Projektdateien (`index.php`, `admin.php`, `config.php`, etc.) in das Root-Verzeichnis deines Webservers hoch.
2. **Datenbank anlegen:**
Erstelle auf deinem Server eine neue MySQL-Datenbank.
3. **Konfiguration anpassen (`config.php`):**
Öffne die Datei `config.php` und trage deine Datenbank-Zugangsdaten sowie dein gewünschtes Administrator-Passwort ein:
```php
$db_host = 'localhost';
$db_user = 'dein_datenbank_benutzer';
$db_pass = 'dein_datenbank_passwort';
$db_name = 'dein_datenbank_name';

$admin_pass = 'DeinSicheresAdminPasswort';

```


4. **Tabellen einrichten & Starten:**
Beim ersten Aufruf der Website oder des Admin-Bereichs verbindet sich das Skript mit der Datenbank. (Hinweis: Stelle sicher, dass die Tabellenstruktur gemäß der App-Konfiguration in der Datenbank hinterlegt ist).
5. **Admin-Bereich aufrufen:**
Rufe `admin.php` in deinem Browser auf, logge dich mit deinem Passwort ein und passe unter *Texte & Anmelde-Status* sowie *Gebiet & Karte* die Einstellungen an deinen Viertel-Flohmarkt an!


---

## 📄 Lizenz & Nutzung

Dieses Projekt ist **vollständig Open-Source und kostenlos**.

Es steht unter der **Creative Commons Attribution-NonCommercial 4.0 International (CC BY-NC 4.0)** Lizenz. Das bedeutet:

* Du darfst die Software frei nutzen, kopieren, verändern und weitergeben.
* **Wichtige Bedingung:** Eine kommerzielle Nutzung oder der kostenpflichtige Vertrieb der Software ist strengstens untersagt.
