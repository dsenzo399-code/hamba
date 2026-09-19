<?php
declare(strict_types=1);

/**
 * Harbor mobile API — Imali-compatible dialect for the Harbor Android app.
 *
 * Harbor (HarborApi Retrofit interface) speaks raw-JSON Bearer-token REST:
 *   POST auth/login {email,password} -> {token,user}
 *   GET  me -> {id,name,email}
 *   GET/POST/PUT/DELETE transactions, shopping-lists, budgets, goals,
 *   recurring, wishlist, reports, calendar, categories, stores,
 *   products, prices + nested shopping-items + forecast/notifications reads.
 *
 * This is intentionally separate from api/index.php (session+CSRF web dialect
 * with {ok:true} wrappers). Mobile cannot do the web CSRF flow, so this
 * controller uses only the existing user_api_tokens Bearer scheme and returns
 * the raw DTO shapes Harbor expects.
 *
 * Base URL to set in Harbor app > More > Settings:
 *   http://<LAPTOP_LAN_IP>/hamba/api/harbor/
 * Laptop itself: http://127.0.0.1/hamba/api/harbor/ (XAMPP Apache must run).
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

// Temporary dev mode: the current Harbor APK has no login screen, so it can
// never send a Bearer token. While guest mode is on, token-less requests run
// as a shared local "guest" user instead of 401. Real logins still work and
// take precedence. Set to false once the app has a login screen.
const HARBOR_GUEST_MODE = true;

function harbor_guest_user(): array {
    $email = 'guest@harbor.local';
    $u = find_user_by_login($email);
    if ($u) {
        return $u;
    }
    for ($i = 0; $i < 5; $i++) {
        // Random phone per attempt: never steal a real user's number.
        $phone = '+2688' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        try {
            db()->prepare('INSERT INTO users (role, full_name, email, phone, password_hash) VALUES (?,?,?,?,?)')
                ->execute(['passenger', 'Harbor Guest', $email, $phone, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
            $uid = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO passengers (user_id) VALUES (?)')->execute([$uid]);
            break;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) != 1062) {
                throw $e;
            }
        }
        $u = find_user_by_login($email);
        if ($u) {
            return $u;
        }
    }
    $u = find_user_by_login($email);
    if (!$u) {
        harbor_error('Guest unavailable.', 500);
    }
    return $u;
}

harbor_migrate();

// ---------------------------------------------------------------- helpers

function harbor_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if ($code === 204 || $data === null) {
        exit;
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function harbor_error(string $msg, int $code): void {
    harbor_response(['error' => $msg], $code);
}

function harbor_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

function harbor_bearer_raw(): string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($hdr === '' && function_exists('apache_request_headers')) {
        $all = apache_request_headers();
        foreach ($all as $k => $v) {
            if (strtolower((string) $k) === 'authorization') {
                $hdr = (string) $v;
                break;
            }
        }
    }
    if (preg_match('/Bearer\s+(.+)/', $hdr, $m)) {
        return trim($m[1]);
    }
    return '';
}

/** @return array{id:int,role:string,full_name:string,email:string,phone:string,status:string} */
function harbor_require_user(): array {
    $raw = harbor_bearer_raw();
    if ($raw === '') {
        if (HARBOR_GUEST_MODE) {
            return harbor_guest_user();
        }
        harbor_error('Authentication required.', 401);
    }
    $u = user_from_token($raw);
    if (!$u || ($u['status'] ?? '') !== 'active') {
        if (HARBOR_GUEST_MODE) {
            return harbor_guest_user();
        }
        harbor_error('Authentication required.', 401);
    }
    return $u;
}

function harbor_user_dto(array $u): array {
    return ['id' => (string) $u['id'], 'name' => (string) ($u['full_name'] ?? ''), 'email' => (string) ($u['email'] ?? '')];
}

function harbor_num($v): ?float {
    if ($v === null || $v === '') {
        return null;
    }
    return is_numeric($v) ? (float) $v : null;
}

function harbor_bool($v): bool {
    return (int) $v === 1;
}

function harbor_str($v, string $default = ''): string {
    if ($v === null) {
        return $default;
    }
    return (string) $v;
}

function harbor_nullable_str($v): ?string {
    if ($v === null || $v === '') {
        return null;
    }
    return (string) $v;
}

function harbor_migrate(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $ddl = [
        "CREATE TABLE IF NOT EXISTS harbor_transactions (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, title VARCHAR(190) NOT NULL DEFAULT '', amount DECIMAL(12,2) DEFAULT NULL, currency CHAR(3) NOT NULL DEFAULT 'EML', occurred_at VARCHAR(64) NOT NULL DEFAULT '', category VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_ht_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_shopping_lists (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '', archived TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hsl_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_shopping_items (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, list_id CHAR(36) NOT NULL, product_id VARCHAR(64) DEFAULT NULL, name VARCHAR(190) NOT NULL DEFAULT '', quantity DECIMAL(12,3) DEFAULT NULL, unit VARCHAR(32) DEFAULT NULL, checked TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hsi_user (user_id), KEY idx_hsi_list (list_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_budgets (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '', amount DECIMAL(12,2) DEFAULT NULL, period VARCHAR(64) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hb_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_goals (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '', target DECIMAL(12,2) DEFAULT NULL, current DECIMAL(12,2) DEFAULT NULL, due_date VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hg_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_forecast (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, period VARCHAR(64) NOT NULL DEFAULT '', amount DECIMAL(12,2) DEFAULT NULL, currency CHAR(3) NOT NULL DEFAULT 'EML', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hf_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_recurring (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, title VARCHAR(190) NOT NULL DEFAULT '', amount DECIMAL(12,2) DEFAULT NULL, cadence VARCHAR(64) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hr_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_wishlist (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '', product_id VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hw_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_reports (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, title VARCHAR(190) NOT NULL DEFAULT '', period VARCHAR(64) NOT NULL DEFAULT '', total DECIMAL(12,2) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hrp_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_calendar (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, title VARCHAR(190) NOT NULL DEFAULT '', date VARCHAR(64) NOT NULL DEFAULT '', notes TEXT DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hc_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_categories (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hcat_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_stores (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '', address VARCHAR(255) DEFAULT NULL, managed TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hs_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_products (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '', price DECIMAL(12,2) DEFAULT NULL, quantity DECIMAL(12,3) DEFAULT NULL, unit VARCHAR(32) DEFAULT NULL, image_url VARCHAR(512) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hp_user (user_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS harbor_prices (id CHAR(36) NOT NULL PRIMARY KEY, user_id INT UNSIGNED NOT NULL, product_id VARCHAR(64) NOT NULL DEFAULT '', store_id VARCHAR(64) NOT NULL DEFAULT '', price DECIMAL(12,2) DEFAULT NULL, currency CHAR(3) NOT NULL DEFAULT 'EML', captured_at VARCHAR(64) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_hpr_user (user_id), KEY idx_hpr_product (product_id)) ENGINE=InnoDB",
    ];
    foreach ($ddl as $sql) {
        try {
            db()->exec($sql);
        } catch (Throwable $e) {
            // Table may already exist with FKs from harbor_schema.sql; ignore.
        }
    }
}

// ------------------------------------------------------------ row mappers

function map_transaction(array $r): array {
    return ['id' => (string) $r['id'], 'title' => harbor_str($r['title']), 'amount' => harbor_num($r['amount']), 'currency' => harbor_str($r['currency'], 'EML'), 'occurredAt' => harbor_str($r['occurred_at']), 'category' => harbor_nullable_str($r['category'])];
}

function map_shopping_list(array $r): array {
    return ['id' => (string) $r['id'], 'name' => harbor_str($r['name']), 'archived' => harbor_bool($r['archived'])];
}

function map_shopping_item(array $r): array {
    return ['id' => (string) $r['id'], 'listId' => (string) $r['list_id'], 'productId' => harbor_nullable_str($r['product_id']), 'name' => harbor_str($r['name']), 'quantity' => harbor_num($r['quantity']), 'unit' => harbor_nullable_str($r['unit']), 'checked' => harbor_bool($r['checked'])];
}

function map_budget(array $r): array {
    return ['id' => (string) $r['id'], 'name' => harbor_str($r['name']), 'amount' => harbor_num($r['amount']), 'period' => harbor_str($r['period'])];
}

function map_goal(array $r): array {
    return ['id' => (string) $r['id'], 'name' => harbor_str($r['name']), 'target' => harbor_num($r['target']), 'current' => harbor_num($r['current']), 'dueDate' => harbor_nullable_str($r['due_date'])];
}

function map_forecast(array $r): array {
    return ['id' => (string) $r['id'], 'period' => harbor_str($r['period']), 'amount' => harbor_num($r['amount']), 'currency' => harbor_str($r['currency'], 'EML')];
}

function map_recurring(array $r): array {
    return ['id' => (string) $r['id'], 'title' => harbor_str($r['title']), 'amount' => harbor_num($r['amount']), 'cadence' => harbor_str($r['cadence'])];
}

function map_wishlist(array $r): array {
    return ['id' => (string) $r['id'], 'name' => harbor_str($r['name']), 'productId' => harbor_nullable_str($r['product_id'])];
}

function map_report(array $r): array {
    return ['id' => (string) $r['id'], 'title' => harbor_str($r['title']), 'period' => harbor_str($r['period']), 'total' => harbor_num($r['total'])];
}

function map_calendar(array $r): array {
    return ['id' => (string) $r['id'], 'title' => harbor_str($r['title']), 'date' => harbor_str($r['date']), 'notes' => harbor_nullable_str($r['notes'])];
}

function map_category(array $r): array {
    return ['id' => (string) $r['id'], 'name' => harbor_str($r['name'])];
}

function map_store(array $r): array {
    return ['id' => (string) $r['id'], 'name' => harbor_str($r['name']), 'address' => harbor_nullable_str($r['address']), 'managed' => harbor_bool($r['managed'])];
}

function map_product(array $r): array {
    return ['id' => (string) $r['id'], 'name' => harbor_str($r['name']), 'price' => harbor_num($r['price']), 'quantity' => harbor_num($r['quantity']), 'unit' => harbor_nullable_str($r['unit']), 'imageUrl' => harbor_nullable_str($r['image_url'])];
}

function map_price(array $r): array {
    return ['id' => (string) $r['id'], 'productId' => harbor_str($r['product_id']), 'storeId' => harbor_str($r['store_id']), 'price' => harbor_num($r['price']), 'currency' => harbor_str($r['currency'], 'EML'), 'capturedAt' => harbor_str($r['captured_at'])];
}

function harbor_rows(string $sql, array $params, callable $map): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map($map, $stmt->fetchAll());
}

function harbor_find(string $table, string $id, int $uid): ?array {
    $stmt = db()->prepare("SELECT * FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------- routing

$route = trim((string) ($_GET['route'] ?? ''), '/');
if ($route === '') {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    if (preg_match('#/harbor/(.+)$#', $uri, $m)) {
        $route = trim($m[1], '/');
    } elseif (preg_match('#/v1/(.+)$#', $uri, $m)) {
        $route = trim($m[1], '/');
    }
}
$parts = $route === '' ? [] : explode('/', $route);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$r0 = $parts[0] ?? '';
$r1 = $parts[1] ?? '';
$r2 = $parts[2] ?? '';
$r3 = $parts[3] ?? '';

// ------------------------------------------------------------- auth/login
if ($r0 === 'auth' && $r1 === 'login' && $method === 'POST') {
    $in = json_input();
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $password = (string) ($in['password'] ?? '');
    if (!validate_email($email) || $password === '') {
        harbor_error('Valid email and password are required.', 422);
    }
    $user = find_user_by_login($email);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        harbor_error('Incorrect login details.', 401);
    }
    if (($user['status'] ?? '') !== 'active') {
        harbor_error('This account is suspended.', 403);
    }
    $token = issue_api_token((int) $user['id']);
    harbor_response(['token' => $token, 'user' => harbor_user_dto($user)]);
}

if ($r0 === 'auth' && $r1 === 'logout' && $method === 'POST') {
    $raw = harbor_bearer_raw();
    if ($raw !== '') {
        db()->prepare('DELETE FROM user_api_tokens WHERE token_hash = ?')->execute([hash('sha256', $raw)]);
    }
    harbor_response(null, 204);
}

if ($r0 === 'me' && $r1 === '' && $method === 'GET') {
    harbor_response(harbor_user_dto(harbor_require_user()));
}

// ------------------------------------------------------------ transactions
if ($r0 === 'transactions' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [(int) $u['id']], 'map_transaction'));
}
if ($r0 === 'transactions' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_transactions (id, user_id, title, amount, currency, occurred_at, category) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], trim((string) ($in['title'] ?? '')), harbor_num($in['amount'] ?? null), substr((string) ($in['currency'] ?? 'EML'), 0, 3) ?: 'EML', (string) ($in['occurredAt'] ?? ''), harbor_nullable_str($in['category'] ?? null)]);
    $row = harbor_find('harbor_transactions', $id, (int) $u['id']);
    harbor_response(map_transaction($row));
}
if ($r0 === 'transactions' && $r1 !== '' && $method === 'PUT') {
    $u = harbor_require_user();
    $in = json_input();
    $row = harbor_find('harbor_transactions', $r1, (int) $u['id']);
    if (!$row) {
        harbor_error('Not found.', 404);
    }
    db()->prepare('UPDATE harbor_transactions SET title = ?, amount = ?, currency = ?, occurred_at = ?, category = ? WHERE id = ? AND user_id = ?')
        ->execute([trim((string) ($in['title'] ?? $row['title'])), harbor_num($in['amount'] ?? $row['amount']), substr((string) ($in['currency'] ?? $row['currency']), 0, 3) ?: 'EML', (string) ($in['occurredAt'] ?? $row['occurred_at']), harbor_nullable_str($in['category'] ?? $row['category']), $r1, (int) $u['id']]);
    harbor_response(map_transaction(harbor_find('harbor_transactions', $r1, (int) $u['id'])));
}
if ($r0 === 'transactions' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_transactions WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// ---------------------------------------------------------- shopping lists
if ($r0 === 'shopping-lists' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_shopping_lists WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [(int) $u['id']], 'map_shopping_list'));
}
if ($r0 === 'shopping-lists' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_shopping_lists (id, user_id, name, archived) VALUES (?,?,?,?)')
        ->execute([$id, (int) $u['id'], trim((string) ($in['name'] ?? '')), !empty($in['archived']) ? 1 : 0]);
    harbor_response(map_shopping_list(harbor_find('harbor_shopping_lists', $id, (int) $u['id'])));
}
if ($r0 === 'shopping-lists' && $r1 !== '' && $r2 === '' && $method === 'PUT') {
    $u = harbor_require_user();
    $in = json_input();
    $row = harbor_find('harbor_shopping_lists', $r1, (int) $u['id']);
    if (!$row) {
        harbor_error('Not found.', 404);
    }
    $archived = array_key_exists('archived', $in) ? (!empty($in['archived']) ? 1 : 0) : (int) $row['archived'];
    db()->prepare('UPDATE harbor_shopping_lists SET name = ?, archived = ? WHERE id = ? AND user_id = ?')
        ->execute([trim((string) ($in['name'] ?? $row['name'])), $archived, $r1, (int) $u['id']]);
    harbor_response(map_shopping_list(harbor_find('harbor_shopping_lists', $r1, (int) $u['id'])));
}
if ($r0 === 'shopping-lists' && $r1 !== '' && $r2 === '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_shopping_items WHERE list_id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    db()->prepare('DELETE FROM harbor_shopping_lists WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}
if ($r0 === 'shopping-lists' && $r1 !== '' && $r2 === 'items' && $method === 'GET') {
    $u = harbor_require_user();
    $list = harbor_find('harbor_shopping_lists', $r1, (int) $u['id']);
    if (!$list) {
        harbor_error('Not found.', 404);
    }
    harbor_response(harbor_rows('SELECT * FROM harbor_shopping_items WHERE list_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 500', [$r1, (int) $u['id']], 'map_shopping_item'));
}
if ($r0 === 'shopping-lists' && $r1 !== '' && $r2 === 'items' && $method === 'POST') {
    $u = harbor_require_user();
    $list = harbor_find('harbor_shopping_lists', $r1, (int) $u['id']);
    if (!$list) {
        harbor_error('Not found.', 404);
    }
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_shopping_items (id, user_id, list_id, product_id, name, quantity, unit, checked) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], $r1, harbor_nullable_str($in['productId'] ?? null), trim((string) ($in['name'] ?? '')), harbor_num($in['quantity'] ?? null), harbor_nullable_str($in['unit'] ?? null), !empty($in['checked']) ? 1 : 0]);
    harbor_response(map_shopping_item(harbor_find('harbor_shopping_items', $id, (int) $u['id'])));
}
if ($r0 === 'shopping-items' && $r1 !== '' && $method === 'PUT') {
    $u = harbor_require_user();
    $in = json_input();
    $row = harbor_find('harbor_shopping_items', $r1, (int) $u['id']);
    if (!$row) {
        harbor_error('Not found.', 404);
    }
    $checked = array_key_exists('checked', $in) ? (!empty($in['checked']) ? 1 : 0) : (int) $row['checked'];
    db()->prepare('UPDATE harbor_shopping_items SET name = ?, quantity = ?, unit = ?, checked = ?, product_id = ? WHERE id = ? AND user_id = ?')
        ->execute([trim((string) ($in['name'] ?? $row['name'])), harbor_num($in['quantity'] ?? $row['quantity']), harbor_nullable_str($in['unit'] ?? $row['unit']), $checked, harbor_nullable_str($in['productId'] ?? $row['product_id']), $r1, (int) $u['id']]);
    harbor_response(map_shopping_item(harbor_find('harbor_shopping_items', $r1, (int) $u['id'])));
}
if ($r0 === 'shopping-items' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_shopping_items WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// ----------------------------------------------------------------- budgets
if ($r0 === 'budgets' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_budgets WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [(int) $u['id']], 'map_budget'));
}
if ($r0 === 'budgets' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_budgets (id, user_id, name, amount, period) VALUES (?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], trim((string) ($in['name'] ?? '')), harbor_num($in['amount'] ?? null), (string) ($in['period'] ?? '')]);
    harbor_response(map_budget(harbor_find('harbor_budgets', $id, (int) $u['id'])));
}
if ($r0 === 'budgets' && $r1 !== '' && $method === 'PUT') {
    $u = harbor_require_user();
    $in = json_input();
    $row = harbor_find('harbor_budgets', $r1, (int) $u['id']);
    if (!$row) {
        harbor_error('Not found.', 404);
    }
    db()->prepare('UPDATE harbor_budgets SET name = ?, amount = ?, period = ? WHERE id = ? AND user_id = ?')
        ->execute([trim((string) ($in['name'] ?? $row['name'])), harbor_num($in['amount'] ?? $row['amount']), (string) ($in['period'] ?? $row['period']), $r1, (int) $u['id']]);
    harbor_response(map_budget(harbor_find('harbor_budgets', $r1, (int) $u['id'])));
}
if ($r0 === 'budgets' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_budgets WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// ------------------------------------------------------------------- goals
if ($r0 === 'goals' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_goals WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [(int) $u['id']], 'map_goal'));
}
if ($r0 === 'goals' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_goals (id, user_id, name, target, current, due_date) VALUES (?,?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], trim((string) ($in['name'] ?? '')), harbor_num($in['target'] ?? null), harbor_num($in['current'] ?? null), harbor_nullable_str($in['dueDate'] ?? null)]);
    harbor_response(map_goal(harbor_find('harbor_goals', $id, (int) $u['id'])));
}
if ($r0 === 'goals' && $r1 !== '' && $method === 'PUT') {
    $u = harbor_require_user();
    $in = json_input();
    $row = harbor_find('harbor_goals', $r1, (int) $u['id']);
    if (!$row) {
        harbor_error('Not found.', 404);
    }
    db()->prepare('UPDATE harbor_goals SET name = ?, target = ?, current = ?, due_date = ? WHERE id = ? AND user_id = ?')
        ->execute([trim((string) ($in['name'] ?? $row['name'])), harbor_num($in['target'] ?? $row['target']), harbor_num($in['current'] ?? $row['current']), harbor_nullable_str($in['dueDate'] ?? $row['due_date']), $r1, (int) $u['id']]);
    harbor_response(map_goal(harbor_find('harbor_goals', $r1, (int) $u['id'])));
}
if ($r0 === 'goals' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_goals WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// ------------------------------------------------------------ notifications
// Read-only view over the existing Hamba notifications table.
if ($r0 === 'notifications' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    $stmt = db()->prepare('SELECT id, title, body, is_read FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 200');
    $stmt->execute([(int) $u['id']]);
    $out = [];
    foreach ($stmt->fetchAll() as $n) {
        $out[] = ['id' => (string) $n['id'], 'title' => harbor_str($n['title']), 'body' => harbor_str($n['body']), 'read' => harbor_bool($n['is_read'])];
    }
    harbor_response($out);
}

// ---------------------------------------------------------------- forecast
if ($r0 === 'forecast' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_forecast WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [(int) $u['id']], 'map_forecast'));
}
if ($r0 === 'forecast' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_forecast (id, user_id, period, amount, currency) VALUES (?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], (string) ($in['period'] ?? ''), harbor_num($in['amount'] ?? null), substr((string) ($in['currency'] ?? 'EML'), 0, 3) ?: 'EML']);
    harbor_response(map_forecast(harbor_find('harbor_forecast', $id, (int) $u['id'])));
}
if ($r0 === 'forecast' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_forecast WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// --------------------------------------------------------------- recurring
if ($r0 === 'recurring' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_recurring WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [(int) $u['id']], 'map_recurring'));
}
if ($r0 === 'recurring' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_recurring (id, user_id, title, amount, cadence) VALUES (?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], trim((string) ($in['title'] ?? '')), harbor_num($in['amount'] ?? null), (string) ($in['cadence'] ?? '')]);
    harbor_response(map_recurring(harbor_find('harbor_recurring', $id, (int) $u['id'])));
}
if ($r0 === 'recurring' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_recurring WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// ---------------------------------------------------------------- wishlist
if ($r0 === 'wishlist' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_wishlist WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [(int) $u['id']], 'map_wishlist'));
}
if ($r0 === 'wishlist' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_wishlist (id, user_id, name, product_id) VALUES (?,?,?,?)')
        ->execute([$id, (int) $u['id'], trim((string) ($in['name'] ?? '')), harbor_nullable_str($in['productId'] ?? null)]);
    harbor_response(map_wishlist(harbor_find('harbor_wishlist', $id, (int) $u['id'])));
}
if ($r0 === 'wishlist' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_wishlist WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// ----------------------------------------------------------------- reports
if ($r0 === 'reports' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [(int) $u['id']], 'map_report'));
}
if ($r0 === 'reports' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_reports (id, user_id, title, period, total) VALUES (?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], trim((string) ($in['title'] ?? '')), (string) ($in['period'] ?? ''), harbor_num($in['total'] ?? null)]);
    harbor_response(map_report(harbor_find('harbor_reports', $id, (int) $u['id'])));
}
if ($r0 === 'reports' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_reports WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// ---------------------------------------------------------------- calendar
if ($r0 === 'calendar' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_calendar WHERE user_id = ? ORDER BY date ASC LIMIT 500', [(int) $u['id']], 'map_calendar'));
}
if ($r0 === 'calendar' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_calendar (id, user_id, title, date, notes) VALUES (?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], trim((string) ($in['title'] ?? '')), (string) ($in['date'] ?? ''), harbor_nullable_str($in['notes'] ?? null)]);
    harbor_response(map_calendar(harbor_find('harbor_calendar', $id, (int) $u['id'])));
}
if ($r0 === 'calendar' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_calendar WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// --------------------------------------------------------------- categories
if ($r0 === 'categories' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_categories WHERE user_id = ? ORDER BY name ASC LIMIT 200', [(int) $u['id']], 'map_category'));
}
if ($r0 === 'categories' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        harbor_error('Name is required.', 422);
    }
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_categories (id, user_id, name) VALUES (?,?,?)')->execute([$id, (int) $u['id'], $name]);
    harbor_response(map_category(harbor_find('harbor_categories', $id, (int) $u['id'])));
}
if ($r0 === 'categories' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_categories WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// ------------------------------------------------------------------ stores
if ($r0 === 'stores' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    harbor_response(harbor_rows('SELECT * FROM harbor_stores WHERE user_id = ? ORDER BY name ASC LIMIT 200', [(int) $u['id']], 'map_store'));
}
if ($r0 === 'stores' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        harbor_error('Name is required.', 422);
    }
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_stores (id, user_id, name, address, managed) VALUES (?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], $name, harbor_nullable_str($in['address'] ?? null), !empty($in['managed']) ? 1 : 0]);
    harbor_response(map_store(harbor_find('harbor_stores', $id, (int) $u['id'])));
}
if ($r0 === 'stores' && $r1 !== '' && $method === 'PUT') {
    $u = harbor_require_user();
    $in = json_input();
    $row = harbor_find('harbor_stores', $r1, (int) $u['id']);
    if (!$row) {
        harbor_error('Not found.', 404);
    }
    $managed = array_key_exists('managed', $in) ? (!empty($in['managed']) ? 1 : 0) : (int) $row['managed'];
    db()->prepare('UPDATE harbor_stores SET name = ?, address = ?, managed = ? WHERE id = ? AND user_id = ?')
        ->execute([trim((string) ($in['name'] ?? $row['name'])), harbor_nullable_str($in['address'] ?? $row['address']), $managed, $r1, (int) $u['id']]);
    harbor_response(map_store(harbor_find('harbor_stores', $r1, (int) $u['id'])));
}
if ($r0 === 'stores' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_stores WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// ----------------------------------------------------------------- products
if ($r0 === 'products' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        harbor_response(harbor_rows('SELECT * FROM harbor_products WHERE user_id = ? ORDER BY name ASC LIMIT 200', [(int) $u['id']], 'map_product'));
    }
    harbor_response(harbor_rows('SELECT * FROM harbor_products WHERE user_id = ? AND name LIKE ? ORDER BY name ASC LIMIT 200', [(int) $u['id'], '%' . $q . '%'], 'map_product'));
}
if ($r0 === 'products' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        harbor_error('Name is required.', 422);
    }
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_products (id, user_id, name, price, quantity, unit, image_url) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], $name, harbor_num($in['price'] ?? null), harbor_num($in['quantity'] ?? null), harbor_nullable_str($in['unit'] ?? null), harbor_nullable_str($in['imageUrl'] ?? null)]);
    harbor_response(map_product(harbor_find('harbor_products', $id, (int) $u['id'])));
}
if ($r0 === 'products' && $r1 !== '' && $r2 === '' && $method === 'GET') {
    $u = harbor_require_user();
    $row = harbor_find('harbor_products', $r1, (int) $u['id']);
    if (!$row) {
        harbor_error('Not found.', 404);
    }
    harbor_response(map_product($row));
}
if ($r0 === 'products' && $r1 !== '' && $method === 'PUT') {
    $u = harbor_require_user();
    $in = json_input();
    $row = harbor_find('harbor_products', $r1, (int) $u['id']);
    if (!$row) {
        harbor_error('Not found.', 404);
    }
    db()->prepare('UPDATE harbor_products SET name = ?, price = ?, quantity = ?, unit = ?, image_url = ? WHERE id = ? AND user_id = ?')
        ->execute([trim((string) ($in['name'] ?? $row['name'])), harbor_num($in['price'] ?? $row['price']), harbor_num($in['quantity'] ?? $row['quantity']), harbor_nullable_str($in['unit'] ?? $row['unit']), harbor_nullable_str($in['imageUrl'] ?? $row['image_url']), $r1, (int) $u['id']]);
    harbor_response(map_product(harbor_find('harbor_products', $r1, (int) $u['id'])));
}
if ($r0 === 'products' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_products WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

// ------------------------------------------------------------------- prices
if ($r0 === 'prices' && $r1 === '' && $method === 'GET') {
    $u = harbor_require_user();
    $pid = trim((string) ($_GET['productId'] ?? ''));
    if ($pid === '') {
        harbor_response(harbor_rows('SELECT * FROM harbor_prices WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [(int) $u['id']], 'map_price'));
    }
    harbor_response(harbor_rows('SELECT * FROM harbor_prices WHERE user_id = ? AND product_id = ? ORDER BY created_at DESC LIMIT 200', [(int) $u['id'], $pid], 'map_price'));
}
if ($r0 === 'prices' && $r1 === '' && $method === 'POST') {
    $u = harbor_require_user();
    $in = json_input();
    $id = harbor_uuid();
    db()->prepare('INSERT INTO harbor_prices (id, user_id, product_id, store_id, price, currency, captured_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, (int) $u['id'], (string) ($in['productId'] ?? ''), (string) ($in['storeId'] ?? ''), harbor_num($in['price'] ?? null), substr((string) ($in['currency'] ?? 'EML'), 0, 3) ?: 'EML', (string) ($in['capturedAt'] ?? '')]);
    harbor_response(map_price(harbor_find('harbor_prices', $id, (int) $u['id'])));
}
if ($r0 === 'prices' && $r1 !== '' && $method === 'DELETE') {
    $u = harbor_require_user();
    db()->prepare('DELETE FROM harbor_prices WHERE id = ? AND user_id = ?')->execute([$r1, (int) $u['id']]);
    harbor_response(null, 204);
}

harbor_response(['error' => 'Not found', 'route' => $route], 404);
