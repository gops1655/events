<?php
require __DIR__ . '/includes/init.php';
require_login();
header('Content-Type: application/json');

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}
$like = '%' . $q . '%';
$pdo = db();
$groups = [];

if (can('events.view')) {
    $params = [$like, $like, $like];
    $sql = 'SELECT id, code, title, venue, city, unit_code FROM events e WHERE (e.title LIKE ? OR e.code LIKE ? OR e.venue LIKE ?)';
    $sql .= unit_where('e', $params);
    $sql .= ' ORDER BY e.start_date DESC LIMIT 6';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    if ($rows) {
        $groups[] = ['label' => 'Events', 'items' => array_map(static function (array $r): array {
            return [
                'title' => $r['code'] . ' · ' . $r['title'],
                'sub' => trim(($r['venue'] ?: '') . ($r['city'] ? ', ' . $r['city'] : '')) ?: ($r['unit_code'] ?? ''),
                'url' => 'event.php?id=' . (int) $r['id'],
            ];
        }, $rows)];
    }
}

if (can('sponsors')) {
    $st = $pdo->prepare('SELECT id, name, type, contact_person, city FROM sponsors WHERE name LIKE ? OR contact_person LIKE ? ORDER BY name LIMIT 6');
    $st->execute([$like, $like]);
    $rows = $st->fetchAll();
    if ($rows) {
        $groups[] = ['label' => 'Sponsors', 'items' => array_map(static function (array $r): array {
            return [
                'title' => $r['name'],
                'sub' => trim(ucfirst($r['type']) . ($r['city'] ? ' · ' . $r['city'] : '')),
                'url' => 'sponsors.php?q=' . urlencode($r['name']),
            ];
        }, $rows)];
    }
}

if (can('expenses.view')) {
    $params = [$like, $like, $like, $like];
    $sql = "SELECT x.id, x.event_id, x.title, x.vendor, x.po_no, x.wo_no, x.ecm_no, ev.code
            FROM expenses x JOIN events ev ON ev.id = x.event_id
            WHERE x.deleted_at IS NULL AND (x.title LIKE ? OR x.vendor LIKE ? OR x.po_no LIKE ? OR x.ecm_no LIKE ?)";
    $sql .= unit_where('ev', $params);
    $sql .= ' ORDER BY x.id DESC LIMIT 6';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    if ($rows) {
        $groups[] = ['label' => 'Expenses', 'items' => array_map(static function (array $r): array {
            return [
                'title' => $r['title'],
                'sub' => trim(($r['vendor'] ?: '') . ' · ' . $r['code']),
                'url' => 'event.php?id=' . (int) $r['event_id'] . '#expenses',
            ];
        }, $rows)];
    }
}

echo json_encode($groups);
