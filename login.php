<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
if (!is_file(storage_path('installed.lock'))) redirect('install.php');
if (current_user()) {
    $u = current_user();
    redirect($u['role'] === 'admin' ? 'admin/' : ($u['role'] === 'driver' ? 'driver/' : 'passenger/'));
}
?>
<!doctype html>
<html lang="en" data-root="./">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Log in — Hamba</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
  <main class="auth-card">
    <div class="brand-mark">Hamba</div>
    <h1>Welcome back</h1>
    <p id="err" class="error hidden"></p>
    <form id="f">
      <label>Email or phone<input name="login" required autocomplete="username"></label>
      <label>Password<input name="password" type="password" required autocomplete="current-password"></label>
      <button class="btn primary" type="submit">Log in</button>
    </form>
    <p class="muted small">New here? <a href="register.php">Create an account</a></p>
  </main>
  <script src="assets/js/api.js"></script>
  <script>
    (async () => {
      await Hamba.boot();
      document.getElementById('f').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const data = await Hamba.api('auth/login', { method: 'POST', body: { login: fd.get('login'), password: fd.get('password') } });
        if (!data.ok) { const el = document.getElementById('err'); el.textContent = data.error; el.classList.remove('hidden'); return; }
        const role = data.user.role;
        location.href = role === 'admin' ? 'admin/' : (role === 'driver' ? 'driver/' : 'passenger/');
      });
    })();
  </script>
</body>
</html>
