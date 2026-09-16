<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
if (!is_file(storage_path('installed.lock'))) redirect('../install.php');
$u = current_user();
if (!$u) redirect('../login.php');
?>
<!doctype html>
<html lang="en" data-root="../">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Hamba passenger</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
  <div class="app-shell">
    <div class="map-wrap" style="position:relative;min-height:45vh">
      <div id="map" class="map"></div>
      <div class="topbar">
        <div class="pill">Hamba</div>
        <div class="pill" id="gpsChip">Finding GPS…</div>
      </div>
    </div>
    <section class="sheet" id="sheet">
      <div class="tabs">
        <button class="btn gold" id="tabRide" type="button">Private ride</button>
        <button class="btn ghost" id="tabTaxi" type="button">Nearby taxis</button>
      </div>
      <div id="panelRide">
        <h2 style="margin:0 0 8px;font-family:var(--display);font-size:1.4rem">Where are you going?</h2>
        <label>Destination
          <input id="destQuery" placeholder="Mbabane, Matsapha, Ezulwini…">
        </label>
        <div id="destResults" class="search-list"></div>
        <div id="estimate" class="card hidden"></div>
        <button class="btn primary hidden" id="btnRequest" type="button">Request ride</button>
        <button class="btn danger hidden" id="btnCancel" type="button">Cancel ride</button>
        <div id="rideLive" class="card hidden"></div>
      </div>
      <div id="panelTaxi" class="hidden">
        <h2 style="margin:0 0 8px;font-family:var(--display);font-size:1.3rem">Kombi & local taxis</h2>
        <p class="muted small">Live GPS from operator phones. Seat counts update as passengers board.</p>
        <label class="row"><input type="checkbox" id="waitingToggle" style="width:auto"> I’m waiting at this pickup point</label>
        <div id="taxiList"></div>
      </div>
      <div class="card">
        <div class="row spread">
          <a href="../profile.php">Profile</a>
          <a href="../history.php">Trips</a>
          <a href="../support.php">Support</a>
          <a href="../logout.php">Log out</a>
        </div>
      </div>
    </section>
  </div>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="../assets/js/api.js"></script>
  <script src="../assets/js/map.js"></script>
  <script src="../assets/js/passenger.js"></script>
</body>
</html>
