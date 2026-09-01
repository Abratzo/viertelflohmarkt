<?php
// config.php - Datenbank- und Systemkonfiguration

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direkter Zugriff nicht erlaubt.');
}

if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,   // Cookie nur über HTTPS senden (sofern per HTTPS aufgerufen)
        'httponly' => true,      // Kein Zugriff per JavaScript (schützt Session-ID vor XSS)
        'samesite' => 'Lax',     // Grundschutz gegen CSRF-getriggerte Cross-Site-Requests
    ]);
    session_start();
}

$app_domain = 'DOMAIN'; 

$db_host = 'localhost';
$db_name = 'DB-Name';        // Dein DB-Name
$db_user = 'DB-Nutzer';      // Dein DB-Nutzer
$db_pass = 'DB-PASSWORT';    // <-- Hier dein DB-Passwort

// --- CSRF-SCHUTZ ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string {
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_require(): void {
    if (!csrf_verify()) {
        http_response_code(403);
        die('Sicherheitsprüfung fehlgeschlagen. Bitte lade die Seite neu.');
    }
}

// --- RATE-LIMITING (Login & Registrierung) ---
function flohmarkt_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function flohmarkt_login_is_locked(PDO $pdo, string $ip): ?DateTime {
    $stmt = $pdo->prepare("SELECT locked_until FROM flohmarkt_login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();

    if ($row && !empty($row['locked_until'])) {
        $tz = new DateTimeZone('Europe/Berlin');
        $locked = DateTime::createFromFormat('Y-m-d H:i:s', $row['locked_until'], $tz);
        $now = new DateTime('now', $tz);
        if ($locked && $now < $locked) {
            return $locked;
        }
    }
    return null;
}

function flohmarkt_login_register_failure(PDO $pdo, string $ip): void {
    $tz = new DateTimeZone('Europe/Berlin');
    $now = new DateTime('now', $tz);

    $stmt = $pdo->prepare("SELECT attempts FROM flohmarkt_login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    $attempts = $row ? ((int)$row['attempts'] + 1) : 1;

    $locked_until = null;
    if ($attempts >= 5) {
        $minutes = min(30, ($attempts - 4) * 2);
        $lock = clone $now;
        $lock->modify("+{$minutes} minutes");
        $locked_until = $lock->format('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare("INSERT INTO flohmarkt_login_attempts (ip, attempts, last_attempt, locked_until)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), last_attempt = VALUES(last_attempt), locked_until = VALUES(locked_until)");
    $stmt->execute([$ip, $attempts, $now->format('Y-m-d H:i:s'), $locked_until]);
}

function flohmarkt_login_register_success(PDO $pdo, string $ip): void {
    $pdo->prepare("DELETE FROM flohmarkt_login_attempts WHERE ip = ?")->execute([$ip]);
}

// --- RATE-LIMITING FÜR DIE STAND-ANMELDUNG (Captcha-Schutz) ---
function flohmarkt_reg_is_locked(PDO $pdo, string $ip): ?DateTime {
    $stmt = $pdo->prepare("SELECT locked_until FROM flohmarkt_reg_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if ($row && !empty($row['locked_until'])) {
        $tz = new DateTimeZone('Europe/Berlin');
        $locked = DateTime::createFromFormat('Y-m-d H:i:s', $row['locked_until'], $tz);
        $now = new DateTime('now', $tz);
        if ($locked && $now < $locked) {
            return $locked;
        }
    }
    return null;
}

function flohmarkt_reg_register_failure(PDO $pdo, string $ip): void {
    $tz = new DateTimeZone('Europe/Berlin');
    $now = new DateTime('now', $tz);
    $stmt = $pdo->prepare("SELECT attempts FROM flohmarkt_reg_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    $attempts = $row ? ((int)$row['attempts'] + 1) : 1;

    $locked_until = null;
    if ($attempts >= 3) {
        $minutes = 15; // 15 Minuten Sperre nach 3 Fehlversuchen
        $lock = clone $now;
        $lock->modify("+{$minutes} minutes");
        $locked_until = $lock->format('Y-m-d H:i:s');
    }
    $stmt = $pdo->prepare("INSERT INTO flohmarkt_reg_attempts (ip, attempts, last_attempt, locked_until)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), last_attempt = VALUES(last_attempt), locked_until = VALUES(locked_until)");
    $stmt->execute([$ip, $attempts, $now->format('Y-m-d H:i:s'), $locked_until]);
}

function flohmarkt_reg_register_success(PDO $pdo, string $ip): void {
    $pdo->prepare("DELETE FROM flohmarkt_reg_attempts WHERE ip = ?")->execute([$ip]);
}

// --- EINFACHES, SELBST GEHOSTETES CAPTCHA (Rechenaufgabe) ---
// Kein externer Dienst (kein Google/hCaptcha) nötig, funktioniert daher ohne
// API-Keys und ohne Daten an Dritte zu senden. Kombiniert mit Honeypot und
// IP-Rate-Limiting reicht das für den erwarteten Bot-Traffic einer kleinen
// Dorf-Veranstaltung locker aus.
function flohmarkt_captcha_new(): array {
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $_SESSION['captcha_answer'] = $a + $b;
    return ['a' => $a, 'b' => $b];
}

function flohmarkt_captcha_verify(?string $answer): bool {
    $expected = $_SESSION['captcha_answer'] ?? null;
    unset($_SESSION['captcha_answer']); // Einmal-Verwendung, verhindert Replay
    if ($expected === null || $answer === null || trim($answer) === '') return false;
    return (int)trim($answer) === (int)$expected;
}

// --- UTF-8-SICHERER MAILVERSAND ---
// PHP's mail() setzt ohne explizite Header kein UTF-8, wodurch Umlaute
// (ö, ä, ü, ß) im Betreff und Text kaputt gehen. Betreff wird MIME-kodiert,
// Body bekommt einen expliziten Content-Type-Header.
function flohmarkt_send_mail(string $to, string $subject, string $body, string $fromDomain): bool {
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = "From: noreply@" . $fromDomain . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit";
    return @mail($to, $encoded_subject, $body, $headers);
}

// --- EINSTELLUNG SPEICHERN (kleiner Helfer für Scheduler & Admin) ---
function flohmarkt_set_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO flohmarkt_settings (s_key, s_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE s_value = VALUES(s_value)");
    $stmt->execute([$key, $value]);
}

// --- ADMIN-BENACHRICHTIGUNGEN & AUTOMATISCHES LÖSCHEN (Scheduler) ---
// Da auf einfachem Webhosting oft kein zuverlässiger Cronjob läuft, wird
// diese Funktion opportunistisch bei JEDEM Seitenaufruf (index.php UND
// admin.php) ausgeführt. Sie ist bewusst günstig gehalten (wenige Selects,
// nur bei Bedarf ein Mailversand) und schadet daher nicht bei häufigem
// Aufruf. Für exaktes Timing kann optional cron.php per echtem Cronjob
// aufgerufen werden - dieselbe Funktion wird dann einfach zusätzlich/eher
// ausgelöst.
function flohmarkt_run_scheduled_tasks(PDO $pdo, array $settings, string $app_domain): void {
    $tz = new DateTimeZone('Europe/Berlin');
    $now = new DateTime('now', $tz);
    $notify_email = trim($settings['admin_notify_email'] ?? '');
    $notify_mode = $settings['admin_notify_mode'] ?? 'off';

    // 1. Tägliche/mehrtägige Sammel-Benachrichtigung über Neuanmeldungen
    if ($notify_mode === 'daily' && $notify_email !== '') {
        $interval_days = max(1, min(7, (int)($settings['admin_notify_interval_days'] ?? 1)));
        $last_sent_raw = trim($settings['admin_notify_last_sent'] ?? '');
        $due = true;
        if ($last_sent_raw !== '') {
            $last_sent = DateTime::createFromFormat('Y-m-d H:i:s', $last_sent_raw, $tz);
            if ($last_sent) {
                $next_due = clone $last_sent;
                $next_due->modify("+{$interval_days} days");
                $due = ($now >= $next_due);
            }
        }
        if ($due) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM flohmarkt_staende WHERE created_at > ?");
            $stmt->execute([$last_sent_raw !== '' ? $last_sent_raw : '1970-01-01 00:00:00']);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $body = "Hallo,\n\nseit der letzten Zusammenfassung haben sich $count neue Standbetreiber:innen für \""
                    . ($settings['title'] ?? 'den Flohmarkt') . "\" angemeldet und warten ggf. auf Freischaltung.\n\n"
                    . "Bitte im Admin-Bereich prüfen: https://" . $app_domain . "/admin.php?tab=staende";
                flohmarkt_send_mail($notify_email, "Flohmarkt: $count neue Anmeldung(en)", $body, $app_domain);
            }
            flohmarkt_set_setting($pdo, 'admin_notify_last_sent', $now->format('Y-m-d H:i:s'));
        }
    }

    // 2. Erinnerung, sobald die Anmeldefrist abgelaufen ist (einmalig pro Frist)
    if ($notify_mode !== 'off' && $notify_email !== '') {
        $deadline_raw = trim($settings['registration_deadline'] ?? '');
        if ($deadline_raw !== '' && $deadline_raw !== '0000-00-00 00:00:00') {
            $normalized = str_replace('T', ' ', $deadline_raw);
            $deadline = DateTime::createFromFormat('Y-m-d H:i:s', $normalized, $tz) ?: DateTime::createFromFormat('Y-m-d H:i', $normalized, $tz);
            $already_sent_for = $settings['admin_notify_deadline_sent_for'] ?? '';
            if ($deadline && $now >= $deadline && $already_sent_for !== $deadline_raw) {
                $body = "Hallo,\n\ndie Anmeldephase für \"" . ($settings['title'] ?? 'den Flohmarkt') . "\" ist soeben abgelaufen "
                    . "(Stichtag: " . $deadline->format('d.m.Y H:i') . " Uhr).\n\n"
                    . "Bitte prüfe im Admin-Bereich, ob noch Anmeldungen zur Freischaltung warten: https://" . $app_domain . "/admin.php?tab=staende";
                flohmarkt_send_mail($notify_email, "Flohmarkt: Anmeldephase abgelaufen", $body, $app_domain);
                flohmarkt_set_setting($pdo, 'admin_notify_deadline_sent_for', $deadline_raw);
            }
        }
    }

    // 3. Automatisches Löschen der Teilnehmerdaten nach Ablauf des Flohmarkts
    if (($settings['auto_delete_enabled'] ?? '0') === '1') {
        $delete_raw = trim($settings['auto_delete_date'] ?? '');
        $already_done_for = $settings['auto_delete_executed_for'] ?? '';
        if ($delete_raw !== '' && $delete_raw !== $already_done_for) {
            $normalized = str_replace('T', ' ', $delete_raw);
            $delete_at = DateTime::createFromFormat('Y-m-d H:i:s', $normalized, $tz) ?: DateTime::createFromFormat('Y-m-d H:i', $normalized, $tz);
            if ($delete_at && $now >= $delete_at) {
                $pdo->exec("TRUNCATE TABLE flohmarkt_staende");
                flohmarkt_set_setting($pdo, 'auto_delete_executed_for', $delete_raw);
                if ($notify_email !== '') {
                    $body = "Hallo,\n\nwie in den Einstellungen festgelegt, wurden die Teilnehmerdaten (Name, E-Mail, Adresse, Angebote) "
                        . "von \"" . ($settings['title'] ?? 'deinem Flohmarkt') . "\" soeben automatisch gelöscht "
                        . "(geplant für: " . $delete_at->format('d.m.Y H:i') . " Uhr).\n\n"
                        . "Info-Points und die allgemeinen Einstellungen sind davon nicht betroffen.";
                    flohmarkt_send_mail($notify_email, "Flohmarkt: Teilnehmerdaten automatisch gelöscht", $body, $app_domain);
                }
            }
        }
    }
}

// --- HTML-WHITELIST-FILTER (für WYSIWYG Editor) ---
function flohmarkt_sanitize_html(string $html): string {
    if (trim($html) === '') return '';
    $dangerousTags = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'link', 'meta', 'base', 'svg', 'math', 'button', 'input', 'textarea', 'select', 'option', 'audio', 'video', 'source', 'track', 'applet'];
    $allowedTags = ['a' => ['href', 'title', 'target'], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 'br' => [], 'p' => [], 'span' => [], 'div' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'small' => []];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?><div id="flohmarkt-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    $root = $dom->getElementById('flohmarkt-root');
    if (!$root) return '';

    flohmarkt_sanitize_walk($root, $allowedTags, $dangerousTags);
    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $dom->saveHTML($child);
    }
    return trim($out);
}

function flohmarkt_sanitize_walk(DOMNode $context, array $allowedTags, array $dangerousTags): void {
    $toRemove = [];
    foreach (iterator_to_array($context->childNodes) as $node) {
        if ($node instanceof DOMComment) { $toRemove[] = $node; continue; }
        if ($node instanceof DOMText) continue;
        if (!($node instanceof DOMElement)) { $toRemove[] = $node; continue; }

        $tag = strtolower($node->tagName);
        if (in_array($tag, $dangerousTags, true)) { $toRemove[] = $node; continue; }

        if (!isset($allowedTags[$tag])) {
            while ($node->firstChild) { $context->insertBefore($node->firstChild, $node); }
            $toRemove[] = $node; continue;
        }

        $allowedAttrs = $allowedTags[$tag];
        foreach (iterator_to_array($node->attributes) as $attr) {
            $aname = strtolower($attr->name);
            if (str_starts_with($aname, 'on') || !in_array($aname, $allowedAttrs, true)) {
                $node->removeAttribute($attr->name); continue;
            }
            if ($aname === 'href') {
                $val = trim($attr->value);
                if (!preg_match('/^(https?:|mailto:|\/|#)/i', $val)) { $node->removeAttribute('href'); }
            }
        }
        if ($node->hasAttribute('target')) { $node->setAttribute('rel', 'noopener noreferrer nofollow'); }
        flohmarkt_sanitize_walk($node, $allowedTags, $dangerousTags);
    }
    foreach ($toRemove as $n) { if ($n->parentNode === $context) { $context->removeChild($n); } }
}

// --- SERVERSEITIGE GEBIETSPRÜFUNG (Punkt-in-Polygon) ---
function flohmarkt_point_in_polygon(float $lat, float $lng, array $polygon): bool {
    $n = count($polygon);
    if ($n < 3) return false; 

    $inside = false;
    $j = $n - 1;
    for ($i = 0; $i < $n; $i++) {
        $latI = (float)($polygon[$i][0] ?? NAN);
        $lngI = (float)($polygon[$i][1] ?? NAN);
        $latJ = (float)($polygon[$j][0] ?? NAN);
        $lngJ = (float)($polygon[$j][1] ?? NAN);

        $intersects = (($lngI > $lng) !== ($lngJ > $lng))
            && ($lat < ($latJ - $latI) * ($lng - $lngI) / ($lngJ - $lngI) + $latI);
        if ($intersects) { $inside = !$inside; }
        $j = $i;
    }
    return $inside;
}

function flohmarkt_address_allowed(float $lat, float $lng, array $settings): bool {
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return false;
    $polygon = json_decode($settings['polygon_coords'] ?? '[]', true);
    if (!is_array($polygon) || count($polygon) < 3) return true;
    return flohmarkt_point_in_polygon($lat, $lng, $polygon);
}

// Erzeugt einen abgedunkelten Farbton (für Hover-/Active-Zustände), damit die
// frei wählbare Website-Farbe nicht überall exakt gleich aussieht.
function flohmarkt_darken_color(string $hex, float $percent = 0.18): string {
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return '#004494';
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $r = max(0, (int)round($r * (1 - $percent)));
    $g = max(0, (int)round($g * (1 - $percent)));
    $b = max(0, (int)round($b * (1 - $percent)));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

// Bounding-Box (kleinstes umschließendes Rechteck) des Gebiets-Polygons, um
// die Nominatim-Adresssuche auf den tatsächlich konfigurierten Bereich
// ("Gebiet & Karte") einzuschränken statt nur auf PLZ/Ort zu vertrauen.
function flohmarkt_polygon_bbox(array $settings): ?array {
    $polygon = json_decode($settings['polygon_coords'] ?? '[]', true);
    if (!is_array($polygon) || count($polygon) < 3) return null;

    $minLat = $maxLat = $minLng = $maxLng = null;
    foreach ($polygon as $pt) {
        if (!is_array($pt) || count($pt) !== 2) continue;
        $lat = (float)$pt[0]; $lng = (float)$pt[1];
        if ($minLat === null || $lat < $minLat) $minLat = $lat;
        if ($maxLat === null || $lat > $maxLat) $maxLat = $lat;
        if ($minLng === null || $lng < $minLng) $minLng = $lng;
        if ($maxLng === null || $lng > $maxLng) $maxLng = $lng;
    }
    if ($minLat === null) return null;

    // Kleinen Puffer (~300m) drumherum, damit Adressen direkt am Rand des
    // Gebiets nicht durch Rundungsfehler aus der Suche fallen.
    $buffer = 0.003;
    return [
        'minLat' => $minLat - $buffer, 'maxLat' => $maxLat + $buffer,
        'minLng' => $minLng - $buffer, 'maxLng' => $maxLng + $buffer,
    ];
}

// --- DATENBANK VERBINDUNG & TABELLEN ---
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `flohmarkt_settings` (
        `s_key` VARCHAR(50) PRIMARY KEY,
        `s_value` TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `flohmarkt_login_attempts` (
        `ip` VARCHAR(45) PRIMARY KEY,
        `attempts` INT NOT NULL DEFAULT 0,
        `last_attempt` DATETIME NOT NULL,
        `locked_until` DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS `flohmarkt_reg_attempts` (
        `ip` VARCHAR(45) PRIMARY KEY,
        `attempts` INT NOT NULL DEFAULT 0,
        `last_attempt` DATETIME NOT NULL,
        `locked_until` DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `flohmarkt_infopoints` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `type` VARCHAR(50) NOT NULL,
        `title` VARCHAR(150) NOT NULL,
        `beschreibung` TEXT NULL,
        `icon` VARCHAR(8) NULL,
        `lat` DECIMAL(10, 8) NOT NULL,
        `lng` DECIMAL(11, 8) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Migration für bereits bestehende Installationen ohne `icon`-Spalte
    // (CREATE TABLE IF NOT EXISTS legt sie bei einer schon vorhandenen
    // Tabelle nicht nachträglich an).
    $col = $pdo->query("SHOW COLUMNS FROM `flohmarkt_infopoints` LIKE 'icon'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `flohmarkt_infopoints` ADD COLUMN `icon` VARCHAR(8) NULL AFTER `beschreibung`");
    }

    $defaults = [
        'title' => 'Dorf-Flohmarkt',
        'date_text' => 'Sonntag, 20. Sept. 2026<br>10:00 - 16:00 Uhr',
        'event_date' => '2026-09-20',
        'theme_color' => '#0056b3',
        'allowed_plz' => '64653',
        'allowed_ort' => 'Lorsch',
        'map_lat' => '49.745472',
        'map_lng' => '8.483472',
        'map_zoom' => '15',
        'polygon_coords' => '[]',
        'polygon_color' => '#ff0000',
        'impressum_text' => "<h2>Impressum</h2>\n<p>Max Mustermann<br>Musterstraße 1<br>12345 Musterdorf</p>",
        'datenschutz_text' => "<h2>Datenschutz</h2>\n<p>Wir speichern Ihre Daten nur für den Flohmarkt.</p>",
        'registration_active' => '0',
        'registration_deadline' => '2026-09-18 23:59',
        'auto_approve_stands' => '0',
        'admin_notify_email' => '',
        'admin_notify_mode' => 'off',
        'admin_notify_interval_days' => '1',
        'admin_notify_last_sent' => '',
        'admin_notify_deadline_sent_for' => '',
        'auto_delete_enabled' => '0',
        'auto_delete_date' => '',
        'auto_delete_executed_for' => '',
    ];

    $insert = $pdo->prepare("INSERT IGNORE INTO flohmarkt_settings (s_key, s_value) VALUES (?, ?)");
    foreach ($defaults as $k => $v) { $insert->execute([$k, $v]); }

} catch (PDOException $e) {
    error_log('Flohmarkt DB-Fehler: ' . $e->getMessage());
    http_response_code(500);
    die('Es ist ein technisches Problem aufgetreten. Bitte versuche es später erneut.');
}

// --- ADMIN PASSWORT HASH ERMITTELN ---
$admin_pass_hash = getenv('FLOHMARKT_ADMIN_HASH');
if (!$admin_pass_hash && isset($pdo)) {
    $stmtHash = $pdo->prepare("SELECT s_value FROM flohmarkt_settings WHERE s_key = 'admin_pass_hash'");
    $stmtHash->execute();
    $rowHash = $stmtHash->fetch();
    if ($rowHash && !empty($rowHash['s_value'])) {
        $admin_pass_hash = $rowHash['s_value'];
    }
}
if (!$admin_pass_hash) {
    $admin_pass_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // admin123
}

$settings = [];
$stmt = $pdo->query("SELECT s_key, s_value FROM flohmarkt_settings");
while ($row = $stmt->fetch()) {
    $settings[$row['s_key']] = $row['s_value'];
}

// Geplante Aufgaben (Benachrichtigungen, automatisches Löschen) opportunistisch
// bei jedem Aufruf prüfen. Fehler hier dürfen die Seite nie zum Absturz bringen.
try {
    flohmarkt_run_scheduled_tasks($pdo, $settings, $app_domain);
    // Nach eventuellen Änderungen (z.B. TRUNCATE, aktualisierte Zeitstempel)
    // die Settings für den Rest des Requests neu laden.
    $settings = [];
    $stmt = $pdo->query("SELECT s_key, s_value FROM flohmarkt_settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['s_key']] = $row['s_value'];
    }
} catch (Throwable $e) {
    error_log('Flohmarkt Scheduler-Fehler: ' . $e->getMessage());
}

function flohmarkt_registration_status(array $settings): array {
    $tz = new DateTimeZone('Europe/Berlin');
    $now = new DateTime('now', $tz);
    $open = isset($settings['registration_active']) && $settings['registration_active'] === '1';
    $deadline = null;
    $deadline_error = false;
    $deadline_raw = trim($settings['registration_deadline'] ?? '');

    if ($deadline_raw !== '' && $deadline_raw !== '0000-00-00 00:00:00') {
        $normalized = str_replace('T', ' ', $deadline_raw);
        $deadline = DateTime::createFromFormat('Y-m-d H:i:s', $normalized, $tz);
        if ($deadline === false) $deadline = DateTime::createFromFormat('Y-m-d H:i', $normalized, $tz);

        if ($deadline === false) {
            $deadline = null;
            $deadline_error = true;
        } elseif ($now >= $deadline) {
            $open = false;
        }
    }
    return ['open' => $open, 'deadline' => $deadline, 'deadline_raw' => $deadline_raw, 'now' => $now, 'deadline_error' => $deadline_error];
}