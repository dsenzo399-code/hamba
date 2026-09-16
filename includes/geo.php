<?php
declare(strict_types=1);

function osrm_route(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array {
    $base = rtrim((string) app_config('osrm_url'), '/');
    $url = sprintf(
        '%s/route/v1/driving/%F,%F;%F,%F?overview=full&geometries=geojson',
        $base,
        $fromLng,
        $fromLat,
        $toLng,
        $toLat
    );
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: HambaTransport/1.0\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $json = json_decode($raw, true);
    if (!isset($json['routes'][0])) {
        return null;
    }
    $r = $json['routes'][0];
    return [
        'distance_km' => ($r['distance'] ?? 0) / 1000,
        'duration_min' => (int) ceil(($r['duration'] ?? 0) / 60),
        'geometry' => $r['geometry'] ?? null,
        'source' => 'osrm',
    ];
}

function estimate_route(float $fromLat, float $fromLng, float $toLat, float $toLng): array {
    $osrm = osrm_route($fromLat, $fromLng, $toLat, $toLng);
    if ($osrm) {
        return $osrm;
    }
    $km = haversine_km($fromLat, $fromLng, $toLat, $toLng) * 1.25;
    return [
        'distance_km' => $km,
        'duration_min' => (int) max(1, round(($km / 35) * 60)),
        'geometry' => null,
        'source' => 'haversine_fallback',
        'note' => 'OSRM routing was unavailable; distance uses a road-factor estimate. Tiles and GPS still use real coordinates.',
    ];
}

function estimate_fare(float $km, int $minutes): array {
    $base = (float) setting('fare_base', '15');
    $perKm = (float) setting('fare_per_km', '8');
    $perMin = (float) setting('fare_per_min', '0.5');
    $commissionPct = (float) setting('commission_percent', '0');
    $total = round($base + ($perKm * $km) + ($perMin * $minutes), 2);
    $commission = round($total * ($commissionPct / 100), 2);
    $driver = round($total - $commission, 2);
    return [
        'fare_total' => $total,
        'driver_earnings' => $driver,
        'platform_commission' => $commission,
        'commission_percent' => $commissionPct,
        'currency' => setting('currency', 'SZL'),
        'breakdown' => [
            'base' => $base,
            'per_km' => $perKm,
            'per_min' => $perMin,
        ],
    ];
}

function bearing_deg(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $y = sin(deg2rad($lng2 - $lng1)) * cos(deg2rad($lat2));
    $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2 - $lng1));
    $brng = rad2deg(atan2($y, $x));
    return fmod($brng + 360, 360);
}
