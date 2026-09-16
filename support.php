<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$u = current_user();
if (!$u) redirect('login.php');
?>
<!doctype html>
<html lang="en" data-root="./">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Support — Hamba</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
<main class="auth-card">
  <a href="<?= $u['role']==='admin'?'admin/':($u['role']==='driver'?'driver/':'passenger/') ?>">← Back</a>
  <h1>Support & reports</h1>
  <form id="t">
    <label>Subject<input name="subject" required></label>
    <label>Message<textarea name="message" rows="4" required></textarea></label>
    <button class="btn primary" type="submit">Send ticket</button>
  </form>
  <form id="r" class="card">
    <h3>Report a problem</h3>
    <label>Ride ID (optional)<input name="ride_id"></label>
    <label>Subject<input name="subject" required></label>
    <label>Details<textarea name="details" rows="4" required></textarea></label>
    <button class="btn ghost" type="submit">Submit report</button>
  </form>
</main>
<script src="assets/js/api.js"></script>
<script>
(async () => {
  await Hamba.boot();
  document.getElementById('t').onsubmit = async (e) => {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.target).entries());
    const d = await Hamba.api('support/create', { method:'POST', body });
    toast(d.ok ? 'Ticket sent' : d.error);
  };
  document.getElementById('r').onsubmit = async (e) => {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.target).entries());
    const d = await Hamba.api('reports/create', { method:'POST', body });
    toast(d.ok ? 'Report filed' : d.error);
  };
})();
</script>
</body></html>
