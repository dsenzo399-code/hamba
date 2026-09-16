(async function () {
  const me = await Hamba.boot();
  if (!me.user) return;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  document.getElementById('hello').textContent = me.user.full_name;

  let driver = me.extra && me.extra.driver;
  const map = createMap('map', { lat: -26.3054, lng: 31.1367 });
  let marker = null;
  let myPos = null;
  let online = !!(driver && driver.is_online);
  let mode = (driver && driver.operating_mode) || 'private';
  let cap = 15;
  let occupied = 0;

  document.getElementById('regBox').classList.toggle('hidden', !!(driver && driver.vehicle));
  renderVerify();
  renderOnline();
  switchMode(mode);

  watchGps(async (pos) => {
    myPos = pos;
    document.getElementById('gpsLine').textContent = `GPS ${pos.lat.toFixed(5)}, ${pos.lng.toFixed(5)}`;
    if (!marker) marker = L.marker([pos.lat, pos.lng], { icon: vehicleIcon(mode === 'taxi' ? 'taxi' : 'car') }).addTo(map);
    else marker.setLatLng([pos.lat, pos.lng]);
    map.setView([pos.lat, pos.lng]);
    if (online) {
      await Hamba.api('driver/location', { method: 'POST', body: { lat: pos.lat, lng: pos.lng, heading: pos.heading, speed_kmh: pos.speed ? pos.speed * 3.6 : null } });
    }
  }, () => { document.getElementById('gpsLine').textContent = 'Enable GPS on this device — live tracking needs a real location.'; });

  document.getElementById('onlineSwitch').onclick = async () => {
    if (!driver) return toast('Register as a driver first');
    const next = !online;
    const r = await Hamba.api('driver/online', { method: 'POST', body: { online: next, mode } });
    if (!r.ok) { toast(r.error); return; }
    online = r.is_online;
    renderOnline();
    toast(online ? 'You are online' : 'You are offline');
  };

  document.getElementById('modePrivate').onclick = () => switchMode('private');
  document.getElementById('modeTaxi').onclick = () => switchMode('taxi');
  function switchMode(next) {
    mode = next;
    document.getElementById('panelPrivate').classList.toggle('hidden', next !== 'private');
    document.getElementById('panelTaxi').classList.toggle('hidden', next !== 'taxi');
    document.getElementById('modePrivate').className = 'btn ' + (next === 'private' ? 'gold' : 'ghost');
    document.getElementById('modeTaxi').className = 'btn ' + (next === 'taxi' ? 'gold' : 'ghost');
  }
  function renderOnline() {
    document.getElementById('onlineSwitch').classList.toggle('on', online);
  }
  function renderVerify() {
    const chip = document.getElementById('verifyChip');
    chip.textContent = driver ? ('Status: ' + driver.verification_status) : 'Not registered';
  }

  document.getElementById('regForm').onsubmit = async (e) => {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.target).entries());
    const r = await Hamba.api('driver/register', { method: 'POST', body });
    toast(r.ok ? 'Submitted — wait for admin verification' : r.error);
    if (r.ok) location.reload();
  };
  document.getElementById('docForm').onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const r = await Hamba.api('driver/document', { method: 'POST', form: fd });
    toast(r.ok ? 'Document stored' : r.error);
  };

  const routes = await Hamba.api('taxi/routes');
  const sel = document.getElementById('routeSelect');
  (routes.routes || []).forEach(rt => {
    const o = document.createElement('option');
    o.value = rt.id;
    o.textContent = rt.origin_name + ' → ' + rt.destination_name;
    sel.appendChild(o);
  });

  document.getElementById('startShift').onclick = async () => {
    cap = +document.getElementById('taxiCap').value || 15;
    const r = await Hamba.api('taxi/start-shift', { method: 'POST', body: { route_id: +sel.value, seat_capacity: cap } });
    if (!r.ok) { toast(r.error); return; }
    online = true; mode = 'taxi'; occupied = 0; renderOnline(); renderSeats();
    toast('Shift live — passengers can see this kombi');
  };
  document.getElementById('endShift').onclick = async () => {
    await Hamba.api('taxi/end-shift', { method: 'POST', body: {} });
    online = false; renderOnline(); toast('Stopped broadcasting');
  };
  document.getElementById('seatPlus').onclick = () => bump(1);
  document.getElementById('seatMinus').onclick = () => bump(-1);
  async function bump(delta) {
    const r = await Hamba.api('taxi/seats', { method: 'POST', body: { delta } });
    if (!r.ok) { toast(r.error); return; }
    occupied = r.occupied; cap = r.capacity; renderSeats();
  }
  function renderSeats() {
    document.getElementById('seatCount').textContent = occupied + ' / ' + cap;
    document.getElementById('fullTag').classList.toggle('hidden', occupied < cap);
  }

  async function poll() {
    if (!driver) return;
    if (mode === 'taxi') {
      const d = await Hamba.api('taxi/demand');
      document.getElementById('demandLine').textContent = d.waiting != null ? `${d.waiting} passengers waiting along ${d.route || 'routes'}` : '';
      return;
    }
    const req = await Hamba.api('driver/requests');
    const box = document.getElementById('requests');
    box.innerHTML = (req.requests || []).map(r => `
      <div class="card">
        <strong>${esc(r.pickup_label)} → ${esc(r.dest_label)}</strong>
        <p class="muted small">${esc(r.distance_to_pickup_km)} km from you · E${Number(r.fare_total).toFixed(2)}</p>
        <button class="btn primary" data-accept="${r.id}">Accept</button>
        <button class="btn ghost" data-reject="${r.id}">Skip</button>
      </div>`).join('');
    box.querySelectorAll('[data-accept]').forEach(b => b.onclick = async () => {
      const r = await Hamba.api('driver/accept', { method: 'POST', body: { ride_id: +b.dataset.accept } });
      toast(r.ok ? 'Ride accepted' : r.error);
    });
    const cur = await Hamba.api('driver/current');
    const wrap = document.getElementById('currentRide');
    if (!cur.ride) { wrap.innerHTML = ''; return; }
    const r = cur.ride;
    wrap.innerHTML = `<div class="card">
      <div class="status-chip">${esc(r.status)}</div>
      <p><strong>${esc(r.passenger_name)}</strong> ★ ${esc(r.passenger_rating || '—')}<br>${esc(r.passenger_phone)}</p>
      <p>${esc(r.pickup_label)} → ${esc(r.dest_label)}</p>
      <div class="row">
        <button class="btn ghost" data-st="DRIVER_ARRIVING">Arriving</button>
        <button class="btn ghost" data-st="DRIVER_ARRIVED">Arrived</button>
        <button class="btn gold" data-st="TRIP_STARTED">Start trip</button>
        <button class="btn primary" data-st="TRIP_COMPLETED">Complete</button>
      </div>
      ${r.status === 'TRIP_COMPLETED' ? '' : ''}
    </div>`;
    wrap.querySelectorAll('[data-st]').forEach(b => b.onclick = async () => {
      const res = await Hamba.api('driver/ride-status', { method: 'POST', body: { ride_id: r.id, status: b.dataset.st } });
      toast(res.ok ? b.dataset.st : res.error);
    });
  }
  setInterval(poll, 3000);
  poll();
})();
