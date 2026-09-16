<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['ok'])) {
    $u = current_user();
    if ($u) {
        try {
            db()->prepare('DELETE FROM user_api_tokens WHERE user_id=?')->execute([(int)$u['id']]);
        } catch (Throwable $e) {
        }
    }
    logout_user();
    redirect('login.php');
}
?>
<!doctype html>
<html lang="en" data-root="./">
<head><meta charset="utf-8"><title>Log out</title></head>
<body>
<script>
localStorage.removeItem('hamba_token');
location.href = 'logout.php?ok=1';
</script>
</body>
</html>
