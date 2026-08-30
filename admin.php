<?php
session_start();
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $admin_pass) { $_SESSION['admin_logged_in'] = true; } 
    else { $error = "Falsches Passwort!"; }
}
if (isset($_GET['logout'])) { session_destroy(); header("Location: admin.php"); exit; }

if (!isset($_SESSION['admin_logged_in'])) {
    ?>
    <!DOCTYPE html><html lang="de"><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Login</title>
    <style>body{font-family:sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;height:100vh;} .login{background:white;padding:20px;border-radius:5px;box-shadow:0 0 10px rgba(0,0,0,0.1);} input,button{width:100%;padding:10px;margin-bottom:10px;box-sizing:border-box;}</style></head>
    <body><div class="login"><h2>Admin-Bereich</h2>
    <?php if(isset($error)) echo "<p style='color:red'>$error</p>"; ?>
    <form method="POST"><input type="password" name="password" placeholder="Passwort" required><button type="submit">Login</button></form></div></body></html>
    <?php exit;
}

// Nutze die zentrale Logik für die Live-Vorschau des Status im Admin-Bereich
$status = flohmarkt_registration_status($settings);

$tab = $_GET['tab'] ?? 'staende';

// --- EINSTELLUNGEN SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $stmt = $pdo->prepare("INSERT INTO flohmarkt_settings (s_key, s_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE s_value = VALUES(s_value)");
    
    $fields = ['title', 'date_text', 'allowed_plz', 'allowed_ort', 'impressum_text', 'datenschutz_text', 'registration_deadline'];
    foreach($fields as $f) { 
        if (isset($_POST[$f])) {
            $stmt->execute([$f, $_POST[$f]]); 
        }
    }
    
    $reg_active_val = isset($_POST['registration_active']) ? '1' : '0';
    $stmt->execute(['registration_active', $reg_active_val]);

    header("Location: admin.php?tab=settings&msg=saved"); exit;
}

// --- KARTE & POLYGON SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_map'])) {
    $stmt = $pdo->prepare("INSERT INTO flohmarkt_settings (s_key, s_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE s_value = VALUES(s_value)");
    $stmt->execute(['map_lat', $_POST['map_lat']]);
    $stmt->execute(['map_lng', $_POST['map_lng']]);
    $stmt->execute(['map_zoom', $_POST['map_zoom']]);
    $stmt->execute(['polygon_coords', $_POST['polygon_coords']]);
    $stmt->execute(['polygon_color', $_POST['polygon_color']]);
    header("Location: admin.php?tab=map&msg=saved"); exit;
}

// --- STÄNDE AKTIONEN ---
if (isset($_GET['approve'])) {
    $pdo->prepare("UPDATE flohmarkt_staende SET is_approved = 1 WHERE id = ?")->execute([$_GET['approve']]);
    header("Location: admin.php?tab=staende"); exit;
}
// Neu: Stand wieder auf inaktiv setzen (Deaktivieren)
if (isset($_GET['unapprove'])) {
    $pdo->prepare("UPDATE flohmarkt_staende SET is_approved = 0 WHERE id = ?")->execute([$_GET['unapprove']]);
    header("Location: admin.php?tab=staende"); exit;
}
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM flohmarkt_staende WHERE id = ?")->execute([$_GET['delete']]);
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
        input[type="text"], input[type="datetime-local"], textarea { width: 100%; padding: 10px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        button { background: #0056b3; color: white; padding: 10px 20px; border: none; cursor: pointer; border-radius: 4px; font-weight: bold; }
        button:hover { background: #004494; }
        .msg { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-radius: 4px; border: 1px solid #c3e6cb; }
        .card { border: 1px solid #ccc; padding: 15px; margin-bottom: 15px; border-radius: 4px; }
        .card.pending { border-left: 5px solid orange; background: #fffcf5; }
        .card.approved { border-left: 5px solid green; background: #f5fff5; }
        .actions a { display: inline-block; padding: 6px 10px; color: white; text-decoration: none; border-radius: 4px; margin-right: 5px; font-size: 13px; }
        #admin-map { height: 500px; width: 100%; margin-bottom: 15px; border: 1px solid #ccc; }
        .admin-footer-donation { margin-top: 40px; padding: 15px; background: #eef5fb; border: 1px solid #bce8f1; border-radius: 4px; text-align: center; font-size: 0.9em; color: #31708f; }
    </style>
</head>
<body>
<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>⚙️ Flohmarkt CMS</h2>
        <a href="?logout=1" style="color:red; text-decoration:none;">Logout</a>
    </div>

    <div class="tabs">
        <a href="?tab=staende" class="<?= $tab==='staende'?'active':'' ?>">📝 Anmeldungen</a>
        <a href="?tab=settings" class="<?= $tab==='settings'?'active':'' ?>">📄 Texte & Anmelde-Status</a>
        <a href="?tab=map" class="<?= $tab==='map'?'active':'' ?>">🗺️ Gebiet & Karte</a>
    </div>

    <?php if(isset($_GET['msg']) && $_GET['msg']=='saved') echo "<div class='msg'>✅ Änderungen erfolgreich gespeichert!</div>"; ?>

    <?php if($tab === 'staende'): ?>
        <?php foreach ($staende as $stand): 
            // Adresse verkürzen, falls sie zu lang ist (nur die ersten Bestandteile vor dem Bundesland/Staat anzeigen)
            $adresse_parts = explode(',', $stand['adresse']);
            $short_address = trim($adresse_parts[0] . (isset($adresse_parts[1]) ? ',' . $adresse_parts[1] : ''));
        ?>
            <div class="card <?= $stand['is_approved'] ? 'approved' : 'pending' ?>">
                <p><strong>Name:</strong> <?= htmlspecialchars($stand['name']) ?> (<?= htmlspecialchars($stand['email']) ?>)</p>
                <p><strong>Adresse:</strong> <span title="<?= htmlspecialchars($stand['adresse']) ?>"><?= htmlspecialchars($short_address) ?></span></p>
                <p><strong>Angebot:</strong> <?= nl2br(htmlspecialchars($stand['beschreibung'])) ?></p>
                <div class="actions">
                    <?php if (!$stand['is_approved']): ?>
                        <a href="?approve=<?= $stand['id'] ?>" style="background:#28a745;">✅ Freigeben</a>
                    <?php else: ?>
                        <a href="?unapprove=<?= $stand['id'] ?>" style="background:#f0ad4e;" title="Stand auf inaktiv setzen">⏸️ Deaktivieren</a>
                    <?php endif; ?>
                    <a href="?delete=<?= $stand['id'] ?>" style="background:#dc3545;" onclick="return confirm('Wirklich löschen?');">🗑️ Löschen</a>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if(count($staende)==0) echo "<p>Keine Anmeldungen vorhanden.</p>"; ?>
    
    <?php elseif($tab === 'settings'): ?>
        <form method="POST">
            <input type="hidden" name="save_settings" value="1">
            
            <fieldset style="border: 1px solid #0056b3; padding: 15px; border-radius: 4px; margin-bottom: 20px; background: #f9fbfd;">
                <legend style="font-weight: bold; color: #0056b3; padding: 0 5px;">🚀 Anmelde-Status für Besucher</legend>
                
                <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 10px;">
                    <input type="checkbox" name="registration_active" id="reg_active" value="1" <?= (isset($settings['registration_active']) && $settings['registration_active'] == '1') ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                    <label for="reg_active" style="margin: 0; cursor: pointer;">Online-Anmeldung für neue Stände erlauben (Manueller Schalter)</label>
                </div>

                <div class="form-group">
                    <label>Automatische Schließung (Datum & Uhrzeit):</label>
                    <input type="datetime-local" name="registration_deadline" value="<?= str_replace(' ', 'T', $settings['registration_deadline'] ?? '') ?>">
                    <small style="color: #666;">Sobald dieser Zeitpunkt überschritten ist, schaltet sich die Anmeldung automatisch ab, auch wenn der obere Haken gesetzt ist.</small>
                </div>

                <div style="background: #fff; border: 1px solid #bce8f1; padding: 12px; border-radius: 4px; color: #31708f; font-size: 0.9em;">
                    <p style="margin: 0 0 5px 0;">⏱️ Server-Zeit gerade eben: <b><?= $status['now']->format('d.m.Y, H:i:s') ?> Uhr</b></p>
                    <p style="margin: 0 0 5px 0;">
                        📅 Gespeicherter Anmeldeschluss: 
                        <b>
                            <?php if ($status['deadline']): ?>
                                <?= $status['deadline']->format('d.m.Y, H:i') ?> Uhr
                            <?php elseif ($status['deadline_error']): ?>
                                <span style="color: red;">Fehler im Datumsformat!</span>
                            <?php else: ?>
                                Kein Enddatum gesetzt
                            <?php endif; ?>
                        </b>
                    </p>
                    <p style="margin: 0;">
                        ➔ Ergebnis gerade jetzt: 
                        <?php if ($status['open']): ?>
                            <span style="color: green; font-weight: bold;">Anmeldung ist OFFEN</span>
                        <?php else: ?>
                            <span style="color: red; font-weight: bold;">Anmeldung ist GESCHLOSSEN</span>
                        <?php endif; ?>
                    </p>
                </div>
            </fieldset>

            <div class="form-group"><label>Name des Flohmarkts:</label><input type="text" name="title" value="<?= htmlspecialchars($settings['title'] ?? '') ?>"></div>
            <div class="form-group"><label>Datum & Uhrzeit (HTML erlaubt wie &lt;br&gt;):</label><input type="text" name="date_text" value="<?= htmlspecialchars($settings['date_text'] ?? '') ?>"></div>
            <div style="display:flex; gap:10px;">
                <div class="form-group" style="flex:1;"><label>Erlaubte PLZ:</label><input type="text" name="allowed_plz" value="<?= htmlspecialchars($settings['allowed_plz'] ?? '') ?>"></div>
                <div class="form-group" style="flex:1;"><label>Erlaubter Ort:</label><input type="text" name="allowed_ort" value="<?= htmlspecialchars($settings['allowed_ort'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label>Impressum (HTML erlaubt):</label><textarea name="impressum_text" rows="6"><?= htmlspecialchars($settings['impressum_text'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Datenschutz-Erklärung (HTML erlaubt):</label><textarea name="datenschutz_text" rows="6"><?= htmlspecialchars($settings['datenschutz_text'] ?? '') ?></textarea></div>
            <button type="submit">💾 Einstellungen speichern</button>
        </form>
    
    <?php elseif($tab === 'map'): ?>
        <p>1. Bewege die Karte auf den gewünschten <b>Start-Ausschnitt</b>.<br>
           2. <b>Klicke auf die Karte</b>, um Punkte für das farbige Gebiet (Polygon) zu setzen.<br>
           3. Klicke auf "Speichern".</p>
        
        <div id="admin-map"></div>
        
        <form method="POST" id="mapForm">
            <input type="hidden" name="save_map" value="1">
            <input type="hidden" name="map_lat" id="inp_lat" value="<?= $settings['map_lat'] ?>">
            <input type="hidden" name="map_lng" id="inp_lng" value="<?= $settings['map_lng'] ?>">
            <input type="hidden" name="map_zoom" id="inp_zoom" value="<?= $settings['map_zoom'] ?>">
            <input type="hidden" name="polygon_coords" id="inp_poly" value="<?= htmlspecialchars($settings['polygon_coords']) ?>">
            
            <div class="form-group" style="display:flex; align-items:center; gap:15px;">
                <label style="margin:0;">Farbe der Grenze:</label>
                <input type="color" name="polygon_color" value="<?= $settings['polygon_color'] ?>" style="height:35px; width:50px;">
                
                <button type="button" onclick="clearPolygon()" style="background:#6c757d;">🔄 Polygon löschen</button>
            </div>
            
            <button type="button" onclick="saveMapData()" style="width:100%; font-size:16px;">💾 Karten-Ansicht & Gebiet speichern</button>
        </form>

        <script>
            var map = L.map('admin-map').setView([<?= $settings['map_lat'] ?>, <?= $settings['map_lng'] ?>], <?= $settings['map_zoom'] ?>);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);

            var polyPoints = <?= $settings['polygon_coords'] ?: '[]' ?>;
            var polygon = L.polygon(polyPoints, {color: '<?= $settings['polygon_color'] ?>'}).addTo(map);

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

    <!-- Spenden-Hinweis am Ende des Admin-Menüs -->
    <div class="admin-footer-donation">
        Wenn dir die App gefällt, <a href="https://de.wikipedia.org/wiki/Gemeinnützigkeit" target="_blank">spende diesem Verein statt mir etwas :)</a>
    </div>
</div>
</body>
</html>