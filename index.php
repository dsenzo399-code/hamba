<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
if (!is_file(storage_path('installed.lock'))) {
    redirect('install.php');
}
$u = current_user();
?>
<!doctype html>
<html lang="en" data-root="./">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Hamba — Move smarter across eSwatini</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="landing">
  <div>
    <div class="logo" style="color:#fff">Hamba<span>.</span></div>
    <h1>Private rides and live kombi tracking, built for eSwatini.</h1>
    <p>See which taxi is coming, how many seats are left, and request a private car when you need the door-to-door trip.</p>
    <div class="actions">
      <?php if ($u): ?>
        <a class="btn primary" href="<?= $u['role']==='admin' ? 'admin/' : ($u['role']==='driver' ? 'driver/' : 'passenger/') ?>">Open my app</a>
      <?php else: ?>
        <a class="btn primary" href="register.php">Create passenger account</a>
        <a class="btn ghost" href="login.php">Log in</a>
        <a class="btn ghost" href="register.php?role=driver">Register as a driver</a>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
