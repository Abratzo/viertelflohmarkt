<?php
require 'config.php'; 

$client_ip = flohmarkt_client_ip();
$pass_error = '';

// --- LOGIN VERARBEITUNG ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    csrf_require();
    $locked_until = flohmarkt_login_is_locked($pdo, $client_ip);
    if ($locked_until) {
        $error = "Zu viele Fehlversuche. Bitte versuche es nach " . $locked_until->format('H:i:s') . " Uhr erneut.";
    } elseif (password_verify($_POST['password'], $admin_pass_hash)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        flohmarkt_login_register_success($pdo, $client_ip);
    } else {
        flohmarkt_login_register_failure($pdo, $client_ip);
        $error = "Falsches Passwort!";
    }
}

// --- LOGOUT VERARBEITUNG ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) { 
    csrf_require();
    session_destroy(); 
    header("Location: admin.php"); 
    exit; 
}

// --- LOGIN-MASKE ---
if (!isset($_SESSION['admin_logged_in'])) {
    $locked_until = flohmarkt_login_is_locked($pdo, $client_ip);
    ?>
    <!DOCTYPE html><html lang="de"><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Login</title>
    <style>body{font-family:sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;height:100vh;} .login{background:white;padding:20px;border-radius:5px;box-shadow:0 0 10px rgba(0,0,0,0.1); width: 300px;} input,button{width:100%;padding:10px;margin-bottom:10px;box-sizing:border-box;}</style></head>
    <body><div class="login"><h2>Admin-Bereich</h2>
    <?php if ($locked_until): ?>
        <p style="color:red">Zu viele Fehlversuche. Bitte versuche es nach <?= $locked_until->format('H:i:s') ?> Uhr erneut.</p>
    <?php else: ?>
        <?php if(isset($error)) echo "<p style='color:red'>" . htmlspecialchars($error) . "</p>"; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="password" name="password" placeholder="Passwort (Default: admin123)" required>
            <button type="submit">Login</button>
        </form>
    <?php endif; ?>
    </div></body></html>
    <?php exit;
}

// --- PASSWORT ÄNDERN VERARBEITUNG ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    csrf_require();
    $new_pass = $_POST['new_password'] ?? '';
    $new_pass_confirm = $_POST['new_password_confirm'] ?? '';

    if (strlen($new_pass) < 6) {
        $pass_error = "Das neue Passwort muss mindestens 6 Zeichen lang sein.";
    } elseif ($new_pass !== $new_pass_confirm) {
        $pass_error = "Die Passwörter stimmen nicht überein.";
    } else {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO flohmarkt_settings (s_key, s_value) VALUES ('admin_pass_hash', ?) ON DUPLICATE KEY UPDATE s_value = VALUES(s_value)");
        $stmt->execute([$new_hash]);
        header("Location: admin.php?tab=settings&msg=pass_changed");
        exit;
    }
}

// Prüfen, ob das Standard-Passwort noch genutzt wird
$is_default_password = password_verify('admin123', $admin_pass_hash);

// --- ERSTEINRICHTUNG / PASSWORT-ZWANGSÄNDERUNG ---
if ($is_default_password) {
    ?>
    <!DOCTYPE html><html lang="de"><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Passwort ändern</title>
    <style>body{font-family:sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;height:100vh;} .box{background:white;padding:25px;border-radius:5px;box-shadow:0 0 10px rgba(0,0,0,0.1); max-width:400px; width:100%;} input,button{width:100%;padding:10px;margin-bottom:10px;box-sizing:border-box;} .alert{background:#fff3cd; color:#856404; padding:10px; border-radius:4px; margin-bottom:15px; border:1px solid #ffeeba;}</style></head>
    <body><div class="box">
        <h3>⚠️ Passwort-Änderung erforderlich</h3>
        <div class="alert">Du nutzt aktuell noch das Standard-Passwort (<code>admin123</code>). Bitte vergib zur Sicherheit ein neues Passwort.</div>
        <?php if($pass_error) echo "<p style='color:red;'>".htmlspecialchars($pass_error)."</p>"; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="change_password" value="1">
            <label>Neues Passwort:</label>
            <input type="password" name="new_password" required minlength="6">
            <label>Passwort wiederholen:</label>
            <input type="password" name="new_password_confirm" required minlength="6">
            <button type="submit" style="background:#0056b3; color:white; border:none; cursor:pointer; font-weight:bold;">Passwort jetzt speichern</button>
        </form>
    </div></body></html>
    <?php exit;
}

$status = flohmarkt_registration_status($settings);
$tab = $_GET['tab'] ?? 'staende';

// --- EINSTELLUNGEN SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    csrf_require();
    $stmt = $pdo->prepare("INSERT INTO flohmarkt_settings (s_key, s_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE s_value = VALUES(s_value)");
    $fields = ['title', 'date_text', 'allowed_plz', 'allowed_ort', 'impressum_text', 'datenschutz_text', 'registration_deadline'];
    $html_fields = ['date_text', 'impressum_text', 'datenschutz_text'];

    foreach($fields as $f) {
        if (isset($_POST[$f])) {
            $value = $_POST[$f];
            if (in_array($f, $html_fields, true)) { $value = flohmarkt_sanitize_html($value); }
            $stmt->execute([$f, $value]);
        }
    }
    $reg_active_val = isset($_POST['registration_active']) ? '1' : '0';
    $stmt->execute(['registration_active', $reg_active_val]);
    header("Location: admin.php?tab=settings&msg=saved"); exit;
}

// --- KARTE SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_map'])) {
    csrf_require();
    $map_lat = filter_var($_POST['map_lat'] ?? '', FILTER_VALIDATE_FLOAT);
    $map_lng = filter_var($_POST['map_lng'] ?? '', FILTER_VALIDATE_FLOAT);
    $map_zoom = filter_var($_POST['map_zoom'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 19]]);
    $polygon_color = $_POST['polygon_color'] ?? '#ff0000';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $polygon_color)) { $polygon_color = '#ff0000'; }

    $decoded = json_decode($_POST['polygon_coords'] ?? '[]', true);
    $clean_coords = [];
    if (is_array($decoded)) {
        foreach ($decoded as $pt) {
            if (is_array($pt) && count($pt) === 2 && is_numeric($pt[0]) && is_numeric($pt[1])) {
                $clean_coords[] = [(float)$pt[0], (float)$pt[1]];
            }
        }
    }
    $polygon_coords = json_encode($clean_coords);

    if ($map_lat === false || $map_lng === false || $map_zoom === false) {
        header("Location: admin.php?tab=map&msg=error"); exit;
    }

    $stmt = $pdo->prepare("INSERT INTO flohmarkt_settings (s_key, s_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE s_value = VALUES(s_value)");
    $stmt->execute(['map_lat', $map_lat]);
    $stmt->execute(['map_lng', $map_lng]);
    $stmt->execute(['map_zoom', $map_zoom]);
    $stmt->execute(['polygon_coords', $polygon_coords]);
    $stmt->execute(['polygon_color', $polygon_color]);
    header("Location: admin.php?tab=map&msg=saved"); exit;
}

// --- STÄNDE AKTIONEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_approve'])) {
    csrf_require();
    $pdo->prepare("UPDATE flohmarkt_staende SET is_approved = 1 WHERE id = ?")->execute([(int)$_POST['approve_id']]);
    header("Location: admin.php?tab=staende"); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_unapprove'])) {
    csrf_require();
    $pdo->prepare("UPDATE flohmarkt_staende SET is_approved = 0 WHERE id = ?")->execute([(int)$_POST['unapprove_id']]);
    header("Location: admin.php?tab=staende"); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_delete'])) {
    csrf_require();
    $pdo->prepare("DELETE FROM flohmarkt_staende WHERE id = ?")->execute([(int)$_POST['delete_id']]);
    header("Location: admin.php?tab=staende"); exit;
}

$staende = $pdo->query("SELECT * FROM flohmarkt_staende ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flohmarkt Admin</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; padding: 20px; margin: 0; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .tabs { display: flex; margin-bottom: 20px; border-bottom: 2px solid #ccc; flex-wrap: wrap; }
        .tabs a { padding: 10px 20px; text-decoration: none; color: #333; font-weight: bold; border-bottom: 3px solid transparent; margin-bottom: -2px; }
        .tabs a.active { border-bottom: 3px solid #0056b3; color: #0056b3; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        input[type="text"], input[type="password"], input[type="datetime-local"], textarea { width: 100%; padding: 10px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        button { background: #0056b3; color: white; padding: 10px 20px; border: none; cursor: pointer; border-radius: 4px; font-weight: bold; }
        button:hover { background: #004494; }
        .msg { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-radius: 4px; border: 1px solid #c3e6cb; }
        .card { border: 1px solid #ccc; padding: 15px; margin-bottom: 15px; border-radius: 4px; }
        .card.pending { border-left: 5px solid orange; background: #fffcf5; }
        .card.approved { border-left: 5px solid green; background: #f5fff5; }
        .actions { display: flex; flex-wrap: wrap; gap: 5px; }
        .actions form { display: inline; margin: 0; }
        .actions .action-btn { display: inline-block; padding: 6px 10px; color: white; text-decoration: none; border: none; border-radius: 4px; font-size: 13px; cursor: pointer; font-weight: normal; }
        #admin-map { height: 500px; width: 100%; margin-bottom: 15px; border: 1px solid #ccc; }
    </style>
</head>
<body>
<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>⚙️ Flohmarkt CMS</h2>
        <form method="POST" style="display:inline; margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="logout" value="1">
            <button type="submit" style="color:red; background:none; border:none; text-decoration:underline; cursor:pointer; font-size:16px;">Logout</button>
        </form>
    </div>

    <div class="tabs">
        <a href="?tab=staende" class="<?= $tab==='staende'?'active':'' ?>">📝 Anmeldungen</a>
        <a href="?tab=settings" class="<?= $tab==='settings'?'active':'' ?>">📄 Texte & Anmelde-Status</a>
        <a href="?tab=map" class="<?= $tab==='map'?'active':'' ?>">🗺️ Gebiet & Karte</a>
    </div>

    <?php if(isset($_GET['msg']) && $_GET['msg']=='saved') echo "<div class='msg'>✅ Einstellungen gespeichert!</div>"; ?>
    <?php if(isset($_GET['msg']) && $_GET['msg']=='pass_changed') echo "<div class='msg'>🔑 Passwort wurde erfolgreich geändert!</div>"; ?>
    <?php if(isset($_GET['msg']) && $_GET['msg']=='error') echo "<div class='msg' style='background:#f8d7da;color:#721c24;'>⚠️ Fehler beim Speichern.</div>"; ?>

    <?php if($tab === 'staende'): ?>
        <?php foreach ($staende as $stand): 
            $adresse_parts = explode(',', $stand['adresse']);
            $short_address = trim($adresse_parts[0] . (isset($adresse_parts[1]) ? ',' . $adresse_parts[1] : ''));
        ?>
            <div class="card <?= $stand['is_approved'] ? 'approved' : 'pending' ?>">
                <p><strong>Name:</strong> <?= htmlspecialchars($stand['name']) ?> (<?= htmlspecialchars($stand['email']) ?>)</p>
                <p><strong>Adresse:</strong> <span title="<?= htmlspecialchars($stand['adresse']) ?>"><?= htmlspecialchars($short_address) ?></span></p>
                <p><strong>Angebot:</strong> <?= nl2br(htmlspecialchars($stand['beschreibung'])) ?></p>
                <div class="actions">
                    <?php if (!$stand['is_approved']): ?>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="approve_id" value="<?= (int)$stand['id'] ?>">
                            <button type="submit" name="do_approve" value="1" class="action-btn" style="background:#28a745;">✅ Freigeben</button>
                        </form>
                    <?php else: ?>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="unapprove_id" value="<?= (int)$stand['id'] ?>">
                            <button type="submit" name="do_unapprove" value="1" class="action-btn" style="background:#f0ad4e;">⏸️ Deaktivieren</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('Wirklich löschen?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_id" value="<?= (int)$stand['id'] ?>">
                        <button type="submit" name="do_delete" value="1" class="action-btn" style="background:#dc3545;">🗑️ Löschen</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if(count($staende)==0) echo "<p>Keine Anmeldungen vorhanden.</p>"; ?>
    
    <?php elseif($tab === 'settings'): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="save_settings" value="1">
            
            <fieldset style="border: 1px solid #0056b3; padding: 15px; border-radius: 4px; margin-bottom: 20px; background: #f9fbfd;">
                <legend style="font-weight: bold; color: #0056b3; padding: 0 5px;">🚀 Anmelde-Status für Besucher</legend>
                <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 10px;">
                    <input type="checkbox" name="registration_active" id="reg_active" value="1" <?= (isset($settings['registration_active']) && $settings['registration_active'] == '1') ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                    <label for="reg_active" style="margin: 0; cursor: pointer;">Online-Anmeldung erlauben</label>
                </div>
                <div class="form-group">
                    <label>Automatische Schließung (Datum & Uhrzeit):</label>
                    <input type="datetime-local" name="registration_deadline" value="<?= str_replace(' ', 'T', $settings['registration_deadline'] ?? '') ?>">
                </div>
            </fieldset>

            <div class="form-group"><label>Name des Flohmarkts:</label><input type="text" name="title" value="<?= htmlspecialchars($settings['title'] ?? '') ?>"></div>
            <div class="form-group"><label>Datum & Uhrzeit (HTML erlaubt):</label><input type="text" name="date_text" value="<?= htmlspecialchars($settings['date_text'] ?? '') ?>"></div>
            <div style="display:flex; gap:10px;">
                <div class="form-group" style="flex:1;"><label>Erlaubte PLZ:</label><input type="text" name="allowed_plz" value="<?= htmlspecialchars($settings['allowed_plz'] ?? '') ?>"></div>
                <div class="form-group" style="flex:1;"><label>Erlaubter Ort:</label><input type="text" name="allowed_ort" value="<?= htmlspecialchars($settings['allowed_ort'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label>Impressum:</label><textarea name="impressum_text" rows="5"><?= htmlspecialchars($settings['impressum_text'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Datenschutz-Erklärung:</label><textarea name="datenschutz_text" rows="5"><?= htmlspecialchars($settings['datenschutz_text'] ?? '') ?></textarea></div>
            <button type="submit">💾 Einstellungen speichern</button>
        </form>

        <hr style="margin: 30px 0;">

        <!-- FORMULAR FÜR SPÄTERE PASSWORT-ÄNDERUNGEN -->
        <h3>🔑 Admin-Passwort ändern</h3>
        <?php if($pass_error) echo "<p style='color:red;'>".htmlspecialchars($pass_error)."</p>"; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="change_password" value="1">
            <div class="form-group"><label>Neues Passwort:</label><input type="password" name="new_password" required minlength="6"></div>
            <div class="form-group"><label>Neues Passwort wiederholen:</label><input type="password" name="new_password_confirm" required minlength="6"></div>
            <button type="submit" style="background:#6c757d;">Passwort aktualisieren</button>
        </form>

    <?php elseif($tab === 'map'): ?>
        <p>1. Bewege die Karte auf den gewünschten <b>Start-Ausschnitt</b>.<br>
           2. <b>Klicke auf die Karte</b>, um Punkte für das farbige Gebiet zu setzen.<br>
           3. Klicke auf "Speichern".</p>
        
        <div id="admin-map"></div>
        
        <form method="POST" id="mapForm">
            <?= csrf_field() ?>
            <input type="hidden" name="save_map" value="1">
            <input type="hidden" name="map_lat" id="inp_lat" value="<?= htmlspecialchars($settings['map_lat'] ?? '') ?>">
            <input type="hidden" name="map_lng" id="inp_lng" value="<?= htmlspecialchars($settings['map_lng'] ?? '') ?>">
            <input type="hidden" name="map_zoom" id="inp_zoom" value="<?= htmlspecialchars($settings['map_zoom'] ?? '') ?>">
            <input type="hidden" name="polygon_coords" id="inp_poly" value="<?= htmlspecialchars($settings['polygon_coords'] ?? '[]') ?>">
            
            <div class="form-group" style="display:flex; align-items:center; gap:15px;">
                <label style="margin:0;">Farbe der Grenze:</label>
                <input type="color" name="polygon_color" value="<?= htmlspecialchars($settings['polygon_color'] ?? '#ff0000') ?>" style="height:35px; width:50px;">
                <button type="button" onclick="clearPolygon()" style="background:#6c757d;">🔄 Polygon löschen</button>
            </div>
            
            <button type="button" onclick="saveMapData()" style="width:100%; font-size:16px;">💾 Karten-Ansicht & Gebiet speichern</button>
        </form>

        <script>
            var map = L.map('admin-map').setView([<?= json_encode((float)($settings['map_lat'] ?? 49.745472)) ?>, <?= json_encode((float)($settings['map_lng'] ?? 8.483472)) ?>], <?= json_encode((int)($settings['map_zoom'] ?? 15)) ?>);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);

            var polyPoints = <?= json_encode(json_decode($settings['polygon_coords'] ?? '[]', true) ?: []) ?>;
            var polygon = L.polygon(polyPoints, {color: <?= json_encode($settings['polygon_color'] ?? '#ff0000') ?>}).addTo(map);

            map.on('click', function(e) {
                polyPoints.push([e.latlng.lat, e.latlng.lng]);
                polygon.setLatLngs(polyPoints);
                document.getElementById('inp_poly').value = JSON.stringify(polyPoints);
            });

            function clearPolygon() {
                polyPoints = [];
                polygon.setLatLngs(polyPoints);
                document.getElementById('inp_poly').value = '[]';
            }

            function saveMapData() {
                var center = map.getCenter();
                document.getElementById('inp_lat').value = center.lat;
                document.getElementById('inp_lng').value = center.lng;
                document.getElementById('inp_zoom').value = map.getZoom();
                document.getElementById('mapForm').submit();
            }
        </script>
    <?php endif; ?>
</div>
</body>
</html>