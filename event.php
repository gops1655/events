<?php
require __DIR__ . '/includes/init.php';
require_login();

$pdo = db();
$id = (int) query('id');
$st = $pdo->prepare(
    'SELECT e.*,
      um.name marketing_name, ud.name doctor_name, up.name pharmacy_name, uc.name coordinator_name
     FROM events e
     LEFT JOIN users um ON um.id = e.marketing_lead_id
     LEFT JOIN users ud ON ud.id = e.doctor_id
     LEFT JOIN users up ON up.id = e.pharmacy_head_id
     LEFT JOIN users uc ON uc.id = e.coordinator_id
     WHERE e.id = ?'
);
$st->execute([$id]);
$event = $st->fetch();
if (!$event) {
    flash('err', 'Event not found.');
    redirect('events.php');
}
deny_other_unit($event);

if (query('download') === 'expense_template' && can('expenses.create')) {
    send_expense_import_template(false);
}
if (query('download') === 'registration_template' && can('registrations.create')) {
    send_registration_import_template(false);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'expense' && (can('expenses.create') || can('expenses.edit'))) {
        try {
            $bill = !empty($_FILES['bill']['name']) ? upload_bill($_FILES['bill']) : null;
            $editId = (int) ($_POST['expense_id'] ?? 0);
            if ($editId > 0 && !can('expenses.edit') && role() !== 'admin') {
                throw new RuntimeException('You cannot edit this expense.');
            }
            $newId = record_event_expense($id, $_POST, $bill, $editId > 0 ? $editId : null);
            $st = $pdo->prepare('SELECT approval_status FROM expenses WHERE id = ?');
            $st->execute([$newId]);
            $appr = (string) $st->fetchColumn();
            if ($appr === 'pending') {
                flash('ok', 'Expense saved. Finance must approve amounts over ' . money(approval_limit()) . ' before it counts in the tracker.');
            } else {
                flash('ok', $editId ? 'Expense updated.' : 'Expense recorded. Approved spend is included in the tracker.');
            }
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'import_expenses' && can('expenses.create')) {
        try {
            $result = import_expenses_from_upload($_FILES['expense_file'] ?? [], $id, is_on_flag($_POST['confirm_overspend'] ?? null));
            flash_expense_import($result);
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'registration' && (can('registrations.create') || can('registrations.edit'))) {
        try {
            $editId = (int) ($_POST['registration_id'] ?? 0);
            record_registration($id, $_POST, $editId > 0 ? $editId : null);
            flash('ok', $editId ? 'Registration updated.' : 'Registration added to this event.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'import_registrations' && can('registrations.create')) {
        try {
            $result = import_registrations_from_upload($_FILES['registration_file'] ?? [], $id);
            flash_registration_import($result);
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'cancel_registration' && (can('registrations.edit') || role() === 'admin')) {
        try {
            cancel_registration($id, (int) ($_POST['registration_id'] ?? 0));
            flash('ok', 'Registration removed from the live list.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'approve_expense' && can('expenses.approve')) {
        try {
            approve_expense($id, (int) $_POST['expense_id']);
            flash('ok', 'Expense approved and added to the tracker.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'reject_expense' && can('expenses.approve')) {
        try {
            reject_expense($id, (int) $_POST['expense_id']);
            flash('ok', 'Expense rejected. It will not count in the tracker.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'cancel_expense' && (can('expenses.edit') || role() === 'admin')) {
        try {
            cancel_expense($id, (int) $_POST['expense_id']);
            flash('ok', 'Expense cancelled. It is kept in history and no longer counts.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'enable_sponsored' && can('events.edit')) {
        $amount = (float) ($_POST['sponsorship_target'] ?? 0);
        $sponsorId = (int) ($_POST['first_sponsor_id'] ?? 0);
        if ($amount <= 0 || $sponsorId < 1) {
            flash('err', 'To switch to sponsored, capture the sponsorship amount and select the company.');
            redirect('event.php?id=' . $id);
        }
        $pdo->prepare('UPDATE events SET funding_mode = "sponsored", sponsorship_target = ? WHERE id = ?')->execute([$amount, $id]);
        try {
            link_sponsorship([
                'event_id' => $id,
                'sponsor_id' => $sponsorId,
                'promised_amount' => $amount,
                'promised_date' => date('Y-m-d'),
                'liaison_user_id' => uid(),
                'notes' => 'Switched from hospital-funded',
            ]);
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
            redirect('event.php?id=' . $id);
        }
        log_activity('event.funding', 'event', $id, 'Switched to sponsored');
        flash('ok', 'Event is sponsored. Amount, expenses and receipts are now tracked together.');
        redirect('event.php?id=' . $id);
    }

    if ($action === 'sponsorship' && can('sponsorships.create')) {
        try {
            link_sponsorship([
                'event_id' => $id,
                'sponsor_id' => (int) ($_POST['sponsor_id'] ?? 0),
                'promised_amount' => $_POST['promised_amount'] ?? 0,
                'promised_date' => $_POST['promised_date'] ?? '',
                'liaison_user_id' => $_POST['liaison_user_id'] ?? '',
                'notes' => $_POST['notes'] ?? '',
            ]);
            flash('ok', 'Sponsor linked to this event.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'receipt' && can('receipts')) {
        try {
            record_receipt($id, $_POST);
            flash('ok', 'Amount received and ledger updated.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'cancel_sp' && can_remove_sponsorship()) {
        try {
            $kind = remove_sponsorship($id, (int) ($_POST['sponsorship_id'] ?? 0));
            flash('ok', $kind === 'deleted' ? 'Sponsorship removed.' : 'Sponsorship cancelled because receipts were already posted.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'edit_sponsorship' && can_edit_sponsorship_amount()) {
        try {
            update_sponsorship_amount($id, (int) ($_POST['sponsorship_id'] ?? 0), (float) ($_POST['promised_amount'] ?? 0));
            flash('ok', 'Sponsorship amount updated.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('event.php?id=' . $id);
    }

    if ($action === 'save_registration_amount' && can('events.edit')) {
        $amt = max(0, (float) ($_POST['registration_target'] ?? 0));
        db()->prepare('UPDATE events SET registration_target = ? WHERE id = ?')->execute([$amt, $id]);
        log_activity('event.update', 'event', $id, 'Registration amount set to ' . $amt);
        flash('ok', 'Registration amount saved.');
        redirect('event.php?id=' . $id);
    }
}

if (!event_is_unsponsored($event)) {
    $event['sponsorship_target'] = sync_sponsorship_target($id);
}

$totals = event_totals($id);
$health = event_health($event, $totals);
$clock = collection_clock($event, $totals);
$flags = event_flags($event, $totals);
$budgetPct = $event['budget_estimate'] > 0 ? min(100, round($totals['expenses'] / (float) $event['budget_estimate'] * 100)) : 0;

$expenses = $pdo->prepare(
    'SELECT e.*, c.name cat, c.color, u.name recorder, ua.name approver
     FROM expenses e
     JOIN expense_categories c ON c.id = e.category_id
     LEFT JOIN users u ON u.id = e.recorded_by
     LEFT JOIN users ua ON ua.id = e.approved_by
     WHERE e.event_id = ? AND e.deleted_at IS NULL
     ORDER BY FIELD(e.approval_status, "pending","rejected","approved"), e.expense_date DESC, e.id DESC'
);
$expenses->execute([$id]);
$expenses = $expenses->fetchAll();

$registrations = [];
try {
    $regStmt = $pdo->prepare(
        'SELECT r.*, u.name recorder
         FROM registrations r
         LEFT JOIN users u ON u.id = r.recorded_by
         WHERE r.event_id = ? AND r.deleted_at IS NULL
         ORDER BY r.registration_date DESC, r.id DESC'
    );
    $regStmt->execute([$id]);
    $registrations = $regStmt->fetchAll();
} catch (Throwable $e) {
    try {
        $regStmt = $pdo->prepare(
            'SELECT r.*, u.name recorder
             FROM registrations r
             LEFT JOIN users u ON u.id = r.recorded_by
             WHERE r.event_id = ?
             ORDER BY r.id DESC'
        );
        $regStmt->execute([$id]);
        $registrations = $regStmt->fetchAll();
    } catch (Throwable $e2) {
        $registrations = [];
    }
}
$regFees = merge_registration_fees(event_registration_fees($id), registration_fees_from_rows($registrations));
$regCount = (int) $regFees['count'];
$regTarget = (float) ($event['registration_target'] ?? 0);
$regAmount = registration_amount_shown($regTarget, $regFees);
if ($regFees['billed'] > $regTarget + 0.009) {
    $regTarget = sync_registration_target($id);
    $event['registration_target'] = $regTarget;
    $regAmount = $regFees['billed'];
}

$history = $pdo->prepare(
    'SELECT h.*, u.name user_name
     FROM expense_history h
     LEFT JOIN users u ON u.id = h.user_id
     WHERE h.event_id = ?
     ORDER BY h.id DESC
     LIMIT 25'
);
$history->execute([$id]);
$history = $history->fetchAll();

$sps = $pdo->prepare(
    'SELECT s.*, sp.name sponsor_name, sp.type sponsor_type, u.name liaison,
      (SELECT COALESCE(SUM(amount),0) FROM sponsorship_receipts r WHERE r.sponsorship_id = s.id) received
     FROM sponsorships s
     JOIN sponsors sp ON sp.id = s.sponsor_id
     LEFT JOIN users u ON u.id = s.liaison_user_id
     WHERE s.event_id = ? ORDER BY s.id DESC'
);
$sps->execute([$id]);
$sps = $sps->fetchAll();

$cats = expense_category_list();
$sponsors = $pdo->query('SELECT id, name FROM sponsors WHERE is_active = 1 ORDER BY name')->fetchAll();
$people = all_active_users();
$unsponsored = event_is_unsponsored($event);
$fund = funding_label($event, count(array_filter($sps, fn($s) => $s['status'] !== 'cancelled')));

$pageTitle = $event['code'];
$pageCrumb = $event['event_type'] . ' · ' . dmy($event['start_date']);
$active = 'events';
require __DIR__ . '/includes/header.php';
?>

<div class="hero-event">
  <div>
    <div class="code"><?= e($event['code']) ?> · <?= e($event['unit_code'] ?? '') ?></div>
    <h1><?= e($event['title']) ?></h1>
    <div class="meta-row">
      <span><?= e($event['venue'] ?: 'Venue TBC') ?><?= $event['city'] ? ', ' . e($event['city']) : '' ?></span>
      <span><?= e(dmy($event['start_date'])) ?><?= $event['end_date'] !== $event['start_date'] ? ' – ' . e(dmy($event['end_date'])) : '' ?></span>
      <span><?= (int)$event['expected_attendees'] ?> expected</span>
      <span class="badge unit"><?= e($event['unit_code'] ?? '') ?></span>
      <span class="badge <?= status_class($event['status']) ?>"><?= e(ucfirst($event['status'])) ?></span>
      <span class="badge <?= e($fund['class']) ?>"><?= e($fund['label']) ?></span>
      <span class="badge <?= e($health['class']) ?>" title="<?= e($health['reason']) ?>"><?= e($health['label']) ?></span>
    </div>
    <?php render_event_flags($flags); ?>
    <?php if ($event['description']): ?><p style="color:#c9d4cc;max-width:560px"><?= e($event['description']) ?></p><?php endif; ?>
    <div class="team" style="margin-top:14px">
      <?php foreach ([['Marketing', $event['marketing_name']], ['Doctor', $event['doctor_name']], ['Pharmacy', $event['pharmacy_name']], ['Coordinator', $event['coordinator_name']]] as $roleChip):
        if (!$roleChip[1]) continue; ?>
        <span class="chip" style="background:rgba(255,255,255,.08);color:#f4efe6"><?= e($roleChip[0]) ?> · <?= e($roleChip[1]) ?></span>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
      <?php if (can('events.edit')): ?><a class="btn btn-brass btn-sm" href="events.php?edit=<?= $id ?>">Edit details</a><?php endif; ?>
      <?php if (can('expenses.create')): ?>
        <button class="btn btn-sm" type="button" onclick="resetExpenseForm(); openModal('expModal')">Add expense</button>
        <button class="btn btn-sm btn-ghost" type="button" style="color:#fff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.2)" onclick="openModal('expUploadModal')">Upload expenses</button>
      <?php endif; ?>
      <?php if (can('registrations.create')): ?>
        <button class="btn btn-sm" type="button" onclick="resetRegForm(); openModal('regModal')">Add registration</button>
        <button class="btn btn-sm btn-ghost" type="button" style="color:#fff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.2)" onclick="openModal('regUploadModal')">Upload list</button>
      <?php endif; ?>
      <?php if (!$unsponsored && can('sponsorships.create')): ?><button class="btn btn-sm btn-ghost" type="button" style="color:#fff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.2)" onclick="openModal('spModal')">Link a sponsor</button><?php endif; ?>
    </div>
  </div>
  <div class="stat-pills">
    <div class="stat-pill"><span>Budget</span><strong><?= money($event['budget_estimate']) ?></strong></div>
    <div class="stat-pill"><span>Registered</span><strong><?= (int) $regCount ?><?= (int)$event['expected_attendees'] > 0 ? ' / ' . (int)$event['expected_attendees'] : '' ?></strong></div>
    <div class="stat-pill"><span>Registration amount</span><strong><?= money($regAmount) ?></strong></div>
    <?php if ($unsponsored): ?>
    <div class="stat-pill"><span>Spent</span><strong><?= money($totals['expenses']) ?></strong></div>
    <div class="stat-pill"><span>Funding</span><strong>Hospital</strong></div>
    <div class="stat-pill"><span>Hospital cost</span><strong><?= money($totals['expenses']) ?></strong></div>
    <?php else: ?>
    <div class="stat-pill"><span>Sponsorship</span><strong><?= money($totals['captured']) ?></strong></div>
    <div class="stat-pill"><span>Expenses</span><strong><?= money($totals['expenses']) ?></strong></div>
    <div class="stat-pill"><span>Received</span><strong><?= money($totals['received']) ?></strong></div>
    <div class="stat-pill"><span>Still to collect</span><strong><?= money($totals['outstanding']) ?></strong></div>
    <div class="stat-pill"><span>Net</span><strong><?= money($totals['net']) ?></strong></div>
    <?php endif; ?>
  </div>
</div>

<div class="health-banner health-<?= e($health['key']) ?>">
  <strong><?= e($health['label']) ?></strong>
  <?= e($health['reason']) ?>
  <?php if (($totals['pending_count'] ?? 0) > 0): ?>
    · <?= (int) $totals['pending_count'] ?> expense<?= $totals['pending_count'] === 1 ? '' : 's' ?> awaiting finance (<?= money($totals['pending_amount']) ?>) — not in the tracker yet
  <?php endif; ?>
</div>
<?php if ($clock['state'] !== 'na' && $clock['state'] !== 'cancelled'): ?>
<div class="clock-card clock-<?= e($clock['state']) ?>">
  <div class="clock-head">
    <div>
      <h3>Collection clock</h3>
      <p class="muted" style="margin:4px 0 0">
        <?php if ($clock['state'] === 'overdue'): ?>
          Red flag — sponsorship not received within <?= (int) $clock['grace'] ?> days of the event ending.
        <?php elseif ($clock['state'] === 'window'): ?>
          <?= (int) $clock['days_left'] ?> day<?= $clock['days_left'] === 1 ? '' : 's' ?> left of the <?= (int) $clock['grace'] ?>-day collection window.
        <?php elseif ($clock['state'] === 'collected'): ?>
          Sponsorship for this event has been received.
        <?php else: ?>
          Companies have <?= (int) $clock['grace'] ?> days after the event ends to pay. Due <?= e(dmy($clock['due_date'])) ?>.
        <?php endif; ?>
      </p>
    </div>
    <?php if ($clock['state'] === 'overdue'): ?>
      <span class="flag flag-coral"><span class="flag-mark">⚑</span> <?= (int) $clock['days_late'] ?> day<?= $clock['days_late'] === 1 ? '' : 's' ?> late</span>
    <?php elseif ($clock['state'] === 'window'): ?>
      <span class="flag flag-warn"><?= (int) $clock['days_left'] ?> days left</span>
    <?php elseif ($clock['state'] === 'collected'): ?>
      <span class="badge ok">Collected</span>
    <?php endif; ?>
  </div>
  <div class="clock-bar"><i style="width:<?= (int) $clock['window_pct'] ?>%"></i></div>
  <div class="clock-meta">
    <div><span>Event ended</span><strong><?= e(dmy($clock['end_date'])) ?></strong></div>
    <div><span>Collect by</span><strong><?= e(dmy($clock['due_date'])) ?></strong></div>
    <div><span>Received</span><strong><?= money($clock['received']) ?></strong></div>
    <div><span>Still due</span><strong><?= money($clock['outstanding']) ?></strong></div>
  </div>
</div>
<?php endif; ?>
<?php
$overAmt = event_overspend_amount($event, $totals);
if ($overAmt > 0.009):
?>
<div class="clock-card clock-overdue" style="margin-top:12px">
  <div class="clock-head">
    <div>
      <h3>Overspend flag</h3>
      <p class="muted" style="margin:4px 0 0">Approved spend is <?= money($overAmt) ?> above the <?= $unsponsored ? 'hospital budget' : 'sponsorship amount' ?>.</p>
    </div>
    <span class="flag flag-coral"><span class="flag-mark">⚑</span> Overspent</span>
  </div>
</div>
<?php endif; ?>

<?php if ($unsponsored): ?>
<div class="grid-2" style="margin-bottom:16px">
  <div class="card">
    <div class="card-h"><h3>Budget utilisation</h3><span><?= $budgetPct ?>%</span></div>
    <div class="card-b"><div class="progress"><i style="width:<?= $budgetPct ?>%"></i></div>
      <p class="muted" style="margin:10px 0 0">Spend against the planned budget for this programme.</p></div>
  </div>
  <div class="card">
    <div class="card-h"><h3>Hospital funded</h3><span>No sponsor ledger</span></div>
    <div class="card-b">
      <p class="muted" style="margin:0 0 12px">This programme is not linked to any sponsor. If a company comes on board, capture the sponsorship amount and the company together.</p>
      <?php if (can('events.edit')): ?>
        <button class="btn btn-brass btn-sm" type="button" onclick="openModal('enableModal')">A sponsor came in</button>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="ledger">
  <div class="ledger-item promised">
    <div class="lbl">Sponsorship amount</div>
    <div class="amt"><?= money($totals['captured']) ?></div>
    <div class="ledger-foot">Sum of linked company promises</div>
  </div>
  <div class="ledger-item spend">
    <div class="lbl">Approved spend</div>
    <div class="amt"><?= money($totals['expenses']) ?></div>
    <div class="ledger-foot"><?= $totals['cover_pct'] ?>% covered by money actually received
      <?php if ($totals['expenses'] > 0): ?>
        · <?= money($totals['purchase_expenses']) ?> PO/WO · <?= money($totals['ecm_expenses']) ?> ECM
      <?php endif; ?>
    </div>
  </div>
  <div class="ledger-item got">
    <div class="lbl">Actually received</div>
    <div class="amt"><?= money($totals['received']) ?></div>
    <div class="ledger-foot"><?= money($totals['outstanding']) ?> still to collect · Net <?= money($totals['net']) ?></div>
  </div>
</div>
<div class="grid-2" style="margin-bottom:16px">
  <div class="card">
    <div class="card-h"><h3>Collection vs sponsorship</h3><span><?= $totals['collect_pct'] ?>%</span></div>
    <div class="card-b"><div class="progress"><i style="width:<?= $totals['collect_pct'] ?>%"></i></div>
      <p class="muted" style="margin:10px 0 0">Received against the promised amounts from linked sponsors.</p></div>
  </div>
  <div class="card">
    <div class="card-h"><h3>Expenses covered</h3><span><?= $totals['cover_pct'] ?>%</span></div>
    <div class="card-b"><div class="progress"><i style="width:<?= $totals['cover_pct'] ?>%"></i></div>
      <p class="muted" style="margin:10px 0 0"><?= $totals['uncovered'] > 0 ? money($totals['uncovered']).' of approved spend is not yet covered by receipts.' : 'Receipts cover approved spend.' ?></p></div>
  </div>
</div>
<?php endif; ?>

<?php render_expense_import_report(); ?>
<?php render_registration_import_report(); ?>
<div class="card" style="margin-bottom:16px" id="expenses">
  <div class="card-h">
    <h3>Expenses</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <span><?= count($expenses) ?> lines · approved <?= money($totals['expenses']) ?>
        <?php if ($totals['expenses'] > 0): ?>
          · PO/WO <?= money($totals['purchase_expenses']) ?> · ECM <?= money($totals['ecm_expenses']) ?>
        <?php endif; ?>
        <?php if (($totals['pending_count'] ?? 0) > 0): ?>
          · <?= (int)$totals['pending_count'] ?> pending
        <?php endif; ?>
      </span>
      <?php if (can('expenses.create')): ?>
        <button class="btn btn-sm" type="button" onclick="resetExpenseForm(); openModal('expModal')">Add expense</button>
        <button class="btn btn-sm btn-ghost" type="button" onclick="openModal('expUploadModal')">Upload</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-b table-wrap">
    <?php if (!$expenses): ?><div class="empty"><p>No expenses recorded yet.</p></div>
    <?php else: ?>
    <table class="data">
      <thead><tr><th>Date</th><th>Item</th><th>Booked as</th><th>Category</th><th class="num">Amount</th><th>Approval</th><th>Payment</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($expenses as $ex): ?>
        <tr class="<?= $ex['approval_status'] === 'pending' ? 'row-pending' : ($ex['approval_status'] === 'rejected' ? 'row-rejected' : '') ?>">
          <td><?= e(dmy($ex['expense_date'])) ?></td>
          <td>
            <strong><?= e($ex['title']) ?></strong>
            <div class="muted"><?= e($ex['vendor'] ?: '') ?><?= !empty($ex['invoice_no']) ? ' · Inv '.e($ex['invoice_no']) : '' ?></div>
          </td>
          <td>
            <span class="badge <?= ($ex['booking_type'] ?? '') === 'ecm' ? 'warn' : 'info' ?>"><?= ($ex['booking_type'] ?? 'purchase') === 'ecm' ? 'ECM' : 'Purchase' ?></span>
            <div class="muted"><?= e(expense_ref($ex)) ?></div>
          </td>
          <td><span class="badge" style="background:<?= e($ex['color']) ?>22"><?= e($ex['cat']) ?></span></td>
          <td class="num"><?= money_dec($ex['amount']) ?></td>
          <td>
            <span class="badge <?= status_class($ex['approval_status']) ?>"><?= e(ucfirst($ex['approval_status'])) ?></span>
            <?php if ($ex['approval_status'] === 'approved' && !empty($ex['approver'])): ?>
              <div class="muted"><?= e($ex['approver']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="badge <?= status_class($ex['payment_status']) ?>"><?= e(ucfirst($ex['payment_status'])) ?></span></td>
          <td>
            <?php if ($ex['bill_path']): ?><a class="btn btn-ghost btn-sm" href="uploads/bills/<?= e($ex['bill_path']) ?>" target="_blank">Bill</a><?php endif; ?>
            <?php if (can('expenses.approve') || (can('expenses.edit') && $ex['approval_status'] !== 'approved')): ?>
              <button class="btn btn-ghost btn-sm" type="button" onclick='fillExpense(<?= (int)$ex['id'] ?>)'>Edit</button>
            <?php endif; ?>
            <?php if (can('expenses.approve') && $ex['approval_status'] === 'pending'): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="approve_expense"><input type="hidden" name="expense_id" value="<?= (int)$ex['id'] ?>">
              <button class="btn btn-teal btn-sm" type="submit">Approve</button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Reject this expense?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="reject_expense"><input type="hidden" name="expense_id" value="<?= (int)$ex['id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit">Reject</button>
            </form>
            <?php endif; ?>
            <?php if ((can('expenses.edit') || role() === 'admin') && ($ex['approval_status'] !== 'approved' || can('expenses.approve') || role() === 'admin')): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Cancel this expense? It stays in history but drops out of the tracker.')">
              <?= csrf_field() ?><input type="hidden" name="action" value="cancel_expense"><input type="hidden" name="expense_id" value="<?= (int)$ex['id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit">Cancel</button>
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

<div class="card" style="margin-bottom:16px">
  <div class="card-h">
    <h3>Registrations</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <span><?= (int) $regCount ?> attendee<?= $regCount === 1 ? '' : 's' ?><?= (int)$event['expected_attendees'] > 0 ? ' · expected ' . (int)$event['expected_attendees'] : '' ?><?= $regAmount > 0 ? ' · fees ' . money($regAmount) : '' ?><?= $regFees['collected'] > 0 ? ' · collected ' . money($regFees['collected']) : '' ?></span>
      <?php if (can('registrations.create')): ?>
        <button class="btn btn-sm" type="button" onclick="resetRegForm(); openModal('regModal')">Add</button>
        <button class="btn btn-sm btn-ghost" type="button" onclick="openModal('regUploadModal')">Upload list</button>
      <?php endif; ?>
      <a class="btn btn-sm btn-ghost" href="registrations.php?event_id=<?= $id ?>">Open list</a>
    </div>
  </div>
  <div class="card-b">
    <?php if (can('events.edit')): ?>
    <form method="post" class="reg-amount-bar">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_registration_amount">
      <div class="field" style="margin:0;min-width:220px;flex:1">
        <label>Registration amount (₹)</label>
        <input type="number" min="0" step="0.01" name="registration_target" value="<?= e((string) ($regFees['billed'] > 0.009 ? $regFees['billed'] : $regTarget)) ?>" placeholder="Total delegate fees">
      </div>
      <button class="btn btn-teal btn-sm" type="submit">Save amount</button>
      <p class="muted" style="margin:0;flex:2;min-width:220px">
        <?php if ($regFees['billed'] > 0.009): ?>
          Fetched from attendee fees on this event: <strong><?= money($regFees['billed']) ?></strong>
          <?= $regFees['collected'] > 0.009 ? ' · collected ' . money($regFees['collected']) : '' ?>.
        <?php else: ?>
          Planned collection from registrations. When you add attendee fees, this total follows that list.
        <?php endif; ?>
      </p>
    </form>
    <?php endif; ?>
    <div class="table-wrap">
    <?php if (!$registrations): ?>
      <div class="empty"><p>No attendees on this programme yet. Add one, or upload the Excel / CSV list.</p></div>
    <?php else: ?>
    <table class="data">
      <thead><tr><th>Name</th><th>Category</th><th>Contact</th><th>Registered</th><th class="num">Fee</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($registrations as $rg): ?>
        <tr>
          <td>
            <strong><?= e($rg['name']) ?></strong>
            <div class="muted"><?= e($rg['registration_no'] ?: '') ?><?= $rg['organization'] ? ($rg['registration_no'] ? ' · ' : '') . e($rg['organization']) : '' ?></div>
          </td>
          <td><span class="badge info"><?= e(attendee_categories()[$rg['category']] ?? $rg['category']) ?></span></td>
          <td><?= e($rg['phone'] ?: '—') ?><div class="muted"><?= e($rg['email'] ?: $rg['city'] ?: '') ?></div></td>
          <td><?= e(dmy($rg['registration_date'])) ?></td>
          <td class="num">
            <?= (float) $rg['fee_amount'] > 0 ? money_dec($rg['fee_amount']) : '—' ?>
            <div class="muted"><?= e(registration_payment_statuses()[$rg['payment_status']] ?? $rg['payment_status']) ?></div>
          </td>
          <td>
            <?php if (can('registrations.edit') || role() === 'admin'): ?>
              <button class="btn btn-ghost btn-sm" type="button" onclick="fillRegistration(<?= (int)$rg['id'] ?>)">Edit</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove this attendee from the live list?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel_registration"><input type="hidden" name="registration_id" value="<?= (int)$rg['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
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
</div>

<?php if ($history): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-h"><h3>Expense history</h3><span>Creates, edits, approvals, cancellations</span></div>
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>When</th><th>Action</th><th>By</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></td>
          <td><span class="badge <?= status_class($h['action'] === 'approve' ? 'approved' : ($h['action'] === 'reject' || $h['action'] === 'cancel' ? 'rejected' : 'pending')) ?>"><?= e(ucfirst($h['action'])) ?></span></td>
          <td><?= e($h['user_name'] ?: '—') ?></td>
          <td><?= e($h['details'] ?: '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card" id="sponsorship">
  <div class="card-h">
    <h3><?= $unsponsored ? 'Sponsorship' : 'Linked sponsors' ?></h3>
    <span><?= $unsponsored ? 'Not applicable' : 'Promised ' . money($totals['promised']) . ' · Received ' . money($totals['received']) ?></span>
  </div>
  <div class="card-b table-wrap">
    <?php if ($unsponsored): ?>
      <div class="empty">
        <h4>Not sponsored</h4>
        <p>No companies are attached. Record expenses as usual; this event will not appear on the sponsorship ledger.</p>
      </div>
    <?php elseif (!$sps): ?>
      <div class="empty">
        <h4>No sponsor linked yet</h4>
        <p>This event is open for sponsorship. Link a company so promises and receipts stay on this programme.</p>
        <?php if (can('sponsorships.create')): ?>
          <button class="btn btn-brass" type="button" onclick="openModal('spModal')">Link a sponsor</button>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <table class="data">
      <thead><tr><th>Sponsor</th><th>Liaison</th><th class="num">Promised</th><th class="num">Received</th><th>Status</th><th>Collection</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sps as $s):
        $left = max(0, (float) $s['promised_amount'] - (float) $s['received']);
        ?>
        <tr>
          <td><strong><?= e($s['sponsor_name']) ?></strong><div class="muted"><?= e(ucfirst($s['sponsor_type'])) ?> · <?= e(dmy($s['promised_date'])) ?></div></td>
          <td><?= e($s['liaison'] ?: '—') ?></td>
          <td class="num"><?= money_dec($s['promised_amount']) ?></td>
          <td class="num"><?= money_dec($s['received']) ?></td>
          <td><span class="badge <?= status_class($s['status']) ?>"><?= e(ucfirst($s['status'])) ?></span></td>
          <td>
            <?php if ($s['status'] === 'cancelled' || $left <= 0.009): ?>
              <span class="muted">—</span>
            <?php elseif ($clock['state'] === 'overdue'): ?>
              <span class="flag flag-coral" title="Not received within <?= (int)$clock['grace'] ?> days of the event ending"><span class="flag-mark">⚑</span> <?= (int)$clock['days_late'] ?>d late</span>
            <?php elseif ($clock['state'] === 'window'): ?>
              <span class="badge warn"><?= (int)$clock['days_left'] ?>d left</span>
            <?php else: ?>
              <span class="muted">Due <?= e(dmy($clock['due_date'])) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (can_edit_sponsorship_amount() && $s['status'] !== 'cancelled'): ?>
              <button class="btn btn-ghost btn-sm" type="button" onclick='prepSpEdit(<?= (int)$s['id'] ?>, <?= json_encode($s['sponsor_name'], JSON_HEX_TAG | JSON_HEX_APOS) ?>, <?= json_encode((float)$s['promised_amount']) ?>)'>Edit amount</button>
            <?php endif; ?>
            <?php if (can('receipts') && $s['status'] !== 'cancelled' && $s['status'] !== 'received'): ?>
              <button class="btn btn-teal btn-sm" type="button" onclick="prepReceipt(<?= (int)$s['id'] ?>, '<?= e($s['sponsor_name']) ?>', <?= json_encode($left) ?>)">Receive</button>
            <?php endif; ?>
            <?php if (can_remove_sponsorship() && $s['status'] !== 'cancelled'): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove this promise from the event? If no money has been received, it is deleted. If receipts exist, it is cancelled.')">
              <?= csrf_field() ?><input type="hidden" name="action" value="cancel_sp"><input type="hidden" name="sponsorship_id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($s['notes']): ?><tr><td colspan="7" class="muted" style="padding-top:0"><?= e($s['notes']) ?></td></tr><?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="modal-bg" id="expModal">
  <form class="modal" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="expense">
    <input type="hidden" name="expense_id" id="expId" value="">
    <h3 id="expTitle">Record expense</h3>
    <p class="muted">Book through Purchase (PO/WO) or ECM. Amounts over <?= money(approval_limit()) ?> need finance approval before they count in the tracker. Paid amount cannot exceed the expense.</p>
    <div class="form-grid">
      <div class="field full">
        <label>How is this booked?</label>
        <div class="choice-row">
          <label class="choice">
            <span><input type="radio" name="booking_type" value="purchase" checked onchange="syncBookingFields()"> <strong>Purchase (PO / WO)</strong></span>
            <span>Vendor work against a purchase order or work order. One PO or WO can have several line items.</span>
          </label>
          <label class="choice">
            <span><input type="radio" name="booking_type" value="ecm" onchange="syncBookingFields()"> <strong>ECM</strong></span>
            <span>Event cost memo / claim, without a PO or WO.</span>
          </label>
        </div>
      </div>
      <div class="field full"><label>What was spent</label><input name="title" required placeholder="e.g. Faculty rooms — 8 keys"></div>
      <div class="field full">
        <label>Category *</label>
        <?php if (!$cats): ?>
          <p class="muted">No expense heads yet. An administrator can add them in Settings.</p>
        <?php else: ?>
          <div class="cat-picks">
            <?php foreach ($cats as $i => $c): ?>
              <label class="cat-pick">
                <input type="radio" name="category_id" value="<?= (int) $c['id'] ?>" <?= $i === 0 ? 'checked required' : '' ?>>
                <?= e($c['name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="field"><label>Amount (₹) *</label><input type="number" step="0.01" min="0.01" name="amount" required></div>

      <div class="field full" id="purchaseFields">
        <div class="form-grid">
          <div class="field"><label>PO number</label><input name="po_no" id="po_no" placeholder="PO-2026-0142"></div>
          <div class="field"><label>WO number</label><input name="wo_no" id="wo_no" placeholder="WO-2026-0088"></div>
          <div class="field"><label>PO / WO date *</label><input type="date" name="order_date" id="order_date" value="<?= date('Y-m-d') ?>"></div>
          <div class="field"><label>Vendor *</label><input name="vendor" id="purchase_vendor" placeholder="Hotel / AV / caterer"></div>
          <div class="field"><label>Vendor GSTIN</label><input name="vendor_gstin"></div>
          <div class="field"><label>Invoice no.</label><input name="invoice_no"></div>
        </div>
        <p class="muted" style="margin:8px 0 0">At least one of PO or WO is required. The same number can be used on several lines — add or upload each item separately.</p>
      </div>

      <div class="field full" id="ecmFields" style="display:none">
        <div class="form-grid">
          <div class="field"><label>ECM number *</label><input name="ecm_no" id="ecm_no" placeholder="ECM-2026-055"></div>
          <div class="field"><label>ECM date *</label><input type="date" name="ecm_date" id="ecm_date" value="<?= date('Y-m-d') ?>"></div>
          <div class="field"><label>Raised by / claimant</label><input name="claimant" placeholder="Coordinator / doctor"></div>
          <div class="field"><label>Approved by</label><input name="ecm_approved_by" placeholder="Finance / HOD"></div>
          <div class="field"><label>Payee / vendor</label><input id="ecm_vendor" placeholder="If paid to a vendor"></div>
        </div>
      </div>

      <div class="field"><label>Payment status</label>
        <select name="payment_status"><option value="unpaid">Unpaid</option><option value="partial">Partial</option><option value="paid">Paid</option></select>
      </div>
      <div class="field"><label>Paid amount (₹)</label><input type="number" step="0.01" min="0" name="paid_amount" value="0"></div>
      <div class="field"><label>Mode</label>
        <select name="payment_mode"><option value="">—</option><?php foreach (payment_modes() as $k=>$v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label>Bill / ECM copy</label><input type="file" name="bill" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
      <div class="field full"><label>Notes</label><textarea name="notes"></textarea></div>
      <?php if (can('expenses.approve')): ?>
      <div class="field full">
        <input type="hidden" name="confirm_overspend" value="0">
        <label class="overspend-tick">
          <input type="checkbox" name="confirm_overspend" value="1">
          <span><strong>Allow overspend</strong> — approved spend may exceed the <?= $unsponsored ? 'budget' : 'sponsorship amount' ?>.</span>
        </label>
      </div>
      <?php endif; ?>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('expModal')">Cancel</button>
      <button class="btn btn-teal" type="submit" id="expSave">Save expense</button>
    </div>
  </form>
</div>

<?php render_expense_import_modal('event.php?id=' . $id, 'event.php?id=' . $id . '&download=expense_template', false, $unsponsored); ?>
<?php render_registration_upload_modal('event.php?id=' . $id, 'event.php?id=' . $id . '&download=registration_template', false); ?>

<?php if (can('registrations.create') || can('registrations.edit')): ?>
<div class="modal-bg" id="regModal">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="registration">
    <input type="hidden" name="registration_id" id="regId" value="">
    <h3 id="regTitle">Add registration</h3>
    <p class="muted">This attendee is stored against <?= e($event['code']) ?> only.</p>
    <?php render_registration_form_fields([], false); ?>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('regModal')">Cancel</button>
      <button class="btn btn-teal" type="submit" id="regSave">Save</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="modal-bg" id="spModal">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="sponsorship">
    <h3>Link a sponsor to <?= e($event['code']) ?></h3>
    <p class="muted">This promise is stored against this event only.</p>
    <div class="form-grid">
      <div class="field full"><label>Sponsor</label>
        <select name="sponsor_id" required>
          <option value="">Select…</option>
          <?php foreach ($sponsors as $sp): ?><option value="<?= $sp['id'] ?>"><?= e($sp['name']) ?></option><?php endforeach; ?>
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
      <div class="field full"><label>Notes</label><textarea name="notes" placeholder="Platinum grant, satellite symposium, booth, etc."></textarea></div>
    </div>
    <p class="muted">Add a sponsor first from the Sponsors page if they are not in the list.</p>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('spModal')">Cancel</button>
      <button class="btn btn-brass" type="submit">Save promise</button>
    </div>
  </form>
</div>

<div class="modal-bg" id="spEditModal">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit_sponsorship">
    <input type="hidden" name="sponsorship_id" id="spEditId">
    <h3>Change sponsorship amount</h3>
    <p class="muted" id="spEditLabel">Update the promise for this company on this event.</p>
    <div class="form-grid">
      <div class="field"><label>New amount (₹) *</label><input type="number" step="0.01" min="0.01" name="promised_amount" id="spEditAmt" required></div>
    </div>
    <p class="muted">Cannot go below money already received against this promise.</p>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('spEditModal')">Cancel</button>
      <button class="btn btn-teal" type="submit">Update amount</button>
    </div>
  </form>
</div>

<div class="modal-bg" id="rcModal">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="receipt">
    <input type="hidden" name="sponsorship_id" id="rcSpId">
    <h3>Record receipt</h3>
      <p class="muted" id="rcLabel"></p>
    <div class="form-grid">
      <div class="field"><label>Amount received (₹)</label><input type="number" step="0.01" min="0.01" name="amount" id="rcAmount" required></div>
      <div class="field"><label>Date</label><input type="date" name="received_date" required value="<?= date('Y-m-d') ?>"></div>
      <div class="field"><label>Mode</label>
        <select name="payment_mode"><?php foreach (payment_modes() as $k=>$v): ?><option value="<?= e($k) ?>" <?= $k==='bank'?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label>Reference / UTR / UPI</label><input name="reference_no"></div>
      <div class="field full"><label>Notes</label><textarea name="notes"></textarea></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('rcModal')">Cancel</button>
      <button class="btn btn-teal" type="submit">Post receipt</button>
    </div>
  </form>
</div>

<div class="modal-bg" id="enableModal">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="enable_sponsored">
    <h3>Capture sponsorship</h3>
    <p class="muted">Amount and company are both required. They stay linked to this event, then expenses and receipts are tracked against that amount.</p>
    <div class="form-grid">
      <div class="field"><label>Sponsorship amount (₹) *</label><input type="number" min="0.01" step="0.01" name="sponsorship_target" required></div>
      <div class="field"><label>Sponsor *</label>
        <select name="first_sponsor_id" required>
          <option value="">Select company…</option>
          <?php foreach ($sponsors as $sp): ?><option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('enableModal')">Cancel</button>
      <button class="btn btn-brass" type="submit">Switch and link</button>
    </div>
  </form>
</div>
<script>
const EXPENSES = <?= json_encode(array_map(static function ($ex) {
    return [
        'id' => (int) $ex['id'],
        'booking_type' => $ex['booking_type'] ?? 'purchase',
        'title' => $ex['title'],
        'category_id' => (int) $ex['category_id'],
        'amount' => $ex['amount'],
        'po_no' => $ex['po_no'] ?? '',
        'wo_no' => $ex['wo_no'] ?? '',
        'order_date' => $ex['order_date'] ?? '',
        'vendor' => $ex['vendor'] ?? '',
        'vendor_gstin' => $ex['vendor_gstin'] ?? '',
        'invoice_no' => $ex['invoice_no'] ?? '',
        'ecm_no' => $ex['ecm_no'] ?? '',
        'ecm_date' => $ex['ecm_date'] ?? '',
        'claimant' => $ex['claimant'] ?? '',
        'ecm_approved_by' => $ex['ecm_approved_by'] ?? '',
        'payment_status' => $ex['payment_status'] ?? 'unpaid',
        'paid_amount' => $ex['paid_amount'] ?? 0,
        'payment_mode' => $ex['payment_mode'] ?? '',
        'notes' => $ex['notes'] ?? '',
    ];
}, $expenses), JSON_UNESCAPED_UNICODE) ?>;

function prepSpEdit(id, name, amount) {
  document.getElementById('spEditId').value = id;
  document.getElementById('spEditLabel').textContent = 'Update the promise from ' + name + ' on this event.';
  document.getElementById('spEditAmt').value = amount;
  openModal('spEditModal');
}
function prepReceipt(id, name, left) {
  document.getElementById('rcSpId').value = id;
  const leftN = Number(left || 0);
  document.getElementById('rcLabel').textContent = 'Against promise from ' + name + (leftN > 0 ? ' · balance ' + leftN.toLocaleString('en-IN', {style:'currency', currency:'INR', maximumFractionDigits:0}) : '');
  const amt = document.getElementById('rcAmount');
  if (amt) {
    amt.max = leftN > 0 ? String(leftN) : '';
    amt.value = leftN > 0 ? leftN : '';
  }
  openModal('rcModal');
}
function syncBookingFields() {
  const purchase = document.querySelector('#expModal input[name="booking_type"]:checked')?.value !== 'ecm';
  const p = document.getElementById('purchaseFields');
  const e = document.getElementById('ecmFields');
  if (p) p.style.display = purchase ? '' : 'none';
  if (e) e.style.display = purchase ? 'none' : '';
  const order = document.getElementById('order_date');
  const ecmNo = document.getElementById('ecm_no');
  const ecmDate = document.getElementById('ecm_date');
  const vendor = document.getElementById('purchase_vendor');
  if (order) order.required = purchase;
  if (vendor) vendor.required = purchase;
  if (ecmNo) ecmNo.required = !purchase;
  if (ecmDate) ecmDate.required = !purchase;
}
function setRadio(name, value) {
  document.querySelectorAll('#expModal input[name="'+name+'"]').forEach(el => { el.checked = el.value === value; });
}
function resetExpenseForm() {
  const form = document.querySelector('#expModal form');
  if (form) form.reset();
  document.getElementById('expId').value = '';
  document.getElementById('expTitle').textContent = 'Record expense';
  document.getElementById('expSave').textContent = 'Save expense';
  setRadio('booking_type', 'purchase');
  syncBookingFields();
}
function fillExpense(id) {
  const ex = EXPENSES.find(row => Number(row.id) === Number(id));
  if (!ex) return;
  resetExpenseForm();
  document.getElementById('expId').value = ex.id;
  document.getElementById('expTitle').textContent = 'Edit expense';
  document.getElementById('expSave').textContent = 'Update expense';
  setRadio('booking_type', ex.booking_type === 'ecm' ? 'ecm' : 'purchase');
  const form = document.querySelector('#expModal form');
  const set = (name, val) => {
    const radios = form.querySelectorAll('input[type="radio"][name="'+name+'"]');
    if (radios.length) {
      radios.forEach(el => { el.checked = String(el.value) === String(val ?? ''); });
      return;
    }
    if (form[name] !== undefined) form[name].value = val ?? '';
  };
  set('title', ex.title);
  set('category_id', ex.category_id);
  set('amount', ex.amount);
  set('po_no', ex.po_no);
  set('wo_no', ex.wo_no);
  set('order_date', ex.order_date);
  set('vendor', ex.vendor);
  set('vendor_gstin', ex.vendor_gstin);
  set('invoice_no', ex.invoice_no);
  set('ecm_no', ex.ecm_no);
  set('ecm_date', ex.ecm_date);
  set('claimant', ex.claimant);
  set('ecm_approved_by', ex.ecm_approved_by);
  set('payment_status', ex.payment_status);
  set('paid_amount', ex.paid_amount);
  set('payment_mode', ex.payment_mode);
  set('notes', ex.notes);
  const ecmVendor = document.getElementById('ecm_vendor');
  if (ecmVendor) ecmVendor.value = ex.vendor || '';
  syncBookingFields();
  openModal('expModal');
}
document.getElementById('expModal')?.querySelector('form')?.addEventListener('submit', () => {
  const ecm = document.querySelector('#expModal input[name="booking_type"]:checked')?.value === 'ecm';
  if (ecm) {
    const payee = document.getElementById('ecm_vendor');
    const vendor = document.getElementById('purchase_vendor');
    if (payee && vendor) vendor.value = payee.value;
  }
});
syncBookingFields();

const REGS = <?= json_encode(array_map(static function ($rg) {
    return [
        'id' => (int) $rg['id'],
        'name' => $rg['name'],
        'registration_no' => $rg['registration_no'] ?? '',
        'email' => $rg['email'] ?? '',
        'phone' => $rg['phone'] ?? '',
        'category' => $rg['category'] ?? 'delegate',
        'organization' => $rg['organization'] ?? '',
        'designation' => $rg['designation'] ?? '',
        'city' => $rg['city'] ?? '',
        'registration_date' => $rg['registration_date'] ?? '',
        'fee_amount' => $rg['fee_amount'] ?? 0,
        'payment_status' => $rg['payment_status'] ?? 'unpaid',
        'notes' => $rg['notes'] ?? '',
    ];
}, $registrations), JSON_UNESCAPED_UNICODE) ?>;

function resetRegForm() {
  const form = document.querySelector('#regModal form');
  if (form) form.reset();
  const idEl = document.getElementById('regId');
  if (idEl) idEl.value = '';
  const title = document.getElementById('regTitle');
  if (title) title.textContent = 'Add registration';
  const save = document.getElementById('regSave');
  if (save) save.textContent = 'Save';
}
function fillRegistration(id) {
  const row = REGS.find(r => Number(r.id) === Number(id));
  if (!row) return;
  resetRegForm();
  document.getElementById('regId').value = row.id;
  document.getElementById('regTitle').textContent = 'Edit registration';
  document.getElementById('regSave').textContent = 'Update';
  const form = document.querySelector('#regModal form');
  if (!form) return;
  const set = (name, val) => { if (form[name] !== undefined) form[name].value = val ?? ''; };
  set('name', row.name);
  set('registration_no', row.registration_no);
  set('email', row.email);
  set('phone', row.phone);
  set('category', row.category);
  set('organization', row.organization);
  set('designation', row.designation);
  set('city', row.city);
  set('registration_date', row.registration_date);
  set('fee_amount', row.fee_amount);
  set('payment_status', row.payment_status);
  set('notes', row.notes);
  openModal('regModal');
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
