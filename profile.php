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
  <title>Profile — Hamba</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
<main class="auth-card">
  <a href="<?= $u['role']==='admin'?'admin/':($u['role']==='driver'?'driver/':'passenger/') ?>">← Back</a>
  <h1>Profile</h1>
  <p id="msg" class="hidden"></p>
  <form id="p">
    <label>Full name<input name="full_name" value="<?= e($u['full_name']) ?>" required></label>
    <label>Phone<input name="phone" value="<?= e($u['phone']) ?>" required></label>
    <button class="btn primary" type="submit">Save</button>
  </form>
  <form id="photo" class="card">
    <label>Profile photo<input type="file" name="photo" accept="image/*"></label>
    <button class="btn ghost" type="submit">Upload photo</button>
  </form>
  <div id="saved"></div>
  <form id="fav" class="card">
    <h3>Favourite place</h3>
    <label>Label<input name="label" placeholder="Home, Work, rank…"></label>
    <label>Latitude<input name="lat"></label>
    <label>Longitude<input name="lng"></label>
    <button class="btn ghost" type="submit">Save location</button>
  </form>
</main>
<script src="assets/js/api.js"></script>
<script>
(async () => {
  await Hamba.boot();
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  document.getElementById('p').onsubmit = async (e) => {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.target).entries());
    const r = await Hamba.api('profile/update', { method:'POST', body });
    toast(r.ok ? 'Saved' : r.error);
  };
  document.getElementById('photo').onsubmit = async (e) => {
    e.preventDefault();
    const r = await Hamba.api('profile/photo', { method:'POST', form: new FormData(e.target) });
    toast(r.ok ? 'Photo updated' : r.error);
  };
  async function listFav() {
    const d = await Hamba.api('saved/list');
    document.getElementById('saved').innerHTML = (d.items||[]).map(i => `<div class="card">${esc(i.label)} (${esc(i.lat)}, ${esc(i.lng)})</div>`).join('');
  }
  document.getElementById('fav').onsubmit = async (e) => {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.target).entries());
    const r = await Hamba.api('saved/add', { method:'POST', body });
    toast(r.ok ? 'Saved' : r.error); listFav();
  };
  listFav();
})();
</script>
</body></html>
