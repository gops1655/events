<?php
require __DIR__ . '/includes/init.php';
require_login();

if (query('download') === 'expense_template' && can('expenses.create')) {
    send_expense_import_template(true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'import_expenses' && can('expenses.create')) {
        try {
            $result = import_expenses_from_upload($_FILES['expense_file'] ?? [], null, is_on_flag($_POST['confirm_overspend'] ?? null));
            flash_expense_import($result);
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('expenses.php');
    }
}

$pdo = db();
$q = trim((string) query('q'));
$cat = (int) query('cat');
$book = (string) query('book');
$appr = (string) query('approval');
$from = (string) query('from');
$to = (string) query('to');

$sql = 'SELECT e.*, c.name cat, c.color, ev.title event_title, ev.code, ev.unit_code, u.name recorder
        FROM expenses e
        JOIN expense_categories c ON c.id = e.category_id
        JOIN events ev ON ev.id = e.event_id
        LEFT JOIN users u ON u.id = e.recorded_by
        WHERE e.deleted_at IS NULL';
$params = [];
if ($q !== '') {
    $sql .= ' AND (e.title LIKE ? OR e.vendor LIKE ? OR ev.code LIKE ? OR e.po_no LIKE ? OR e.wo_no LIKE ? OR e.ecm_no LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($cat) {
    $sql .= ' AND e.category_id = ?';
    $params[] = $cat;
}
if ($book === 'purchase' || $book === 'ecm') {
    $sql .= ' AND e.booking_type = ?';
    $params[] = $book;
}
if (in_array($appr, ['pending', 'approved', 'rejected'], true)) {
    $sql .= ' AND e.approval_status = ?';
    $params[] = $appr;
}
if ($from !== '') {
    $sql .= ' AND e.expense_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $sql .= ' AND e.expense_date <= ?';
    $params[] = $to;
}
$sql .= unit_where('ev', $params);
$sql .= ' ORDER BY FIELD(e.approval_status, "pending","rejected","approved"), e.expense_date DESC, e.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$approvedRows = array_filter($rows, fn($r) => ($r['approval_status'] ?? '') === 'approved');
$sum = array_sum(array_map(fn($r) => (float) $r['amount'], $approvedRows));
$sumPo = array_sum(array_map(fn($r) => (($r['booking_type'] ?? '') === 'ecm' ? 0 : (float) $r['amount']), $approvedRows));
$sumEcm = $sum - $sumPo;
$pendingN = count(array_filter($rows, fn($r) => ($r['approval_status'] ?? '') === 'pending'));
$cats = $pdo->query('SELECT id, name FROM expense_categories ORDER BY sort_order')->fetchAll();

$pageTitle = 'Expenses';
$pageCrumb = count($rows) . ' entries · approved ' . money($sum) . ($pendingN ? ' · ' . $pendingN . ' pending' : '') . (active_unit_filter() ? ' · ' . active_unit_filter() : '');
$active = 'expenses';
require __DIR__ . '/includes/header.php';
render_unit_pills('expenses.php');
?>
<form class="filters" method="get">
  <?php if (query('unit')): ?><input type="hidden" name="unit" value="<?= e((string) query('unit')) ?>"><?php endif; ?>
  <div class="field grow"><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="Item, vendor, PO, WO, ECM, event"></div>
  <div class="field"><label>Category</label>
    <select name="cat"><option value="0">All</option>
      <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>Booked as</label>
    <select name="book">
      <option value="">All</option>
      <option value="purchase" <?= $book === 'purchase' ? 'selected' : '' ?>>Purchase (PO / WO)</option>
      <option value="ecm" <?= $book === 'ecm' ? 'selected' : '' ?>>ECM</option>
    </select>
  </div>
  <div class="field"><label>Approval</label>
    <select name="approval">
      <option value="">All live</option>
      <option value="pending" <?= $appr === 'pending' ? 'selected' : '' ?>>Pending</option>
      <option value="approved" <?= $appr === 'approved' ? 'selected' : '' ?>>Approved</option>
      <option value="rejected" <?= $appr === 'rejected' ? 'selected' : '' ?>>Rejected</option>
    </select>
  </div>
  <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-ghost" type="submit">Filter</button>
  <a class="btn btn-brass" href="accounting.php">Purchase vs ECM books</a>
  <?php if (can('expenses.create')): ?>
    <button class="btn btn-teal" type="button" onclick="openModal('expUploadModal')">Upload expenses</button>
  <?php endif; ?>
</form>
<?php render_expense_import_report(); ?>
<div class="card">
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>Event</th><th>Item</th><th>Booked as</th><th>Category</th><th class="num">Amount</th><th>Approval</th><th>Payment</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $ex): ?>
        <tr class="<?= ($ex['approval_status'] ?? '') === 'pending' ? 'row-pending' : '' ?>">
          <td><?= e(dmy($ex['expense_date'])) ?></td>
          <td><a href="event.php?id=<?= (int)$ex['event_id'] ?>"><?= e($ex['code']) ?></a><div class="muted"><?= e($ex['event_title']) ?> · <?= e($ex['unit_code'] ?? '') ?></div></td>
          <td><?= e($ex['title']) ?><div class="muted"><?= e($ex['vendor'] ?: '') ?></div></td>
          <td>
            <span class="badge <?= ($ex['booking_type'] ?? '') === 'ecm' ? 'warn' : 'info' ?>"><?= ($ex['booking_type'] ?? 'purchase') === 'ecm' ? 'ECM' : 'Purchase' ?></span>
            <div class="muted"><?= e(expense_ref($ex)) ?></div>
          </td>
          <td><?= e($ex['cat']) ?></td>
          <td class="num"><?= money_dec($ex['amount']) ?></td>
          <td><span class="badge <?= status_class($ex['approval_status'] ?? 'approved') ?>"><?= e(ucfirst($ex['approval_status'] ?? 'approved')) ?></span></td>
          <td><span class="badge <?= status_class($ex['payment_status']) ?>"><?= e(ucfirst($ex['payment_status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$rows): ?><div class="empty"><p>No expenses match these filters.</p></div><?php endif; ?>
  </div>
</div>
<?php render_expense_import_modal('expenses.php', 'expenses.php?download=expense_template', true, false); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
