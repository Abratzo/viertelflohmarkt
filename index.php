<?php
require 'config.php';

// Zwingend deutsche Zeit erzwingen
date_default_timezone_set('Europe/Berlin');

// Session starten (wichtig für das Captcha)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page = $_GET['page'] ?? 'map';
$msg = '';
$msgType = 'success';
$delete_link_show = '';

// --- ANMELDE-STATUS LOGIK PRÜFEN ---
$status = flohmarkt_registration_status($settings);
$registration_is_open = $status['open'];

// Weiterleitung zur Karte: Falls jemand direkt ?page=anmelden aufruft, obwohl geschlossen ist.
if ($page === 'anmelden' && !$registration_is_open) {
    $page = 'map';
    $msg = "Die Anmeldung ist inzwischen leider geschlossen.";
    $msgType = 'error';
}

// --- LÖSCHEN (über Token) ---
if ($page === 'delete' && $_GET['token']) {
    $stmt = $pdo->prepare("DELETE FROM flohmarkt_staende WHERE delete_token = ?");
    $stmt->execute([$_GET['token']]);
    if ($stmt->rowCount() > 0) { 
        $msg = "Dein Stand wurde erfolgreich gelöscht."; 
    } else { 
        $msg = "Stand nicht gefunden oder bereits gelöscht."; 
        $msgType = 'error'; 
    }
    $page = 'map';
}

// --- ANMELDUNG SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'anmelden') {
    if (!$registration_is_open) {
        $msg = "Die Anmeldung ist inzwischen leider geschlossen.";
        $msgType = 'error';
        $page = 'map';
    } else {
        $name = trim($_POST['name'] ?? ''); 
        $email = trim($_POST['email'] ?? ''); 
        $adresse = trim($_POST['adresse'] ?? '');
        $lat = $_POST['lat'] ?? ''; 
        $lng = $_POST['lng'] ?? ''; 
        $beschreibung = trim($_POST['beschreibung'] ?? '');
        
        // Eingaben für Captcha & Validierung
        $user_captcha = trim($_POST['captcha'] ?? '');
        $session_captcha = $_SESSION['captcha_result'] ?? 999;

        // 1. Captcha prüfen
        if (empty($user_captcha) || intval($user_captcha) !== intval($session_captcha)) {
            $msg = "Das Rechenergebnis des Captchas ist falsch. Bitte versuche es erneut.";
            $msgType = 'error';
        }
        // 2. Datenschutz prüfen
        elseif (!isset($_POST['datenschutz'])) { 
            $msg = "Bitte stimme den Datenschutzbedingungen zu."; 
            $msgType = 'error'; 
        } 
        // 3. E-Mail-Adresse auf korrekte Syntax überprüfen
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "Die angegebene E-Mail-Adresse ist ungültig.";
            $msgType = 'error';
        } 
        elseif (!empty($name) && !empty($email) && !empty($adresse) && !empty($lat)) {
            
            // 4. Prüfen, ob die E-Mail-Adresse bereits registriert wurde
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM flohmarkt_staende WHERE email = ?");
            $stmt_check->execute([$email]);
            if ($stmt_check->fetchColumn() > 0) {
                $msg = "Diese E-Mail-Adresse wurde bereits für einen Stand verwendet. Jede E-Mail ist nur einmal erlaubt.";
                $msgType = 'error';
            } else {
                // Alles fein -> Speichern
                $delete_token = bin2hex(random_bytes(16));
                $pdo->prepare("INSERT INTO flohmarkt_staende (name, email, adresse, lat, lng, beschreibung, delete_token) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$name, $email, $adresse, $lat, $lng, $beschreibung, $delete_token]);
                
                $delete_link = "https://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . "?page=delete&token=" . $delete_token;
                @mail($email, "Deine Flohmarkt-Anmeldung", "Hallo $name,\ndein Stand wurde angemeldet.\nLöschen-Link: $delete_link", "From: noreply@" . $_SERVER['HTTP_HOST']);

                $msg = "Dein Stand wurde angemeldet und wird nach Prüfung sichtbar.";
                $delete_link_show = "Lösch-Link (wurde auch per E-Mail gesendet): <br><a href='$delete_link'>$delete_link</a>";
                $page = 'map'; 
            }
        } else { 
            $msg = "Bitte suche über den Button deine Adresse und wähle sie aus!"; 
            $msgType = 'error'; 
        }
    }
}

// Neues Captcha für den Aufruf generieren
$num1 = rand(1, 9);
$num2 = rand(1, 9);
$_SESSION['captcha_result'] = $num1 + $num2;
$captcha_question = "$num1 + $num2";
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['title']) ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body, html { 
            margin: 0; 
            padding: 0; 
            font-family: sans-serif; 
            background: #f4f4f4;
            height: 100dvh; 
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        
        .navbar { 
            display: flex; 
            background: #0056b3; 
            color: white; 
            flex-wrap: wrap; 
            flex-shrink: 0; 
        }
        .navbar a { 
            flex: 1; 
            text-align: center; 
            color: white; 
            text-decoration: none; 
            font-weight: bold; 
            padding: 15px 5px; 
            min-width: 30%; 
        }
        .navbar a.active { background: #004494; }
        
        .container { 
            padding: 20px; 
            max-width: 600px; 
            margin: 0 auto; 
            width: 100%;
            box-sizing: border-box;
        }
        
        .map-container { 
            position: relative; 
            flex-grow: 1; 
            width: 100%; 
            overflow: hidden; 
        }
        #map { height: 100%; width: 100%; z-index: 1; }
        
        .map-info-box { 
            position: absolute; 
            bottom: 25px; 
            left: 50%; 
            transform: translateX(-50%); 
            background: rgba(255, 255, 255, 0.95); 
            padding: 12px 20px; 
            border-radius: 20px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.2); 
            text-align: center; 
            z-index: 1000; 
            font-size: 0.9em; 
            color: #333; 
            width: 85%; 
            max-width: 350px; 
            border: 2px solid #0056b3;
            transition: all 0.3s ease;
        }
        .map-info-box.minimized {
            width: auto;
            padding: 8px 15px;
            bottom: 10px;
        }
        .map-info-box.minimized .info-content { display: none; }
        .toggle-info-btn {
            background: none;
            border: none;
            color: #0056b3;
            font-size: 0.8em;
            cursor: pointer;
            font-weight: bold;
            margin-top: 5px;
            padding: 0;
            text-decoration: underline;
        }

        .app-attribution {
            position: absolute;
            bottom: 5px;
            left: 5px;
            background: rgba(255, 255, 255, 0.8);
            padding: 2px 6px;
            font-size: 11px;
            z-index: 1000;
            border-radius: 3px;
            text-decoration: none;
            color: #0078A8;
        }

        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; text-align: center; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="email"], textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button.btn-primary { background: #0056b3; color: white; border: none; padding: 15px; width: 100%; font-size: 16px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-top: 10px; }
        button.btn-secondary { background: #6c757d; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; width: 120px;}
        .addr-option { display: block; width: 100%; text-align: left; padding: 10px; margin-bottom: 5px; background: #fff; border: 1px solid #0056b3; border-radius: 4px; cursor: pointer; }
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
                <?= $settings['date_text'] ?>
            </div>
            <button type="button" class="toggle-info-btn" id="toggleInfoBtn" onclick="toggleInfoBox()">Info ausblenden</button>
        </div>
        <div id="map"></div>
        <a href="https://github.com" target="_blank" class="app-attribution">ViertelFlohmarkt App</a>
    </div>
    
    <script>
        var map = L.map('map').setView([<?= $settings['map_lat'] ?>, <?= $settings['map_lng'] ?>], <?= $settings['map_zoom'] ?>);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);

        var polyCoords = <?= $settings['polygon_coords'] ?: '[]' ?>;
        if(polyCoords.length > 2) {
            L.polygon(polyCoords, {
                color: '<?= $settings['polygon_color'] ?>',
                fillOpacity: 0.1,
                weight: 3
            }).addTo(map);
        }

        <?php
        $stmt = $pdo->query("SELECT * FROM flohmarkt_staende WHERE is_approved = 1");
        while ($sys_row = $stmt->fetch()) {
            $popupContent = nl2br(htmlspecialchars($sys_row['beschreibung']));
            echo "var marker = L.marker([" . $sys_row['lat'] . ", " . $sys_row['lng'] . "]).addTo(map);";
            echo "marker.bindPopup(" . json_encode($popupContent) . ");";
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
            
            <div class="form-group"><label>Was bietest du an?</label><textarea name="beschreibung" rows="5" required></textarea></div>
            
            <!-- Captcha Feld -->
            <div class="form-group" style="background: #eef5fb; padding: 12px; border-radius: 4px; border: 1px solid #bce8f1;">
                <label style="color: #31708f;">Spamschutz: Was ist <?= $captcha_question ?>?</label>
                <input type="text" name="captcha" placeholder="Ergebnis eingeben" required style="margin-top: 5px;">
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
        document.getElementById('search_addr_btn').addEventListener('click', function() {
            let street = document.getElementById('street_input').value;
            if(street.length < 3) return alert("Bitte eine Straße eingeben.");
            
            this.innerText = "Lädt...";
            let url = `https://nominatim.openstreetmap.org/search?street=${encodeURIComponent(street)}&postalcode=<?= urlencode($settings['allowed_plz']) ?>&city=<?= urlencode($settings['allowed_ort']) ?>&format=json&addressdetails=1`;
            
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
            }
        });
    </script>

<?php elseif ($page === 'impressum'): ?>
    <div class="container">
        <?= $settings['impressum_text'] ?>
        <hr>
        <?= $settings['datenschutz_text'] ?>
    </div>
<?php endif; ?>

</body>
</html>