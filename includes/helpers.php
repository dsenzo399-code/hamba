<?php
declare(strict_types=1);

function e(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function encrypt_secret(string $plain): string {
    $key = hash('sha256', (string) app_config('security.app_key'), true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function decrypt_secret(string $enc): string {
    $key = hash('sha256', (string) app_config('security.app_key'), true);
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

function validate_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validate_phone(string $phone): bool {
    return (bool) preg_match('/^\+?[0-9]{8,15}$/', $phone);
}

function money_szl(float $amount): string {
    return 'E' . number_format($amount, 2);
}

function ride_status_label(string $status): string {
    return match ($status) {
        'REQUESTED' => 'Looking for a driver',
        'DRIVER_ASSIGNED' => 'Driver assigned',
        'DRIVER_ACCEPTED' => 'Driver accepted',
        'DRIVER_ARRIVING' => 'Driver on the way',
        'DRIVER_ARRIVED' => 'Driver has arrived',
        'TRIP_STARTED' => 'Trip in progress',
        'TRIP_COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled',
        default => $status,
    };
}

function ensure_upload_dir(string $subdir): string {
    $dir = storage_path('uploads/' . $subdir);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function save_uploaded_image(array $file, string $subdir): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($map[$mime])) {
        return null;
    }
    $name = bin2hex(random_bytes(12)) . '.' . $map[$mime];
    $dir = ensure_upload_dir($subdir);
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return null;
    }
    return $subdir . '/' . $name;
}
