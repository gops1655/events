<?php
require __DIR__ . '/includes/init.php';
require_login();
header('Content-Type: application/json');

$userId = uid();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $items = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'body' => $row['body'],
            'type' => $row['type'],
            'url' => notification_link($row),
            'read' => $row['read_at'] !== null,
            'when' => time_ago($row['created_at']),
        ];
    }, recent_notifications($userId, 15));
    echo json_encode(['unread' => unread_notification_count($userId), 'items' => $items]);
    exit;
}

if ($method === 'POST') {
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['error' => 'Invalid security token. Refresh the page and try again.']);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'read') {
        mark_notification_read($userId, (int) ($_POST['id'] ?? 0));
    } elseif ($action === 'read_all') {
        mark_all_notifications_read($userId);
    }
    echo json_encode(['unread' => unread_notification_count($userId)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
