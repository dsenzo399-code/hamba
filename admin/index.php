<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
if (!is_file(storage_path('installed.lock'))) redirect('../install.php');
$u = current_user();
if (!$u || $u['role'] !== 'admin') {
    redirect('../login.php');
}
?>
<!doctype html>
<html lang="en" data-root="../">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Hamba admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="admin-dash">
  <header class="hero-toggle">
    <div class="row spread">
      <div><div class="muted" style="color:#dce8df">Hamba control</div><strong>Platform operations</strong></div>
      <a class="btn gold" href="../logout.php">Log out</a>
    </div>
  </header>
  <div class="grid-stats" id="stats"></div>
  <nav class="admin-nav">
    <button class="btn gold" data-view="drivers">Drivers</button>
    <button class="btn ghost" data-view="rides">Rides</button>
    <button class="btn ghost" data-view="people">Passengers</button>
    <button class="btn ghost" data-view="vehicles">Vehicles</button>
    <button class="btn ghost" data-view="reports">Reports</button>
    <button class="btn ghost" data-view="tickets">Support</button>
    <button class="btn ghost" data-view="settings">Fares & commission</button>
  </nav>
  <div id="view" style="padding:16px"></div>
  <script src="../assets/js/api.js"></script>
  <script src="../assets/js/admin.js"></script>
</body>
</html>
