<?php
require __DIR__ . '/includes/init.php';
require_login();

$pdo = db();
$year = (int) date('Y');
$unitParams = [];
$ew = unit_where('e', $unitParams);
$evw = $ew; // alias e for events

$eventsCount = (int) (function () use ($ew, $unitParams) {
    $st = db()->prepare("SELECT COUNT(*) FROM events e WHERE e.status <> 'cancelled'" . $ew);
    $st->execute($unitParams);
    return $st->fetchColumn();
})();
$sponsoredN = (int) (function () use ($ew, $unitParams) {
    $st = db()->prepare("SELECT COUNT(*) FROM events e WHERE e.status <> 'cancelled' AND e.funding_mode = 'sponsored' AND EXISTS (SELECT 1 FROM sponsorships s WHERE s.event_id = e.id AND s.status <> 'cancelled')" . $ew);
    $st->execute($unitParams);
    return $st->fetchColumn();
})();
$seekingN = (int) (function () use ($ew, $unitParams) {
    $st = db()->prepare("SELECT COUNT(*) FROM events e WHERE e.status <> 'cancelled' AND e.funding_mode = 'sponsored' AND NOT EXISTS (SELECT 1 FROM sponsorships s WHERE s.event_id = e.id AND s.status <> 'cancelled')" . $ew);
    $st->execute($unitParams);
    return $st->fetchColumn();
})();
$unsponsoredN = (int) (function () use ($ew, $unitParams) {
    $st = db()->prepare("SELECT COUNT(*) FROM events e WHERE e.status <> 'cancelled' AND e.funding_mode = 'unsponsored'" . $ew);
    $st->execute($unitParams);
    return $st->fetchColumn();
})();

$expP = [];
$expW = unit_where('ev', $expP);
$st = $pdo->prepare("SELECT COALESCE(SUM(e.amount),0) FROM expenses e JOIN events ev ON ev.id = e.event_id WHERE e.deleted_at IS NULL AND e.approval_status = 'approved'" . $expW);
$st->execute($expP);
$expTotal = (float) $st->fetchColumn();
$st = $pdo->prepare("SELECT e.booking_type, COALESCE(SUM(e.amount),0) total FROM expenses e JOIN events ev ON ev.id = e.event_id WHERE e.deleted_at IS NULL AND e.approval_status = 'approved'" . $expW . ' GROUP BY e.booking_type');
$st->execute($expP);
$poTotal = $ecmTotal = 0.0;
foreach ($st->fetchAll() as $row) {
    if (($row['booking_type'] ?? '') === 'ecm') {
        $ecmTotal = (float) $row['total'];
    } else {
        $poTotal = (float) $row['total'];
    }
}
$st = $pdo->prepare("SELECT COUNT(*) FROM expenses e JOIN events ev ON ev.id = e.event_id WHERE e.deleted_at IS NULL AND e.approval_status = 'pending'" . $expW);
$st->execute($expP);
$pendingExp = (int) $st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(s.promised_amount),0) FROM sponsorships s JOIN events ev ON ev.id = s.event_id WHERE s.status <> 'cancelled'" . $expW);
$st->execute($expP);
$promised = (float) $st->fetchColumn();
$st = $pdo->prepare("SELECT COALESCE(SUM(r.amount),0) FROM sponsorship_receipts r JOIN sponsorships s ON s.id = r.sponsorship_id JOIN events ev ON ev.id = s.event_id WHERE 1=1" . $expW);
$st->execute($expP);
$received = (float) $st->fetchColumn();
$outstanding = max(0, $promised - $received);
$net = $received - $expTotal;
$overdue = overdue_collections();
$overspent = overspent_events();

$st = $pdo->prepare('SELECT e.status, COUNT(*) c FROM events e WHERE 1=1' . $ew . ' GROUP BY e.status');
$st->execute($unitParams);
$byStatus = $st->fetchAll();
$statusLabels = [];
$statusData = [];
foreach ($byStatus as $row) {
    $statusLabels[] = ucfirst($row['status']);
    $statusData[] = (int) $row['c'];
}

$unitNow = active_unit_filter();
if ($unitNow) {
    $st = $pdo->prepare(
        "SELECT c.name, c.color, COALESCE(SUM(e.amount),0) total
         FROM expense_categories c
         LEFT JOIN expenses e ON e.category_id = c.id AND e.deleted_at IS NULL AND e.approval_status = 'approved'
           AND EXISTS (SELECT 1 FROM events ev WHERE ev.id = e.event_id AND ev.unit_code = ?)
         GROUP BY c.id
         HAVING total > 0
         ORDER BY total DESC"
    );
    $st->execute([$unitNow]);
} else {
    $st = $pdo->query(
        "SELECT c.name, c.color, COALESCE(SUM(e.amount),0) total
         FROM expense_categories c
         LEFT JOIN expenses e ON e.category_id = c.id AND e.deleted_at IS NULL AND e.approval_status = 'approved'
         GROUP BY c.id
         HAVING total > 0
         ORDER BY total DESC"
    );
}
$byCat = $st->fetchAll();

$months = [];
$monthExp = [];
$monthRecv = [];
for ($i = 11; $i >= 0; $i--) {
    $start = date('Y-m-01', strtotime("-{$i} months"));
    $end = date('Y-m-t', strtotime($start));
    $label = date('M y', strtotime($start));
    $months[] = $label;
    $mp = array_merge([$start, $end], $expP);
    $st = $pdo->prepare("SELECT COALESCE(SUM(e.amount),0) FROM expenses e JOIN events ev ON ev.id = e.event_id WHERE e.expense_date BETWEEN ? AND ? AND e.deleted_at IS NULL AND e.approval_status = 'approved'" . $expW);
    $st->execute($mp);
    $monthExp[] = (float) $st->fetchColumn();
    $st = $pdo->prepare("SELECT COALESCE(SUM(r.amount),0) FROM sponsorship_receipts r JOIN sponsorships s ON s.id = r.sponsorship_id JOIN events ev ON ev.id = s.event_id WHERE r.received_date BETWEEN ? AND ?" . $expW);
    $st->execute($mp);
    $monthRecv[] = (float) $st->fetchColumn();
}

$st = $pdo->prepare(
    "SELECT e.*, c.name cat, ev.title event_title, ev.code, ev.unit_code
     FROM expenses e
     JOIN expense_categories c ON c.id = e.category_id
     JOIN events ev ON ev.id = e.event_id
     WHERE e.deleted_at IS NULL" . $expW . "
     ORDER BY e.id DESC LIMIT 6"
);
$st->execute($expP);
$recentExp = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT sp.name, SUM(s.promised_amount) promised, COALESCE(SUM(r.amount),0) received
     FROM sponsorships s
     JOIN sponsors sp ON sp.id = s.sponsor_id
     JOIN events ev ON ev.id = s.event_id
     LEFT JOIN sponsorship_receipts r ON r.sponsorship_id = s.id
     WHERE s.status <> 'cancelled'" . $expW . "
     GROUP BY sp.id
     ORDER BY promised DESC
     LIMIT 5"
);
$st->execute($expP);
$topSponsors = $st->fetchAll();

$stage = (string) query('stage');
if ($stage !== '' && !isset(event_statuses()[$stage])) {
    $stage = '';
}
if ($stage === 'cancelled') {
    $stage = '';
}
$boardParams = [];
$boardSql = "SELECT e.*,
  (SELECT COALESCE(SUM(amount),0) FROM expenses x WHERE x.event_id = e.id AND x.deleted_at IS NULL AND x.approval_status = 'approved') expenses,
  (SELECT COALESCE(SUM(amount),0) FROM expenses x WHERE x.event_id = e.id AND x.deleted_at IS NULL AND x.approval_status = 'approved' AND x.booking_type = 'purchase') purchase_amt,
  (SELECT COALESCE(SUM(amount),0) FROM expenses x WHERE x.event_id = e.id AND x.deleted_at IS NULL AND x.approval_status = 'approved' AND x.booking_type = 'ecm') ecm_amt,
  (SELECT COALESCE(SUM(promised_amount),0) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> 'cancelled') promised,
  (SELECT COALESCE(SUM(r.amount),0) FROM sponsorship_receipts r JOIN sponsorships s ON s.id = r.sponsorship_id WHERE s.event_id = e.id AND s.status <> 'cancelled') received,
  (SELECT COUNT(*) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> 'cancelled') sponsor_count,
  (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.deleted_at IS NULL) registered,
  (SELECT COALESCE(SUM(r.fee_amount),0) FROM registrations r WHERE r.event_id = e.id AND r.deleted_at IS NULL) reg_billed
 FROM events e WHERE e.status <> 'cancelled'";
if ($stage !== '') {
    $boardSql .= ' AND e.status = ?';
    $boardParams[] = $stage;
}
$boardSql .= unit_where('e', $boardParams);
$boardSql .= ' ORDER BY e.start_date DESC, e.id DESC';
try {
    $st = $pdo->prepare($boardSql);
    $st->execute($boardParams);
    $boardEvents = $st->fetchAll();
} catch (Throwable $e) {
    $boardParams = [];
    $boardSql = "SELECT e.*,
      (SELECT COALESCE(SUM(amount),0) FROM expenses x WHERE x.event_id = e.id AND x.deleted_at IS NULL AND x.approval_status = 'approved') expenses,
      (SELECT COALESCE(SUM(promised_amount),0) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> 'cancelled') promised,
      (SELECT COALESCE(SUM(r.amount),0) FROM sponsorship_receipts r JOIN sponsorships s ON s.id = r.sponsorship_id WHERE s.event_id = e.id) received,
      (SELECT COUNT(*) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> 'cancelled') sponsor_count
     FROM events e WHERE e.status <> 'cancelled'";
    if ($stage !== '') {
        $boardSql .= ' AND e.status = ?';
        $boardParams[] = $stage;
    }
    $boardSql .= unit_where('e', $boardParams);
    $boardSql .= ' ORDER BY e.start_date DESC, e.id DESC';
    $st = $pdo->prepare($boardSql);
    $st->execute($boardParams);
    $boardEvents = $st->fetchAll();
    foreach ($boardEvents as &$row) {
        $row['purchase_amt'] = 0;
        $row['ecm_amt'] = 0;
        $row['registered'] = 0;
        $row['reg_billed'] = 0;
    }
    unset($row);
}

$unitBoard = [];
if (can_see_all_units() && $unitNow === null) {
    $unitBoard = $pdo->query(
        "SELECT e.unit_code,
            COUNT(*) events_n,
            COALESCE((SELECT SUM(s.promised_amount) FROM sponsorships s JOIN events ex ON ex.id = s.event_id WHERE s.status <> 'cancelled' AND ex.unit_code = e.unit_code),0) promised
         FROM events e
         WHERE e.status <> 'cancelled'
         GROUP BY e.unit_code"
    )->fetchAll();
}

$pageTitle = 'Dashboard';
$pageCrumb = 'Good ' . (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')) . ', ' . current_user()['name'] . (active_unit_filter() ? ' · ' . active_unit_filter() : (can_see_all_units() ? ' · All units' : ''));
$active = 'dashboard';
require __DIR__ . '/includes/header.php';
render_unit_pills('dashboard.php');
?>

<div class="kpis">
  <div class="kpi">
    <div class="label">Active events</div>
    <div class="value"><?= $eventsCount ?></div>
    <div class="hint"><?= $sponsoredN ?> with sponsors · <?= $seekingN ?> seeking · <?= $unsponsoredN ?> hospital-funded</div>
  </div>
  <div class="kpi brass">
    <div class="label">Sponsorship promised</div>
    <div class="value"><?= money($promised) ?></div>
    <div class="hint"><?= money($received) ?> received</div>
  </div>
  <div class="kpi teal">
    <div class="label">Expenses booked</div>
    <div class="value"><?= money($expTotal) ?></div>
    <div class="hint">Purchase <?= money($poTotal) ?> · ECM <?= money($ecmTotal) ?><?= $pendingExp ? ' · ' . $pendingExp . ' pending' : '' ?></div>
  </div>
  <div class="kpi <?= $net >= 0 ? 'ok' : 'coral' ?>">
    <div class="label">Net (received − spend)</div>
    <div class="value"><?= money($net) ?></div>
    <div class="hint"><?= money($outstanding) ?> still outstanding</div>
  </div>
</div>

<div class="event-board-head">
  <div>
    <h2>Events</h2>
    <p class="muted" style="margin:4px 0 0"><?= count($boardEvents) ?> programme<?= count($boardEvents) === 1 ? '' : 's' ?> · each card has registrations, sponsorship, spend, and receipts</p>
  </div>
  <form class="filters" method="get" style="margin:0;background:transparent;box-shadow:none;padding:0">
    <?php if (query('unit')): ?><input type="hidden" name="unit" value="<?= e((string) query('unit')) ?>"><?php endif; ?>
    <div class="field">
      <label>Stage</label>
      <select name="stage" onchange="this.form.submit()">
        <option value="">All live events</option>
        <?php foreach (event_statuses() as $k => $v): ?>
          <?php if ($k === 'cancelled') continue; ?>
          <option value="<?= e($k) ?>" <?= $stage === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>
<?php if (!$boardEvents): ?>
  <div class="card" style="margin-bottom:16px"><div class="card-b"><div class="empty"><h4>No events in this view</h4><p>Plan a programme from Events, or clear the stage filter.</p></div></div></div>
<?php else: ?>
<div class="event-board">
  <?php foreach ($boardEvents as $ev):
    $unsponsored = event_is_unsponsored($ev);
    $promised = (float) ($ev['promised'] ?? 0);
    $recv = (float) ($ev['received'] ?? 0);
    $spend = (float) ($ev['expenses'] ?? 0);
    $poAmt = (float) ($ev['purchase_amt'] ?? 0);
    $ecmAmt = (float) ($ev['ecm_amt'] ?? 0);
    $captured = $unsponsored
        ? (float) ($ev['budget_estimate'] ?? 0)
        : sponsorship_captured((float) ($ev['sponsorship_target'] ?? 0), $promised);
    $regN = (int) ($ev['registered'] ?? 0);
    $regAmt = registration_amount_shown((float) ($ev['registration_target'] ?? 0), ['billed' => (float) ($ev['reg_billed'] ?? 0)]);
    $gap = $unsponsored ? 0.0 : max(0, $captured - $recv);
    $net = $recv - $spend;
    $totals = [
        'captured' => $captured,
        'expenses' => $spend,
        'received' => $recv,
        'outstanding' => $gap,
        'net' => $net,
    ];
    $fund = funding_label($ev, (int) ($ev['sponsor_count'] ?? 0));
    $health = event_health($ev, $totals);
    $flags = event_flags($ev, $totals);
    ?>
  <article class="event-board-card">
    <div class="eb-top">
      <div>
        <div class="eb-code"><?= e($ev['code']) ?> · <?= e($ev['unit_code'] ?? '') ?></div>
        <h3><a href="event.php?id=<?= (int)$ev['id'] ?>"><?= e($ev['title']) ?></a></h3>
        <div class="eb-meta">
          <?= e($ev['venue'] ?: 'Venue TBC') ?><?= $ev['city'] ? ', ' . e($ev['city']) : '' ?>
          · <?= e(dmy($ev['start_date'])) ?><?= $ev['end_date'] !== $ev['start_date'] ? ' – ' . e(dmy($ev['end_date'])) : '' ?>
        </div>
      </div>
      <a class="btn btn-ghost btn-sm" href="event.php?id=<?= (int)$ev['id'] ?>">Open</a>
    </div>
    <div class="eb-tags">
      <span class="badge unit"><?= e($ev['unit_code'] ?? '') ?></span>
      <span class="badge <?= status_class($ev['status']) ?>"><?= e(ucfirst($ev['status'])) ?></span>
      <span class="badge <?= e($fund['class']) ?>"><?= e($fund['label']) ?></span>
      <span class="badge <?= e($health['class']) ?>" title="<?= e($health['reason']) ?>"><?= e($health['label']) ?></span>
    </div>
    <?php render_event_flags($flags); ?>
    <div class="eb-stats">
      <div class="eb-stat"><div class="lbl">Budget</div><div class="val"><?= money($ev['budget_estimate']) ?></div></div>
      <div class="eb-stat"><div class="lbl">Registered</div><div class="val"><?= $regN ?><?= (int)$ev['expected_attendees'] > 0 ? ' / ' . (int)$ev['expected_attendees'] : '' ?></div></div>
      <div class="eb-stat"><div class="lbl">Registration amount</div><div class="val"><?= money($regAmt) ?></div></div>
      <?php if ($unsponsored): ?>
        <div class="eb-stat"><div class="lbl">Spent</div><div class="val"><?= money($spend) ?></div></div>
        <div class="eb-stat"><div class="lbl">Funding</div><div class="val" style="font-size:15px">Hospital</div></div>
        <div class="eb-stat"><div class="lbl">Hospital cost</div><div class="val"><?= money($spend) ?></div></div>
      <?php else: ?>
        <div class="eb-stat brass"><div class="lbl">Sponsorship</div><div class="val"><?= money($captured) ?></div></div>
        <div class="eb-stat teal"><div class="lbl">Expenses</div><div class="val"><?= money($spend) ?></div><div class="hint">PO/WO <?= money($poAmt) ?> · ECM <?= money($ecmAmt) ?></div></div>
        <div class="eb-stat ok"><div class="lbl">Received</div><div class="val"><?= money($recv) ?></div></div>
        <div class="eb-stat"><div class="lbl">Still to collect</div><div class="val"><?= money($gap) ?></div></div>
        <div class="eb-stat <?= $net >= 0 ? 'ok' : 'coral' ?>"><div class="lbl">Net</div><div class="val"><?= money($net) ?></div></div>
      <?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($unitBoard): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-h"><h3>Units</h3><span>Marketing is local · purchase &amp; finance are central</span></div>
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>Unit</th><th class="num">Active events</th><th class="num">Sponsorship promised</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($unitBoard as $ub): ?>
        <tr>
          <td><span class="badge unit"><?= e($ub['unit_code']) ?></span> <?= e(unit_label($ub['unit_code'])) ?></td>
          <td class="num"><?= (int) $ub['events_n'] ?></td>
          <td class="num"><?= money($ub['promised']) ?></td>
          <td><a class="btn btn-ghost btn-sm" href="dashboard.php?unit=<?= e($ub['unit_code']) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($overdue): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-h">
    <h3>Late collections</h3>
    <span><?= count($overdue) ?> promise<?= count($overdue) === 1 ? '' : 's' ?> still open more than <?= collection_grace_days() ?> days after the event</span>
  </div>
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>Event</th><th>Sponsor</th><th>Ended</th><th>Delay</th><th class="num">Still to collect</th></tr></thead>
      <tbody>
      <?php foreach ($overdue as $od): ?>
        <tr>
          <td><a href="event.php?id=<?= (int)$od['event_id'] ?>"><strong><?= e($od['code']) ?></strong></a><div class="muted"><?= e($od['title']) ?> · <?= e($od['unit_code'] ?? '') ?></div></td>
          <td><?= e($od['sponsor_name']) ?></td>
          <td><?= e(dmy($od['end_date'])) ?></td>
          <td><span class="flag flag-coral"><span class="flag-mark">⚑</span> <?= e(overdue_bucket($od['end_date'])) ?></span></td>
          <td class="num"><?= money($od['outstanding']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($overspent): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-h">
    <h3>Overspent events</h3>
    <span>Approved spend above the sponsorship amount (or hospital budget)</span>
  </div>
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>Event</th><th>Ended</th><th class="num">Cap</th><th class="num">Spend</th><th class="num">Over by</th></tr></thead>
      <tbody>
      <?php foreach ($overspent as $os): ?>
        <tr>
          <td><a href="event.php?id=<?= (int)$os['id'] ?>"><strong><?= e($os['code']) ?></strong></a><div class="muted"><?= e($os['title']) ?> · <?= e($os['unit_code'] ?? '') ?></div></td>
          <td><?= e(dmy($os['end_date'])) ?></td>
          <td class="num"><?= money($os['captured']) ?></td>
          <td class="num"><?= money($os['expenses']) ?></td>
          <td class="num"><span class="flag flag-coral"><span class="flag-mark">⚑</span> <?= money($os['over_by']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card-h">
    <h3>Purchase vs ECM</h3>
    <a href="accounting.php">Open books dashboard</a>
  </div>
  <div class="card-b">
    <?php
      $booksTotal = $poTotal + $ecmTotal;
      $poShare = $booksTotal > 0 ? (int) round($poTotal / $booksTotal * 100) : 0;
    ?>
    <div class="grid-2" style="align-items:center">
      <div>
        <div class="split-bar" style="margin-bottom:12px">
          <i class="po" style="width:<?= $poShare ?>%"></i>
          <i class="ecm" style="width:<?= 100 - $poShare ?>%"></i>
        </div>
        <div class="split-legend">
          <span><b class="dot po"></b> Purchase <?= money($poTotal) ?> (<?= $poShare ?>%)</span>
          <span><b class="dot ecm"></b> ECM <?= money($ecmTotal) ?> (<?= 100 - $poShare ?>%)</span>
        </div>
        <p class="muted" style="margin:12px 0 0">Approved spend only. Open Purchase &amp; ECM for monthly charts, unpaid balances, and event-wise books.</p>
      </div>
      <div><?php if ($booksTotal > 0): ?><canvas id="booksChart" height="140"></canvas><?php else: ?><p class="muted">No approved spend yet.</p><?php endif; ?></div>
    </div>
  </div>
</div>

<div class="grid-2" style="margin-bottom:16px">
  <div class="card">
    <div class="card-h">
      <h3>Cash movement</h3>
      <span>Last 12 months</span>
    </div>
    <div class="card-b"><canvas id="cashChart" height="120"></canvas></div>
  </div>
  <div class="card">
    <div class="card-h">
      <h3>Spend by category</h3>
      <span>All events</span>
    </div>
    <div class="card-b"><canvas id="catChart" height="120"></canvas></div>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-h"><h3>Recent expenses</h3><a href="expenses.php" class="muted">Ledger</a></div>
    <div class="card-b table-wrap">
      <table class="data">
        <thead><tr><th>Item</th><th>Event</th><th class="num">Amount</th><th>Approval</th></tr></thead>
        <tbody>
        <?php foreach ($recentExp as $ex): ?>
          <tr>
            <td><?= e($ex['title']) ?><div class="muted"><?= e($ex['cat']) ?></div></td>
            <td><a href="event.php?id=<?= (int)$ex['event_id'] ?>"><?= e($ex['code']) ?></a></td>
            <td class="num"><?= money($ex['amount']) ?></td>
            <td><span class="badge <?= status_class($ex['approval_status'] ?? 'approved') ?>"><?= e(ucfirst($ex['approval_status'] ?? 'approved')) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="stack">
    <div class="card">
      <div class="card-h"><h3>Events by stage</h3></div>
      <div class="card-b"><canvas id="statusChart" height="160"></canvas></div>
    </div>
    <div class="card">
      <div class="card-h"><h3>Top sponsors</h3></div>
      <div class="card-b table-wrap">
        <table class="data">
          <thead><tr><th>Sponsor</th><th class="num">Promised</th><th class="num">Received</th></tr></thead>
          <tbody>
          <?php foreach ($topSponsors as $sp): ?>
            <tr>
              <td><?= e($sp['name']) ?></td>
              <td class="num"><?= money($sp['promised']) ?></td>
              <td class="num"><?= money($sp['received']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
const months = <?= json_encode($months) ?>;
new Chart(document.getElementById('cashChart'), {
  type: 'line',
  data: {
    labels: months,
    datasets: [
      { label: 'Expenses', data: <?= json_encode($monthExp) ?>, borderColor: '#1b6e64', backgroundColor: 'rgba(27,110,100,.12)', fill: true, tension: .35 },
      { label: 'Received', data: <?= json_encode($monthRecv) ?>, borderColor: '#c4a35a', backgroundColor: 'rgba(196,163,90,.12)', fill: true, tension: .35 }
    ]
  },
  options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { ticks: { callback: v => '₹' + Number(v).toLocaleString('en-IN') } } } }
});
<?php if ($booksTotal > 0): ?>
new Chart(document.getElementById('booksChart'), {
  type: 'doughnut',
  data: {
    labels: ['Purchase (PO / WO)', 'ECM'],
    datasets: [{ data: [<?= json_encode($poTotal) ?>, <?= json_encode($ecmTotal) ?>], backgroundColor: ['#1b6e64', '#c4a35a'], borderWidth: 0 }]
  },
  options: { plugins: { legend: { position: 'bottom' } }, cutout: '62%' }
});
<?php endif; ?>
new Chart(document.getElementById('catChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($byCat, 'name')) ?>,
    datasets: [{ data: <?= json_encode(array_map('floatval', array_column($byCat, 'total'))) ?>, backgroundColor: <?= json_encode(array_column($byCat, 'color')) ?>, borderWidth: 0 }]
  },
  options: { plugins: { legend: { position: 'bottom' } }, cutout: '62%' }
});
new Chart(document.getElementById('statusChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($statusLabels) ?>,
    datasets: [{ data: <?= json_encode($statusData) ?>, backgroundColor: '#1b6e64', borderRadius: 8 }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
