<?php
require __DIR__ . '/includes/init.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save' && (can('events.create') || can('events.edit'))) {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['event_type'] ?? 'CME';
        if ($title === '' || !in_array($type, event_types(), true)) {
            flash('err', 'Title and a valid event type are required.');
            redirect('events.php');
        }
        $mode = ($_POST['funding_mode'] ?? 'sponsored') === 'unsponsored' ? 'unsponsored' : 'sponsored';
        $target = $mode === 'unsponsored' ? 0.0 : (float) ($_POST['sponsorship_target'] ?? 0);
        $firstSponsor = (int) ($_POST['first_sponsor_id'] ?? 0);
        if ($mode === 'sponsored' && $target <= 0) {
            flash('err', 'A sponsored event must capture the sponsorship amount.');
            redirect($id ? 'events.php?edit=' . $id : 'events.php');
        }
        $needsSponsor = $mode === 'sponsored' && (!$id || active_sponsor_count($id) === 0);
        if ($needsSponsor && $firstSponsor < 1) {
            flash('err', 'Link a sponsor when the event is sponsored. Amount and company stay on this event.');
            redirect($id ? 'events.php?edit=' . $id : 'events.php');
        }
        if ($mode === 'unsponsored' && $id && active_sponsor_count($id) > 0) {
            flash('err', 'This event still has linked sponsors. Cancel those promises first, or leave it as sponsored.');
            redirect('events.php?edit=' . $id);
        }
        $startDate = $_POST['start_date'] ?: date('Y-m-d');
        $endDate = $_POST['end_date'] ?: $startDate;
        if ($endDate < $startDate) {
            flash('err', 'End date cannot be before the start date.');
            redirect($id ? 'events.php?edit=' . $id : 'events.php');
        }
        try {
            $unitCode = resolve_event_unit($id, (string) ($_POST['unit_code'] ?? ''));
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
            redirect($id ? 'events.php?edit=' . $id : 'events.php');
        }
        $data = [
            $title,
            $type,
            trim($_POST['description'] ?? ''),
            trim($_POST['venue'] ?? ''),
            trim($_POST['city'] ?? ''),
            $startDate,
            $endDate,
            (int) ($_POST['expected_attendees'] ?? 0),
            (float) ($_POST['budget_estimate'] ?? 0),
            in_array($_POST['status'] ?? '', array_keys(event_statuses()), true) ? $_POST['status'] : 'draft',
            $unitCode,
            $mode,
            $target,
            max(0, (float) ($_POST['registration_target'] ?? 0)),
            $_POST['marketing_lead_id'] !== '' ? (int) $_POST['marketing_lead_id'] : null,
            $_POST['doctor_id'] !== '' ? (int) $_POST['doctor_id'] : null,
            $_POST['pharmacy_head_id'] !== '' ? (int) $_POST['pharmacy_head_id'] : null,
            $_POST['coordinator_id'] !== '' ? (int) $_POST['coordinator_id'] : null,
            trim($_POST['notes'] ?? ''),
        ];
        if ($id) {
            if ($mode === 'sponsored' && !$needsSponsor) {
                try {
                    apply_event_sponsorship_amount($id, $target);
                } catch (RuntimeException $e) {
                    flash('err', $e->getMessage());
                    redirect('events.php?edit=' . $id);
                }
            }
            $sql = 'UPDATE events SET title=?, event_type=?, description=?, venue=?, city=?, start_date=?, end_date=?, expected_attendees=?, budget_estimate=?, status=?, unit_code=?, funding_mode=?, sponsorship_target=?, registration_target=?, marketing_lead_id=?, doctor_id=?, pharmacy_head_id=?, coordinator_id=?, notes=? WHERE id=?';
            $data[] = $id;
            $pdo->prepare($sql)->execute($data);
            if ($mode === 'sponsored') {
                sync_sponsorship_target($id);
            }
            if ($mode === 'sponsored' && $needsSponsor) {
                try {
                    link_sponsorship([
                        'event_id' => $id,
                        'sponsor_id' => $firstSponsor,
                        'promised_amount' => $target,
                        'promised_date' => $startDate,
                        'liaison_user_id' => uid(),
                        'notes' => 'Captured with event',
                    ]);
                } catch (RuntimeException $e) {
                    flash('err', $e->getMessage());
                    redirect('events.php?edit=' . $id);
                }
            }
            log_activity('event.update', 'event', $id, $title);
            flash('ok', 'Event updated.');
            redirect('event.php?id=' . $id);
        } else {
            $code = next_event_code($unitCode);
            $sql = 'INSERT INTO events (code, title, event_type, description, venue, city, start_date, end_date, expected_attendees, budget_estimate, status, unit_code, funding_mode, sponsorship_target, registration_target, marketing_lead_id, doctor_id, pharmacy_head_id, coordinator_id, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $pdo->prepare($sql)->execute(array_merge([$code], $data, [uid()]));
            $newId = (int) $pdo->lastInsertId();
            if ($mode === 'sponsored') {
                try {
                    link_sponsorship([
                        'event_id' => $newId,
                        'sponsor_id' => $firstSponsor,
                        'promised_amount' => $target,
                        'promised_date' => $startDate,
                        'liaison_user_id' => uid(),
                        'notes' => 'Captured with event',
                    ]);
                } catch (RuntimeException $e) {
                    flash('err', $e->getMessage());
                    redirect('event.php?id=' . $newId);
                }
            }
            log_activity('event.create', 'event', $newId, $title);
            flash('ok', 'Event created. Sponsorship amount is now tracked against expenses and receipts.');
            redirect('event.php?id=' . $newId);
        }
    }

    if ($action === 'delete' && role() === 'admin') {
        $id = (int) $_POST['id'];
        $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
        log_activity('event.delete', 'event', $id, 'Deleted event');
        flash('ok', 'Event removed.');
        redirect('events.php');
    }
}

$q = trim((string) query('q'));
$status = (string) query('status');
$type = (string) query('type');
$funding = (string) query('funding');
$sql = "SELECT e.*, 
  (SELECT COALESCE(SUM(amount),0) FROM expenses x WHERE x.event_id = e.id AND x.deleted_at IS NULL AND x.approval_status = 'approved') expenses,
  (SELECT COALESCE(SUM(promised_amount),0) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> 'cancelled') promised,
  (SELECT COALESCE(SUM(r.amount),0) FROM sponsorship_receipts r JOIN sponsorships s ON s.id = r.sponsorship_id WHERE s.event_id = e.id) received,
  (SELECT COUNT(*) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> 'cancelled') sponsor_count,
  (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.deleted_at IS NULL) registered
  FROM events e WHERE 1=1";
$params = [];
if ($q !== '') {
    $sql .= ' AND (e.title LIKE ? OR e.code LIKE ? OR e.city LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if ($status !== '' && isset(event_statuses()[$status])) {
    $sql .= ' AND e.status = ?';
    $params[] = $status;
}
if ($type !== '' && in_array($type, event_types(), true)) {
    $sql .= ' AND e.event_type = ?';
    $params[] = $type;
}
if ($funding === 'unsponsored') {
    $sql .= ' AND e.funding_mode = "unsponsored"';
} elseif ($funding === 'sponsored') {
    $sql .= ' AND e.funding_mode = "sponsored" AND (SELECT COUNT(*) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> "cancelled") > 0';
} elseif ($funding === 'seeking') {
    $sql .= ' AND e.funding_mode = "sponsored" AND (SELECT COUNT(*) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> "cancelled") = 0';
}
$sql .= unit_where('e', $params);
$sql .= ' ORDER BY e.start_date DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

$editId = (int) query('edit');
$edit = null;
if ($editId) {
    $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch();
    if ($edit) {
        deny_other_unit($edit);
    }
}

$teamUnit = $edit['unit_code'] ?? user_unit() ?? (string) query('unit');
$marketing = users_by_role('marketing', $teamUnit ?: null);
$doctors = users_by_role('doctor', $teamUnit ?: null);
$pharmacy = users_by_role('pharmacy', $teamUnit ?: null);
$coords = users_by_role('coordinator', $teamUnit ?: null);
if (!$doctors) {
    $doctors = users_by_role('doctor');
}
$sponsorList = $pdo->query('SELECT id, name FROM sponsors WHERE is_active = 1 ORDER BY name')->fetchAll();
$needsFirstSponsor = !$edit || event_is_unsponsored($edit) || active_sponsor_count((int) $edit['id']) === 0;
$editFees = $edit ? event_registration_fees((int) $edit['id']) : ['count' => 0, 'billed' => 0.0, 'collected' => 0.0];
$editRegAmount = $edit ? registration_amount_shown((float) ($edit['registration_target'] ?? 0), $editFees) : 0.0;

$pageTitle = 'Events';
$pageCrumb = count($events) . ' programmes' . (active_unit_filter() ? ' · ' . active_unit_filter() : '');
$active = 'events';
require __DIR__ . '/includes/header.php';
render_unit_pills('events.php');
?>

<form class="filters" method="get">
  <?php if (query('unit')): ?><input type="hidden" name="unit" value="<?= e((string) query('unit')) ?>"><?php endif; ?>
  <div class="field grow"><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="Title, code, city"></div>
  <div class="field"><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (event_statuses() as $k => $v): ?>
        <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>Type</label>
    <select name="type">
      <option value="">All</option>
      <?php foreach (event_types() as $t): ?>
        <option <?= $type === $t ? 'selected' : '' ?>><?= e($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>Funding</label>
    <select name="funding">
      <option value="">All</option>
      <option value="sponsored" <?= $funding === 'sponsored' ? 'selected' : '' ?>>Has sponsors</option>
      <option value="seeking" <?= $funding === 'seeking' ? 'selected' : '' ?>>Seeking sponsors</option>
      <option value="unsponsored" <?= $funding === 'unsponsored' ? 'selected' : '' ?>>Not sponsored</option>
    </select>
  </div>
  <button class="btn btn-ghost" type="submit">Filter</button>
  <?php if (can('events.create')): ?>
    <button class="btn btn-brass" type="button" onclick="openModal('eventModal')">New event</button>
  <?php endif; ?>
</form>

<div class="card">
  <div class="card-b table-wrap">
    <?php if (!$events): ?>
      <div class="empty"><h4>No events yet</h4><p>Plan a CME, camp, or launch and attach a team.</p></div>
    <?php else: ?>
    <table class="data">
      <thead>
        <tr>
          <th>Event</th><th>Unit</th><th>Dates</th><th>Status</th><th>Health</th><th>Funding</th>
          <th class="num">Registered</th><th class="num">Sponsorship</th><th class="num">Spend</th><th class="num">Received</th><th class="num">Gap</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($events as $ev):
        $fund = funding_label($ev, (int) $ev['sponsor_count']);
        $captured = event_is_unsponsored($ev) ? 0 : sponsorship_captured((float) ($ev['sponsorship_target'] ?? 0), (float) $ev['promised']);
        $recv = (float) $ev['received'];
        $gap = $captured - $recv;
        $health = event_health($ev, [
            'expenses' => (float) $ev['expenses'],
            'captured' => $captured,
            'outstanding' => max(0, $gap),
        ]);
        ?>
        <tr>
          <td>
            <a href="event.php?id=<?= (int)$ev['id'] ?>"><strong><?= e($ev['title']) ?></strong></a>
            <div class="muted"><?= e($ev['code']) ?> · <?= e($ev['event_type']) ?> · <?= e($ev['city'] ?: '—') ?></div>
          </td>
          <td><span class="badge unit"><?= e($ev['unit_code'] ?? '—') ?></span></td>
          <td><?= e(dmy($ev['start_date'])) ?><?= $ev['end_date'] !== $ev['start_date'] ? ' – '.e(dmy($ev['end_date'])) : '' ?></td>
          <td><span class="badge <?= status_class($ev['status']) ?>"><?= e(ucfirst($ev['status'])) ?></span></td>
          <td>
            <span class="badge <?= e($health['class']) ?>" title="<?= e($health['reason']) ?>"><?= e($health['label']) ?></span>
            <?php render_event_flags(event_flags($ev, [
                'expenses' => (float) $ev['expenses'],
                'captured' => $captured,
                'outstanding' => max(0, $gap),
                'received' => $recv,
            ])); ?>
          </td>
          <td><span class="badge <?= e($fund['class']) ?>"><?= e($fund['label']) ?></span></td>
          <td class="num"><?= (int)($ev['registered'] ?? 0) ?><?= (int)$ev['expected_attendees'] > 0 ? '<div class="muted">of '.(int)$ev['expected_attendees'].'</div>' : '' ?></td>
          <td class="num"><?= event_is_unsponsored($ev) ? '—' : money($captured) ?></td>
          <td class="num"><?= money($ev['expenses']) ?></td>
          <td class="num"><?= event_is_unsponsored($ev) ? '—' : money($recv) ?></td>
          <td class="num"><?php if (event_is_unsponsored($ev)): ?>—<?php else: ?><span style="color:<?= $gap > 0 ? 'var(--coral)' : 'var(--ok)' ?>"><?= money($gap) ?></span><?php endif; ?></td>
          <td>
            <?php if (can('events.edit')): ?>
              <a class="btn btn-ghost btn-sm" href="events.php?edit=<?= (int)$ev['id'] ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="modal-bg <?= $edit ? 'open' : '' ?>" id="eventModal">
  <form class="modal wide" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <h3><?= $edit ? 'Edit event' : 'Plan an event' ?></h3>
    <div class="form-grid">
      <div class="field full"><label>Title</label><input name="title" required value="<?= e($edit['title'] ?? '') ?>"></div>
      <div class="field"><label>Type</label>
        <select name="event_type"><?php foreach (event_types() as $t): ?>
          <option <?= ($edit['event_type'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?></select>
      </div>
      <div class="field"><label>Unit</label>
        <?php if (can_see_all_units()): ?>
        <select name="unit_code" required>
          <?php foreach (units() as $code => $u): ?>
            <option value="<?= e($code) ?>" <?= ($edit['unit_code'] ?? user_unit() ?? 'HTC') === $code ? 'selected' : '' ?>><?= e($code) ?><?= $u['name'] !== $code ? ' · ' . e($u['name']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input value="<?= e(user_unit() ?: '—') ?>" disabled>
        <input type="hidden" name="unit_code" value="<?= e(user_unit() ?: '') ?>">
        <?php endif; ?>
      </div>
      <div class="field"><label>Status</label>
        <select name="status"><?php foreach (event_statuses() as $k=>$v): ?>
          <option value="<?= e($k) ?>" <?= ($edit['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?></select>
      </div>
      <div class="field"><label>Start</label><input type="date" name="start_date" required value="<?= e($edit['start_date'] ?? '') ?>"></div>
      <div class="field"><label>End</label><input type="date" name="end_date" required value="<?= e($edit['end_date'] ?? '') ?>"></div>
      <div class="field full"><label>Venue</label><input name="venue" value="<?= e($edit['venue'] ?? '') ?>"></div>
      <div class="field"><label>City</label><input name="city" value="<?= e($edit['city'] ?? setting('hospital_city')) ?>"></div>
      <div class="field"><label>Expected attendees</label><input type="number" min="0" name="expected_attendees" value="<?= e((string)($edit['expected_attendees'] ?? 0)) ?>"></div>
      <div class="field"><label>Budget estimate (₹)</label><input type="number" min="0" step="0.01" name="budget_estimate" value="<?= e((string)($edit['budget_estimate'] ?? 0)) ?>"></div>
      <div class="field full reg-amount-block">
        <label>Registration amount (₹)</label>
        <input type="number" min="0" step="0.01" name="registration_target" value="<?= e((string) $editRegAmount) ?>" placeholder="Total delegate / attendee fees for this programme">
        <p class="muted" style="margin:8px 0 0">
          Planned collection from registrations — separate from company sponsorship.
          <?php if (($editFees['count'] ?? 0) > 0): ?>
            <strong><?= (int) $editFees['count'] ?></strong> attendee<?= $editFees['count'] === 1 ? '' : 's' ?> already listed · fees <?= money($editFees['billed']) ?><?= $editFees['collected'] > 0.009 ? ' · collected ' . money($editFees['collected']) : '' ?>.
          <?php else: ?>
            After people are added on Registrations, the event page shows the sum of their fees.
          <?php endif; ?>
        </p>
      </div>
      <div class="field full">
        <label>Is this event sponsored?</label>
        <div class="choice-row">
          <label class="choice">
            <span><input type="radio" name="funding_mode" value="sponsored" <?= ($edit['funding_mode'] ?? 'sponsored') !== 'unsponsored' ? 'checked' : '' ?> onchange="syncFundingFields()"> <strong>Will be sponsored</strong></span>
            <span>Sponsorship amount is compulsory. Expenses and receipts are tracked against it.</span>
          </label>
          <label class="choice">
            <span><input type="radio" name="funding_mode" value="unsponsored" <?= ($edit['funding_mode'] ?? '') === 'unsponsored' ? 'checked' : '' ?> onchange="syncFundingFields()"> <strong>Not sponsored</strong></span>
            <span>Hospital-funded programme. Expenses only — no sponsor ledger.</span>
          </label>
        </div>
      </div>
      <div class="field full" id="sponsoredFields">
        <div class="form-grid">
          <div class="field">
            <label>Sponsorship amount (₹) *</label>
            <input type="number" min="0.01" step="0.01" name="sponsorship_target" id="sponsorship_target" value="<?= e((string)($edit['sponsorship_target'] ?? '')) ?>" placeholder="Promised / committed total">
          </div>
          <?php if ($needsFirstSponsor): ?>
          <div class="field">
            <label>Sponsor *</label>
            <select name="first_sponsor_id" id="first_sponsor_id">
              <option value="">Select company…</option>
              <?php foreach ($sponsorList as $sp): ?>
                <option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>
        <p class="muted" style="margin:8px 0 0"><?= $needsFirstSponsor ? 'This amount is stored on the event and on the linked company promise. If the company revises the grant, change it here or use Edit amount on the event.' : 'This event already has linked companies. The sponsorship total is the sum of their promised amounts — edit or delete those rows on the event hub or Sponsorships.' ?></p>
      </div>
      <div class="field"><label>Marketing lead</label>
        <select name="marketing_lead_id"><option value="">—</option>
        <?php foreach ($marketing as $p): ?><option value="<?= $p['id'] ?>" <?= ($edit['marketing_lead_id'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Doctor</label>
        <select name="doctor_id"><option value="">—</option>
        <?php foreach ($doctors as $p): ?><option value="<?= $p['id'] ?>" <?= ($edit['doctor_id'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Pharmacy head</label>
        <select name="pharmacy_head_id"><option value="">—</option>
        <?php foreach ($pharmacy as $p): ?><option value="<?= $p['id'] ?>" <?= ($edit['pharmacy_head_id'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Coordinator</label>
        <select name="coordinator_id"><option value="">—</option>
        <?php foreach ($coords as $p): ?><option value="<?= $p['id'] ?>" <?= ($edit['coordinator_id'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field full"><label>Description</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
      <div class="field full"><label>Internal notes</label><textarea name="notes"><?= e($edit['notes'] ?? '') ?></textarea></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('eventModal')">Cancel</button>
      <button class="btn btn-teal" type="submit">Save event</button>
    </div>
  </form>
</div>
<script>
function syncFundingFields() {
  const sponsored = document.querySelector('input[name="funding_mode"]:checked')?.value === 'sponsored';
  const box = document.getElementById('sponsoredFields');
  if (box) box.style.display = sponsored ? '' : 'none';
  const amt = document.getElementById('sponsorship_target');
  if (amt) amt.required = !!sponsored;
  const sp = document.getElementById('first_sponsor_id');
  if (sp) sp.required = !!sponsored;
}
syncFundingFields();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
