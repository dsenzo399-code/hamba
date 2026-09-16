<?php
declare(strict_types=1);

function notify(int $userId, string $type, string $title, string $body, array $payload = []): void {
    db()->prepare(
        'INSERT INTO notifications (user_id, title, body, type, payload_json) VALUES (?,?,?,?,?)'
    )->execute([$userId, $title, $body, $type, json_encode($payload)]);
}

function unread_notifications(int $userId, int $limit = 30): array {
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
