<?php
require __DIR__ . '/includes/init.php';
require_can('expenses.view');

$pdo = db();

$fyYear = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
$fyFrom = sprintf('%04d-04-01', $fyYear);
$fyTo = sprintf('%04d-03-31', $fyYear + 1);
$calFrom = date('Y-01-01');
$calTo = date('Y-12-31');

$preset = (string) query('preset');
if ($preset === 'all') {
    $from = '2000-01-01';
    $to = '2099-12-31';
} elseif ($preset === 'cal') {
    $from = $calFrom;
    $to = $calTo;
} else {
    $from = (string) (query('from') ?: $fyFrom);
    $to = (string) (query('to') ?: $fyTo);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $fyFrom;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $fyTo;
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$unitQs = query('unit') ? '&unit=' . urlencode((string) query('unit')) : '';
$rangeQs = 'from=' . urlencode($from) . '&to=' . urlencode($to) . $unitQs;

$base = ' FROM expenses e JOIN events ev ON ev.id = e.event_id WHERE e.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ?';
$params = [$from, $to];
$base .= unit_where('ev', $params);

$st = $pdo->prepare(
    "SELECT e.booking_type,
            COUNT(*) lines_n,
            COALESCE(SUM(e.amount),0) amount,
            COALESCE(SUM(e.paid_amount),0) paid
     $base AND e.approval_status = 'approved'
     GROUP BY e.booking_type"
);
$st->execute($params);
$byType = ['purchase' => ['lines_n' => 0, 'amount' => 0.0, 'paid' => 0.0], 'ecm' => ['lines_n' => 0, 'amount' => 0.0, 'paid' => 0.0]];
foreach ($st->fetchAll() as $row) {
    $k = ($row['booking_type'] ?? 'purchase') === 'ecm' ? 'ecm' : 'purchase';
    $byType[$k] = [
        'lines_n' => (int) $row['lines_n'],
        'amount' => (float) $row['amount'],
        'paid' => (float) $row['paid'],
    ];
}
$poAmt = $byType['purchase']['amount'];
$ecmAmt = $byType['ecm']['amount'];
$totalAmt = $poAmt + $ecmAmt;
$poPaid = $byType['purchase']['paid'];
$ecmPaid = $byType['ecm']['paid'];
$poUnpaid = max(0, $poAmt - $poPaid);
$ecmUnpaid = max(0, $ecmAmt - $ecmPaid);
$poPct = $totalAmt > 0 ? (int) round($poAmt / $totalAmt * 100) : 0;
$ecmPct = $totalAmt > 0 ? 100 - $poPct : 0;

$st = $pdo->prepare(
    "SELECT e.booking_type, COUNT(*) n, COALESCE(SUM(e.amount),0) amount
     $base AND e.approval_status = 'pending'
     GROUP BY e.booking_type"
);
$st->execute($params);
$pendingPo = $pendingEcm = 0.0;
$pendingPoN = $pendingEcmN = 0;
foreach ($st->fetchAll() as $row) {
    if (($row['booking_type'] ?? '') === 'ecm') {
        $pendingEcm = (float) $row['amount'];
        $pendingEcmN = (int) $row['n'];
    } else {
        $pendingPo = (float) $row['amount'];
        $pendingPoN = (int) $row['n'];
    }
}

$st = $pdo->prepare(
    "SELECT DATE_FORMAT(e.expense_date, '%Y-%m') ym,
            SUM(CASE WHEN e.booking_type = 'ecm' THEN 0 ELSE e.amount END) purchase,
            SUM(CASE WHEN e.booking_type = 'ecm' THEN e.amount ELSE 0 END) ecm
     $base AND e.approval_status = 'approved'
     GROUP BY ym
     ORDER BY ym"
);
$st->execute($params);
$monthRows = $st->fetchAll();
$monthLabels = [];
$monthPo = [];
$monthEcm = [];
foreach ($monthRows as $row) {
    $monthLabels[] = date('M y', strtotime($row['ym'] . '-01'));
    $monthPo[] = (float) $row['purchase'];
    $monthEcm[] = (float) $row['ecm'];
}

$catParams = [$from, $to];
$st = $pdo->prepare(
    "SELECT c.name, c.color,
            SUM(CASE WHEN e.booking_type = 'ecm' THEN 0 ELSE e.amount END) purchase,
            SUM(CASE WHEN e.booking_type = 'ecm' THEN e.amount ELSE 0 END) ecm
     FROM expense_categories c
     JOIN expenses e ON e.category_id = c.id AND e.deleted_at IS NULL AND e.approval_status = 'approved' AND e.expense_date BETWEEN ? AND ?
     JOIN events ev ON ev.id = e.event_id
     WHERE 1=1" . unit_where('ev', $catParams) . "
     GROUP BY c.id
     HAVING SUM(CASE WHEN e.booking_type = 'ecm' THEN 0 ELSE e.amount END) > 0
         OR SUM(CASE WHEN e.booking_type = 'ecm' THEN e.amount ELSE 0 END) > 0
     ORDER BY (SUM(CASE WHEN e.booking_type = 'ecm' THEN 0 ELSE e.amount END) + SUM(CASE WHEN e.booking_type = 'ecm' THEN e.amount ELSE 0 END)) DESC"
);
$st->execute($catParams);
$catRows = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT ev.unit_code,
            SUM(CASE WHEN e.booking_type = 'ecm' THEN 0 ELSE e.amount END) purchase,
            SUM(CASE WHEN e.booking_type = 'ecm' THEN e.amount ELSE 0 END) ecm
     $base AND e.approval_status = 'approved'
     GROUP BY ev.unit_code
     ORDER BY ev.unit_code"
);
$st->execute($params);
$unitRows = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT ev.id, ev.code, ev.title, ev.unit_code, ev.start_date, ev.end_date,
            SUM(CASE WHEN e.booking_type = 'ecm' THEN 0 ELSE e.amount END) purchase,
            SUM(CASE WHEN e.booking_type = 'ecm' THEN e.amount ELSE 0 END) ecm,
            SUM(e.amount) total,
            COALESCE(SUM(e.paid_amount),0) paid
     $base AND e.approval_status = 'approved'
     GROUP BY ev.id
     ORDER BY total DESC, ev.start_date"
);
$st->execute($params);
$eventRows = $st->fetchAll();

$lineParams = [$from, $to];
$lineSql = "SELECT e.*, c.name cat, ev.code, ev.title event_title, ev.unit_code
            FROM expenses e
            JOIN events ev ON ev.id = e.event_id
            JOIN expense_categories c ON c.id = e.category_id
            WHERE e.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ?" . unit_where('ev', $lineParams) . "
              AND e.approval_status = 'approved' AND e.booking_type = ?
            ORDER BY e.expense_date DESC, e.id DESC
            LIMIT 12";
$st = $pdo->prepare($lineSql);
$st->execute(array_merge($lineParams, ['purchase']));
$poLines = $st->fetchAll();
$st->execute(array_merge($lineParams, ['ecm']));
$ecmLines = $st->fetchAll();

$pageTitle = 'Purchase & ECM';
$pageCrumb = dmy($from) . ' – ' . dmy($to) . (active_unit_filter() ? ' · ' . active_unit_filter() : ' · all units');
$active = 'accounting';
require __DIR__ . '/includes/header.php';
render_unit_pills('accounting.php');
?>
<form class="filters" method="get">
  <?php if (query('unit')): ?><input type="hidden" name="unit" value="<?= e((string) query('unit')) ?>"><?php endif; ?>
  <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-ghost" type="submit">Apply</button>
  <a class="btn btn-ghost" href="accounting.php?preset=fy<?= e($unitQs) ?>">This FY</a>
  <a class="btn btn-ghost" href="accounting.php?preset=cal<?= e($unitQs) ?>">This year</a>
  <a class="btn btn-ghost" href="accounting.php?preset=all<?= e($unitQs) ?>">All dates</a>
  <a class="btn btn-ghost" href="expenses.php?book=purchase&from=<?= e($from) ?>&to=<?= e($to) ?><?= e($unitQs) ?>">Purchase ledger</a>
  <a class="btn btn-ghost" href="expenses.php?book=ecm&from=<?= e($from) ?>&to=<?= e($to) ?><?= e($unitQs) ?>">ECM ledger</a>
</form>

<div class="kpis">
  <div class="kpi teal">
    <div class="label">Purchase (PO / WO)</div>
    <div class="value"><?= money($poAmt) ?></div>
    <div class="hint"><?= (int) $byType['purchase']['lines_n'] ?> approved lines · <?= $poPct ?>% of spend</div>
  </div>
  <div class="kpi brass">
    <div class="label">ECM</div>
    <div class="value"><?= money($ecmAmt) ?></div>
    <div class="hint"><?= (int) $byType['ecm']['lines_n'] ?> approved lines · <?= $ecmPct ?>% of spend</div>
  </div>
  <div class="kpi">
    <div class="label">Combined books</div>
    <div class="value"><?= money($totalAmt) ?></div>
    <div class="hint"><?= count($eventRows) ?> programme<?= count($eventRows) === 1 ? '' : 's' ?> with approved spend</div>
  </div>
  <div class="kpi <?= ($poUnpaid + $ecmUnpaid) > 0 ? 'coral' : 'ok' ?>">
    <div class="label">Still unpaid</div>
    <div class="value"><?= money($poUnpaid + $ecmUnpaid) ?></div>
    <div class="hint">Purchase <?= money($poUnpaid) ?> · ECM <?= money($ecmUnpaid) ?></div>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-h">
    <h3>Split of approved spend</h3>
    <span>Purchase is PO / WO · ECM is event cost memo</span>
  </div>
  <div class="card-b">
    <div class="split-bar" title="Purchase <?= $poPct ?>% · ECM <?= $ecmPct ?>%">
      <i class="po" style="width:<?= $poPct ?>%"></i>
      <i class="ecm" style="width:<?= $ecmPct ?>%"></i>
    </div>
    <div class="split-legend">
      <span><b class="dot po"></b> Purchase <?= money($poAmt) ?> (<?= $poPct ?>%)</span>
      <span><b class="dot ecm"></b> ECM <?= money($ecmAmt) ?> (<?= $ecmPct ?>%)</span>
      <?php if ($pendingPoN + $pendingEcmN): ?>
        <span class="muted">Pending finance: Purchase <?= money($pendingPo) ?> (<?= $pendingPoN ?>) · ECM <?= money($pendingEcm) ?> (<?= $pendingEcmN ?>)</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid-2" style="margin-bottom:16px">
  <div class="card">
    <div class="card-h"><h3>Month by month</h3><span>Approved spend</span></div>
    <div class="card-b"><?php if ($monthLabels): ?><canvas id="booksMonth" height="140"></canvas><?php else: ?><p class="muted">No approved spend in this period.</p><?php endif; ?></div>
  </div>
  <div class="card">
    <div class="card-h"><h3>Share of books</h3><span>Purchase vs ECM</span></div>
    <div class="card-b"><?php if ($totalAmt > 0): ?><canvas id="booksShare" height="140"></canvas><?php else: ?><p class="muted">Nothing to chart yet.</p><?php endif; ?></div>
  </div>
</div>

<div class="grid-2" style="margin-bottom:16px">
  <div class="card">
    <div class="card-h"><h3>By category</h3><span>Stacked Purchase + ECM</span></div>
    <div class="card-b table-wrap">
      <?php if (!$catRows): ?>
        <p class="muted">No category spend in this period.</p>
      <?php else: ?>
      <table class="data">
        <thead><tr><th>Category</th><th class="num">Purchase</th><th class="num">ECM</th><th class="num">Total</th><th>Mix</th></tr></thead>
        <tbody>
        <?php foreach ($catRows as $c):
            $ct = (float) $c['purchase'] + (float) $c['ecm'];
            $pp = $ct > 0 ? (int) round((float) $c['purchase'] / $ct * 100) : 0; ?>
          <tr>
            <td><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= e($c['color'] ?: '#1b6e64') ?>;margin-right:6px"></span><?= e($c['name']) ?></td>
            <td class="num"><?= money($c['purchase']) ?></td>
            <td class="num"><?= money($c['ecm']) ?></td>
            <td class="num"><strong><?= money($ct) ?></strong></td>
            <td><div class="split-bar thin"><i class="po" style="width:<?= $pp ?>%"></i><i class="ecm" style="width:<?= 100 - $pp ?>%"></i></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><h3>By unit</h3><span>Where spend was booked</span></div>
    <div class="card-b"><?php if ($unitRows): ?><canvas id="booksUnit" height="160"></canvas><?php else: ?><p class="muted">No unit spend in this period.</p><?php endif; ?></div>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-h">
    <h3>Event books</h3>
    <span>Every programme with approved Purchase or ECM in this period</span>
  </div>
  <div class="card-b table-wrap">
    <?php if (!$eventRows): ?>
      <div class="empty"><h4>No approved spend</h4><p>Widen the dates, or book expenses as Purchase (PO/WO) or ECM on an event.</p></div>
    <?php else: ?>
    <table class="data">
      <thead>
        <tr>
          <th>Event</th><th>Unit</th><th>Dates</th>
          <th class="num">Purchase</th><th class="num">ECM</th><th class="num">Total</th>
          <th class="num">Paid</th><th class="num">Unpaid</th><th>Mix</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($eventRows as $ev):
          $tot = (float) $ev['total'];
          $pp = $tot > 0 ? (int) round((float) $ev['purchase'] / $tot * 100) : 0;
          $unpaid = max(0, $tot - (float) $ev['paid']); ?>
        <tr>
          <td><a href="event.php?id=<?= (int) $ev['id'] ?>"><strong><?= e($ev['code']) ?></strong></a><div class="muted"><?= e($ev['title']) ?></div></td>
          <td><span class="badge unit"><?= e($ev['unit_code'] ?? '') ?></span></td>
          <td><?= e(dmy($ev['start_date'])) ?><?= $ev['end_date'] !== $ev['start_date'] ? ' – ' . e(dmy($ev['end_date'])) : '' ?></td>
          <td class="num"><?= money($ev['purchase']) ?></td>
          <td class="num"><?= money($ev['ecm']) ?></td>
          <td class="num"><strong><?= money($tot) ?></strong></td>
          <td class="num"><?= money($ev['paid']) ?></td>
          <td class="num" style="color:<?= $unpaid > 0 ? 'var(--coral)' : 'var(--ok)' ?>"><?= money($unpaid) ?></td>
          <td style="min-width:90px"><div class="split-bar thin"><i class="po" style="width:<?= $pp ?>%"></i><i class="ecm" style="width:<?= 100 - $pp ?>%"></i></div></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td colspan="3"><strong>Total</strong></td>
        <td class="num"><strong><?= money($poAmt) ?></strong></td>
        <td class="num"><strong><?= money($ecmAmt) ?></strong></td>
        <td class="num"><strong><?= money($totalAmt) ?></strong></td>
        <td class="num"><strong><?= money($poPaid + $ecmPaid) ?></strong></td>
        <td class="num"><strong><?= money($poUnpaid + $ecmUnpaid) ?></strong></td>
        <td></td>
      </tr>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-h">
      <h3>Latest Purchase lines</h3>
      <a class="muted" href="expenses.php?book=purchase&from=<?= e($from) ?>&to=<?= e($to) ?><?= e($unitQs) ?>">Full ledger</a>
    </div>
    <div class="card-b table-wrap">
      <?php if (!$poLines): ?>
        <p class="muted">No Purchase (PO / WO) lines in this period.</p>
      <?php else: ?>
      <table class="data">
        <thead><tr><th>Date</th><th>Event</th><th>Item</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($poLines as $ex): ?>
          <tr>
            <td><?= e(dmy($ex['expense_date'])) ?></td>
            <td><a href="event.php?id=<?= (int) $ex['event_id'] ?>"><?= e($ex['code']) ?></a></td>
            <td><?= e($ex['title']) ?><div class="muted"><?= e(expense_ref($ex)) ?></div></td>
            <td class="num"><?= money($ex['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-h">
      <h3>Latest ECM lines</h3>
      <a class="muted" href="expenses.php?book=ecm&from=<?= e($from) ?>&to=<?= e($to) ?><?= e($unitQs) ?>">Full ledger</a>
    </div>
    <div class="card-b table-wrap">
      <?php if (!$ecmLines): ?>
        <p class="muted">No ECM lines in this period.</p>
      <?php else: ?>
      <table class="data">
        <thead><tr><th>Date</th><th>Event</th><th>Item</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($ecmLines as $ex): ?>
          <tr>
            <td><?= e(dmy($ex['expense_date'])) ?></td>
            <td><a href="event.php?id=<?= (int) $ex['event_id'] ?>"><?= e($ex['code']) ?></a></td>
            <td><?= e($ex['title']) ?><div class="muted"><?= e(expense_ref($ex)) ?></div></td>
            <td class="num"><?= money($ex['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
<?php if ($monthLabels): ?>
new Chart(document.getElementById('booksMonth'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($monthLabels) ?>,
    datasets: [
      { label: 'Purchase', data: <?= json_encode($monthPo) ?>, backgroundColor: '#1b6e64', borderRadius: 6, stack: 's' },
      { label: 'ECM', data: <?= json_encode($monthEcm) ?>, backgroundColor: '#c4a35a', borderRadius: 6, stack: 's' }
    ]
  },
  options: {
    plugins: { legend: { position: 'bottom' } },
    scales: {
      x: { stacked: true },
      y: { stacked: true, ticks: { callback: v => '₹' + Number(v).toLocaleString('en-IN') } }
    }
  }
});
<?php endif; ?>
<?php if ($totalAmt > 0): ?>
new Chart(document.getElementById('booksShare'), {
  type: 'doughnut',
  data: {
    labels: ['Purchase (PO / WO)', 'ECM'],
    datasets: [{ data: [<?= json_encode($poAmt) ?>, <?= json_encode($ecmAmt) ?>], backgroundColor: ['#1b6e64', '#c4a35a'], borderWidth: 0 }]
  },
  options: { plugins: { legend: { position: 'bottom' } }, cutout: '62%' }
});
<?php endif; ?>
<?php if ($unitRows): ?>
new Chart(document.getElementById('booksUnit'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($unitRows, 'unit_code')) ?>,
    datasets: [
      { label: 'Purchase', data: <?= json_encode(array_map('floatval', array_column($unitRows, 'purchase'))) ?>, backgroundColor: '#1b6e64', borderRadius: 6 },
      { label: 'ECM', data: <?= json_encode(array_map('floatval', array_column($unitRows, 'ecm'))) ?>, backgroundColor: '#c4a35a', borderRadius: 6 }
    ]
  },
  options: {
    plugins: { legend: { position: 'bottom' } },
    scales: { y: { ticks: { callback: v => '₹' + Number(v).toLocaleString('en-IN') } } }
  }
});
<?php endif; ?>
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
