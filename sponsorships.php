<?php
require __DIR__ . '/includes/init.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'link' && can('sponsorships.create')) {
        try {
            $sid = link_sponsorship([
                'event_id' => (int) ($_POST['event_id'] ?? 0),
                'sponsor_id' => (int) ($_POST['sponsor_id'] ?? 0),
                'promised_amount' => $_POST['promised_amount'] ?? 0,
                'promised_date' => $_POST['promised_date'] ?? '',
                'liaison_user_id' => $_POST['liaison_user_id'] ?? '',
                'notes' => $_POST['notes'] ?? '',
            ]);
            $eid = (int) $_POST['event_id'];
            flash('ok', 'Sponsor linked to the event.');
            redirect('event.php?id=' . $eid);
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
            redirect('sponsorships.php');
        }
    }
    if (($_POST['action'] ?? '') === 'edit_amount' && can_edit_sponsorship_amount()) {
        try {
            update_sponsorship_amount(
                (int) ($_POST['event_id'] ?? 0),
                (int) ($_POST['sponsorship_id'] ?? 0),
                (float) ($_POST['promised_amount'] ?? 0)
            );
            flash('ok', 'Promised amount updated.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        $back = 'sponsorships.php';
        if ((int) query('event_id') > 0) {
            $back .= '?event_id=' . (int) query('event_id');
        }
        redirect($back);
    }
    if (($_POST['action'] ?? '') === 'delete' && can_remove_sponsorship()) {
        try {
            $kind = remove_sponsorship(
                (int) ($_POST['event_id'] ?? 0),
                (int) ($_POST['sponsorship_id'] ?? 0)
            );
            flash('ok', $kind === 'deleted' ? 'Sponsorship removed.' : 'Sponsorship cancelled because receipts were already posted.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        $back = 'sponsorships.php';
        $qs = [];
        if ((int) query('event_id') > 0) {
            $qs[] = 'event_id=' . (int) query('event_id');
        }
        if ((string) query('status') !== '') {
            $qs[] = 'status=' . rawurlencode((string) query('status'));
        }
        if ($qs) {
            $back .= '?' . implode('&', $qs);
        }
        redirect($back);
    }
}

$status = (string) query('status');
$eventFilter = (int) query('event_id');
$sql = 'SELECT s.*, sp.name sponsor_name, ev.title event_title, ev.code, ev.funding_mode, ev.unit_code,
          (SELECT COALESCE(SUM(amount),0) FROM sponsorship_receipts r WHERE r.sponsorship_id = s.id) received,
          u.name liaison
        FROM sponsorships s
        JOIN sponsors sp ON sp.id = s.sponsor_id
        JOIN events ev ON ev.id = s.event_id
        LEFT JOIN users u ON u.id = s.liaison_user_id
        WHERE 1=1';
$params = [];
if ($status !== '' && in_array($status, ['promised','partial','received','cancelled'], true)) {
    $sql .= ' AND s.status = ?';
    $params[] = $status;
}
if ($eventFilter) {
    $sql .= ' AND s.event_id = ?';
    $params[] = $eventFilter;
}
$sql .= unit_where('ev', $params);
$sql .= ' ORDER BY s.promised_date DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$sumP = 0; $sumR = 0;
foreach ($rows as $r) {
    if ($r['status'] !== 'cancelled') {
        $sumP += (float) $r['promised_amount'];
        $sumR += (float) $r['received'];
    }
}

$evSql = 'SELECT id, code, title, funding_mode, unit_code,
       (SELECT COUNT(*) FROM sponsorships s WHERE s.event_id = events.id AND s.status <> "cancelled") sponsor_count
     FROM events WHERE status <> "cancelled"';
$evP = [];
$evSql .= unit_where('events', $evP);
$evSql .= ' ORDER BY start_date DESC';
$st = $pdo->prepare($evSql);
$st->execute($evP);
$allEvents = $st->fetchAll();
$linkable = array_filter($allEvents, fn($e) => ($e['funding_mode'] ?? 'sponsored') !== 'unsponsored');
$seeking = array_filter($allEvents, fn($e) => ($e['funding_mode'] ?? 'sponsored') === 'sponsored' && (int) $e['sponsor_count'] === 0);
$hospital = array_filter($allEvents, fn($e) => ($e['funding_mode'] ?? '') === 'unsponsored');
$sponsors = $pdo->query('SELECT id, name FROM sponsors WHERE is_active = 1 ORDER BY name')->fetchAll();
$people = all_active_users();

$pageTitle = 'Sponsorships';
$pageCrumb = 'Every promise is tied to one event' . (active_unit_filter() ? ' · ' . active_unit_filter() : '');
$active = 'sponsorships';
require __DIR__ . '/includes/header.php';
render_unit_pills('sponsorships.php');
?>
<form class="filters" method="get">
  <?php if (query('unit')): ?><input type="hidden" name="unit" value="<?= e((string) query('unit')) ?>"><?php endif; ?>
  <div class="field grow"><label>Event</label>
    <select name="event_id" onchange="this.form.submit()">
      <option value="0">All events</option>
      <?php foreach ($allEvents as $ev): ?>
        <option value="<?= (int)$ev['id'] ?>" <?= $eventFilter === (int)$ev['id'] ? 'selected' : '' ?>>
          <?= e($ev['code']) ?> — <?= e($ev['title']) ?><?= ($ev['funding_mode'] ?? '') === 'unsponsored' ? ' (not sponsored)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>Status</label>
    <select name="status" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach (['promised'=>'Promised','partial'=>'Partial','received'=>'Received','cancelled'=>'Cancelled'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if (can('sponsorships.create')): ?>
    <button class="btn btn-brass" type="button" onclick="openModal('linkModal')">Link sponsor to event</button>
  <?php endif; ?>
</form>
<div class="kpis">
  <div class="kpi brass"><div class="label">Promised</div><div class="value"><?= money($sumP) ?></div></div>
  <div class="kpi ok"><div class="label">Received</div><div class="value"><?= money($sumR) ?></div></div>
  <div class="kpi coral"><div class="label">Outstanding</div><div class="value"><?= money(max(0,$sumP-$sumR)) ?></div></div>
  <div class="kpi"><div class="label">Collection</div><div class="value"><?= $sumP>0 ? round($sumR/$sumP*100) : 0 ?>%</div></div>
</div>

<?php if ($seeking): ?>
<div class="banner-note">
  <h3>Events still seeking a sponsor</h3>
  <p class="muted" style="margin:0 0 10px">Marked for sponsorship, but no company is linked yet.</p>
  <div class="team">
    <?php foreach ($seeking as $ev): ?>
      <a class="chip" href="event.php?id=<?= (int)$ev['id'] ?>"><?= e($ev['code']) ?> · <?= e($ev['title']) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card-h"><h3>Linked promises</h3><span>Sponsor ↔ event</span></div>
  <div class="card-b table-wrap">
    <?php if (!$rows): ?>
      <div class="empty"><h4>No sponsorships in this view</h4><p>Link a company to an event that is marked “Will be sponsored”.</p></div>
    <?php else: ?>
    <table class="data">
      <thead><tr><th>Event</th><th>Sponsor</th><th>Liaison</th><th>Date</th><th class="num">Promised</th><th class="num">Received</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="event.php?id=<?= (int)$r['event_id'] ?>"><?= e($r['code']) ?></a><div class="muted"><?= e($r['event_title']) ?> · <?= e($r['unit_code'] ?? '') ?></div></td>
          <td><?= e($r['sponsor_name']) ?></td>
          <td><?= e($r['liaison'] ?: '—') ?></td>
          <td><?= e(dmy($r['promised_date'])) ?></td>
          <td class="num"><?= money_dec($r['promised_amount']) ?></td>
          <td class="num"><?= money_dec($r['received']) ?></td>
          <td><span class="badge <?= status_class($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          <td>
            <?php if (can_edit_sponsorship_amount() && $r['status'] !== 'cancelled'): ?>
              <button class="btn btn-ghost btn-sm" type="button"
                onclick='prepPromiseEdit(<?= (int)$r['id'] ?>, <?= (int)$r['event_id'] ?>, <?= json_encode($r['sponsor_name'] . ' · ' . $r['code'], JSON_HEX_TAG | JSON_HEX_APOS) ?>, <?= json_encode((float)$r['promised_amount']) ?>, <?= json_encode((float)$r['received']) ?>)'>Edit amount</button>
            <?php endif; ?>
            <?php if (can_remove_sponsorship() && $r['status'] !== 'cancelled'): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove this promise? If no money has been received, it is deleted. If receipts exist, it is cancelled.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="sponsorship_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="event_id" value="<?= (int)$r['event_id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($hospital): ?>
<div class="card">
  <div class="card-h"><h3>Not sponsored</h3><span>Hospital-funded — no sponsor ledger</span></div>
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>Event</th><th>Funding</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($hospital as $ev): ?>
        <tr>
          <td><a href="event.php?id=<?= (int)$ev['id'] ?>"><strong><?= e($ev['code']) ?></strong></a><div class="muted"><?= e($ev['title']) ?></div></td>
          <td><span class="badge muted">Not sponsored</span></td>
          <td><a class="btn btn-ghost btn-sm" href="event.php?id=<?= (int)$ev['id'] ?>">Open event</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="modal-bg" id="linkModal">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="link">
    <h3>Link a sponsor to an event</h3>
    <p class="muted">A sponsorship cannot stand alone. Pick the programme first, then the company.</p>
    <div class="form-grid">
      <div class="field full"><label>Event</label>
        <select name="event_id" required>
          <option value="">Select event…</option>
          <?php foreach ($linkable as $ev): ?>
            <option value="<?= (int)$ev['id'] ?>"><?= e($ev['code']) ?> — <?= e($ev['title']) ?><?= (int)$ev['sponsor_count'] ? ' · already '.(int)$ev['sponsor_count'].' linked' : ' · none linked yet' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field full"><label>Sponsor</label>
        <select name="sponsor_id" required>
          <option value="">Select company…</option>
          <?php foreach ($sponsors as $sp): ?><option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Promised amount (₹) *</label><input type="number" step="0.01" min="0.01" name="promised_amount" required></div>
      <div class="field"><label>Promise date</label><input type="date" name="promised_date" required value="<?= date('Y-m-d') ?>"></div>
      <div class="field full"><label>Closed by</label>
        <select name="liaison_user_id">
          <option value="">Me</option>
          <?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['role']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="field full"><label>Notes</label><textarea name="notes"></textarea></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('linkModal')">Cancel</button>
      <button class="btn btn-brass" type="submit">Save link</button>
    </div>
  </form>
</div>

<?php if (can_edit_sponsorship_amount()): ?>
<div class="modal-bg" id="promiseEditModal">
  <form class="modal" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="edit_amount">
    <input type="hidden" name="sponsorship_id" id="peId">
    <input type="hidden" name="event_id" id="peEventId">
    <h3>Change promised amount</h3>
    <p class="muted" id="peLabel">Update the company’s promise on this event.</p>
    <div class="form-grid">
      <div class="field"><label>New promised amount (₹) *</label><input type="number" step="0.01" min="0.01" name="promised_amount" id="peAmt" required></div>
    </div>
    <p class="muted" id="peFloor">Cannot go below money already received against this promise.</p>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('promiseEditModal')">Cancel</button>
      <button class="btn btn-teal" type="submit">Update amount</button>
    </div>
  </form>
</div>
<script>
function prepPromiseEdit(id, eventId, label, amount, received) {
  document.getElementById('peId').value = id;
  document.getElementById('peEventId').value = eventId;
  document.getElementById('peLabel').textContent = 'Update the promise for ' + label + '.';
  document.getElementById('peAmt').value = amount;
  const rec = Number(received || 0);
  document.getElementById('peFloor').textContent = rec > 0
    ? 'Already received ' + rec.toLocaleString('en-IN', {style:'currency', currency:'INR', maximumFractionDigits:0}) + '. New amount cannot be below that.'
    : 'Cannot go below money already received against this promise.';
  openModal('promiseEditModal');
}
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
