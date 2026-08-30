<?php
// config.php - Datenbank- und Systemkonfiguration

$db_host = 'localhost';       
$db_name = 'w01b646e';        // Dein DB-Name
$db_user = 'w01b646e';        // Dein DB-Nutzer
$db_pass = 'DEIN_DATENBANK_PASSWORT';   // <-- Hier dein DB-Passwort
 
$admin_pass = 'flohmarkt2026';           // <-- Hier dein Passwort für die admin.php


try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Tabelle für Stände anlegen
    $pdo->exec("CREATE TABLE IF NOT EXISTS `flohmarkt_staende` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(150) NOT NULL,
        `adresse` VARCHAR(255) NOT NULL,
        `lat` DECIMAL(10, 8),
        `lng` DECIMAL(11, 8),
        `beschreibung` TEXT NOT NULL,
        `delete_token` VARCHAR(64) NULL,
        `is_approved` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $columnCheck = $pdo->query("SHOW COLUMNS FROM `flohmarkt_staende` LIKE 'delete_token'");
    if ($columnCheck->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `flohmarkt_staende` ADD COLUMN `delete_token` VARCHAR(64) NULL AFTER `beschreibung`");
    }

    // Automatisch sicherstellen, dass jede E-Mail nur einmal vorkommt (wird beim Löschen automatisch freigegeben)
    try {
        $stmtCheckIndex = $pdo->query("SHOW INDEXES FROM flohmarkt_staende WHERE Key_name = 'email'");
        if ($stmtCheckIndex->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `flohmarkt_staende` ADD UNIQUE (`email`)");
        }
    } catch (PDOException $e) {
        // Falls bereits Duplikate existieren sollten, fängt das Skript es ab
    }

    // Tabelle für Einstellungen anlegen
    $pdo->exec("CREATE TABLE IF NOT EXISTS `flohmarkt_settings` (
        `s_key` VARCHAR(50) PRIMARY KEY,
        `s_value` TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Standard-Einstellungen definieren
    $defaults = [
        'title' => 'Dorf-Flohmarkt',
        'date_text' => 'Sonntag, 20. Sept. 2026<br>10:00 - 16:00 Uhr',
        'allowed_plz' => '64653',
        'allowed_ort' => 'Lorsch',
        'map_lat' => '49.745472',
        'map_lng' => '8.483472',
        'map_zoom' => '15',
        'polygon_coords' => '[]',
        'polygon_color' => '#ff0000',
        'impressum_text' => "<h2>Impressum</h2>\n<p>Max Mustermann<br>Musterstraße 1<br>12345 Musterdorf</p>\n<p>Kontakt: mail@deinedomain.de</p>",
        'datenschutz_text' => "<h2>Datenschutz</h2>\n<p>Wir speichern Ihre E-Mail nur für den Lösch-Link.</p>",
        'registration_active' => '0',
        'registration_deadline' => '2026-09-18 23:59' 
    ];

    $insert = $pdo->prepare("INSERT IGNORE INTO flohmarkt_settings (s_key, s_value) VALUES (?, ?)");
    foreach ($defaults as $k => $v) { 
        $insert->execute([$k, $v]); 
    }
    
} catch (PDOException $e) {
    die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
}

$settings = [];
$stmt = $pdo->query("SELECT s_key, s_value FROM flohmarkt_settings");
while ($row = $stmt->fetch()) {
    $settings[$row['s_key']] = $row['s_value'];
}

function flohmarkt_registration_status(array $settings): array {
    $tz = new DateTimeZone('Europe/Berlin');
    $now = new DateTime('now', $tz);

    $open = isset($settings['registration_active']) && $settings['registration_active'] === '1';

    $deadline = null;
    $deadline_error = false;
    $deadline_raw = trim($settings['registration_deadline'] ?? '');

    if ($deadline_raw !== '' && $deadline_raw !== '0000-00-00' && $deadline_raw !== '0000-00-00 00:00:00') {
        $normalized = str_replace('T', ' ', $deadline_raw);
        $deadline = DateTime::createFromFormat('Y-m-d H:i:s', $normalized, $tz);
        if ($deadline === false) {
            $deadline = DateTime::createFromFormat('Y-m-d H:i', $normalized, $tz);
        }

        if ($deadline === false) {
            $deadline = null;
            $deadline_error = true;
        } elseif ($now >= $deadline) {
            $open = false;
        }
    }

    return [
        'open' => $open,
        'deadline' => $deadline,
        'deadline_raw' => $deadline_raw,
        'now' => $now,
        'deadline_error' => $deadline_error,
    ];
}
?>