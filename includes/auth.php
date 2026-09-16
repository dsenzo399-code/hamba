<?php
declare(strict_types=1);

function current_user(): ?array {
    if (!empty($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/', $hdr, $m)) {
        $user = user_from_token(trim($m[1]));
        if ($user) {
            $_SESSION['user'] = $user;
            return $user;
        }
    }
    return null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        json_response(['ok' => false, 'error' => 'Authentication required.'], 401);
    }
    if ($u['status'] !== 'active') {
        json_response(['ok' => false, 'error' => 'Account is not active.'], 403);
    }
    return $u;
}

function require_role(string ...$roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        json_response(['ok' => false, 'error' => 'Not authorised for this action.'], 403);
    }
    return $u;
}

function login_user(array $user): void {
    session_regenerate_id(true);
    unset($user['password_hash']);
    $_SESSION['user'] = $user;
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, true);
    }
    session_destroy();
}

function issue_api_token(int $userId): string {
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $days = (int) app_config('security.token_ttl_days', 30);
    $stmt = db()->prepare('INSERT INTO user_api_tokens (user_id, token_hash, user_agent, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY))');
    $stmt->execute([$userId, $hash, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $days]);
    return $raw;
}

function user_from_token(string $raw): ?array {
    $hash = hash('sha256', $raw);
    $stmt = db()->prepare(
        'SELECT u.* FROM user_api_tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([$hash]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function find_user_by_login(string $login): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR phone = ? LIMIT 1');
    $stmt->execute([$login, $login]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function public_user(array $user): array {
    return [
        'id' => (int) $user['id'],
        'role' => $user['role'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'photo_path' => $user['photo_path'],
        'status' => $user['status'],
    ];
}
