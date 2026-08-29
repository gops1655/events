<?php
require __DIR__ . '/includes/init.php';
require_login();
if (role() !== 'admin') {
    http_response_code(403);
    die('Administrators only.');
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'hospital') {
        foreach (['hospital_name', 'hospital_city'] as $k) {
            $val = trim($_POST[$k] ?? '');
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$k, $val]);
        }
        $limit = max(0, (float) ($_POST['expense_approval_limit'] ?? 50000));
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute(['expense_approval_limit', (string) $limit]);
        $grace = max(1, min(365, (int) ($_POST['collection_grace_days'] ?? 30)));
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute(['collection_grace_days', (string) $grace]);
        flash('ok', 'Hospital profile and money rules saved.');
        redirect('settings.php');
    }
    if ($action === 'units') {
        foreach (unit_codes() as $code) {
            $name = trim((string) ($_POST['name'][$code] ?? $code)) ?: $code;
            $notes = trim((string) ($_POST['notes'][$code] ?? ''));
            $pdo->prepare('UPDATE units SET name = ?, notes = ? WHERE code = ?')->execute([$name, $notes, $code]);
        }
        flash('ok', 'Unit names and control notes saved.');
        redirect('settings.php');
    }
    if ($action === 'category') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash('err', 'Category name is required.');
            redirect('settings.php');
        }
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $color = $_POST['color'] ?: '#1b6e64';
        $pdo->prepare('INSERT INTO expense_categories (name, slug, icon, color, sort_order) VALUES (?,?,?,?,?)')
            ->execute([$name, $slug . '-' . bin2hex(random_bytes(2)), 'circle', $color, 99]);
        flash('ok', 'Category added.');
        redirect('settings.php');
    }
}

$cats = $pdo->query('SELECT * FROM expense_categories ORDER BY sort_order, name')->fetchAll();

$pageTitle = 'Settings';
$pageCrumb = 'Hospital profile and expense heads';
$active = 'settings';
require __DIR__ . '/includes/header.php';
?>
<div class="grid-2">
  <div class="card">
    <div class="card-h"><h3>Hospital</h3></div>
    <div class="card-b">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="hospital">
        <div class="field" style="margin-bottom:12px"><label>Name</label><input name="hospital_name" required value="<?= e(setting('hospital_name')) ?>"></div>
        <div class="field" style="margin-bottom:12px"><label>City</label><input name="hospital_city" value="<?= e(setting('hospital_city')) ?>"></div>
        <div class="field" style="margin-bottom:12px"><label>Expense approval limit (₹)</label>
          <input type="number" min="0" step="1" name="expense_approval_limit" value="<?= e(setting('expense_approval_limit', '50000')) ?>">
          <p class="muted" style="margin:6px 0 0">Amounts above this booked by non-finance staff wait for finance approval before they count in the tracker.</p>
        </div>
        <div class="field" style="margin-bottom:12px"><label>Days to collect after the event ends</label>
          <input type="number" min="1" max="365" step="1" name="collection_grace_days" value="<?= e(setting('collection_grace_days', '30')) ?>">
          <p class="muted" style="margin:6px 0 0">Default 30 days. After this, a red flag shows if sponsorship money is still outstanding.</p>
        </div>
        <button class="btn btn-teal" type="submit">Save</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><h3>Expense categories</h3></div>
    <div class="card-b">
      <table class="data">
        <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= e($c['color']) ?>;margin-right:8px"></span><?= e($c['name']) ?></td>
            <td class="muted"><?= e($c['slug']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" class="form-grid" style="margin-top:16px">
        <?= csrf_field() ?><input type="hidden" name="action" value="category">
        <div class="field"><label>New category</label><input name="name" required placeholder="e.g. Security"></div>
        <div class="field"><label>Colour</label><input type="color" name="color" value="#1b6e64"></div>
        <div class="field" style="justify-content:end"><button class="btn btn-ghost" type="submit">Add</button></div>
      </form>
    </div>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-h"><h3>Units</h3><span>HTC, SEC, SMJ, MLK — marketing is local, purchase &amp; finance stay central</span></div>
  <div class="card-b">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="units">
      <table class="data">
        <thead><tr><th>Code</th><th>Display name</th><th>How this unit is controlled</th></tr></thead>
        <tbody>
        <?php foreach (units() as $code => $u): ?>
          <tr>
            <td><span class="badge unit"><?= e($code) ?></span></td>
            <td><input name="name[<?= e($code) ?>]" value="<?= e($u['name']) ?>"></td>
            <td><input name="notes[<?= e($code) ?>]" value="<?= e($u['notes'] ?? '') ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button class="btn btn-teal" type="submit" style="margin-top:12px">Save units</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
