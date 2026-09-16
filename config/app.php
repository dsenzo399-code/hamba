<?php
/**
 * Hamba — Eswatini ride-hailing + local taxi tracking
 *
 * External services used in development (no API keys required):
 * - OpenStreetMap tiles (https://tile.openstreetmap.org) — map background
 * - Leaflet (CDN) — interactive map
 * - OSRM public router (https://router.project-osrm.org) — routes & ETA, free demo, rate-limited
 * - Nominatim (https://nominatim.openstreetmap.org) — place search, free, requires a User-Agent
 *
 * Put optional overrides in config/local.php (copied from local.example.php).
 */
$defaults = [
    'name' => 'Hamba',
    'tagline' => 'Move smarter across eSwatini',
    'currency' => 'SZL',
    'currency_label' => 'eMaLANGENI',
    'country' => 'SZ',
    'timezone' => 'Africa/Mbabane',
    'map_center' => ['lat' => -26.3054, 'lng' => 31.1367], // Mbabane
    'nearby_driver_km' => 8.0,
    'nearby_taxi_km' => 12.0,
    'location_stale_seconds' => 90,
    'poll_interval_ms' => 3000,
    'base_url' => '',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'hamba',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'app_key' => 'change-this-to-a-long-random-string',
        'session_name' => 'HAMBASESS',
        'token_ttl_days' => 30,
    ],
    'osrm_url' => 'https://router.project-osrm.org',
    'nominatim_url' => 'https://nominatim.openstreetmap.org',
];

$local = [];
$localFile = __DIR__ . '/local.php';
if (is_file($localFile)) {
    $local = require $localFile;
}

return array_replace_recursive($defaults, is_array($local) ? $local : []);
