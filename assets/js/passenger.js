(async function () {
  const me = await Hamba.boot();
  if (!me.user) return;

  const map = createMap('map', { lat: -26.3054, lng: 31.1367 });
  let myPos = null;
  let myMarker = null;
  const driverMarkers = {};
  const taxiMarkers = {};
  const routeLayer = { current: null };
  let destMarker = null;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let pickup = null;
  let dest = null;
  let mode = 'ride';
  let pollTimer = null;

  function setGpsChip(text) { document.getElementById('gpsChip').textContent = text; }

  watchGps((pos) => {
    myPos = pos;
    pickup = pickup || { lat: pos.lat, lng: pos.lng, label: 'Current location' };
    if (!myMarker) myMarker = L.marker([pos.lat, pos.lng], { icon: personIcon('#d4a017') }).addTo(map);
    else myMarker.setLatLng([pos.lat, pos.lng]);
    setGpsChip('GPS live');
  }, (err) => setGpsChip(err && err.message ? String(err.message) : 'GPS blocked — enable location'));

  // Demo fallback: if the phone never delivers GPS (indoors, denied),
  // still show nearby kombis around Mbabane instead of a dead screen.
  // A real fix replaces this with the passenger's rank or saved place.
  setTimeout(() => {
    if (!myPos) {
      myPos = { lat: -26.3054, lng: 31.1367 };
      pickup = pickup || { lat: myPos.lat, lng: myPos.lng, label: 'Mbabane (approximate)' };
      if (!myMarker) myMarker = L.marker([myPos.lat, myPos.lng], { icon: personIcon('#d4a017') }).addTo(map);
      setGpsChip('Approximate location — enable GPS for exact');
    }
  }, 8000);

  document.getElementById('tabRide').onclick = () => switchMode('ride');
  document.getElementById('tabTaxi').onclick = () => switchMode('taxi');
  function switchMode(next) {
    mode = next;
    document.getElementById('panelRide').classList.toggle('hidden', next !== 'ride');
    document.getElementById('panelTaxi').classList.toggle('hidden', next !== 'taxi');
    document.getElementById('tabRide').className = 'btn ' + (next === 'ride' ? 'gold' : 'ghost');
    document.getElementById('tabTaxi').className = 'btn ' + (next === 'taxi' ? 'gold' : 'ghost');
  }

  let searchT;
  document.getElementById('destQuery').addEventListener('input', (e) => {
    clearTimeout(searchT);
    const q = e.target.value.trim();
    searchT = setTimeout(async () => {
      if (q.length < 2) return;
      const data = await Hamba.api('places/search?q=' + encodeURIComponent(q));
      const box = document.getElementById('destResults');
      box.innerHTML = (data.items || []).map((i, idx) => `<button type="button" data-i="${idx}">${esc(i.label)}</button>`).join('');
      box.querySelectorAll('button').forEach((b) => {
        b.onclick = async () => {
          const item = data.items[+b.dataset.i];
          dest = { lat: item.lat, lng: item.lng, label: item.label };
          if (!myPos) { toast('Waiting for your GPS pickup point'); return; }
          pickup = { lat: myPos.lat, lng: myPos.lng, label: 'Current location' };
          if (destMarker) map.removeLayer(destMarker);
          destMarker = L.marker([dest.lat, dest.lng], { icon: personIcon('#c24e1d') }).addTo(map);
          const est = await Hamba.api('rides/estimate', { method: 'POST', body: {
            pickup_lat: pickup.lat, pickup_lng: pickup.lng, dest_lat: dest.lat, dest_lng: dest.lng
          }});
          const boxE = document.getElementById('estimate');
          boxE.classList.remove('hidden');
          boxE.innerHTML = `<strong>${est.fare.currency} ${est.fare.fare_total.toFixed(2)}</strong>
            <div class="muted small">${est.route.distance_km.toFixed(1)} km · about ${est.route.duration_min} min
            ${est.route.source === 'osrm' ? '' : ' · road estimate (OSRM offline)'}</div>`;
          drawRoute(map, routeLayer, est.route.geometry, [[pickup.lat, pickup.lng], [dest.lat, dest.lng]]);
          document.getElementById('btnRequest').classList.remove('hidden');
        };
      });
    }, 350);
  });

  document.getElementById('btnRequest').onclick = async () => {
    const data = await Hamba.api('rides/request', { method: 'POST', body: {
      pickup_lat: pickup.lat, pickup_lng: pickup.lng, dest_lat: dest.lat, dest_lng: dest.lng,
      pickup_label: pickup.label, dest_label: dest.label
    }});
    if (!data.ok) { toast(data.error); return; }
    toast('Looking for a nearby driver');
    document.getElementById('btnRequest').classList.add('hidden');
    document.getElementById('btnCancel').classList.remove('hidden');
  };

  document.getElementById('btnCancel').onclick = async () => {
    const cur = await Hamba.api('rides/current');
    if (cur.ride) await Hamba.api('rides/cancel', { method: 'POST', body: { ride_id: cur.ride.id } });
    location.reload();
  };

  document.getElementById('waitingToggle').onchange = async (e) => {
    if (!myPos) return toast('Need GPS to mark a wait point');
    await Hamba.api('taxi/waiting', { method: 'POST', body: { active: e.target.checked, lat: myPos.lat, lng: myPos.lng } });
  };

  async function poll() {
    if (!myPos) return;
    if (mode === 'taxi') {
      const data = await Hamba.api(`nearby/taxis?lat=${myPos.lat}&lng=${myPos.lng}`);
      const myB = await Hamba.api('taxi/boarding').catch(() => ({ boarding: null }));
      const onboardId = myB.boarding ? myB.boarding.shift_id : null;
      const box = document.getElementById('taxiList');
      let html = (data.taxis || []).map(t => `
        <div class="card ${t.is_full ? 'is-full' : 'not-full'}">
          <div class="row spread"><strong>${esc(t.route)}</strong><span class="status-chip">${esc(t.movement_status)}</span></div>
          <div>${t.is_full ? '<b>FULL</b>' : `<b>${t.available} seats available</b> · ${t.occupied} on board`}</div>
          <div class="muted small">≈ ${t.eta_min} min away · ${t.distance_km} km · ${esc(t.vehicle)}</div>
          ${onboardId === t.shift_id
            ? `<button class="btn danger" data-alight type="button">Alight — I'm getting off</button>`
            : `<button class="btn primary" data-board="${t.shift_id}" type="button" ${t.is_full || onboardId ? 'disabled' : ''}>I'm on board</button>`}
        </div>`).join('');
      if (onboardId && !(data.taxis || []).some(t => t.shift_id === onboardId)) {
        html = `<div class="card"><strong>You're on board</strong><p class="muted small">Your kombi is out of range or ended its shift.</p><button class="btn danger" data-alight type="button">Alight</button></div>` + html;
      }
      box.innerHTML = html || '<p class="muted">No live kombis nearby yet. Ask an operator to start a shift.</p>';
      box.querySelectorAll('[data-board]').forEach(b => b.onclick = async () => {
        const r = await Hamba.api('taxi/board', { method: 'POST', body: { shift_id: +b.dataset.board } });
        toast(r.ok ? 'On board — seat counted' : r.error);
        if (r.ok) document.getElementById('waitingToggle').checked = false;
        poll();
      });
      box.querySelectorAll('[data-alight]').forEach(b => b.onclick = async () => {
        await Hamba.api('taxi/alight', { method: 'POST', body: {} });
        toast('Alighted — seat freed');
        poll();
      });
      Object.values(taxiMarkers).forEach(m => map.removeLayer(m));
      (data.taxis || []).forEach(t => {
        taxiMarkers[t.shift_id] = L.marker([t.lat, t.lng], { icon: vehicleIcon('taxi', t.heading) })
          .addTo(map).bindPopup(`${esc(t.route)}<br>${t.is_full ? 'FULL' : t.available + ' seats'}<br>${t.eta_min} min`);
      });
      return;
    }
    const near = await Hamba.api(`nearby/drivers?lat=${myPos.lat}&lng=${myPos.lng}`);
    Object.values(driverMarkers).forEach(m => map.removeLayer(m));
    (near.drivers || []).forEach(d => {
      driverMarkers[d.id] = L.marker([d.lat, d.lng], { icon: vehicleIcon('car', d.heading) })
        .addTo(map).bindPopup(`${esc(d.full_name)}<br>★ ${esc(d.rating_avg)}`);
    });
    const cur = await Hamba.api('rides/current');
    const live = document.getElementById('rideLive');
    if (!cur.ride) {
      live.classList.add('hidden');
      document.getElementById('btnCancel').classList.add('hidden');
      return;
    }
    document.getElementById('btnCancel').classList.toggle('hidden', ['TRIP_STARTED','TRIP_COMPLETED'].includes(cur.ride.status));
    live.classList.remove('hidden');
    const tr = await Hamba.api('rides/track?ride_id=' + cur.ride.id);
    const drv = cur.driver;
    live.innerHTML = `
      <div class="status-chip">${esc(cur.ride.status).replaceAll('_',' ')}</div>
      ${drv ? `<p><strong>${esc(drv.full_name)}</strong> · ★ ${esc(drv.rating_avg)} · ${drv.completed_rides} trips<br>
        ${drv.vehicle ? esc(drv.vehicle.color) + ' ' + esc(drv.vehicle.make) + ' ' + esc(drv.vehicle.model) + ' · ' + esc(drv.vehicle.plate_number) : ''}<br>
        ${esc(drv.phone || '')}</p>` : '<p>Waiting for a verified driver…</p>'}
      ${tr.route ? `<p class="muted small">ETA ${tr.route.duration_min} min</p>` : ''}
      ${cur.ride.status === 'TRIP_COMPLETED' ? rateForm(cur.ride.id) : ''}
    `;
    if (tr.driver_location && tr.driver_location.lat) {
      const id = 'active';
      const ll = [+tr.driver_location.lat, +tr.driver_location.lng];
      if (driverMarkers[id]) driverMarkers[id].setLatLng(ll);
      else driverMarkers[id] = L.marker(ll, { icon: vehicleIcon('car', tr.driver_location.heading) }).addTo(map);
      drawRoute(map, routeLayer, tr.route && tr.route.geometry, [ll, [+cur.ride.dest_lat, +cur.ride.dest_lng]]);
    }
    if (cur.ride.status === 'TRIP_COMPLETED') bindRate();
  }

  function rateForm(id) {
    return `<form id="rateForm"><p>Rate this trip</p>
      <select name="stars"><option>5</option><option>4</option><option>3</option><option>2</option><option>1</option></select>
      <input name="review" placeholder="Optional review">
      <input type="hidden" name="ride_id" value="${id}">
      <button class="btn primary" type="submit">Submit rating</button></form>`;
  }
  function bindRate() {
    const f = document.getElementById('rateForm');
    if (!f || f.dataset.bound) return;
    f.dataset.bound = '1';
    f.onsubmit = async (e) => {
      e.preventDefault();
      const fd = new FormData(f);
      const r = await Hamba.api('ratings/submit', { method: 'POST', body: { ride_id: +fd.get('ride_id'), stars: +fd.get('stars'), review: fd.get('review') } });
      toast(r.ok ? 'Thanks — rating saved' : r.error);
    };
  }

  pollTimer = setInterval(poll, 3000);
  poll();
})();
