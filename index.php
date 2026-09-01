<?php
require 'config.php';

date_default_timezone_set('Europe/Berlin');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page = $_GET['page'] ?? 'map';
$msg = '';
$msgType = 'success';
$delete_link_show = '';

$status = flohmarkt_registration_status($settings);
$registration_is_open = $status['open'];

if ($page === 'anmelden' && !$registration_is_open) {
    $page = 'map';
    $msg = "Die Anmeldung ist inzwischen leider geschlossen.";
    $msgType = 'error';
}

// --- LÖSCHEN (über Token) ---
if ($page === 'delete' && isset($_GET['token'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
        csrf_require();
        $stmt = $pdo->prepare("DELETE FROM flohmarkt_staende WHERE delete_token = ?");
        $stmt->execute([$_POST['token']]);
        if ($stmt->rowCount() > 0) { 
            $msg = "Dein Stand wurde erfolgreich gelöscht."; 
        } else { 
            $msg = "Stand nicht gefunden oder bereits gelöscht."; 
            $msgType = 'error'; 
        }
        $page = 'map';
    }
}

// --- ANMELDUNG SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'anmelden') {
    if (!$registration_is_open) {
        $msg = "Die Anmeldung ist inzwischen leider geschlossen.";
        $msgType = 'error';
        $page = 'map';
    } else {
        csrf_require();

        $client_ip = flohmarkt_client_ip();
        $reg_locked_until = flohmarkt_reg_is_locked($pdo, $client_ip);

        if (!empty($_POST['honeypot'])) {
            flohmarkt_reg_register_failure($pdo, $client_ip);
            die('Spam erkannt.');
        }

        $name = trim($_POST['name'] ?? ''); 
        $email = trim($_POST['email'] ?? ''); 
        $adresse = trim($_POST['adresse'] ?? '');
        $lat = (float)($_POST['lat'] ?? 0); 
        $lng = (float)($_POST['lng'] ?? 0); 
        $beschreibung = flohmarkt_sanitize_html(trim($_POST['beschreibung'] ?? ''));
        $captcha_ok = flohmarkt_captcha_verify($_POST['captcha_answer'] ?? null);

        if ($reg_locked_until) {
            $msg = "Zu viele fehlgeschlagene Versuche. Bitte versuche es nach " . $reg_locked_until->format('H:i:s') . " Uhr erneut.";
            $msgType = 'error';
        }
        elseif (!$captcha_ok) {
            flohmarkt_reg_register_failure($pdo, $client_ip);
            $msg = "Die Rechenaufgabe wurde nicht richtig gelöst. Bitte versuche es erneut.";
            $msgType = 'error';
        }
        elseif (!isset($_POST['datenschutz'])) { 
            $msg = "Bitte stimme den Datenschutzbedingungen zu."; 
            $msgType = 'error'; 
        } 
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "Die angegebene E-Mail-Adresse ist ungültig.";
            $msgType = 'error';
        }
        elseif (empty($name) || empty($adresse) || $lat === 0.0) {
            $msg = "Bitte suche über den Button deine Adresse und wähle sie aus!"; 
            $msgType = 'error'; 
        }
        elseif (empty($beschreibung)) {
            $msg = "Bitte beschreibe kurz, was du anbietest.";
            $msgType = 'error';
        }
        elseif (!flohmarkt_address_allowed($lat, $lng, $settings)) {
            // Serverseitige Gebietsprüfung: die Browser-Adresssuche ist nur
            // eine Komfortfunktion, ein direkter POST könnte sonst beliebige
            // Koordinaten außerhalb des Flohmarkt-Gebiets einschleusen.
            $msg = "Die gewählte Adresse liegt außerhalb des Flohmarkt-Gebiets (" . htmlspecialchars($settings['allowed_plz'] . ' ' . $settings['allowed_ort']) . "). Bitte wähle eine Adresse innerhalb des markierten Bereichs.";
            $msgType = 'error';
        }
        else {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM flohmarkt_staende WHERE email = ?");
            $stmt_check->execute([$email]);
            if ($stmt_check->fetchColumn() > 0) {
                $msg = "Diese E-Mail-Adresse wurde bereits für einen Stand verwendet. Jede E-Mail ist nur einmal erlaubt.";
                $msgType = 'error';
            } else {
                $delete_token = bin2hex(random_bytes(16));
                $auto_approve = (($settings['auto_approve_stands'] ?? '0') === '1') ? 1 : 0;
                $pdo->prepare("INSERT INTO flohmarkt_staende (name, email, adresse, lat, lng, beschreibung, delete_token, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$name, $email, $adresse, $lat, $lng, $beschreibung, $delete_token, $auto_approve]);

                flohmarkt_reg_register_success($pdo, $client_ip);

                $delete_link = "https://" . $app_domain . strtok($_SERVER['REQUEST_URI'], '?') . "?page=delete&token=" . $delete_token;
                flohmarkt_send_mail(
                    $email,
                    "Deine Flohmarkt-Anmeldung",
                    "Hallo $name,\ndein Stand wurde angemeldet.\nLöschen-Link: $delete_link",
                    $app_domain
                );

                // Sofort-Benachrichtigung an den Admin (falls aktiviert) - ohne
                // persönliche Details, nur der Hinweis, dass eine Anmeldung wartet.
                if (($settings['admin_notify_mode'] ?? 'off') === 'instant' && !empty($settings['admin_notify_email'])) {
                    flohmarkt_send_mail(
                        $settings['admin_notify_email'],
                        "Flohmarkt: neue Anmeldung",
                        "Es hat sich soeben eine neue Person für \"" . ($settings['title'] ?? 'den Flohmarkt') . "\" angemeldet"
                            . ($auto_approve ? " (automatisch freigeschaltet)." : " und wartet auf Freischaltung.")
                            . "\n\nAdmin-Bereich: https://" . $app_domain . "/admin.php?tab=staende",
                        $app_domain
                    );
                }

                $msg = $auto_approve
                    ? "Dein Stand wurde angemeldet und ist ab sofort auf der Karte sichtbar."
                    : "Dein Stand wurde angemeldet und wird nach Prüfung sichtbar.";
                $delete_link_show = "Lösch-Link (wurde auch per E-Mail gesendet): <br><a href='$delete_link'>$delete_link</a>";
                $page = 'map'; 
            }
        }
    }
}

// Für die Anmeldeseite (egal ob Erstaufruf oder nach einem Fehler) immer
// eine frische Rechenaufgabe bereitstellen - alte Aufgaben wurden oben schon
// verbraucht (Einmal-Verwendung gegen Replay).
$captcha = ($page === 'anmelden' && $registration_is_open) ? flohmarkt_captcha_new() : null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['title']) ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
    <style>
        :root {
            --theme-color: <?= htmlspecialchars($settings['theme_color'] ?? '#0056b3') ?>;
            --theme-color-dark: <?= flohmarkt_darken_color($settings['theme_color'] ?? '#0056b3') ?>;
        }
        body, html { margin: 0; padding: 0; font-family: sans-serif; background: #f4f4f4; height: 100dvh; display: flex; flex-direction: column; overflow-y: auto; }
        .navbar { display: flex; background: var(--theme-color); color: white; flex-wrap: wrap; flex-shrink: 0; }
        .navbar a { flex: 1; text-align: center; color: white; text-decoration: none; font-weight: bold; padding: 15px 5px; min-width: 30%; }
        .navbar a.active { background: var(--theme-color-dark); }
        .container { padding: 20px; max-width: 600px; margin: 0 auto; width: 100%; box-sizing: border-box; }
        .map-container { position: relative; flex-grow: 1; width: 100%; overflow: hidden; }
        #map { height: 100%; width: 100%; z-index: 1; }
        .map-info-box { position: absolute; bottom: 25px; left: 50%; transform: translateX(-50%); background: rgba(255, 255, 255, 0.95); padding: 12px 20px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); text-align: center; z-index: 1000; font-size: 0.9em; color: #333; width: 85%; max-width: 350px; border: 2px solid var(--theme-color); transition: all 0.3s ease; }
        .map-info-box.minimized { width: auto; padding: 8px 15px; bottom: 10px; }
        .map-info-box.minimized .info-content { display: none; }
        .toggle-info-btn { background: none; border: none; color: var(--theme-color); font-size: 0.8em; cursor: pointer; font-weight: bold; margin-top: 5px; padding: 0; text-decoration: underline; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; text-align: center; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="email"], textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button.btn-primary { background: var(--theme-color); color: white; border: none; padding: 15px; width: 100%; font-size: 16px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-top: 10px; }
        button.btn-secondary { background: #6c757d; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; width: 120px;}
        .addr-option { display: block; width: 100%; text-align: left; padding: 10px; margin-bottom: 5px; background: #fff; border: 1px solid var(--theme-color); border-radius: 4px; cursor: pointer; }
        #beschreibung_editor { background: white; height: 130px; }
        .ql-toolbar { background: #f8f9fa; border-top-left-radius: 4px; border-top-right-radius: 4px; }
        .ql-container { border-bottom-left-radius: 4px; border-bottom-right-radius: 4px; font-family: sans-serif; font-size: 14px; }
        .captcha-box { display: flex; align-items: center; gap: 10px; background: #f4f4f4; padding: 10px; border-radius: 4px; border: 1px solid #ccc; }
        .captcha-box input { width: 80px !important; text-align: center; }
    </style>
</head>
<body>

<div class="navbar">
    <a href="?page=map" class="<?= $page === 'map' ? 'active' : '' ?>">🗺️ Karte</a>
    <?php if ($registration_is_open): ?>
        <a href="?page=anmelden" class="<?= $page === 'anmelden' ? 'active' : '' ?>">🏪 Stand anmelden</a>
    <?php endif; ?>
    <a href="?page=impressum" class="<?= $page === 'impressum' ? 'active' : '' ?>">§ Info / Impressum</a>
</div>

<?php if ($msg): ?>
    <div class="container"><div class="alert <?= $msgType ?>"><b><?= htmlspecialchars($msg) ?></b>
    <?php if($delete_link_show): ?><hr><p style="font-size: 0.9em; word-break: break-all;"><?= $delete_link_show ?></p><?php endif; ?>
    </div></div>
<?php endif; ?>

<?php if ($page === 'map'): ?>
    <div class="map-container">
        <div class="map-info-box" id="infoBox">
            🎈 <b><?= htmlspecialchars($settings['title']) ?></b>
            <div class="info-content" style="margin-top: 4px;">
                <?= flohmarkt_sanitize_html($settings['date_text'] ?? '') ?>
            </div>
            <button type="button" class="toggle-info-btn" id="toggleInfoBtn" onclick="toggleInfoBox()">Info ausblenden</button>
        </div>
        <div id="map"></div>
    </div>
    
    <script>
        var map = L.map('map').setView([<?= json_encode((float)($settings['map_lat'] ?? 49.745472)) ?>, <?= json_encode((float)($settings['map_lng'] ?? 8.483472)) ?>], <?= json_encode((int)($settings['map_zoom'] ?? 15)) ?>);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);

        var polyCoords = <?= json_encode(json_decode($settings['polygon_coords'] ?? '[]', true) ?: []) ?>;
        if(polyCoords.length > 2) {
            L.polygon(polyCoords, {
                color: <?= json_encode($settings['polygon_color'] ?? '#ff0000') ?>,
                fillOpacity: 0.1,
                weight: 3
            }).addTo(map);
        }

        // --- BERECHNUNG GEGEN MARKER-DOPPLUNG (SPIRALE) ---
        var markerTracker = {};
        function getOffsetCoordinates(lat, lng) {
            var key = lat.toFixed(5) + '_' + lng.toFixed(5);
            if (!markerTracker[key]) {
                markerTracker[key] = 0;
                return [lat, lng];
            }
            markerTracker[key]++;
            var count = markerTracker[key];
            var angle = count * 1.25; 
            var radius = 0.00012 * Math.sqrt(count); // ca. 10-15m Versatz pro Schritt
            return [lat + (radius * Math.cos(angle)), lng + (radius * Math.sin(angle))];
        }

        // --- CUSTOM ICONS FÜR INFO-POINTS ---
        function getCustomIcon(type, customIcon) {
            var symbol = '📍';
            if(type === 'wc') symbol = '🚻';
            if(type === 'food') symbol = '🍔';
            if(type === 'drink') symbol = '🥤';
            if(type === 'info') symbol = 'ℹ️';
            if(type === 'arrow') symbol = '➡️';
            if(type === 'baustelle') symbol = '🚧';
            if(type === 'custom' && customIcon) symbol = customIcon;
            
            return L.divIcon({
                className: 'custom-infopoint-icon',
                html: "<div style='font-size:22px; text-shadow:0 0 3px white, 0 0 2px black; text-align:center; cursor:pointer;'>" + symbol + "</div>",
                iconSize: [30, 30],
                iconAnchor: [15, 15]
            });
        }

        <?php
        // 1. Freigegebene Stände zeichnen (mit Versatz bei doppelten Adressen)
        // Auf der öffentlichen Karte wird bewusst NUR der vom Teilnehmenden
        // eingetragene Text gezeigt - keine Adresse (Datensparsamkeit).
        $stmt = $pdo->query("SELECT * FROM flohmarkt_staende WHERE is_approved = 1");
        while ($sys_row = $stmt->fetch()) {
            $popupContent = flohmarkt_sanitize_html($sys_row['beschreibung']);
            if ($popupContent === '') { $popupContent = '<i>Keine Beschreibung angegeben.</i>'; }
            echo "var coords = getOffsetCoordinates(" . (float)$sys_row['lat'] . ", " . (float)$sys_row['lng'] . ");\n";
            echo "var marker = L.marker(coords).addTo(map);\n";
            echo "marker.bindPopup(" . json_encode($popupContent) . ");\n";
        }

        // 2. Info-Points zeichnen
        $stmtIP = $pdo->query("SELECT * FROM flohmarkt_infopoints");
        while ($ip = $stmtIP->fetch()) {
            $ipPopup = "<b>" . htmlspecialchars($ip['title']) . "</b>";
            if (!empty($ip['beschreibung'])) {
                $ipPopup .= "<br>" . nl2br(htmlspecialchars($ip['beschreibung']));
            }
            echo "var ipMarker = L.marker([" . (float)$ip['lat'] . ", " . (float)$ip['lng'] . "], {icon: getCustomIcon(" . json_encode($ip['type']) . ", " . json_encode($ip['icon']) . ")}).addTo(map);\n";
            echo "ipMarker.bindPopup(" . json_encode($ipPopup) . ");\n";
        }
        ?>

        function toggleInfoBox() {
            var box = document.getElementById('infoBox');
            var btn = document.getElementById('toggleInfoBtn');
            if(box.classList.contains('minimized')) {
                box.classList.remove('minimized');
                btn.innerText = "Info ausblenden";
            } else {
                box.classList.add('minimized');
                btn.innerText = "Info anzeigen";
            }
        }
    </script>

<?php elseif ($page === 'anmelden' && $registration_is_open): ?>
    <div class="container">
        <h2>Mach mit beim <?= htmlspecialchars($settings['title']) ?>!</h2>
        
        <?php if ($status['deadline'] && !$status['deadline_error']): ?>
            <p style="background: #e2f0d9; padding: 10px; border-radius: 4px; color: #385723; font-size: 0.95em;">
                ⏰ Anmeldeschluss ist am: <b><?= $status['deadline']->format('d.m.Y, H:i') ?> Uhr</b>
            </p>
        <?php endif; ?>

        <form method="POST" action="?page=anmelden" id="anmeldeForm">
            <?= csrf_field() ?>
            <input type="text" name="honeypot" value="" style="display:none;" tabindex="-1" autocomplete="off">

            <div class="form-group"><label>Dein Name (nicht öffentlich):</label><input type="text" name="name" required></div>
            <div class="form-group"><label>E-Mail Adresse (wird auf Einmaligkeit geprüft):</label><input type="email" name="email" required></div>
            
            <div class="form-group">
                <label>Adresse des Stands (nur in <?= htmlspecialchars($settings['allowed_plz'] . ' ' . $settings['allowed_ort']) ?>):</label>
                <div style="display:flex; gap:10px;">
                    <input type="text" id="street_input" placeholder="Straße und Hausnummer">
                    <button type="button" id="search_addr_btn" class="btn-secondary">Suchen</button>
                </div>
                <div id="addr_results" style="margin-top:10px;"></div>
                <input type="hidden" name="adresse" id="final_adresse" required>
                <input type="hidden" name="lat" id="final_lat" required>
                <input type="hidden" name="lng" id="final_lng" required>
            </div>
            
            <div class="form-group">
                <label>Was bietest du an?</label>
                <div id="beschreibung_editor"></div>
                <input type="hidden" name="beschreibung" id="final_beschreibung">
            </div>

            <div class="form-group">
                <label>Sicherheitsabfrage:</label>
                <div class="captcha-box">
                    <span>Wieviel ist <b><?= (int)$captcha['a'] ?></b> + <b><?= (int)$captcha['b'] ?></b>?</span>
                    <input type="number" name="captcha_answer" required>
                </div>
            </div>
            
            <div style="display: flex; margin-top: 15px;">
                <input type="checkbox" name="datenschutz" id="dsgvo" required style="margin-right:10px; margin-top:4px;">
                <label for="dsgvo" style="font-weight: normal; font-size: 0.9em;">
                    Ich stimme zu, dass meine eingegebenen Daten (Adresse, Angebot) öffentlich auf der Karte angezeigt werden. Weitere Details im <a href="?page=impressum">Datenschutz</a>.
                </label>
            </div>
            
            <button type="submit" class="btn-primary">Jetzt Stand anmelden</button>
        </form>
    </div>

    <script>
        var beschreibungQuill = new Quill('#beschreibung_editor', {
            theme: 'snow',
            placeholder: 'z.B. Kinderkleidung, Spielzeug, Bücher ...',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }]
                ]
            }
        });
    </script>

    <script>
        document.getElementById('search_addr_btn').addEventListener('click', function() {
            let street = document.getElementById('street_input').value;
            if(street.length < 3) return alert("Bitte eine Straße eingeben.");
            
            this.innerText = "Lädt...";
            let url = `https://nominatim.openstreetmap.org/search?street=${encodeURIComponent(street)}&postalcode=<?= urlencode($settings['allowed_plz']) ?>&city=<?= urlencode($settings['allowed_ort']) ?>&format=json&addressdetails=1<?php $bbox = flohmarkt_polygon_bbox($settings); if ($bbox): ?>&viewbox=<?= $bbox['minLng'] ?>,<?= $bbox['maxLat'] ?>,<?= $bbox['maxLng'] ?>,<?= $bbox['minLat'] ?>&bounded=1<?php endif; ?>`;
            
            fetch(url, { headers: { 'User-Agent': 'FlohmarktApp/1.0' } })
            .then(res => res.json())
            .then(data => {
                document.getElementById('search_addr_btn').innerText = "Suchen";
                let resDiv = document.getElementById('addr_results');
                resDiv.innerHTML = '';
                
                let validResults = data.filter(item => item.address && item.address.house_number);
                
                if(validResults.length === 0) {
                    return resDiv.innerHTML = '<span style="color:red;">Keine passende Adresse mit Hausnummer gefunden. Bitte prüfe die Eingabe!</span>';
                }
                
                validResults.forEach(item => {
                    let btn = document.createElement('button');
                    btn.type = 'button'; 
                    btn.className = 'addr-option'; 
                    btn.innerText = item.display_name;
                    btn.onclick = function() {
                        document.getElementById('final_adresse').value = item.display_name;
                        document.getElementById('final_lat').value = item.lat;
                        document.getElementById('final_lng').value = item.lon;
                        resDiv.innerHTML = '<div style="background:#d4edda; padding:10px;">✅ <b>' + item.display_name + '</b></div>';
                    };
                    resDiv.appendChild(btn);
                });
            }).catch(err => alert("Fehler bei der Adresssuche."));
        });
        
        document.getElementById('anmeldeForm').addEventListener('submit', function(e) {
            if(!document.getElementById('final_lat').value) { 
                e.preventDefault(); 
                alert("Bitte Adresse suchen und anklicken!"); 
                return;
            }
            var content = beschreibungQuill.root.innerHTML.trim();
            if(content === '<p><br></p>') content = '';
            document.getElementById('final_beschreibung').value = content;
            if(content === '') {
                e.preventDefault();
                alert("Bitte beschreibe kurz, was du anbietest.");
            }
        });
    </script>

<?php elseif ($page === 'delete' && isset($_GET['token'])): ?>
    <div class="container">
        <h2>Stand löschen</h2>
        <div class="alert" style="background:#fff3cd; border:1px solid #ffeeba; color:#856404; text-align:left;">
            <p>Möchtest du deinen Stand wirklich endgültig löschen?</p>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token']) ?>">
                <input type="hidden" name="confirm_delete" value="1">
                <button type="submit" class="btn-primary" style="background:#dc3545; border-color:#dc3545;">Ja, Stand löschen</button>
            </form>
            <a href="?page=map" style="display:block; text-align:center; margin-top:15px; color:#666; text-decoration:none;">Abbrechen und zurück zur Karte</a>
        </div>
    </div>

<?php elseif ($page === 'impressum'): ?>
    <div class="container">
        <?= flohmarkt_sanitize_html($settings['impressum_text'] ?? '') ?>
        <hr>
        <?= flohmarkt_sanitize_html($settings['datenschutz_text'] ?? '') ?>
    </div>
<?php endif; ?>

</body>
</html>