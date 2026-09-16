<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
if (!is_file(storage_path('installed.lock'))) redirect('install.php');
$asDriver = ($_GET['role'] ?? '') === 'driver';
?>
<!doctype html>
<html lang="en" data-root="./">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register — Hamba</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
  <main class="auth-card">
    <div class="brand-mark">Hamba</div>
    <h1><?= $asDriver ? 'Drive with Hamba' : 'Ride with Hamba' ?></h1>
    <p class="muted"><?= $asDriver ? 'You will still need verification before accepting private rides.' : 'Use one number that can receive calls from drivers.' ?></p>
    <p id="err" class="error hidden"></p>
    <form id="f">
      <label>Full name<input name="full_name" required></label>
      <label>Email<input name="email" type="email" required></label>
      <label>Phone<input name="phone" placeholder="+268..." required></label>
      <label>Password (8+ characters)<input name="password" type="password" minlength="8" required></label>
      <input type="hidden" name="role" value="<?= $asDriver ? 'driver' : 'passenger' ?>">
      <button class="btn primary" type="submit">Create account</button>
    </form>
    <p class="muted small"><a href="login.php">Already have an account</a><?php if (!$asDriver): ?> · <a href="register.php?role=driver">I want to drive</a><?php endif; ?></p>
  </main>
  <script src="assets/js/api.js"></script>
  <script>
    (async () => {
      await Hamba.boot();
      document.getElementById('f').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const body = Object.fromEntries(fd.entries());
        const data = await Hamba.api('auth/register', { method: 'POST', body });
        if (!data.ok) { const el = document.getElementById('err'); el.textContent = data.error; el.classList.remove('hidden'); return; }
        location.href = body.role === 'driver' ? 'driver/' : 'passenger/';
      });
    })();
  </script>
</body>
</html>
