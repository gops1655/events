<?php
require __DIR__ . '/includes/init.php';
require_login();

$rows = db()->query(
    'SELECT a.*, u.name
     FROM activity_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC
     LIMIT 200'
)->fetchAll();

$pageTitle = 'Activity';
$pageCrumb = 'Recent changes across the desk';
$active = 'activity';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
          <td><?= e($r['name'] ?: 'System') ?></td>
          <td><?= e($r['action']) ?></td>
          <td><?= e($r['details'] ?: ($r['entity_type'] ? $r['entity_type'].' #'.$r['entity_id'] : '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$rows): ?><div class="empty"><p>No activity yet.</p></div><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
