<?php
require __DIR__ . '/includes/init.php';
require_can('reports');

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

$eventId = (int) query('event');
$book = (string) query('book');
if (!in_array($book, ['purchase', 'ecm'], true)) {
    $book = '';
}
$outstandingOnly = query('money') === 'outstanding';

$pickParams = [];
$pickSql = 'SELECT id, code, title, start_date, unit_code FROM events e WHERE 1=1' . unit_where('e', $pickParams) . ' ORDER BY e.start_date DESC, e.code DESC';
$pickSt = $pdo->prepare($pickSql);
$pickSt->execute($pickParams);
$eventChoices = $pickSt->fetchAll();

if ($eventId > 0) {
    $st = $pdo->prepare(
        'SELECT e.*,
            (SELECT COUNT(*) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> "cancelled") sponsor_count
         FROM events e WHERE e.id = ?'
    );
    $st->execute([$eventId]);
    $picked = $st->fetch();
    if (!$picked) {
        flash('err', 'Event not found.');
        redirect('reports.php');
    }
    deny_other_unit($picked);
    $events = [$picked];
} else {
    $evSql = 'SELECT e.*,
        (SELECT COUNT(*) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> "cancelled") sponsor_count
      FROM events e
      WHERE e.start_date <= ? AND e.end_date >= ?';
    $evParams = [$to, $from];
    $evSql .= unit_where('e', $evParams);
    $evSql .= ' ORDER BY e.start_date, e.code';
    $st = $pdo->prepare($evSql);
    $st->execute($evParams);
    $events = $st->fetchAll();
}

$rows = [];
$totSpend = $totPromised = $totReceived = $totTarget = 0.0;
$eventIds = [];
foreach ($events as $ev) {
    $t = event_totals((int) $ev['id']);
    $unsponsored = event_is_unsponsored($ev);
    $promised = $unsponsored ? 0.0 : (float) $t['promised'];
    $received = $unsponsored ? 0.0 : (float) $t['received'];
    $captured = $unsponsored ? 0.0 : (float) $t['captured'];
    if ($book === 'purchase') {
        $spend = (float) $t['purchase_expenses'];
    } elseif ($book === 'ecm') {
        $spend = (float) $t['ecm_expenses'];
    } else {
        $spend = (float) $t['expenses'];
    }
    $gap = $unsponsored ? 0.0 : max(0, $captured - $received);
    if ($outstandingOnly && ($unsponsored || $gap <= 0.009)) {
        continue;
    }
    $net = $unsponsored ? (0 - $spend) : ($received - $spend);
    $rows[] = [
        'event' => $ev,
        'totals' => $t,
        'unsponsored' => $unsponsored,
        'funding' => funding_label($ev, (int) $ev['sponsor_count']),
        'promised' => $promised,
        'received' => $received,
        'captured' => $captured,
        'spend' => $spend,
        'gap' => $gap,
        'net' => $net,
    ];
    $eventIds[] = (int) $ev['id'];
    $totSpend += $spend;
    $totPromised += $promised;
    $totReceived += $received;
    $totTarget += $captured;
}

$cats = [];
$sponsors = [];
$expenseLines = [];
$outstandingLines = [];
if ($eventIds) {
    $in = implode(',', array_fill(0, count($eventIds), '?'));
    $catSql = "SELECT c.id, c.name, c.color, COALESCE(SUM(x.amount),0) total
         FROM expense_categories c
         LEFT JOIN expenses x ON x.category_id = c.id
            AND x.event_id IN ($in)
            AND x.deleted_at IS NULL
            AND x.approval_status = 'approved'";
    $catParams = $eventIds;
    if ($book !== '') {
        $catSql .= ' AND x.booking_type = ?';
        $catParams[] = $book;
    }
    $catSql .= ' GROUP BY c.id HAVING total > 0 ORDER BY total DESC';
    $catSt = $pdo->prepare($catSql);
    $catSt->execute($catParams);
    $cats = $catSt->fetchAll();

    if ($eventId > 0 || $outstandingOnly) {
        $spSql = "SELECT sp.name, e.code event_code, e.title event_title,
                    SUM(s.promised_amount) promised,
                    COALESCE(SUM(r.received), 0) received
             FROM sponsorships s
             JOIN sponsors sp ON sp.id = s.sponsor_id
             JOIN events e ON e.id = s.event_id
             LEFT JOIN (
                SELECT sponsorship_id, SUM(amount) received
                FROM sponsorship_receipts
                GROUP BY sponsorship_id
             ) r ON r.sponsorship_id = s.id
             WHERE s.status <> 'cancelled' AND s.event_id IN ($in)
             GROUP BY sp.id, sp.name, e.id, e.code, e.title";
    } else {
        $spSql = "SELECT sp.name, '' event_code, '' event_title,
                    SUM(s.promised_amount) promised,
                    COALESCE(SUM(r.received), 0) received
             FROM sponsorships s
             JOIN sponsors sp ON sp.id = s.sponsor_id
             LEFT JOIN (
                SELECT sponsorship_id, SUM(amount) received
                FROM sponsorship_receipts
                GROUP BY sponsorship_id
             ) r ON r.sponsorship_id = s.id
             WHERE s.status <> 'cancelled' AND s.event_id IN ($in)
             GROUP BY sp.id, sp.name";
    }
    if ($outstandingOnly) {
        $spSql .= ' HAVING (SUM(s.promised_amount) - COALESCE(SUM(r.received), 0)) > 0.009';
    }
    $spSql .= ' ORDER BY promised DESC';
    $spSt = $pdo->prepare($spSql);
    $spSt->execute($eventIds);
    $sponsors = $spSt->fetchAll();

    if ($outstandingOnly) {
        $outSt = $pdo->prepare(
            "SELECT e.code, e.title event_title, e.unit_code, sp.name sponsor_name, s.promised_amount,
                    COALESCE(r.received, 0) received,
                    (s.promised_amount - COALESCE(r.received, 0)) outstanding
             FROM sponsorships s
             JOIN sponsors sp ON sp.id = s.sponsor_id
             JOIN events e ON e.id = s.event_id
             LEFT JOIN (
                SELECT sponsorship_id, SUM(amount) received
                FROM sponsorship_receipts
                GROUP BY sponsorship_id
             ) r ON r.sponsorship_id = s.id
             WHERE s.status <> 'cancelled'
               AND s.event_id IN ($in)
               AND (s.promised_amount - COALESCE(r.received, 0)) > 0.009
             ORDER BY outstanding DESC, e.code"
        );
        $outSt->execute($eventIds);
        $outstandingLines = $outSt->fetchAll();
    }

    if ($eventId > 0 || $book !== '') {
        $lineSql = "SELECT x.*, c.name cat, ev.code, ev.title event_title
             FROM expenses x
             JOIN expense_categories c ON c.id = x.category_id
             JOIN events ev ON ev.id = x.event_id
             WHERE x.deleted_at IS NULL AND x.approval_status = 'approved' AND x.event_id IN ($in)";
        $lineParams = $eventIds;
        if ($book !== '') {
            $lineSql .= ' AND x.booking_type = ?';
            $lineParams[] = $book;
        }
        $lineSql .= ' ORDER BY ev.code, x.expense_date, x.id LIMIT 2000';
        $lineSt = $pdo->prepare($lineSql);
        $lineSt->execute($lineParams);
        $expenseLines = $lineSt->fetchAll();
    }
}

$unitLabel = active_unit_filter() ?: 'All units';
$hospital = setting('hospital_name', 'Hospital');
$city = setting('hospital_city', '');

$scopeBits = [];
if ($eventId > 0 && $rows) {
    $scopeBits[] = $rows[0]['event']['code'] . ' · ' . $rows[0]['event']['title'];
}
if ($book === 'purchase') {
    $scopeBits[] = 'Purchase (PO / WO) only';
} elseif ($book === 'ecm') {
    $scopeBits[] = 'ECM only';
}
if ($outstandingOnly) {
    $scopeBits[] = 'Outstanding sponsorship only';
}
$reportTitle = $outstandingOnly
    ? 'Outstanding sponsorship report'
    : ($book === 'purchase' ? 'Purchase expense report' : ($book === 'ecm' ? 'ECM expense report' : 'Event sponsorship report'));
$periodLabel = $eventId > 0 && $rows
    ? (dmy($rows[0]['event']['start_date']) . ($rows[0]['event']['end_date'] !== $rows[0]['event']['start_date'] ? ' – ' . dmy($rows[0]['event']['end_date']) : ''))
    : (dmy($from) . ' – ' . dmy($to));
$spendLabel = $book === 'purchase' ? 'Approved PO / WO spend' : ($book === 'ecm' ? 'Approved ECM spend' : 'Approved spend');
$scopeText = $scopeBits ? implode(' · ', $scopeBits) : 'All programmes in period';

$filterQs = http_build_query(array_filter([
    'from' => $from,
    'to' => $to,
    'unit' => query('unit') ?: null,
    'event' => $eventId ?: null,
    'book' => $book ?: null,
    'money' => $outstandingOnly ? 'outstanding' : null,
], static fn($v) => $v !== null && $v !== ''));
$unitQs = query('unit') ? '&unit=' . urlencode((string) query('unit')) : '';

$exportPayload = [
    'hospital' => $hospital,
    'city' => $city,
    'from' => $from,
    'to' => $to,
    'unit' => $unitLabel,
    'rows' => $rows,
    'sponsors' => $sponsors,
    'cats' => $cats,
    'totPromised' => $totPromised,
    'totReceived' => $totReceived,
    'totSpend' => $totSpend,
    'totTarget' => $totTarget,
    'report_title' => $reportTitle,
    'period_label' => $periodLabel,
    'scope' => $scopeText,
    'spend_label' => $spendLabel,
    'lines' => $expenseLines,
    'outstanding_lines' => $outstandingLines,
    'book' => $book,
];

if (query('export') === 'xls') {
    require __DIR__ . '/includes/excel_report.php';
    download_eventgrant_excel($exportPayload);
}

if (query('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    $file = 'eventgrant-report';
    if ($eventId && $rows) {
        $file .= '-' . preg_replace('/[^A-Za-z0-9\-]/', '', $rows[0]['event']['code']);
    }
    if ($book) {
        $file .= '-' . $book;
    }
    if ($outstandingOnly) {
        $file .= '-outstanding';
    }
    header('Content-Disposition: attachment; filename="' . $file . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [$reportTitle, $scopeText, $periodLabel]);
    fputcsv($out, ['Unit', 'Code', 'Event', 'Type', 'Status', 'Funding', 'Start', 'End', 'Sponsorship', 'Promised', 'Received', 'Still to collect', 'Spend', 'Net (received − spend)']);
    foreach ($rows as $row) {
        $ev = $row['event'];
        fputcsv($out, [
            $ev['unit_code'] ?? '',
            $ev['code'],
            $ev['title'],
            $ev['event_type'],
            $ev['status'],
            $row['funding']['label'],
            $ev['start_date'],
            $ev['end_date'],
            $row['unsponsored'] ? '' : $row['captured'],
            $row['unsponsored'] ? '' : $row['promised'],
            $row['unsponsored'] ? '' : $row['received'],
            $row['unsponsored'] ? '' : $row['gap'],
            $row['spend'],
            $row['net'],
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['TOTAL', '', '', '', '', '', '', '', $totTarget, $totPromised, $totReceived, max(0, $totTarget - $totReceived), $totSpend, $totReceived - $totSpend]);
    if ($outstandingLines) {
        fputcsv($out, []);
        fputcsv($out, ['Outstanding sponsorships']);
        fputcsv($out, ['Event', 'Title', 'Sponsor', 'Promised', 'Received', 'Outstanding']);
        foreach ($outstandingLines as $o) {
            fputcsv($out, [$o['code'], $o['event_title'], $o['sponsor_name'], $o['promised_amount'], $o['received'], $o['outstanding']]);
        }
    }
    if ($expenseLines) {
        fputcsv($out, []);
        fputcsv($out, [$book === 'ecm' ? 'ECM lines' : ($book === 'purchase' ? 'Purchase lines' : 'Approved expense lines')]);
        fputcsv($out, ['Event', 'Date', 'Item', 'Booked as', 'Ref', 'Category', 'Vendor', 'Amount']);
        foreach ($expenseLines as $ex) {
            fputcsv($out, [
                $ex['code'],
                $ex['expense_date'],
                $ex['title'],
                ($ex['booking_type'] ?? '') === 'ecm' ? 'ECM' : 'Purchase',
                expense_ref($ex),
                $ex['cat'],
                $ex['vendor'] ?? '',
                $ex['amount'],
            ]);
        }
    }
    fclose($out);
    exit;
}

$agingBuckets = ['Not yet due' => 0.0, '0–30d late' => 0.0, '31–60d late' => 0.0, '61–90d late' => 0.0, '90d+ late' => 0.0];
$turnaround = null;
$unitCompare = [];
if ($eventIds) {
    $in = implode(',', array_fill(0, count($eventIds), '?'));
    $grace = collection_grace_days();
    $st = $pdo->prepare(
        "SELECT s.promised_amount, ev.end_date,
                (s.promised_amount - COALESCE((SELECT SUM(r.amount) FROM sponsorship_receipts r WHERE r.sponsorship_id = s.id),0)) outstanding
         FROM sponsorships s JOIN events ev ON ev.id = s.event_id
         WHERE s.status IN ('promised','partial') AND s.event_id IN ({$in})"
    );
    $st->execute($eventIds);
    foreach ($st->fetchAll() as $row) {
        $outstanding = (float) $row['outstanding'];
        if ($outstanding <= 0.009) {
            continue;
        }
        $daysLate = (int) floor((time() - strtotime($row['end_date'] . " +{$grace} days")) / 86400);
        if ($daysLate <= 0) {
            $agingBuckets['Not yet due'] += $outstanding;
        } elseif ($daysLate <= 30) {
            $agingBuckets['0–30d late'] += $outstanding;
        } elseif ($daysLate <= 60) {
            $agingBuckets['31–60d late'] += $outstanding;
        } elseif ($daysLate <= 90) {
            $agingBuckets['61–90d late'] += $outstanding;
        } else {
            $agingBuckets['90d+ late'] += $outstanding;
        }
    }

    $st = $pdo->prepare(
        "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, approved_at)) avg_h, COUNT(*) n
         FROM expenses WHERE event_id IN ({$in}) AND approval_status = 'approved' AND approved_at IS NOT NULL AND deleted_at IS NULL"
    );
    $st->execute($eventIds);
    $tr = $st->fetch();
    if ($tr && (int) $tr['n'] > 0) {
        $turnaround = ['hours' => (float) $tr['avg_h'], 'n' => (int) $tr['n']];
    }

    if (can_see_all_units() && !active_unit_filter()) {
        foreach ($rows as $r) {
            $uc = $r['event']['unit_code'] ?? '—';
            if (!isset($unitCompare[$uc])) {
                $unitCompare[$uc] = ['promised' => 0.0, 'received' => 0.0, 'spend' => 0.0];
            }
            if (!$r['unsponsored']) {
                $unitCompare[$uc]['promised'] += $r['promised'];
                $unitCompare[$uc]['received'] += $r['received'];
            }
            $unitCompare[$uc]['spend'] += $r['spend'];
        }
        ksort($unitCompare);
    }
}

$pageTitle = 'Reports';
$pageCrumb = $scopeText . ' · ' . $periodLabel . (active_unit_filter() ? ' · ' . active_unit_filter() : '');
$active = 'reports';
require __DIR__ . '/includes/header.php';
render_unit_pills('reports.php');
?>
<form class="filters no-print" method="get">
  <?php if (query('unit')): ?><input type="hidden" name="unit" value="<?= e((string) query('unit')) ?>"><?php endif; ?>
  <div class="field grow"><label>Event</label>
    <select name="event">
      <option value="0">All programmes in the dates below</option>
      <?php foreach ($eventChoices as $ev): ?>
        <option value="<?= (int) $ev['id'] ?>" <?= $eventId === (int) $ev['id'] ? 'selected' : '' ?>>
          <?= e($ev['code']) ?> · <?= e($ev['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>Spend</label>
    <select name="book">
      <option value="">All (PO / WO + ECM)</option>
      <option value="purchase" <?= $book === 'purchase' ? 'selected' : '' ?>>Purchase only</option>
      <option value="ecm" <?= $book === 'ecm' ? 'selected' : '' ?>>ECM only</option>
    </select>
  </div>
  <div class="field"><label>Sponsorship</label>
    <select name="money">
      <option value="">All promises</option>
      <option value="outstanding" <?= $outstandingOnly ? 'selected' : '' ?>>Outstanding only</option>
    </select>
  </div>
  <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-ghost" type="submit">Apply</button>
  <a class="btn btn-ghost" href="reports.php?preset=fy<?= e($unitQs) ?>">This FY</a>
  <a class="btn btn-ghost" href="reports.php?preset=cal<?= e($unitQs) ?>">This year</a>
  <a class="btn btn-ghost" href="reports.php?preset=all<?= e($unitQs) ?>">All events</a>
  <a class="btn btn-teal" href="?<?= e($filterQs) ?>&export=xls">Download Excel</a>
  <a class="btn btn-ghost" href="?<?= e($filterQs) ?>&export=csv">Plain CSV</a>
  <button class="btn btn-ghost" type="button" onclick="window.print()">Print / PDF</button>
</form>

<div class="grid-2 no-print" style="margin-bottom:16px">
  <div class="card">
    <div class="card-h"><h3>Collection aging</h3><span>Outstanding sponsorship, by how late it is</span></div>
    <div class="card-b">
      <?php if (array_sum($agingBuckets) <= 0.009): ?>
        <p class="muted">Nothing outstanding in this selection.</p>
      <?php else: ?>
        <canvas id="agingChart" height="150"></canvas>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><h3>Expense approval turnaround</h3><span>Time from booking to finance approval</span></div>
    <div class="card-b">
      <?php if (!$turnaround): ?>
        <p class="muted">No approved expenses with a recorded approval time in this selection.</p>
      <?php else: ?>
        <div class="kpi ok" style="max-width:280px">
          <div class="label">Average turnaround</div>
          <div class="value"><?= $turnaround['hours'] < 48 ? round($turnaround['hours']) . ' hrs' : round($turnaround['hours'] / 24, 1) . ' days' ?></div>
          <div class="hint">Across <?= $turnaround['n'] ?> approved expense<?= $turnaround['n'] === 1 ? '' : 's' ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if ($unitCompare): ?>
<div class="card no-print" style="margin-bottom:16px">
  <div class="card-h"><h3>Unit comparison</h3><span>Promised, received and spend across units for this period</span></div>
  <div class="card-b"><canvas id="unitCompareChart" height="110"></canvas></div>
</div>
<?php endif; ?>

<div class="report-doc">
  <div class="report-head">
    <div>
      <p class="report-kicker"><?= e($hospital) ?><?= $city ? ' · ' . e($city) : '' ?></p>
      <p class="report-conf">Confidential — hospital finance and administration</p>
      <h3><?= e($reportTitle) ?></h3>
      <p class="muted"><?= e($scopeText) ?>. <?= $book === '' ? 'Spend is approved PO / ECM only.' : 'Spend is approved ' . ($book === 'ecm' ? 'ECM' : 'Purchase') . ' lines only.' ?> Dates apply when no single event is selected.</p>
    </div>
    <div class="report-period">
      <span><?= $eventId > 0 ? 'Event dates' : 'Period' ?></span>
      <strong><?= e($periodLabel) ?></strong>
      <span><?= count($rows) ?> programme<?= count($rows) === 1 ? '' : 's' ?><?= active_unit_filter() ? ' · ' . e(active_unit_filter()) : ' · all units' ?></span>
    </div>
  </div>

  <div class="kpis">
    <div class="kpi"><div class="label">Programmes</div><div class="value"><?= count($rows) ?></div><div class="hint"><?= $outstandingOnly ? 'With money still to collect' : ($eventId > 0 ? 'Selected event' : 'Events whose dates fall in this period') ?></div></div>
    <div class="kpi brass"><div class="label">Promised</div><div class="value"><?= money($totPromised) ?></div><div class="hint">Sponsorship committed by companies</div></div>
    <div class="kpi ok"><div class="label">Received</div><div class="value"><?= money($totReceived) ?></div><div class="hint">Money actually collected</div></div>
    <div class="kpi teal"><div class="label"><?= e($spendLabel) ?></div><div class="value"><?= money($totSpend) ?></div><div class="hint"><?= $book === 'ecm' ? 'ECM lines that count in the tracker' : ($book === 'purchase' ? 'PO / WO lines that count in the tracker' : 'PO / ECM lines that count in the tracker') ?></div></div>
  </div>
  <div class="kpis" style="grid-template-columns:repeat(3,1fr)">
    <div class="kpi"><div class="label">Still to collect</div><div class="value"><?= money(max(0, $totTarget - $totReceived)) ?></div><div class="hint">Sponsorship amount minus receipts</div></div>
    <div class="kpi"><div class="label">Net (received − spend)</div><div class="value" style="color:<?= ($totReceived - $totSpend) >= 0 ? 'var(--ok)' : 'var(--coral)' ?>"><?= money($totReceived - $totSpend) ?></div><div class="hint">Cash in minus <?= $book === '' ? 'approved spend' : ($book === 'ecm' ? 'ECM spend' : 'Purchase spend') ?></div></div>
    <div class="kpi"><div class="label">Collection</div><div class="value"><?= $totPromised > 0 ? round($totReceived / $totPromised * 100) . '%' : '—' ?></div><div class="hint">Received against promised</div></div>
  </div>

  <div class="card">
    <div class="card-h"><h3>Event ledger</h3><span><?= $outstandingOnly ? 'Programmes with a collection balance' : 'One line per programme' ?></span></div>
    <div class="card-b table-wrap">
      <?php if (!$rows): ?>
        <div class="empty"><h4>Nothing matches these filters</h4><p>Pick another event, widen the dates, or turn off Purchase / ECM / outstanding.</p></div>
      <?php else: ?>
      <table class="data">
        <thead>
          <tr>
            <th>Event</th>
            <th>Unit</th>
            <th>Dates</th>
            <th>Funding</th>
            <th class="num">Promised</th>
            <th class="num">Received</th>
            <th class="num">To collect</th>
            <th class="num">Spend</th>
            <th class="num">Net</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $ev = $row['event']; ?>
          <tr>
            <td>
              <a href="event.php?id=<?= (int) $ev['id'] ?>"><strong><?= e($ev['code']) ?></strong></a>
              <div class="muted"><?= e($ev['title']) ?></div>
            </td>
            <td><span class="badge unit"><?= e($ev['unit_code'] ?? '') ?></span></td>
            <td><?= e(dmy($ev['start_date'])) ?><?= $ev['end_date'] !== $ev['start_date'] ? ' – ' . e(dmy($ev['end_date'])) : '' ?>
              <div><span class="badge <?= status_class($ev['status']) ?>"><?= e(ucfirst($ev['status'])) ?></span></div>
            </td>
            <td><span class="badge <?= e($row['funding']['class']) ?>"><?= e($row['funding']['label']) ?></span></td>
            <td class="num"><?= $row['unsponsored'] ? '—' : money($row['promised']) ?></td>
            <td class="num"><?= $row['unsponsored'] ? '—' : money($row['received']) ?></td>
            <td class="num"><?= $row['unsponsored'] ? '—' : money($row['gap']) ?></td>
            <td class="num"><?= money($row['spend']) ?></td>
            <td class="num" style="color:<?= $row['net'] >= 0 ? 'var(--ok)' : 'var(--coral)' ?>"><?= money($row['net']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <td colspan="4"><strong>Total</strong></td>
          <td class="num"><strong><?= money($totPromised) ?></strong></td>
          <td class="num"><strong><?= money($totReceived) ?></strong></td>
          <td class="num"><strong><?= money(max(0, $totTarget - $totReceived)) ?></strong></td>
          <td class="num"><strong><?= money($totSpend) ?></strong></td>
          <td class="num"><strong><?= money($totReceived - $totSpend) ?></strong></td>
        </tr>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($outstandingLines): ?>
  <div class="card" style="margin-top:16px">
    <div class="card-h"><h3>Outstanding sponsorships</h3><span>Promises with money still to collect</span></div>
    <div class="card-b table-wrap">
      <table class="data">
        <thead><tr><th>Event</th><th>Sponsor</th><th class="num">Promised</th><th class="num">Received</th><th class="num">Outstanding</th></tr></thead>
        <tbody>
        <?php foreach ($outstandingLines as $o): ?>
          <tr>
            <td><strong><?= e($o['code']) ?></strong><div class="muted"><?= e($o['event_title']) ?> · <?= e($o['unit_code'] ?? '') ?></div></td>
            <td><?= e($o['sponsor_name']) ?></td>
            <td class="num"><?= money($o['promised_amount']) ?></td>
            <td class="num"><?= money($o['received']) ?></td>
            <td class="num"><?= money($o['outstanding']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($expenseLines): ?>
  <div class="card" style="margin-top:16px">
    <div class="card-h">
      <h3><?= $book === 'ecm' ? 'ECM lines' : ($book === 'purchase' ? 'Purchase lines' : 'Approved expenses') ?></h3>
      <span><?= count($expenseLines) ?> lines · <?= money($totSpend) ?></span>
    </div>
    <div class="card-b table-wrap">
      <table class="data">
        <thead><tr><th>Date</th><?php if ($eventId < 1): ?><th>Event</th><?php endif; ?><th>Item</th><th>Booked as</th><th>Category</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($expenseLines as $ex): ?>
          <tr>
            <td><?= e(dmy($ex['expense_date'])) ?></td>
            <?php if ($eventId < 1): ?>
              <td><?= e($ex['code']) ?><div class="muted"><?= e($ex['event_title']) ?></div></td>
            <?php endif; ?>
            <td><strong><?= e($ex['title']) ?></strong><div class="muted"><?= e($ex['vendor'] ?: '') ?></div></td>
            <td>
              <span class="badge <?= ($ex['booking_type'] ?? '') === 'ecm' ? 'warn' : 'info' ?>"><?= ($ex['booking_type'] ?? '') === 'ecm' ? 'ECM' : 'Purchase' ?></span>
              <div class="muted"><?= e(expense_ref($ex)) ?></div>
            </td>
            <td><?= e($ex['cat']) ?></td>
            <td class="num"><?= money_dec($ex['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid-2" style="margin-top:16px">
    <div class="card">
      <div class="card-h"><h3><?= $outstandingOnly ? 'Outstanding by company' : 'Collections by company' ?></h3><span><?= $outstandingOnly ? 'Balance still due' : 'Promised vs money received' ?></span></div>
      <div class="card-b table-wrap">
        <?php if (!$sponsors): ?>
          <p class="muted"><?= $outstandingOnly ? 'No outstanding promises on these programmes.' : 'No sponsor promises on these programmes.' ?></p>
        <?php else: ?>
        <table class="data">
          <thead><tr><th>Sponsor</th><?php if ($eventId < 1): ?><th>Event</th><?php endif; ?><th class="num">Promised</th><th class="num">Received</th><th class="num">Balance</th><th>Collected</th></tr></thead>
          <tbody>
          <?php foreach ($sponsors as $s):
              $p = (float) $s['promised'];
              $r = (float) $s['received'];
              $pct = $p > 0 ? min(100, round($r / $p * 100)) : 0; ?>
            <tr>
              <td><?= e($s['name']) ?></td>
              <?php if ($eventId < 1): ?><td class="muted"><?= e($s['event_code'] ?? '') ?></td><?php endif; ?>
              <td class="num"><?= money($p) ?></td>
              <td class="num"><?= money($r) ?></td>
              <td class="num"><?= money(max(0, $p - $r)) ?></td>
              <td>
                <div class="mini-bar"><span style="width:<?= $pct ?>%"></span></div>
                <span class="muted"><?= $pct ?>%</span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><h3>Spend by category</h3><span><?= e($spendLabel) ?></span></div>
      <div class="card-b table-wrap">
        <?php if (!$cats): ?>
          <p class="muted">No approved <?= $book === 'ecm' ? 'ECM' : ($book === 'purchase' ? 'Purchase' : '') ?> expenses on these programmes.</p>
        <?php else: ?>
        <table class="data">
          <thead><tr><th>Category</th><th class="num">Amount</th><th>Share</th></tr></thead>
          <tbody>
          <?php foreach ($cats as $c):
              $amt = (float) $c['total'];
              $pct = $totSpend > 0 ? round($amt / $totSpend * 100) : 0; ?>
            <tr>
              <td><?= e($c['name']) ?></td>
              <td class="num"><?= money($amt) ?></td>
              <td>
                <div class="mini-bar"><span style="width:<?= $pct ?>%;background:<?= e($c['color'] ?: 'var(--teal)') ?>"></span></div>
                <span class="muted"><?= $pct ?>%</span>
              </td>
            </tr>
          <?php endforeach; ?>
          <tr><td><strong>Total</strong></td><td class="num"><strong><?= money($totSpend) ?></strong></td><td></td></tr>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php if (array_sum($agingBuckets) > 0.009): ?>
<script>
new Chart(document.getElementById('agingChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_keys($agingBuckets)) ?>,
    datasets: [{ data: <?= json_encode(array_values(array_map('floatval', $agingBuckets))) ?>, backgroundColor: ['#3a6ea5', '#c4892a', '#c45c4a', '#a83f2e', '#7a2a1e'], borderRadius: 8 }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: v => '₹' + Number(v).toLocaleString('en-IN') } } } }
});
</script>
<?php endif; ?>
<?php if ($unitCompare): ?>
<script>
new Chart(document.getElementById('unitCompareChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_keys($unitCompare)) ?>,
    datasets: [
      { label: 'Promised', data: <?= json_encode(array_column($unitCompare, 'promised')) ?>, backgroundColor: '#c4a35a', borderRadius: 6 },
      { label: 'Received', data: <?= json_encode(array_column($unitCompare, 'received')) ?>, backgroundColor: '#2f7d5b', borderRadius: 6 },
      { label: 'Spend', data: <?= json_encode(array_column($unitCompare, 'spend')) ?>, backgroundColor: '#1b6e64', borderRadius: 6 }
    ]
  },
  options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { ticks: { callback: v => '₹' + Number(v).toLocaleString('en-IN') } } } }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
