(async function () {
  const me = await Hamba.boot();
  if (!me.user || me.user.role !== 'admin') { location.href = '../login.php'; return; }

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  async function loadStats() {
    const d = await Hamba.api('admin/stats');
    const s = d.stats || {};
    const labels = {
      passengers: 'Passengers', drivers: 'Drivers', pending: 'Pending verify', verified: 'Verified',
      online_drivers: 'Online cars', online_taxis: 'Live kombis', active_rides: 'Active rides',
      completed: 'Completed', cancelled: 'Cancelled', rides_today: 'Rides today',
      revenue: 'Gross fares (E)', commission: 'Commission (E)', driver_earnings: 'Driver earnings (E)'
    };
    document.getElementById('stats').innerHTML = Object.entries(labels).map(([k, lab]) =>
      `<div class="stat"><span class="muted">${lab}</span><b>${s[k] ?? 0}</b></div>`).join('');
    window.__settings = d.settings;
  }

  async function show(view) {
    const el = document.getElementById('view');
    if (view === 'drivers') {
      const d = await Hamba.api('admin/drivers');
      el.innerHTML = `<table><tr><th>Name</th><th>Phone</th><th>Status</th><th></th></tr>${(d.drivers||[]).map(x => `
        <tr><td>${esc(x.full_name)}<br><span class="muted small">${esc(x.email)}</span><br><span class="muted small">Lic: ${esc(x.license_number || '—')} · Docs: ${x.doc_count ?? 0}</span></td><td>${esc(x.phone)}</td><td>${esc(x.verification_status)}${x.is_online==1?' · online':''}</td>
        <td>
          <button data-v="${x.id}" data-s="verified">Approve</button>
          <button data-v="${x.id}" data-s="rejected">Reject</button>
          <button data-v="${x.id}" data-s="suspended">Suspend</button>
          <button data-docs="${x.id}">Docs</button>
        </td></tr>`).join('')}</table><div id="docs"></div>`;
      el.querySelectorAll('[data-v]').forEach(b => b.onclick = async () => {
        const r = await Hamba.api('admin/verify', { method: 'POST', body: { driver_id: +b.dataset.v, status: b.dataset.s } });
        toast(r.ok ? 'Updated' : r.error); show('drivers'); loadStats();
      });
      el.querySelectorAll('[data-docs]').forEach(b => b.onclick = async () => {
        const docs = await Hamba.api('admin/documents?driver_id=' + b.dataset.docs);
        document.getElementById('docs').innerHTML = `<div class="card"><h3>Documents</h3>` + ((docs.documents || []).map(doc =>
          `<div><span class="status-chip">${esc(doc.doc_type)}</span> <span class="muted small">${esc(doc.created_at)}</span><br><a href="${Hamba.fileUrl(doc.file_path)}" target="_blank" rel="noopener">Open file</a></div>`
        ).join('') || '<p class="muted">No documents uploaded.</p>') + `</div>`;
      });
    }
    if (view === 'rides') {
      const d = await Hamba.api('admin/rides');
      el.innerHTML = `<table><tr><th>ID</th><th>Status</th><th>Passenger</th><th>Driver</th><th>Fare</th><th>When</th></tr>${(d.rides||[]).map(r => `
        <tr><td>${r.id}</td><td>${esc(r.status)}</td><td>${esc(r.passenger_name)}</td><td>${esc(r.driver_name||'—')}</td>
        <td>${r.fare_total||'—'}</td><td>${esc(r.requested_at)}</td></tr>`).join('')}</table>`;
    }
    if (view === 'people') {
      const d = await Hamba.api('admin/passengers');
      el.innerHTML = `<table><tr><th>Name</th><th>Phone</th><th>Status</th><th>Rating</th><th>Trips</th><th></th></tr>${(d.passengers||[]).map(p => `
        <tr><td>${esc(p.full_name)}<br><span class="muted small">${esc(p.email)}</span></td><td>${esc(p.phone)}</td><td>${esc(p.status)}</td><td>${esc(p.rating_avg||'—')}</td><td>${p.completed_rides||0}</td>
        <td>${p.status === 'suspended' ? `<button data-u="${p.id}" data-s="active">Activate</button>` : `<button data-u="${p.id}" data-s="suspended">Suspend</button>`}</td></tr>`).join('')}</table>`;
      el.querySelectorAll('[data-u]').forEach(b => b.onclick = async () => {
        const r = await Hamba.api('admin/user-status', { method: 'POST', body: { user_id: +b.dataset.u, status: b.dataset.s } });
        toast(r.ok ? 'Updated' : (r.error || 'Failed')); show('people');
      });
    }
    if (view === 'vehicles') {
      const d = await Hamba.api('admin/vehicles');
      el.innerHTML = `<table><tr><th>Owner</th><th>Vehicle</th><th>Plate</th><th>Type</th></tr>${(d.vehicles||[]).map(v => `
        <tr><td>${esc(v.full_name)}</td><td>${esc(v.color)} ${esc(v.make)} ${esc(v.model)}</td><td>${esc(v.plate_number)}</td><td>${esc(v.vehicle_type)}</td></tr>`).join('')}</table>`;
    }
    if (view === 'reports') {
      const d = await Hamba.api('admin/reports');
      el.innerHTML = (d.reports||[]).map(r => `<div class="card"><strong>${esc(r.subject)}</strong> · ${esc(r.status)}<br><span class="muted small">${esc(r.full_name)}</span><p>${esc(r.details)}</p>
        <button data-r="${r.id}" data-st="reviewing">Reviewing</button> <button data-r="${r.id}" data-st="resolved">Resolve</button></div>`).join('') || '<p>No reports</p>';
      el.querySelectorAll('[data-r]').forEach(b => b.onclick = async () => {
        const r = await Hamba.api('admin/report-status', { method: 'POST', body: { id: +b.dataset.r, status: b.dataset.st } });
        toast(r.ok ? 'Updated' : (r.error || 'Failed'));
        show('reports');
      });
    }
    if (view === 'tickets') {
      const d = await Hamba.api('admin/tickets');
      el.innerHTML = (d.tickets||[]).map(t => `<div class="card"><strong>${esc(t.subject)}</strong> · ${esc(t.status)}<p>${esc(t.message)}</p><span class="muted">${esc(t.full_name)}</span><br>
        <button data-t="${t.id}" data-st="answered">Mark answered</button> <button data-t="${t.id}" data-st="closed">Close</button></div>`).join('') || '<p>No tickets</p>';
      el.querySelectorAll('[data-t]').forEach(b => b.onclick = async () => {
        const r = await Hamba.api('admin/ticket-status', { method: 'POST', body: { id: +b.dataset.t, status: b.dataset.st } });
        toast(r.ok ? 'Updated' : (r.error || 'Failed'));
        show('tickets');
      });
    }
    if (view === 'settings') {
      const s = window.__settings || {};
      el.innerHTML = `<form id="setF" class="card">
        <label>Platform commission %<input name="commission_percent" value="${s.commission_percent||0}"></label>
        <label>Base fare (SZL)<input name="fare_base" value="${s.fare_base||15}"></label>
        <label>Per km (SZL)<input name="fare_per_km" value="${s.fare_per_km||8}"></label>
        <label>Per minute (SZL)<input name="fare_per_min" value="${s.fare_per_min||0.5}"></label>
        <button class="btn primary" type="submit">Save</button></form>
        <form id="routeF" class="card"><h3>Add taxi route</h3>
          <label>From<input name="origin_name" required></label>
          <label>To<input name="destination_name" required></label>
          <label>Typical minutes<input name="typical_duration_min" value="40"></label>
          <button class="btn ghost" type="submit">Add route</button></form>`;
      document.getElementById('setF').onsubmit = async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target).entries());
        const r = await Hamba.api('admin/settings', { method: 'POST', body });
        toast(r.ok ? 'Settings saved' : r.error); loadStats();
      };
      document.getElementById('routeF').onsubmit = async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target).entries());
        const r = await Hamba.api('admin/routes', { method: 'POST', body });
        toast(r.ok ? 'Route added' : r.error);
      };
    }
  }

  document.querySelectorAll('.admin-nav button').forEach(b => {
    b.onclick = () => {
      document.querySelectorAll('.admin-nav button').forEach(x => x.className = 'btn ghost');
      b.className = 'btn gold';
      show(b.dataset.view);
    };
  });
  await loadStats();
  show('drivers');
  setInterval(loadStats, 8000);
})();
