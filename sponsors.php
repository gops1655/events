<?php
require __DIR__ . '/includes/init.php';
require_login();
if (!can('sponsors') && role() !== 'admin' && !can('sponsorships.view')) {
    // doctors can still view the list
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!can('sponsors') && role() !== 'admin') {
        flash('err', 'You cannot change sponsors.');
        redirect('sponsors.php');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? 'pharma';
        if ($name === '' || !isset(sponsor_types()[$type])) {
            flash('err', 'Name and type are required.');
            redirect('sponsors.php');
        }
        $data = [
            $name, $type, trim($_POST['contact_person'] ?? ''), trim($_POST['email'] ?? ''),
            trim($_POST['phone'] ?? ''), trim($_POST['city'] ?? ''), trim($_POST['gstin'] ?? ''),
            trim($_POST['notes'] ?? ''), isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($id) {
            $data[] = $id;
            $pdo->prepare('UPDATE sponsors SET name=?, type=?, contact_person=?, email=?, phone=?, city=?, gstin=?, notes=?, is_active=? WHERE id=?')->execute($data);
            flash('ok', 'Sponsor updated.');
        } else {
            $pdo->prepare('INSERT INTO sponsors (name, type, contact_person, email, phone, city, gstin, notes, is_active) VALUES (?,?,?,?,?,?,?,?,?)')->execute($data);
            log_activity('sponsor.create', 'sponsor', (int)$pdo->lastInsertId(), $name);
            flash('ok', 'Sponsor added.');
        }
        redirect('sponsors.php');
    }
}

$q = trim((string) query('q'));
$sql = 'SELECT s.*,
  (SELECT COALESCE(SUM(promised_amount),0) FROM sponsorships x WHERE x.sponsor_id = s.id AND x.status <> "cancelled") promised,
  (SELECT COALESCE(SUM(r.amount),0) FROM sponsorship_receipts r JOIN sponsorships x ON x.id = r.sponsorship_id WHERE x.sponsor_id = s.id) received
  FROM sponsors s WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (s.name LIKE ? OR s.contact_person LIKE ? OR s.city LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
$sql .= ' ORDER BY s.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$editId = (int) query('edit');
$edit = null;
if ($editId) {
    $st = $pdo->prepare('SELECT * FROM sponsors WHERE id=?');
    $st->execute([$editId]);
    $edit = $st->fetch();
}

$pageTitle = 'Sponsors';
$pageCrumb = 'Pharma, device and other partners';
$active = 'sponsors';
require __DIR__ . '/includes/header.php';
?>
<form class="filters" method="get">
  <div class="field grow"><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="Company or contact"></div>
  <button class="btn btn-ghost" type="submit">Search</button>
  <?php if (can('sponsors') || role() === 'admin'): ?>
    <button class="btn btn-brass" type="button" onclick="openModal('spModal')">New sponsor</button>
  <?php endif; ?>
</form>
<div class="card">
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>Organisation</th><th>Contact</th><th>Type</th><th class="num">Promised</th><th class="num">Received</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong><div class="muted"><?= e($r['city'] ?: '') ?> <?= $r['gstin'] ? '· GSTIN '.e($r['gstin']) : '' ?></div></td>
          <td><?= e($r['contact_person'] ?: '—') ?><div class="muted"><?= e($r['phone'] ?: $r['email'] ?: '') ?></div></td>
          <td><span class="badge info"><?= e(sponsor_types()[$r['type']] ?? $r['type']) ?></span></td>
          <td class="num"><?= money($r['promised']) ?></td>
          <td class="num"><?= money($r['received']) ?></td>
          <td><?php if (can('sponsors') || role() === 'admin'): ?><a class="btn btn-ghost btn-sm" href="sponsors.php?edit=<?= (int)$r['id'] ?>">Edit</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal-bg <?= $edit ? 'open' : '' ?>" id="spModal">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <h3><?= $edit ? 'Edit sponsor' : 'Add sponsor' ?></h3>
    <div class="form-grid">
      <div class="field full"><label>Name</label><input name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
      <div class="field"><label>Type</label>
        <select name="type"><?php foreach (sponsor_types() as $k=>$v): ?>
          <option value="<?= e($k) ?>" <?= ($edit['type'] ?? 'pharma') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?></select>
      </div>
      <div class="field"><label>City</label><input name="city" value="<?= e($edit['city'] ?? '') ?>"></div>
      <div class="field"><label>Contact person</label><input name="contact_person" value="<?= e($edit['contact_person'] ?? '') ?>"></div>
      <div class="field"><label>Phone</label><input name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
      <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
      <div class="field"><label>GSTIN</label><input name="gstin" value="<?= e($edit['gstin'] ?? '') ?>"></div>
      <div class="field full"><label>Notes</label><textarea name="notes"><?= e($edit['notes'] ?? '') ?></textarea></div>
      <div class="field"><label><input type="checkbox" name="is_active" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>> Active</label></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('spModal')">Cancel</button>
      <button class="btn btn-teal" type="submit">Save</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
