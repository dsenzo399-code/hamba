<?php
declare(strict_types=1);

function handle_rides(string $action, string $method): void {
    $u = require_login();

    if ($action === 'estimate' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $route = estimate_route((float)$in['pickup_lat'], (float)$in['pickup_lng'], (float)$in['dest_lat'], (float)$in['dest_lng']);
        $fare = estimate_fare((float)$route['distance_km'], (int)$route['duration_min']);
        json_response(['ok' => true, 'route' => $route, 'fare' => $fare]);
    }

    if ($action === 'request' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        foreach (['pickup_lat','pickup_lng','dest_lat','dest_lng'] as $k) {
            if (!isset($in[$k]) || !is_numeric($in[$k])) {
                json_response(['ok' => false, 'error' => 'Pickup and destination coordinates are required.'], 422);
            }
        }
        $open = db()->prepare('SELECT id FROM rides WHERE passenger_id = ? AND status IN ("REQUESTED","DRIVER_ASSIGNED","DRIVER_ACCEPTED","DRIVER_ARRIVING","DRIVER_ARRIVED","TRIP_STARTED") LIMIT 1');
        $open->execute([$u['id']]);
        if ($open->fetch()) {
            json_response(['ok' => false, 'error' => 'You already have an active ride.'], 409);
        }
        $route = estimate_route((float)$in['pickup_lat'], (float)$in['pickup_lng'], (float)$in['dest_lat'], (float)$in['dest_lng']);
        $fare = estimate_fare((float)$route['distance_km'], (int)$route['duration_min']);
        db()->prepare(
            'INSERT INTO rides (passenger_id, status, pickup_label, dest_label, pickup_lat, pickup_lng, dest_lat, dest_lng, distance_km, eta_minutes, fare_total, driver_earnings, platform_commission, commission_percent, currency)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $u['id'], 'REQUESTED',
            trim((string)($in['pickup_label'] ?? 'Pickup')),
            trim((string)($in['dest_label'] ?? 'Destination')),
            $in['pickup_lat'], $in['pickup_lng'], $in['dest_lat'], $in['dest_lng'],
            $route['distance_km'], $route['duration_min'],
            $fare['fare_total'], $fare['driver_earnings'], $fare['platform_commission'], $fare['commission_percent'],
            $fare['currency']
        ]);
        $id = (int) db()->lastInsertId();
        record_ride_status($id, 'REQUESTED', (int)$u['id']);
        notify((int)$u['id'], 'ride_requested', 'Ride requested', 'Looking for a nearby Hamba driver.', ['ride_id' => $id]);

        $km = (float) setting('nearby_driver_km', '8');
        $drivers = db()->query(
            'SELECT d.id, d.user_id, d.lat, d.lng FROM drivers d
             WHERE d.is_online = 1 AND d.verification_status = "verified" AND d.operating_mode = "private"
               AND d.lat IS NOT NULL AND d.last_location_at > DATE_SUB(NOW(), INTERVAL 90 SECOND)'
        )->fetchAll();
        foreach ($drivers as $drv) {
            $dist = haversine_km((float)$drv['lat'], (float)$drv['lng'], (float)$in['pickup_lat'], (float)$in['pickup_lng']);
            if ($dist <= $km) {
                notify((int)$drv['user_id'], 'new_ride_request', 'New ride request', 'A passenger nearby needs a ride.', ['ride_id' => $id]);
            }
        }
        json_response(['ok' => true, 'ride_id' => $id, 'fare' => $fare, 'route' => $route]);
    }

    if ($action === 'cancel' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $id = (int)($in['ride_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM rides WHERE id = ?');
        $stmt->execute([$id]);
        $ride = $stmt->fetch();
        if (!$ride) {
            json_response(['ok' => false, 'error' => 'Ride not found.'], 404);
        }
        $d = driver_for_user((int)$u['id']);
        $isPassenger = (int)$ride['passenger_id'] === (int)$u['id'];
        $isDriver = $d && (int)$ride['driver_id'] === (int)$d['id'];
        if (!$isPassenger && !$isDriver && $u['role'] !== 'admin') {
            json_response(['ok' => false, 'error' => 'Cannot cancel this ride.'], 403);
        }
        if (in_array($ride['status'], ['TRIP_COMPLETED','CANCELLED'], true)) {
            json_response(['ok' => false, 'error' => 'Ride already finished.'], 409);
        }
        $who = $isDriver ? 'driver' : ($isPassenger ? 'passenger' : 'admin');
        db()->prepare('UPDATE rides SET status="CANCELLED", cancelled_at=NOW(), cancelled_by=?, cancel_reason=? WHERE id=?')
            ->execute([$who, trim((string)($in['reason'] ?? '')), $id]);
        record_ride_status($id, 'CANCELLED', (int)$u['id'], $who . ' cancelled');
        if ($isPassenger && $ride['driver_id']) {
            $du = db()->prepare('SELECT user_id FROM drivers WHERE id=?');
            $du->execute([$ride['driver_id']]);
            $row = $du->fetch();
            if ($row) {
                notify((int)$row['user_id'], 'passenger_cancelled', 'Passenger cancelled', 'The passenger cancelled the ride.', ['ride_id' => $id]);
            }
        }
        if ($isDriver) {
            notify((int)$ride['passenger_id'], 'ride_cancelled', 'Ride cancelled', 'The driver cancelled. You can request again.', ['ride_id' => $id]);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'current' && $method === 'GET') {
        $stmt = db()->prepare(
            'SELECT r.* FROM rides r
             WHERE r.passenger_id = ? AND (
               r.status NOT IN ("TRIP_COMPLETED","CANCELLED")
               OR (r.status = "TRIP_COMPLETED" AND r.completed_at > DATE_SUB(NOW(), INTERVAL 45 MINUTE)
                   AND NOT EXISTS (SELECT 1 FROM ratings rt WHERE rt.ride_id = r.id AND rt.rater_user_id = ?))
             )
             ORDER BY r.id DESC LIMIT 1'
        );
        $stmt->execute([$u['id'], $u['id']]);
        $ride = $stmt->fetch() ?: null;
        $driverInfo = null;
        if ($ride && $ride['driver_id']) {
            $ds = db()->prepare(
                'SELECT d.*, u.full_name, u.phone, u.photo_path FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ?'
            );
            $ds->execute([$ride['driver_id']]);
            $drow = $ds->fetch();
            $v = $ride['vehicle_id'] ? db()->prepare('SELECT * FROM vehicles WHERE id=?') : null;
            $veh = null;
            if ($ride['vehicle_id']) {
                $vs = db()->prepare('SELECT * FROM vehicles WHERE id=?');
                $vs->execute([$ride['vehicle_id']]);
                $veh = $vs->fetch();
            }
            if ($drow) {
                $driverInfo = driver_public($drow, $veh ?: null, $drow);
            }
        }
        json_response(['ok' => true, 'ride' => $ride, 'driver' => $driverInfo]);
    }

    if ($action === 'track' && $method === 'GET') {
        $id = (int)($_GET['ride_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM rides WHERE id = ?');
        $stmt->execute([$id]);
        $ride = $stmt->fetch();
        if (!$ride) {
            json_response(['ok' => false, 'error' => 'Ride not found.'], 404);
        }
        $d = driver_for_user((int)$u['id']);
        $ok = (int)$ride['passenger_id'] === (int)$u['id'] || ($d && (int)$ride['driver_id'] === (int)$d['id']) || $u['role'] === 'admin';
        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Not allowed.'], 403);
        }
        $loc = null;
        if ($ride['driver_id']) {
            $ls = db()->prepare('SELECT lat, lng, heading, last_location_at FROM drivers WHERE id=?');
            $ls->execute([$ride['driver_id']]);
            $loc = $ls->fetch();
        }
        $route = null;
        if ($loc && $ride['status'] !== 'TRIP_STARTED') {
            $route = estimate_route((float)$loc['lat'], (float)$loc['lng'], (float)$ride['pickup_lat'], (float)$ride['pickup_lng']);
        } elseif ($loc) {
            $route = estimate_route((float)$loc['lat'], (float)$loc['lng'], (float)$ride['dest_lat'], (float)$ride['dest_lng']);
        }
        json_response(['ok' => true, 'ride' => $ride, 'driver_location' => $loc, 'route' => $route]);
    }

    if ($action === 'history' && $method === 'GET') {
        if ($u['role'] === 'driver') {
            $d = driver_for_user((int)$u['id']);
            $stmt = db()->prepare('SELECT * FROM rides WHERE driver_id = ? ORDER BY id DESC LIMIT 50');
            $stmt->execute([$d['id'] ?? 0]);
        } else {
            $stmt = db()->prepare('SELECT * FROM rides WHERE passenger_id = ? ORDER BY id DESC LIMIT 50');
            $stmt->execute([$u['id']]);
        }
        json_response(['ok' => true, 'rides' => $stmt->fetchAll()]);
    }

    json_response(['ok' => false, 'error' => 'Unknown rides action.'], 404);
}

function handle_nearby(string $action, string $method): void {
    $u = require_login();
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
    if ($lat === null || $lng === null) {
        json_response(['ok' => false, 'error' => 'lat and lng are required.'], 422);
    }
    $stale = (int) app_config('location_stale_seconds', 90);

    if ($action === 'drivers') {
        $km = (float) setting('nearby_driver_km', '8');
        $rows = db()->query(
            'SELECT d.id, d.lat, d.lng, d.heading, d.rating_avg, d.completed_rides, d.last_location_at,
                    u.full_name, u.photo_path, v.make, v.model, v.color, v.plate_number, v.vehicle_type
             FROM drivers d
             JOIN users u ON u.id = d.user_id
             LEFT JOIN vehicles v ON v.driver_id = d.id AND v.is_active = 1
             WHERE d.is_online = 1 AND d.verification_status = "verified" AND d.operating_mode = "private"
               AND d.lat IS NOT NULL AND d.last_location_at > DATE_SUB(NOW(), INTERVAL ' . $stale . ' SECOND)'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $dist = haversine_km($lat, $lng, (float)$r['lat'], (float)$r['lng']);
            if ($dist <= $km) {
                $r['distance_km'] = round($dist, 2);
                $r['eta_min'] = max(1, (int) round(($dist / 30) * 60));
                unset($r['plate_number']);
                $out[] = $r;
            }
        }
        usort($out, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
        json_response(['ok' => true, 'drivers' => $out]);
    }

    if ($action === 'taxis') {
        $km = (float) setting('nearby_taxi_km', '12');
        $rows = db()->query(
            'SELECT s.id AS shift_id, s.seat_capacity, s.occupied_seats, s.is_full, s.movement_status,
                    r.id AS route_id, r.origin_name, r.destination_name,
                    d.id AS driver_id, d.lat, d.lng, d.heading, d.last_location_at, d.rating_avg,
                    u.full_name, v.make, v.model, v.color, v.vehicle_type, v.plate_number
             FROM taxi_shifts s
             JOIN taxi_routes r ON r.id = s.route_id
             JOIN drivers d ON d.id = s.driver_id
             JOIN users u ON u.id = d.user_id
             JOIN vehicles v ON v.id = s.vehicle_id
             WHERE s.status = "active" AND d.is_online = 1 AND d.verification_status = "verified"
               AND d.lat IS NOT NULL AND d.last_location_at > DATE_SUB(NOW(), INTERVAL ' . $stale . ' SECOND)'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $dist = haversine_km($lat, $lng, (float)$r['lat'], (float)$r['lng']);
            if ($dist <= $km) {
                $available = max(0, (int)$r['seat_capacity'] - (int)$r['occupied_seats']);
                $eta = null;
                $osrm = osrm_route((float)$r['lat'], (float)$r['lng'], $lat, $lng);
                $eta = $osrm ? (int)$osrm['duration_min'] : max(1, (int) round(($dist / 35) * 60));
                $waiting = db()->prepare('SELECT COUNT(*) c FROM taxi_waiting WHERE is_active=1 AND route_id=?');
                $waiting->execute([$r['route_id']]);
                $out[] = [
                    'shift_id' => (int)$r['shift_id'],
                    'route' => $r['origin_name'] . ' → ' . $r['destination_name'],
                    'origin_name' => $r['origin_name'],
                    'destination_name' => $r['destination_name'],
                    'lat' => (float)$r['lat'],
                    'lng' => (float)$r['lng'],
                    'heading' => $r['heading'] !== null ? (float)$r['heading'] : null,
                    'movement_status' => $r['movement_status'],
                    'seat_capacity' => (int)$r['seat_capacity'],
                    'occupied' => (int)$r['occupied_seats'],
                    'available' => $available,
                    'is_full' => (int)$r['is_full'] === 1 || $available === 0,
                    'eta_min' => $eta,
                    'distance_km' => round($dist, 2),
                    'driver_name' => $r['full_name'],
                    'rating_avg' => (float)$r['rating_avg'],
                    'vehicle' => $r['make'] . ' ' . $r['model'] . ' · ' . $r['color'],
                    'vehicle_type' => $r['vehicle_type'],
                    'passengers_waiting_on_route' => (int)$waiting->fetch()['c'],
                ];
            }
        }
        usort($out, fn($a, $b) => $a['eta_min'] <=> $b['eta_min']);
        json_response(['ok' => true, 'taxis' => $out]);
    }

    json_response(['ok' => false, 'error' => 'Unknown nearby action.'], 404);
}

function handle_taxi(string $action, string $method): void {
    $u = require_login();

    if ($action === 'routes' && $method === 'GET') {
        $rows = db()->query('SELECT * FROM taxi_routes WHERE is_active = 1 ORDER BY origin_name, destination_name')->fetchAll();
        json_response(['ok' => true, 'routes' => $rows]);
    }

    if ($action === 'waiting' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $lat = isset($in['lat']) ? (float) $in['lat'] : 0.0;
        $lng = isset($in['lng']) ? (float) $in['lng'] : 0.0;
        if (($lat === 0.0 && $lng === 0.0) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            json_response(['ok' => false, 'error' => 'GPS location required.'], 422);
        }
        $routeId = isset($in['route_id']) && $in['route_id'] !== '' && $in['route_id'] !== null ? (int) $in['route_id'] : null;
        if ($routeId !== null) {
            $chk = db()->prepare('SELECT id FROM taxi_routes WHERE id = ? AND is_active = 1');
            $chk->execute([$routeId]);
            if (!$chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Unknown route.'], 422);
            }
        }
        $active = !empty($in['active']);
        db()->prepare('UPDATE taxi_waiting SET is_active=0 WHERE passenger_id=?')->execute([$u['id']]);
        if ($active) {
            db()->prepare('INSERT INTO taxi_waiting (passenger_id, route_id, lat, lng, note, is_active) VALUES (?,?,?,?,?,1)')
                ->execute([$u['id'], $routeId, $lat, $lng, substr(trim((string)($in['note'] ?? '')), 0, 190)]);
        }
        json_response(['ok' => true, 'waiting' => $active]);
    }

    if ($action === 'boarding' && $method === 'GET') {
        $b = db()->prepare(
            'SELECT b.*, r.origin_name, r.destination_name FROM taxi_boardings b
             JOIN taxi_shifts s ON s.id = b.shift_id
             JOIN taxi_routes r ON r.id = s.route_id
             WHERE b.passenger_id = ? AND b.is_active = 1 AND s.status = "active" LIMIT 1'
        );
        $b->execute([$u['id']]);
        json_response(['ok' => true, 'boarding' => $b->fetch() ?: null]);
    }

    if ($action === 'board' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $shiftId = (int)($in['shift_id'] ?? 0);
        $ex = db()->prepare('SELECT id FROM taxi_boardings WHERE passenger_id = ? AND is_active = 1 LIMIT 1');
        $ex->execute([$u['id']]);
        if ($ex->fetch()) {
            json_response(['ok' => false, 'error' => 'You are already on board. Alight first.'], 409);
        }
        $s = db()->prepare('SELECT * FROM taxi_shifts WHERE id = ? AND status = "active"');
        $s->execute([$shiftId]);
        $shift = $s->fetch();
        if (!$shift) {
            json_response(['ok' => false, 'error' => 'That kombi is no longer active.'], 404);
        }
        db()->beginTransaction();
        try {
            $upd = db()->prepare('UPDATE taxi_shifts SET occupied_seats = occupied_seats + 1 WHERE id = ? AND status = "active" AND occupied_seats < seat_capacity');
            $upd->execute([$shiftId]);
            if ($upd->rowCount() === 0) {
                db()->rollBack();
                json_response(['ok' => false, 'error' => 'This kombi just filled up.'], 409);
            }
            db()->prepare('INSERT INTO taxi_boardings (shift_id, passenger_id) VALUES (?, ?)')->execute([$shiftId, $u['id']]);
            db()->prepare('UPDATE taxi_shifts SET is_full = (occupied_seats >= seat_capacity) WHERE id = ?')->execute([$shiftId]);
            db()->prepare('UPDATE taxi_waiting SET is_active = 0 WHERE passenger_id = ?')->execute([$u['id']]);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            json_response(['ok' => false, 'error' => 'Boarding failed.'], 500);
        }
        $du = db()->prepare('SELECT user_id FROM drivers WHERE id = ?');
        $du->execute([$shift['driver_id']]);
        $dr = $du->fetch();
        if ($dr) {
            notify((int)$dr['user_id'], 'passenger_boarded', 'Passenger on board', 'A passenger confirmed boarding from the app.', ['shift_id' => $shiftId]);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'alight' && $method === 'POST') {
        require_csrf();
        $b = db()->prepare('SELECT * FROM taxi_boardings WHERE passenger_id = ? AND is_active = 1 LIMIT 1');
        $b->execute([$u['id']]);
        $row = $b->fetch();
        if (!$row) {
            json_response(['ok' => true, 'onboard' => false]);
        }
        db()->prepare('UPDATE taxi_boardings SET is_active = 0, alighted_at = NOW() WHERE id = ?')->execute([$row['id']]);
        db()->prepare('UPDATE taxi_shifts SET occupied_seats = GREATEST(0, occupied_seats - 1), is_full = 0 WHERE id = ?')->execute([$row['shift_id']]);
        json_response(['ok' => true]);
    }

    $d = driver_for_user((int)$u['id']);
    if (!$d) {
        json_response(['ok' => false, 'error' => 'Driver profile required.'], 403);
    }

    if ($action === 'start-shift' && $method === 'POST') {
        require_csrf();
        if ($d['verification_status'] !== 'verified') {
            json_response(['ok' => false, 'error' => 'Only verified operators can start a taxi shift.'], 403);
        }
        $in = json_input();
        $routeId = (int)($in['route_id'] ?? 0);
        $veh = active_vehicle((int)$d['id']);
        if (!$veh) {
            json_response(['ok' => false, 'error' => 'Add a vehicle first.'], 422);
        }
        $rs = db()->prepare('SELECT * FROM taxi_routes WHERE id=? AND is_active=1');
        $rs->execute([$routeId]);
        if (!$rs->fetch()) {
            json_response(['ok' => false, 'error' => 'Unknown route.'], 422);
        }
        $existing = active_shift((int)$d['id']);
        if ($existing) {
            db()->prepare('UPDATE taxi_shifts SET status="ended", ended_at=NOW() WHERE id=?')->execute([$existing['id']]);
            db()->prepare('UPDATE taxi_boardings SET is_active=0, alighted_at=NOW() WHERE shift_id=? AND is_active=1')->execute([$existing['id']]);
        }
        $cap = max(1, (int)($in['seat_capacity'] ?? $veh['seat_capacity']));
        db()->prepare('INSERT INTO taxi_shifts (driver_id, vehicle_id, route_id, seat_capacity, occupied_seats) VALUES (?,?,?,?,0)')
            ->execute([$d['id'], $veh['id'], $routeId, $cap]);
        db()->prepare('UPDATE drivers SET is_online=1, operating_mode="taxi" WHERE id=?')->execute([$d['id']]);
        json_response(['ok' => true, 'shift' => active_shift((int)$d['id'])]);
    }

    if ($action === 'seats' && $method === 'POST') {
        require_csrf();
        $shift = active_shift((int)$d['id']);
        if (!$shift) {
            json_response(['ok' => false, 'error' => 'No active taxi shift.'], 404);
        }
        $in = json_input();
        $delta = (int)($in['delta'] ?? 0);
        $occupied = max(0, min((int)$shift['seat_capacity'], (int)$shift['occupied_seats'] + $delta));
        if (isset($in['occupied'])) {
            $occupied = max(0, min((int)$shift['seat_capacity'], (int)$in['occupied']));
        }
        $full = $occupied >= (int)$shift['seat_capacity'] ? 1 : 0;
        db()->prepare('UPDATE taxi_shifts SET occupied_seats=?, is_full=? WHERE id=?')->execute([$occupied, $full, $shift['id']]);
        json_response(['ok' => true, 'occupied' => $occupied, 'capacity' => (int)$shift['seat_capacity'], 'available' => (int)$shift['seat_capacity'] - $occupied, 'is_full' => $full === 1]);
    }

    if ($action === 'end-shift' && $method === 'POST') {
        require_csrf();
        $shift = active_shift((int)$d['id']);
        if ($shift) {
            db()->prepare('UPDATE taxi_shifts SET status="ended", ended_at=NOW() WHERE id=?')->execute([$shift['id']]);
            db()->prepare('UPDATE taxi_boardings SET is_active=0, alighted_at=NOW() WHERE shift_id=? AND is_active=1')->execute([$shift['id']]);
        }
        db()->prepare('UPDATE drivers SET is_online=0 WHERE id=?')->execute([$d['id']]);
        json_response(['ok' => true]);
    }

    if ($action === 'demand' && $method === 'GET') {
        $shift = active_shift((int)$d['id']);
        if (!$shift) {
            json_response(['ok' => true, 'waiting' => 0]);
        }
        $c = db()->prepare('SELECT COUNT(*) c FROM taxi_waiting WHERE is_active=1 AND (route_id = ? OR route_id IS NULL)');
        $c->execute([$shift['route_id']]);
        json_response(['ok' => true, 'waiting' => (int)$c->fetch()['c'], 'route' => $shift['origin_name'] . ' → ' . $shift['destination_name']]);
    }

    json_response(['ok' => false, 'error' => 'Unknown taxi action.'], 404);
}

function handle_ratings(string $action, string $method): void {
    $u = require_login();
    if ($action === 'submit' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $rideId = (int)($in['ride_id'] ?? 0);
        $stars = (int)($in['stars'] ?? 0);
        $review = trim((string)($in['review'] ?? ''));
        if ($stars < 1 || $stars > 5) {
            json_response(['ok' => false, 'error' => 'Stars must be 1–5.'], 422);
        }
        $stmt = db()->prepare('SELECT * FROM rides WHERE id=? AND status="TRIP_COMPLETED"');
        $stmt->execute([$rideId]);
        $ride = $stmt->fetch();
        if (!$ride) {
            json_response(['ok' => false, 'error' => 'Only completed rides can be rated.'], 422);
        }
        $d = driver_for_user((int)$u['id']);
        $isPassenger = (int)$ride['passenger_id'] === (int)$u['id'];
        $isDriver = $d && (int)$ride['driver_id'] === (int)$d['id'];
        if (!$isPassenger && !$isDriver) {
            json_response(['ok' => false, 'error' => 'You were not on this ride.'], 403);
        }
        if ($isPassenger) {
            $du = db()->prepare('SELECT user_id FROM drivers WHERE id=?');
            $du->execute([$ride['driver_id']]);
            $ratee = (int) ($du->fetch()['user_id'] ?? 0);
        } else {
            $ratee = (int) $ride['passenger_id'];
        }
        if ($ratee < 1) {
            json_response(['ok' => false, 'error' => 'Could not find who to rate.'], 422);
        }
        try {
            db()->prepare('INSERT INTO ratings (ride_id, rater_user_id, ratee_user_id, stars) VALUES (?,?,?,?)')
                ->execute([$rideId, $u['id'], $ratee, $stars]);
        } catch (PDOException $e) {
            json_response(['ok' => false, 'error' => 'You already rated this ride.'], 409);
        }
        $ratingId = (int) db()->lastInsertId();
        if ($review !== '') {
            db()->prepare('INSERT INTO reviews (rating_id, ride_id, body) VALUES (?,?,?)')->execute([$ratingId, $rideId, $review]);
        }
        $avg = db()->prepare('SELECT AVG(stars) a, COUNT(*) c FROM ratings WHERE ratee_user_id=?');
        $avg->execute([$ratee]);
        $a = $avg->fetch();
        if ($isPassenger) {
            db()->prepare('UPDATE drivers SET rating_avg=? WHERE user_id=?')->execute([round((float)$a['a'], 2), $ratee]);
        } else {
            db()->prepare('UPDATE passengers SET rating_avg=? WHERE user_id=?')->execute([round((float)$a['a'], 2), $ratee]);
        }
        json_response(['ok' => true]);
    }
    json_response(['ok' => false, 'error' => 'Unknown ratings action.'], 404);
}

function handle_misc(string $resource, string $action, string $method): void {
    $u = require_login();

    if ($resource === 'notifications') {
        if ($action === 'list' && $method === 'GET') {
            json_response(['ok' => true, 'items' => unread_notifications((int)$u['id'])]);
        }
        if ($action === 'read' && $method === 'POST') {
            require_csrf();
            db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$u['id']]);
            json_response(['ok' => true]);
        }
    }

    if ($resource === 'saved') {
        if ($action === 'list' && $method === 'GET') {
            $s = db()->prepare('SELECT * FROM saved_locations WHERE user_id=? ORDER BY id DESC');
            $s->execute([$u['id']]);
            json_response(['ok' => true, 'items' => $s->fetchAll()]);
        }
        if ($action === 'add' && $method === 'POST') {
            require_csrf();
            $in = json_input();
            $label = substr(trim((string)($in['label'] ?? 'Saved')), 0, 80);
            if ($label === '') {
                $label = 'Saved';
            }
            if (!isset($in['lat'], $in['lng']) || !is_numeric($in['lat']) || !is_numeric($in['lng'])) {
                json_response(['ok' => false, 'error' => 'Latitude and longitude are required.'], 422);
            }
            $sLat = (float) $in['lat'];
            $sLng = (float) $in['lng'];
            if ($sLat < -90 || $sLat > 90 || $sLng < -180 || $sLng > 180) {
                json_response(['ok' => false, 'error' => 'Invalid coordinates.'], 422);
            }
            db()->prepare('INSERT INTO saved_locations (user_id, label, address, lat, lng) VALUES (?,?,?,?,?)')
                ->execute([$u['id'], $label, substr(trim((string)($in['address'] ?? '')), 0, 190), $sLat, $sLng]);
            json_response(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        }
        if ($action === 'delete' && $method === 'POST') {
            require_csrf();
            $in = json_input();
            db()->prepare('DELETE FROM saved_locations WHERE id=? AND user_id=?')->execute([(int)$in['id'], $u['id']]);
            json_response(['ok' => true]);
        }
    }

    if ($resource === 'reports' && $action === 'create' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $subject = substr(trim((string)($in['subject'] ?? 'Report')), 0, 120);
        $details = trim((string)($in['details'] ?? ''));
        if ($subject === '' || $details === '') {
            json_response(['ok' => false, 'error' => 'Subject and details are required.'], 422);
        }
        $rideId = isset($in['ride_id']) && $in['ride_id'] !== '' && $in['ride_id'] !== null ? (int) $in['ride_id'] : null;
        if ($rideId !== null) {
            $chk = db()->prepare('SELECT id FROM rides WHERE id = ?');
            $chk->execute([$rideId]);
            if (!$chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ride not found.'], 404);
            }
        }
        db()->prepare('INSERT INTO reports (reporter_id, ride_id, subject, details) VALUES (?,?,?,?)')
            ->execute([$u['id'], $rideId, $subject, $details]);
        json_response(['ok' => true]);
    }

    if ($resource === 'support' && $action === 'create' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $subject = substr(trim((string)($in['subject'] ?? 'Help')), 0, 120);
        $message = trim((string)($in['message'] ?? ''));
        if ($subject === '' || $message === '') {
            json_response(['ok' => false, 'error' => 'Subject and message are required.'], 422);
        }
        db()->prepare('INSERT INTO support_tickets (user_id, subject, message) VALUES (?,?,?)')
            ->execute([$u['id'], $subject, $message]);
        json_response(['ok' => true]);
    }

    if ($resource === 'places' && $action === 'search' && $method === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        if (strlen($q) < 2) {
            json_response(['ok' => true, 'items' => []]);
        }
        $url = app_config('nominatim_url') . '/search?' . http_build_query([
            'q' => $q . ', Eswatini',
            'format' => 'jsonv2',
            'limit' => 6,
            'countrycodes' => 'sz',
        ]);
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: HambaTransport/1.0 (local-dev)\r\n"]]);
        $raw = @file_get_contents($url, false, $ctx);
        $items = [];
        if ($raw !== false) {
            $json = json_decode($raw, true) ?: [];
            foreach ($json as $p) {
                $items[] = ['label' => $p['display_name'] ?? $q, 'lat' => (float)$p['lat'], 'lng' => (float)$p['lon']];
            }
        }
        json_response(['ok' => true, 'items' => $items, 'source' => $raw === false ? 'unavailable' : 'nominatim']);
    }

    json_response(['ok' => false, 'error' => 'Unknown endpoint.'], 404);
}

function handle_admin(string $action, string $method): void {
    $u = require_role('admin');

    if ($action === 'stats' && $method === 'GET') {
        $q = fn(string $sql) => (int) db()->query($sql)->fetchColumn();
        json_response(['ok' => true, 'stats' => [
            'passengers' => $q('SELECT COUNT(*) FROM users WHERE role="passenger"'),
            'drivers' => $q('SELECT COUNT(*) FROM drivers'),
            'pending' => $q('SELECT COUNT(*) FROM drivers WHERE verification_status="pending"'),
            'verified' => $q('SELECT COUNT(*) FROM drivers WHERE verification_status="verified"'),
            'online_drivers' => $q('SELECT COUNT(*) FROM drivers WHERE is_online=1 AND operating_mode="private"'),
            'online_taxis' => $q('SELECT COUNT(*) FROM taxi_shifts WHERE status="active"'),
            'active_rides' => $q('SELECT COUNT(*) FROM rides WHERE status NOT IN ("TRIP_COMPLETED","CANCELLED")'),
            'completed' => $q('SELECT COUNT(*) FROM rides WHERE status="TRIP_COMPLETED"'),
            'cancelled' => $q('SELECT COUNT(*) FROM rides WHERE status="CANCELLED"'),
            'rides_today' => $q('SELECT COUNT(*) FROM rides WHERE DATE(requested_at)=CURDATE()'),
            'revenue' => (float) db()->query('SELECT COALESCE(SUM(fare_total),0) FROM rides WHERE status="TRIP_COMPLETED"')->fetchColumn(),
            'commission' => (float) db()->query('SELECT COALESCE(SUM(platform_commission),0) FROM rides WHERE status="TRIP_COMPLETED"')->fetchColumn(),
            'driver_earnings' => (float) db()->query('SELECT COALESCE(SUM(net_amount),0) FROM earnings')->fetchColumn(),
        ], 'settings' => [
            'commission_percent' => setting('commission_percent', '0'),
            'fare_base' => setting('fare_base', '15'),
            'fare_per_km' => setting('fare_per_km', '8'),
            'fare_per_min' => setting('fare_per_min', '0.5'),
        ]]);
    }

    if ($action === 'drivers' && $method === 'GET') {
        $rows = db()->query(
            'SELECT d.id, d.user_id, d.license_number, d.license_expiry, d.verification_status, d.verification_notes,
                    d.operating_mode, d.is_online, d.lat, d.lng, d.rating_avg, d.completed_rides, d.created_at,
                    u.full_name, u.email, u.phone, u.photo_path FROM drivers d JOIN users u ON u.id=d.user_id ORDER BY d.id DESC'
        )->fetchAll();
        foreach ($rows as &$r) {
            $c = db()->prepare('SELECT COUNT(*) FROM driver_documents WHERE driver_id = ?');
            $c->execute([$r['id']]);
            $r['doc_count'] = (int) $c->fetchColumn();
        }
        unset($r);
        json_response(['ok' => true, 'drivers' => $rows]);
    }

    if ($action === 'documents' && $method === 'GET') {
        $did = (int)($_GET['driver_id'] ?? 0);
        $stmt = db()->prepare('SELECT id, doc_type, file_path, created_at FROM driver_documents WHERE driver_id = ? ORDER BY id DESC');
        $stmt->execute([$did]);
        json_response(['ok' => true, 'documents' => $stmt->fetchAll()]);
    }

    if ($action === 'verify' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $did = (int)($in['driver_id'] ?? 0);
        $status = (string)($in['status'] ?? '');
        if (!in_array($status, ['pending','verified','rejected','suspended'], true)) {
            json_response(['ok' => false, 'error' => 'Invalid status.'], 422);
        }
        $cur = db()->prepare('SELECT * FROM drivers WHERE id=?');
        $cur->execute([$did]);
        $d = $cur->fetch();
        if (!$d) {
            json_response(['ok' => false, 'error' => 'Driver not found.'], 404);
        }
        db()->prepare('UPDATE drivers SET verification_status=?, verification_notes=?, is_online=IF(?="verified", is_online, 0) WHERE id=?')
            ->execute([$status, trim((string)($in['notes'] ?? '')), $status, $did]);
        db()->prepare('INSERT INTO driver_verifications (driver_id, admin_user_id, previous_status, new_status, notes) VALUES (?,?,?,?,?)')
            ->execute([$did, $u['id'], $d['verification_status'], $status, $in['notes'] ?? '']);
        notify((int)$d['user_id'], 'verification', 'Verification update', 'Your driver status is now: ' . $status, []);
        json_response(['ok' => true]);
    }

    if ($action === 'rides' && $method === 'GET') {
        $rows = db()->query(
            'SELECT r.*, pu.full_name passenger_name, du.full_name driver_name
             FROM rides r
             JOIN users pu ON pu.id=r.passenger_id
             LEFT JOIN drivers d ON d.id=r.driver_id
             LEFT JOIN users du ON du.id=d.user_id
             ORDER BY r.id DESC LIMIT 200'
        )->fetchAll();
        json_response(['ok' => true, 'rides' => $rows]);
    }

    if ($action === 'passengers' && $method === 'GET') {
        $rows = db()->query(
            'SELECT u.id, u.full_name, u.email, u.phone, u.status, u.created_at, p.rating_avg, p.completed_rides
             FROM users u LEFT JOIN passengers p ON p.user_id=u.id WHERE u.role IN ("passenger","driver") ORDER BY u.id DESC'
        )->fetchAll();
        json_response(['ok' => true, 'passengers' => $rows]);
    }

    if ($action === 'vehicles' && $method === 'GET') {
        $rows = db()->query(
            'SELECT v.*, u.full_name FROM vehicles v JOIN drivers d ON d.id=v.driver_id JOIN users u ON u.id=d.user_id ORDER BY v.id DESC'
        )->fetchAll();
        json_response(['ok' => true, 'vehicles' => $rows]);
    }

    if ($action === 'reports' && $method === 'GET') {
        $rows = db()->query(
            'SELECT r.*, u.full_name FROM reports r JOIN users u ON u.id=r.reporter_id ORDER BY r.id DESC'
        )->fetchAll();
        json_response(['ok' => true, 'reports' => $rows]);
    }

    if ($action === 'tickets' && $method === 'GET') {
        $rows = db()->query(
            'SELECT t.*, u.full_name FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC'
        )->fetchAll();
        json_response(['ok' => true, 'tickets' => $rows]);
    }

    if ($action === 'settings' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        foreach (['commission_percent','fare_base','fare_per_km','fare_per_min','nearby_driver_km','nearby_taxi_km'] as $k) {
            if (isset($in[$k]) && is_numeric($in[$k])) {
                set_setting($k, (string)$in[$k]);
            }
        }
        json_response(['ok' => true]);
    }

    if ($action === 'routes' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $origin = substr(trim((string)($in['origin_name'] ?? '')), 0, 80);
        $dest = substr(trim((string)($in['destination_name'] ?? '')), 0, 80);
        if ($origin === '' || $dest === '') {
            json_response(['ok' => false, 'error' => 'Origin and destination names are required.'], 422);
        }
        $numOrNull = static function ($v): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            return is_numeric($v) ? (float) $v : null;
        };
        $oLat = $numOrNull($in['origin_lat'] ?? null);
        $oLng = $numOrNull($in['origin_lng'] ?? null);
        $dLat = $numOrNull($in['dest_lat'] ?? null);
        $dLng = $numOrNull($in['dest_lng'] ?? null);
        try {
            db()->prepare('INSERT INTO taxi_routes (origin_name, destination_name, origin_lat, origin_lng, dest_lat, dest_lng, typical_duration_min) VALUES (?,?,?,?,?,?,?)')
                ->execute([$origin, $dest, $oLat, $oLng, $dLat, $dLng, max(1, (int)($in['typical_duration_min'] ?? 30))]);
        } catch (PDOException $e) {
            json_response(['ok' => false, 'error' => 'That route already exists.'], 409);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'report-status' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $status = (string)($in['status'] ?? '');
        if (!in_array($status, ['open', 'reviewing', 'resolved'], true)) {
            json_response(['ok' => false, 'error' => 'Invalid report status.'], 422);
        }
        db()->prepare('UPDATE reports SET status=? WHERE id=?')->execute([$status, (int)($in['id'] ?? 0)]);
        json_response(['ok' => true]);
    }

    if ($action === 'ticket-status' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $status = (string)($in['status'] ?? '');
        if (!in_array($status, ['open', 'answered', 'closed'], true)) {
            json_response(['ok' => false, 'error' => 'Invalid ticket status.'], 422);
        }
        db()->prepare('UPDATE support_tickets SET status=? WHERE id=?')->execute([$status, (int)($in['id'] ?? 0)]);
        json_response(['ok' => true]);
    }

    if ($action === 'user-status' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $status = (string)($in['status'] ?? '');
        if (!in_array($status, ['active', 'suspended'], true)) {
            json_response(['ok' => false, 'error' => 'Invalid user status.'], 422);
        }
        $targetId = (int)($in['user_id'] ?? 0);
        if ($targetId === (int)$u['id']) {
            json_response(['ok' => false, 'error' => 'You cannot change your own status.'], 422);
        }
        db()->prepare('UPDATE users SET status=? WHERE id=? AND role <> "admin"')->execute([$status, $targetId]);
        if ($status === 'suspended') {
            db()->prepare('UPDATE drivers SET is_online=0 WHERE user_id=?')->execute([$targetId]);
        }
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'Unknown admin action.'], 404);
}
