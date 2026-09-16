<?php
declare(strict_types=1);

function record_ride_status(int $rideId, string $status, ?int $actor, ?string $note = null): void {
    db()->prepare('INSERT INTO ride_status_history (ride_id, status, actor_user_id, note) VALUES (?,?,?,?)')
        ->execute([$rideId, $status, $actor, $note]);
    db()->prepare('UPDATE rides SET status = ? WHERE id = ?')->execute([$status, $rideId]);
}

function driver_for_user(int $userId): ?array {
    $stmt = db()->prepare('SELECT * FROM drivers WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function active_vehicle(int $driverId): ?array {
    $stmt = db()->prepare('SELECT * FROM vehicles WHERE driver_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$driverId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function active_shift(int $driverId): ?array {
    $stmt = db()->prepare(
        'SELECT s.*, r.origin_name, r.destination_name, r.origin_lat, r.origin_lng, r.dest_lat, r.dest_lng
         FROM taxi_shifts s JOIN taxi_routes r ON r.id = s.route_id
         WHERE s.driver_id = ? AND s.status = "active" ORDER BY s.id DESC LIMIT 1'
    );
    $stmt->execute([$driverId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function driver_public(array $d, ?array $v = null, ?array $u = null): array {
    $out = [
        'driver_id' => (int) $d['id'],
        'full_name' => $u['full_name'] ?? null,
        'phone' => $u['phone'] ?? null,
        'photo_path' => $u['photo_path'] ?? null,
        'rating_avg' => (float) $d['rating_avg'],
        'completed_rides' => (int) $d['completed_rides'],
        'verification_status' => $d['verification_status'],
        'operating_mode' => $d['operating_mode'],
        'is_online' => (int) $d['is_online'] === 1,
        'lat' => $d['lat'] !== null ? (float) $d['lat'] : null,
        'lng' => $d['lng'] !== null ? (float) $d['lng'] : null,
        'heading' => $d['heading'] !== null ? (float) $d['heading'] : null,
        'last_location_at' => $d['last_location_at'],
    ];
    if ($v) {
        $out['vehicle'] = [
            'make' => $v['make'],
            'model' => $v['model'],
            'color' => $v['color'],
            'plate_number' => $v['plate_number'],
            'vehicle_type' => $v['vehicle_type'],
            'seat_capacity' => (int) $v['seat_capacity'],
            'photo_path' => $v['photo_path'],
        ];
    }
    return $out;
}

function handle_auth(string $action, string $method): void {
    if ($action === 'csrf' && $method === 'GET') {
        json_response(['ok' => true, 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'register' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $name = trim((string) ($in['full_name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $phone = trim((string) ($in['phone'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $role = ($in['role'] ?? 'passenger') === 'driver' ? 'driver' : 'passenger';
        if (strlen($name) < 3 || !validate_email($email) || !validate_phone($phone) || strlen($password) < 8) {
            json_response(['ok' => false, 'error' => 'Check name, email, phone (+268...), and password (8+ characters).'], 422);
        }
        $exists = db()->prepare('SELECT id FROM users WHERE email = ? OR phone = ?');
        $exists->execute([$email, $phone]);
        if ($exists->fetch()) {
            json_response(['ok' => false, 'error' => 'Email or phone already registered.'], 409);
        }
        db()->beginTransaction();
        try {
            db()->prepare('INSERT INTO users (role, full_name, email, phone, password_hash) VALUES (?,?,?,?,?)')
                ->execute([$role, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
            $uid = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO passengers (user_id) VALUES (?)')->execute([$uid]);
            if ($role === 'driver') {
                db()->prepare('INSERT INTO drivers (user_id, verification_status) VALUES (?, "pending")')->execute([$uid]);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            json_response(['ok' => false, 'error' => 'Could not create account.'], 500);
        }
        $user = find_user_by_login($email);
        login_user($user);
        $token = issue_api_token($uid);
        json_response(['ok' => true, 'user' => public_user($user), 'token' => $token, 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'login' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $login = trim((string) ($in['login'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $user = find_user_by_login($login);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            json_response(['ok' => false, 'error' => 'Incorrect login details.'], 401);
        }
        if ($user['status'] !== 'active') {
            json_response(['ok' => false, 'error' => 'This account is suspended.'], 403);
        }
        login_user($user);
        $token = issue_api_token((int) $user['id']);
        json_response(['ok' => true, 'user' => public_user($user), 'token' => $token, 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'logout' && $method === 'POST') {
        logout_user();
        json_response(['ok' => true]);
    }
    if ($action === 'me' && $method === 'GET') {
        $u = current_user();
        if (!$u) {
            json_response(['ok' => false, 'user' => null], 200);
        }
        $extra = [];
        if ($u['role'] === 'driver' || driver_for_user((int) $u['id'])) {
            $d = driver_for_user((int) $u['id']);
            if ($d) {
                $extra['driver'] = driver_public($d, active_vehicle((int) $d['id']), $u);
                $extra['driver']['verification_status'] = $d['verification_status'];
                $shift = active_shift((int) $d['id']);
                $extra['taxi_shift'] = $shift;
            }
        }
        json_response(['ok' => true, 'user' => public_user($u), 'csrf' => $_SESSION['csrf'], 'extra' => $extra]);
    }
    json_response(['ok' => false, 'error' => 'Unknown auth action.'], 404);
}

function handle_profile(string $action, string $method): void {
    $u = require_login();
    if ($action === 'update' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $name = trim((string) ($in['full_name'] ?? $u['full_name']));
        $phone = trim((string) ($in['phone'] ?? $u['phone']));
        if (strlen($name) < 3 || !validate_phone($phone)) {
            json_response(['ok' => false, 'error' => 'Invalid name or phone.'], 422);
        }
        db()->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?')->execute([$name, $phone, $u['id']]);
        $_SESSION['user']['full_name'] = $name;
        $_SESSION['user']['phone'] = $phone;
        json_response(['ok' => true]);
    }
    if ($action === 'photo' && $method === 'POST') {
        require_csrf();
        $path = save_uploaded_image($_FILES['photo'] ?? [], 'profiles');
        if (!$path) {
            json_response(['ok' => false, 'error' => 'Upload a JPG or PNG under 4MB.'], 422);
        }
        db()->prepare('UPDATE users SET photo_path = ? WHERE id = ?')->execute([$path, $u['id']]);
        $_SESSION['user']['photo_path'] = $path;
        json_response(['ok' => true, 'photo_path' => $path]);
    }
    json_response(['ok' => false, 'error' => 'Unknown profile action.'], 404);
}

function handle_driver(string $action, string $method): void {
    $u = require_login();
    if ($action === 'register' && $method === 'POST') {
        require_csrf();
        $existingDriver = driver_for_user((int) $u['id']);
        $in = json_input();
        $license = trim((string) ($in['license_number'] ?? ''));
        $expiry = trim((string) ($in['license_expiry'] ?? ''));
        $nid = trim((string) ($in['national_id'] ?? ''));
        $make = trim((string) ($in['make'] ?? ''));
        $model = trim((string) ($in['model'] ?? ''));
        $color = trim((string) ($in['color'] ?? ''));
        $plate = strtoupper(trim((string) ($in['plate_number'] ?? '')));
        $type = (string) ($in['vehicle_type'] ?? 'sedan');
        $seats = max(1, (int) ($in['seat_capacity'] ?? 4));
        $allowedTypes = ['sedan','hatchback','suv','bakkie','minibus','kombi','other'];
        if ($license === '' || $make === '' || $model === '' || $color === '' || $plate === '' || !in_array($type, $allowedTypes, true)) {
            json_response(['ok' => false, 'error' => 'Complete licence and vehicle details.'], 422);
        }
        db()->beginTransaction();
        try {
            if ($existingDriver) {
                $did = (int) $existingDriver['id'];
                db()->prepare('UPDATE drivers SET national_id_enc=?, license_number=?, license_expiry=? WHERE id=?')
                    ->execute([$nid !== '' ? encrypt_secret($nid) : null, $license, $expiry !== '' ? $expiry : null, $did]);
                if (active_vehicle($did)) {
                    db()->commit();
                    json_response(['ok' => true, 'verification_status' => $existingDriver['verification_status']]);
                }
            } else {
                db()->prepare('INSERT INTO drivers (user_id, national_id_enc, license_number, license_expiry, verification_status) VALUES (?,?,?,?, "pending")')
                    ->execute([(int) $u['id'], $nid !== '' ? encrypt_secret($nid) : null, $license, $expiry !== '' ? $expiry : null]);
                $did = (int) db()->lastInsertId();
            }
            db()->prepare('INSERT INTO vehicles (driver_id, make, model, color, plate_number, vehicle_type, seat_capacity, registration_info) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$did, $make, $model, $color, $plate, $type, $seats, trim((string) ($in['registration_info'] ?? ''))]);
            if ($u['role'] === 'passenger') {
                db()->prepare('UPDATE users SET role = "driver" WHERE id = ?')->execute([$u['id']]);
                $_SESSION['user']['role'] = 'driver';
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            json_response(['ok' => false, 'error' => 'Could not save driver registration.'], 500);
        }
        json_response(['ok' => true, 'verification_status' => 'pending']);
    }
    $d = driver_for_user((int) $u['id']);
    if (!$d) {
        json_response(['ok' => false, 'error' => 'No driver profile.'], 404);
    }

    if ($action === 'document' && $method === 'POST') {
        require_csrf();
        $type = (string) ($_POST['doc_type'] ?? 'other');
        $allowed = ['id','license','vehicle_registration','vehicle_photo','profile','other'];
        if (!in_array($type, $allowed, true)) {
            json_response(['ok' => false, 'error' => 'Invalid document type.'], 422);
        }
        $path = save_uploaded_image($_FILES['file'] ?? [], 'documents');
        if (!$path) {
            json_response(['ok' => false, 'error' => 'Could not store document image.'], 422);
        }
        db()->prepare('INSERT INTO driver_documents (driver_id, doc_type, file_path) VALUES (?,?,?)')
            ->execute([$d['id'], $type, $path]);
        if ($type === 'vehicle_photo') {
            $v = active_vehicle((int) $d['id']);
            if ($v) {
                db()->prepare('UPDATE vehicles SET photo_path = ? WHERE id = ?')->execute([$path, $v['id']]);
            }
        }
        json_response(['ok' => true, 'file_path' => $path]);
    }

    if ($action === 'online' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $online = !empty($in['online']);
        $mode = ($in['mode'] ?? $d['operating_mode']) === 'taxi' ? 'taxi' : 'private';
        if ($online && $d['verification_status'] !== 'verified') {
            json_response(['ok' => false, 'error' => 'Only verified drivers can go online.'], 403);
        }
        db()->prepare('UPDATE drivers SET is_online = ?, operating_mode = ? WHERE id = ?')
            ->execute([$online ? 1 : 0, $mode, $d['id']]);
        if (!$online) {
            $shift = active_shift((int) $d['id']);
            if ($shift) {
                db()->prepare('UPDATE taxi_shifts SET status = "ended", ended_at = NOW() WHERE id = ?')->execute([$shift['id']]);
            }
        }
        json_response(['ok' => true, 'is_online' => $online, 'mode' => $mode]);
    }

    if ($action === 'location' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $lat = isset($in['lat']) ? (float) $in['lat'] : null;
        $lng = isset($in['lng']) ? (float) $in['lng'] : null;
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            json_response(['ok' => false, 'error' => 'Valid GPS coordinates are required.'], 422);
        }
        $heading = isset($in['heading']) ? (float) $in['heading'] : null;
        $speed = isset($in['speed_kmh']) ? (float) $in['speed_kmh'] : null;
        $prevLat = $d['lat'] !== null ? (float) $d['lat'] : null;
        $prevLng = $d['lng'] !== null ? (float) $d['lng'] : null;
        if ($heading === null && $prevLat !== null) {
            $heading = bearing_deg($prevLat, $prevLng, $lat, $lng);
        }
        db()->prepare('UPDATE drivers SET lat=?, lng=?, heading=?, speed_kmh=?, last_location_at=NOW() WHERE id=?')
            ->execute([$lat, $lng, $heading, $speed, $d['id']]);
        db()->prepare('INSERT INTO driver_locations (driver_id, lat, lng, heading) VALUES (?,?,?,?)')
            ->execute([$d['id'], $lat, $lng, $heading]);
        $shift = active_shift((int) $d['id']);
        if ($shift) {
            $moving = ($speed !== null && $speed > 3) || ($prevLat !== null && haversine_km($prevLat, $prevLng, $lat, $lng) > 0.03);
            db()->prepare('INSERT INTO taxi_locations (shift_id, driver_id, lat, lng, heading) VALUES (?,?,?,?,?)')
                ->execute([$shift['id'], $d['id'], $lat, $lng, $heading]);
            db()->prepare('UPDATE taxi_shifts SET movement_status = ? WHERE id = ?')
                ->execute([$moving ? 'moving' : 'waiting', $shift['id']]);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'requests' && $method === 'GET') {
        if ($d['verification_status'] !== 'verified' || !(int) $d['is_online']) {
            json_response(['ok' => true, 'requests' => []]);
        }
        $km = (float) setting('nearby_driver_km', '8');
        $stmt = db()->prepare(
            'SELECT r.* FROM rides r
             WHERE r.status = "REQUESTED" AND r.driver_id IS NULL
             ORDER BY r.id DESC LIMIT 20'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $ride) {
            if ($d['lat'] === null) {
                continue;
            }
            $dist = haversine_km((float) $d['lat'], (float) $d['lng'], (float) $ride['pickup_lat'], (float) $ride['pickup_lng']);
            if ($dist <= $km) {
                $ride['distance_to_pickup_km'] = round($dist, 2);
                $out[] = $ride;
            }
        }
        json_response(['ok' => true, 'requests' => $out]);
    }

    if ($action === 'accept' && $method === 'POST') {
        require_csrf();
        if ($d['verification_status'] !== 'verified') {
            json_response(['ok' => false, 'error' => 'Driver is not verified.'], 403);
        }
        $in = json_input();
        $rideId = (int) ($in['ride_id'] ?? 0);
        $veh = active_vehicle((int) $d['id']);
        db()->beginTransaction();
        try {
            $stmt = db()->prepare('SELECT * FROM rides WHERE id = ? AND status = "REQUESTED" AND driver_id IS NULL FOR UPDATE');
            $stmt->execute([$rideId]);
            $ride = $stmt->fetch();
            if (!$ride) {
                db()->rollBack();
                json_response(['ok' => false, 'error' => 'Ride is no longer available.'], 409);
            }
            $busy = db()->prepare('SELECT id FROM rides WHERE driver_id = ? AND status IN ("DRIVER_ASSIGNED","DRIVER_ACCEPTED","DRIVER_ARRIVING","DRIVER_ARRIVED","TRIP_STARTED") LIMIT 1');
            $busy->execute([$d['id']]);
            if ($busy->fetch()) {
                db()->rollBack();
                json_response(['ok' => false, 'error' => 'Finish your current trip first.'], 409);
            }
            db()->prepare('UPDATE rides SET driver_id=?, vehicle_id=?, accepted_at=NOW() WHERE id=?')
                ->execute([$d['id'], $veh['id'] ?? null, $rideId]);
            record_ride_status($rideId, 'DRIVER_ASSIGNED', (int) $u['id'], 'Matched');
            record_ride_status($rideId, 'DRIVER_ACCEPTED', (int) $u['id'], 'Driver accepted');
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            json_response(['ok' => false, 'error' => 'Accept failed.'], 500);
        }
        notify((int) $ride['passenger_id'], 'driver_accepted', 'Driver accepted', 'A driver accepted your Hamba ride.', ['ride_id' => $rideId]);
        $p = db()->prepare('SELECT full_name FROM users WHERE id=?');
        $p->execute([$ride['passenger_id']]);
        json_response(['ok' => true]);
    }

    if ($action === 'reject' && $method === 'POST') {
        require_csrf();
        json_response(['ok' => true]);
    }

    if ($action === 'ride-status' && $method === 'POST') {
        require_csrf();
        $in = json_input();
        $rideId = (int) ($in['ride_id'] ?? 0);
        $status = (string) ($in['status'] ?? '');
        $allowed = ['DRIVER_ARRIVING','DRIVER_ARRIVED','TRIP_STARTED','TRIP_COMPLETED'];
        if (!in_array($status, $allowed, true)) {
            json_response(['ok' => false, 'error' => 'Invalid status.'], 422);
        }
        $stmt = db()->prepare('SELECT * FROM rides WHERE id = ? AND driver_id = ?');
        $stmt->execute([$rideId, $d['id']]);
        $ride = $stmt->fetch();
        if (!$ride) {
            json_response(['ok' => false, 'error' => 'Ride not found.'], 404);
        }
        $order = ['REQUESTED','DRIVER_ASSIGNED','DRIVER_ACCEPTED','DRIVER_ARRIVING','DRIVER_ARRIVED','TRIP_STARTED','TRIP_COMPLETED'];
        if (array_search($status, $order, true) < array_search($ride['status'], $order, true)) {
            json_response(['ok' => false, 'error' => 'Cannot move ride backwards.'], 422);
        }
        if ($status === 'TRIP_STARTED') {
            db()->prepare('UPDATE rides SET started_at = NOW() WHERE id = ?')->execute([$rideId]);
        }
        if ($status === 'TRIP_COMPLETED') {
            complete_ride($ride, $d);
        } else {
            record_ride_status($rideId, $status, (int) $u['id']);
            $titles = [
                'DRIVER_ARRIVING' => ['Driver arriving', 'Your driver is on the way.'],
                'DRIVER_ARRIVED' => ['Driver arrived', 'Your driver is at the pickup point.'],
                'TRIP_STARTED' => ['Trip started', 'You are on the way.'],
            ];
            if (isset($titles[$status])) {
                notify((int) $ride['passenger_id'], strtolower($status), $titles[$status][0], $titles[$status][1], ['ride_id' => $rideId]);
            }
        }
        json_response(['ok' => true, 'status' => $status]);
    }

    if ($action === 'current' && $method === 'GET') {
        $stmt = db()->prepare(
            'SELECT r.*, u.full_name AS passenger_name, u.phone AS passenger_phone, u.photo_path AS passenger_photo,
                    p.rating_avg AS passenger_rating
             FROM rides r
             JOIN users u ON u.id = r.passenger_id
             LEFT JOIN passengers p ON p.user_id = u.id
             WHERE r.driver_id = ? AND r.status IN ("DRIVER_ASSIGNED","DRIVER_ACCEPTED","DRIVER_ARRIVING","DRIVER_ARRIVED","TRIP_STARTED")
             ORDER BY r.id DESC LIMIT 1'
        );
        $stmt->execute([$d['id']]);
        json_response(['ok' => true, 'ride' => $stmt->fetch() ?: null, 'driver' => driver_public($d, active_vehicle((int)$d['id']), $u)]);
    }

    if ($action === 'earnings' && $method === 'GET') {
        $sum = db()->prepare('SELECT COALESCE(SUM(net_amount),0) t, COUNT(*) c FROM earnings WHERE driver_id = ?');
        $sum->execute([$d['id']]);
        $s = $sum->fetch();
        $rows = db()->prepare('SELECT e.*, r.pickup_label, r.dest_label, r.completed_at FROM earnings e JOIN rides r ON r.id = e.ride_id WHERE e.driver_id = ? ORDER BY e.id DESC LIMIT 50');
        $rows->execute([$d['id']]);
        json_response(['ok' => true, 'totals' => $s, 'items' => $rows->fetchAll(), 'rating' => $d['rating_avg'], 'completed' => $d['completed_rides']]);
    }

    json_response(['ok' => false, 'error' => 'Unknown driver action.'], 404);
}

function complete_ride(array $ride, array $driver): void {
    $route = estimate_route((float)$ride['pickup_lat'], (float)$ride['pickup_lng'], (float)$ride['dest_lat'], (float)$ride['dest_lng']);
    $fare = estimate_fare((float)$route['distance_km'], (int)$route['duration_min']);
    db()->prepare(
        'UPDATE rides SET status="TRIP_COMPLETED", completed_at=NOW(), distance_km=?, eta_minutes=?, fare_total=?, driver_earnings=?, platform_commission=?, commission_percent=? WHERE id=?'
    )->execute([
        $route['distance_km'], $route['duration_min'], $fare['fare_total'], $fare['driver_earnings'],
        $fare['platform_commission'], $fare['commission_percent'], $ride['id']
    ]);
    record_ride_status((int)$ride['id'], 'TRIP_COMPLETED', null, 'Completed');
    db()->prepare('INSERT INTO payments (ride_id, method, amount, currency, status) VALUES (?,?,?,?, "recorded")')
        ->execute([$ride['id'], 'cash', $fare['fare_total'], $fare['currency']]);
    db()->prepare('INSERT INTO earnings (driver_id, ride_id, gross_fare, commission, net_amount, currency) VALUES (?,?,?,?,?,?)')
        ->execute([$driver['id'], $ride['id'], $fare['fare_total'], $fare['platform_commission'], $fare['driver_earnings'], $fare['currency']]);
    db()->prepare('UPDATE drivers SET completed_rides = completed_rides + 1, is_online = 1 WHERE id = ?')->execute([$driver['id']]);
    db()->prepare('UPDATE passengers SET completed_rides = completed_rides + 1 WHERE user_id = ?')->execute([$ride['passenger_id']]);
    notify((int)$ride['passenger_id'], 'trip_completed', 'Trip completed', 'Please rate your driver. Fare: ' . money_szl($fare['fare_total']), ['ride_id' => (int)$ride['id']]);
    $pUser = db()->prepare('SELECT user_id FROM drivers WHERE id=?');
    $pUser->execute([$driver['id']]);
    $du = $pUser->fetch();
    if ($du) {
        notify((int)$du['user_id'], 'trip_completed', 'Trip completed', 'You earned ' . money_szl($fare['driver_earnings']), ['ride_id' => (int)$ride['id']]);
    }
}
