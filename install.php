<?php
declare(strict_types=1);

$config = require __DIR__ . '/config/app.php';
date_default_timezone_set($config['timezone']);
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';

function cfg() {
    global $config;
    return $config;
}
function app_config(?string $key = null, $default = null) {
    global $config;
    if ($key === null) return $config;
    $parts = explode('.', $key);
    $val = $config;
    foreach ($parts as $p) {
        if (!is_array($val) || !array_key_exists($p, $val)) return $default;
        $val = $val[$p];
    }
    return $val;
}

$lock = __DIR__ . '/storage/installed.lock';
$error = '';
$ok = false;
$adminPassShown = null;

if (is_file($lock) && ($_POST['force'] ?? '') !== '1') {
    $ok = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$ok) {
    $name = trim((string)($_POST['admin_name'] ?? 'Platform Owner'));
    $email = strtolower(trim((string)($_POST['admin_email'] ?? 'admin@hamba.local')));
    $pass = (string)($_POST['admin_password'] ?? '');
    $dbName = trim((string)($_POST['db_name'] ?? $config['db']['name']));
    $dbUser = trim((string)($_POST['db_user'] ?? $config['db']['user']));
    $dbPass = (string)($_POST['db_pass'] ?? $config['db']['pass']);
    $dbHost = trim((string)($_POST['db_host'] ?? $config['db']['host']));

    if ($pass === '') {
        $pass = bin2hex(random_bytes(5));
        $adminPassShown = $pass;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($name) < 2) {
        $error = 'Enter a valid admin name and email.';
    } else {
        try {
            $local = [
                'db' => ['host' => $dbHost, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass],
                'security' => ['app_key' => bin2hex(random_bytes(32))],
            ];
            $export = "<?php\nreturn " . var_export($local, true) . ";\n";
            file_put_contents(__DIR__ . '/config/local.php', $export);
            $config = array_replace_recursive($config, $local);

            $server = db_server();
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $config['db']['port'], $dbName),
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $sql = file_get_contents(__DIR__ . '/database/schema.sql');
            $sql = preg_replace('/CREATE DATABASE.*?;/s', '', $sql);
            $sql = preg_replace('/USE `hamba`;/', '', $sql);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }

            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $exists->execute([$email]);
            if (!$exists->fetch()) {
                $pdo->prepare('INSERT INTO users (role, full_name, email, phone, password_hash) VALUES ("admin",?,?,?,?)')
                    ->execute([$name, $email, '+26800000000', $hash]);
            } else {
                $pdo->prepare('UPDATE users SET password_hash=?, role="admin", full_name=? WHERE email=?')->execute([$hash, $name, $email]);
            }

            foreach (['uploads/profiles','uploads/documents','uploads/vehicles','logs'] as $d) {
                $p = __DIR__ . '/storage/' . $d;
                if (!is_dir($p)) mkdir($p, 0755, true);
            }
            file_put_contents($lock, date('c'));
            $ok = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install Hamba</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
  <main class="auth-card">
    <div class="brand-mark">Hamba</div>
    <p class="muted">Eswatini ride-hailing + kombi tracking</p>
    <?php if ($ok): ?>
      <h1>Ready to move</h1>
      <p>Database tables, default routes, and your admin account are in place.</p>
      <?php if ($adminPassShown): ?>
        <p class="notice">Generated admin password (save it now): <strong><?= e($adminPassShown) ?></strong></p>
      <?php endif; ?>
      <p>Open <a href="index.php">Hamba home</a> · <a href="admin/">Admin</a></p>
      <p class="small muted">Maps use OpenStreetMap + Leaflet (free, no key). Routes use the public OSRM demo server (free, rate-limited). Place search uses Nominatim (free, no key). If OSRM is unreachable, distance falls back to GPS haversine — coordinates are still real.</p>
    <?php else: ?>
      <h1>Install Hamba</h1>
      <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
      <form method="post">
        <label>MySQL host<input name="db_host" value="127.0.0.1" required></label>
        <label>Database name<input name="db_name" value="hamba" required></label>
        <label>MySQL user<input name="db_user" value="root" required></label>
        <label>MySQL password<input name="db_pass" type="password" placeholder="XAMPP default is empty"></label>
        <label>Admin name<input name="admin_name" value="Hamba Admin" required></label>
        <label>Admin email<input name="admin_email" type="email" value="admin@hamba.local" required></label>
        <label>Admin password<input name="admin_password" type="password" placeholder="Leave blank to auto-generate"></label>
        <button class="btn primary" type="submit">Create database &amp; admin</button>
      </form>
      <p class="small muted">Requires Apache + PHP + MySQL from XAMPP. Start Apache and MySQL in the XAMPP Control Panel first.</p>
    <?php endif; ?>
  </main>
</body>
</html>
