function createMap(elId, center) {
  const map = L.map(elId, { zoomControl: false }).setView([center.lat, center.lng], 13);
  L.control.zoom({ position: 'bottomright' }).addTo(map);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
  }).addTo(map);
  return map;
}

function personIcon(color) {
  return L.divIcon({
    className: '',
    html: `<div style="width:16px;height:16px;border-radius:50%;background:${color};border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35)"></div>`,
    iconSize: [16, 16],
    iconAnchor: [8, 8]
  });
}

function vehicleIcon(kind, heading = 0) {
  const bg = kind === 'taxi' ? '#c24e1d' : '#1c3d2e';
  const glyph = kind === 'taxi' ? '🚐' : '🚗';
  return L.divIcon({
    className: '',
    html: `<div style="transform:rotate(${heading || 0}deg);font-size:22px;filter:drop-shadow(0 2px 4px rgba(0,0,0,.4));background:${bg};border-radius:10px;padding:2px 4px">${glyph}</div>`,
    iconSize: [32, 32],
    iconAnchor: [16, 16]
  });
}

function drawRoute(map, layerRef, geometry, fallbackLine) {
  if (layerRef.current) map.removeLayer(layerRef.current);
  if (geometry && geometry.coordinates) {
    const latlngs = geometry.coordinates.map(([lng, lat]) => [lat, lng]);
    layerRef.current = L.polyline(latlngs, { color: '#1c3d2e', weight: 5, opacity: 0.85 }).addTo(map);
    map.fitBounds(layerRef.current.getBounds(), { padding: [40, 40] });
  } else if (fallbackLine) {
    layerRef.current = L.polyline(fallbackLine, { color: '#1c3d2e', weight: 4, dashArray: '6 8' }).addTo(map);
  }
}
