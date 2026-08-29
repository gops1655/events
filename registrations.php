<?php
require __DIR__ . '/includes/init.php';
require_login();

$pdo = db();

if (query('download') === 'registration_template' && can('registrations.create')) {
    send_registration_import_template(true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save' && (can('registrations.create') || can('registrations.edit'))) {
        $id = (int) ($_POST['id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);
        try {
            if ($eventId < 1) {
                throw new RuntimeException('Choose the event this attendee belongs to.');
            }
            record_registration($eventId, $_POST, $id > 0 ? $id : null);
            flash('ok', $id ? 'Registration updated.' : 'Registration added.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect($id ? 'registrations.php?edit=' . $id : 'registrations.php');
    }
    if ($action === 'cancel' && (can('registrations.edit') || role() === 'admin')) {
        try {
            cancel_registration((int) ($_POST['event_id'] ?? 0), (int) ($_POST['id'] ?? 0));
            flash('ok', 'Registration removed. It is kept out of the live list.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('registrations.php');
    }
    if ($action === 'import_registrations' && can('registrations.create')) {
        try {
            $result = import_registrations_from_upload($_FILES['registration_file'] ?? [], null);
            flash_registration_import($result);
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('registrations.php');
    }
}

$q = trim((string) query('q'));
$cat = (string) query('cat');
$pay = (string) query('pay');
$from = (string) query('from');
$to = (string) query('to');
$eventFilter = (int) query('event_id');

$sql = 'SELECT r.*, ev.title event_title, ev.code, ev.unit_code, ev.expected_attendees, u.name recorder
        FROM registrations r
        JOIN events ev ON ev.id = r.event_id
        LEFT JOIN users u ON u.id = r.recorded_by
        WHERE r.deleted_at IS NULL';
$params = [];
if ($q !== '') {
    $sql .= ' AND (r.name LIKE ? OR r.phone LIKE ? OR r.email LIKE ? OR r.organization LIKE ? OR r.registration_no LIKE ? OR ev.code LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($cat !== '' && isset(attendee_categories()[$cat])) {
    $sql .= ' AND r.category = ?';
    $params[] = $cat;
}
if ($pay !== '' && isset(registration_payment_statuses()[$pay])) {
    $sql .= ' AND r.payment_status = ?';
    $params[] = $pay;
}
if ($from !== '') {
    $sql .= ' AND r.registration_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $sql .= ' AND r.registration_date <= ?';
    $params[] = $to;
}
if ($eventFilter > 0) {
    $sql .= ' AND r.event_id = ?';
    $params[] = $eventFilter;
}
$sql .= unit_where('ev', $params);
$sql .= ' ORDER BY r.registration_date DESC, r.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$evParams = [];
$evSql = "SELECT id, code, title FROM events e WHERE e.status <> 'cancelled'" . unit_where('e', $evParams) . ' ORDER BY e.start_date DESC, e.id DESC';
$evSt = $pdo->prepare($evSql);
$evSt->execute($evParams);
$eventOptions = $evSt->fetchAll();

$editId = (int) query('edit');
$edit = null;
if ($editId) {
    $st = $pdo->prepare('SELECT * FROM registrations WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$editId]);
    $edit = $st->fetch();
    if ($edit) {
        try {
            assert_unit_access(event_row((int) $edit['event_id']));
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
            redirect('registrations.php');
        }
    }
}

$pageTitle = 'Registrations';
$pageCrumb = count($rows) . ' attendees' . (active_unit_filter() ? ' · ' . active_unit_filter() : '');
$active = 'registrations';
require __DIR__ . '/includes/header.php';
render_unit_pills('registrations.php');
?>
<form class="filters" method="get">
  <?php if (query('unit')): ?><input type="hidden" name="unit" value="<?= e((string) query('unit')) ?>"><?php endif; ?>
  <div class="field grow"><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="Name, phone, email, org, event code"></div>
  <div class="field"><label>Event</label>
    <select name="event_id" onchange="this.form.submit()">
      <option value="0">All events</option>
      <?php foreach ($eventOptions as $ev): ?>
        <option value="<?= (int)$ev['id'] ?>" <?= $eventFilter === (int)$ev['id'] ? 'selected' : '' ?>><?= e($ev['code']) ?> · <?= e($ev['title']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>Category</label>
    <select name="cat">
      <option value="">All</option>
      <?php foreach (attendee_categories() as $k => $v): ?>
        <option value="<?= e($k) ?>" <?= $cat === $k ? 'selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>Fee</label>
    <select name="pay">
      <option value="">All</option>
      <?php foreach (registration_payment_statuses() as $k => $v): ?>
        <option value="<?= e($k) ?>" <?= $pay === $k ? 'selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-ghost" type="submit">Filter</button>
  <?php if (can('registrations.create')): ?>
    <button class="btn btn-brass" type="button" onclick="openModal('regModal')">Add registration</button>
    <button class="btn btn-teal" type="button" onclick="openModal('regUploadModal')">Upload list</button>
  <?php endif; ?>
</form>
<?php render_registration_import_report(); ?>
<div class="card">
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>Attendee</th><th>Event</th><th>Category</th><th>Contact</th><th>Registered</th><th class="num">Fee</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <strong><?= e($r['name']) ?></strong>
            <div class="muted"><?= e($r['registration_no'] ?: '') ?><?= $r['organization'] ? ($r['registration_no'] ? ' · ' : '') . e($r['organization']) : '' ?></div>
          </td>
          <td><a href="event.php?id=<?= (int)$r['event_id'] ?>"><?= e($r['code']) ?></a><div class="muted"><?= e($r['event_title']) ?> · <?= e($r['unit_code'] ?? '') ?></div></td>
          <td><span class="badge info"><?= e(attendee_categories()[$r['category']] ?? $r['category']) ?></span></td>
          <td><?= e($r['phone'] ?: '—') ?><div class="muted"><?= e($r['email'] ?: $r['city'] ?: '') ?></div></td>
          <td><?= e(dmy($r['registration_date'])) ?></td>
          <td class="num">
            <?= (float) $r['fee_amount'] > 0 ? money_dec($r['fee_amount']) : '—' ?>
            <div class="muted"><?= e(registration_payment_statuses()[$r['payment_status']] ?? $r['payment_status']) ?></div>
          </td>
          <td>
            <?php if (can('registrations.edit') || role() === 'admin'): ?>
              <a class="btn btn-ghost btn-sm" href="registrations.php?edit=<?= (int)$r['id'] ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$rows): ?><div class="empty"><h4>No registrations yet</h4><p>Add attendees one by one, or upload the Excel / CSV list against an event.</p></div><?php endif; ?>
  </div>
</div>

<?php if (can('registrations.create') || can('registrations.edit')): ?>
<div class="modal-bg <?= $edit ? 'open' : '' ?>" id="regModal">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <h3><?= $edit ? 'Edit registration' : 'Add registration' ?></h3>
    <p class="muted">Every attendee belongs to one event, the same way a sponsor promise does.</p>
    <?php render_registration_form_fields($edit ?: [], true, $eventOptions); ?>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('regModal')">Cancel</button>
      <button class="btn btn-teal" type="submit"><?= $edit ? 'Update' : 'Save' ?></button>
    </div>
  </form>
</div>
<?php render_registration_upload_modal('registrations.php', 'registrations.php?download=registration_template', true); ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
