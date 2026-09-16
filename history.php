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
  <title>Trips — Hamba</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
<main class="auth-card" style="width:min(720px,100%)">
  <a href="<?= $u['role']==='admin'?'admin/':($u['role']==='driver'?'driver/':'passenger/') ?>">← Back</a>
  <h1>Trip history</h1>
  <div id="earn"></div>
  <div id="list"></div>
</main>
<script src="assets/js/api.js"></script>
<script>
(async () => {
  const me = await Hamba.boot();
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const d = await Hamba.api('rides/history');
  document.getElementById('list').innerHTML = (d.rides||[]).map(r => `
    <div class="card"><strong>${esc(r.pickup_label)} → ${esc(r.dest_label)}</strong>
    <div class="muted small">${esc(r.status)} · ${esc(r.requested_at)} · ${r.fare_total ? 'E'+esc(r.fare_total) : ''}</div>
    ${r.status==='TRIP_COMPLETED' ? `<form data-rate="${r.id}"><select name="stars"><option>5</option><option>4</option><option>3</option><option>2</option><option>1</option></select>
    <input name="review" placeholder="Review (optional)">
    <button class="btn ghost" type="submit">Rate</button></form>`:''}
    </div>`).join('') || '<p>No trips yet.</p>';
  document.querySelectorAll('[data-rate]').forEach(f => {
    f.onsubmit = async (e) => {
      e.preventDefault();
      const fd = new FormData(f);
      const r = await Hamba.api('ratings/submit', { method:'POST', body:{ ride_id:+f.dataset.rate, stars:+fd.get('stars'), review: fd.get('review') }});
      toast(r.ok ? 'Rated' : r.error);
    };
  });
  if (me.user.role === 'driver') {
    const e = await Hamba.api('driver/earnings');
    if (e.ok) {
      document.getElementById('earn').innerHTML = `<div class="card">★ ${esc(e.rating)} · ${esc(e.completed)} trips · net E${Number(e.totals.t).toFixed(2)}</div>`;
    }
  }
})();
</script>
</body></html>
