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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Hamba driver</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="driver-dash">
  <header class="hero-toggle">
    <div class="row spread">
      <div>
        <div class="muted" style="color:#dce8df">Hamba driver</div>
        <strong id="hello"></strong>
        <div id="verifyChip" class="status-chip"></div>
      </div>
      <button class="switch" id="onlineSwitch" type="button" aria-label="Go online"><i></i></button>
    </div>
    <p id="gpsLine" class="small" style="color:#dce8df">Waiting for GPS from this device…</p>
    <div class="tabs" style="margin-top:12px">
      <button class="btn gold" id="modePrivate" type="button">Private rides</button>
      <button class="btn ghost" id="modeTaxi" type="button">Kombi / taxi</button>
    </div>
  </header>
  <div id="map" class="map" style="height:220px"></div>
  <section id="panelPrivate" style="padding:16px">
    <div id="regBox" class="card hidden">
      <h3>Finish driver registration</h3>
      <form id="regForm">
        <label>National ID<input name="national_id" required></label>
        <label>Licence number<input name="license_number" required></label>
        <label>Licence expiry<input name="license_expiry" type="date"></label>
        <label>Vehicle make<input name="make" required></label>
        <label>Model<input name="model" required></label>
        <label>Colour<input name="color" required></label>
        <label>Plate / registration<input name="plate_number" required></label>
        <label>Type
          <select name="vehicle_type">
            <option value="sedan">Sedan</option>
            <option value="hatchback">Hatchback</option>
            <option value="suv">SUV</option>
            <option value="bakkie">Bakkie</option>
            <option value="minibus">Minibus</option>
            <option value="kombi">Kombi</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label>Seats<input name="seat_capacity" type="number" value="4" min="1"></label>
        <label>Registration details<input name="registration_info"></label>
        <button class="btn primary" type="submit">Submit for verification</button>
      </form>
      <form id="docForm" class="card">
        <p class="small">Upload licence or vehicle photo (JPG/PNG)</p>
        <select name="doc_type"><option value="license">Licence</option><option value="id">ID</option><option value="vehicle_photo">Vehicle photo</option><option value="vehicle_registration">Vehicle registration</option></select>
        <input type="file" name="file" accept="image/*">
        <button class="btn ghost" type="submit">Upload</button>
      </form>
    </div>
    <div id="requests"></div>
    <div id="currentRide"></div>
  </section>
  <section id="panelTaxi" class="hidden" style="padding:16px">
    <div class="card">
      <label>Route<select id="routeSelect"></select></label>
      <label>Seat capacity<input id="taxiCap" type="number" value="15" min="1"></label>
      <button class="btn primary" id="startShift" type="button">Start shift &amp; broadcast GPS</button>
    </div>
    <div class="card seat-board">
      <div class="muted">Passengers on board</div>
      <div class="count" id="seatCount">0 / 15</div>
      <p id="fullTag" class="status-chip hidden">FULL</p>
      <div class="row" style="justify-content:center;margin-top:10px">
        <button class="btn xl ghost" id="seatMinus" type="button">−</button>
        <button class="btn xl gold" id="seatPlus" type="button">+</button>
      </div>
      <p class="muted small" id="demandLine"></p>
      <button class="btn danger" id="endShift" type="button">Stop broadcasting</button>
    </div>
  </section>
  <p class="row spread" style="padding:16px"><a href="../history.php">Earnings & trips</a> <a href="../profile.php">Profile</a> <a href="../logout.php">Log out</a></p>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="../assets/js/api.js"></script>
  <script src="../assets/js/map.js"></script>
  <script src="../assets/js/driver.js"></script>
</body>
</html>
