<?php
declare(strict_types=1);

function approval_limit(): float
{
    return (float) setting('expense_approval_limit', '50000');
}

function log_expense_change(int $expenseId, int $eventId, string $action, string $details): void
{
    db()->prepare('INSERT INTO expense_history (expense_id, event_id, user_id, action, details) VALUES (?,?,?,?,?)')
        ->execute([$expenseId, $eventId, uid() ?: null, $action, $details]);
}

function event_row(int $eventId): array
{
    $st = db()->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$eventId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Event not found.');
    }
    return $row;
}

function assert_event_writable(array $event, bool $forReceipt = false): void
{
    $status = $event['status'] ?? '';
    if ($status === 'cancelled') {
        throw new RuntimeException('This event is cancelled. No further bookings or receipts.');
    }
    if ($status === 'completed' && !$forReceipt && !can('expenses.approve')) {
        throw new RuntimeException('This event is completed. Only finance can add or change expenses.');
    }
}

function assert_gstin(?string $gstin): void
{
    $gstin = strtoupper(trim((string) $gstin));
    if ($gstin === '') {
        return;
    }
    if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) {
        throw new RuntimeException('GSTIN must be a valid 15-character number.');
    }
}

function assert_unique_doc(string $field, ?string $value, int $ignoreId = 0): void
{
    $value = trim((string) $value);
    if ($value === '') {
        return;
    }
    $sql = "SELECT id, event_id FROM expenses WHERE {$field} = ? AND deleted_at IS NULL";
    $params = [$value];
    if ($ignoreId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $ignoreId;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    $hit = $st->fetch();
    if ($hit) {
        $labels = ['po_no' => 'PO', 'wo_no' => 'WO', 'ecm_no' => 'ECM'];
        $label = $labels[$field] ?? $field;
        throw new RuntimeException($label . ' ' . $value . ' is already used on another expense.');
    }
}

function assert_doc_date_against_event(array $event, string $docDate, string $label): void
{
    if ($docDate === '') {
        return;
    }
    $start = $event['start_date'] ?? $docDate;
    $end = $event['end_date'] ?? $docDate;
    $min = date('Y-m-d', strtotime($start . ' -180 days'));
    $max = date('Y-m-d', strtotime($end . ' +90 days'));
    if ($docDate < $min || $docDate > $max) {
        throw new RuntimeException($label . ' must fall between 180 days before the event and 90 days after it.');
    }
}

function collection_grace_days(): int
{
    $n = (int) setting('collection_grace_days', '30');
    return $n > 0 ? min(365, $n) : 30;
}

function event_is_complete(array $event): bool
{
    $status = (string) ($event['status'] ?? '');
    if ($status === 'cancelled') {
        return false;
    }
    if ($status === 'completed') {
        return true;
    }
    $end = (string) ($event['end_date'] ?? '');
    return $end !== '' && $end < date('Y-m-d');
}

function collection_clock(array $event, ?array $totals = null): array
{
    $totals ??= event_totals((int) $event['id']);
    $grace = collection_grace_days();
    $end = (string) ($event['end_date'] ?? '');
    $today = new DateTimeImmutable('today');
    $endDt = $end !== '' ? new DateTimeImmutable($end) : null;
    $dueDt = $endDt ? $endDt->modify('+' . $grace . ' days') : null;
    $outstanding = (float) ($totals['outstanding'] ?? 0);
    $captured = (float) ($totals['captured'] ?? 0);
    $received = (float) ($totals['received'] ?? 0);
    $completed = event_is_complete($event);
    $daysSinceEnd = ($endDt && $today >= $endDt) ? (int) $endDt->diff($today)->days : 0;
    $daysLeft = ($dueDt && $today <= $dueDt) ? (int) $today->diff($dueDt)->days : 0;
    $daysLate = ($dueDt && $today > $dueDt) ? (int) $dueDt->diff($today)->days : 0;
    $windowPct = $grace > 0 ? min(100, (int) round($daysSinceEnd / $grace * 100)) : 0;

    if (event_is_unsponsored($event)) {
        $state = 'na';
    } elseif (($event['status'] ?? '') === 'cancelled') {
        $state = 'cancelled';
    } elseif ($outstanding <= 0.009) {
        $state = ($captured > 0.009 || $received > 0.009) ? 'collected' : 'upcoming';
    } elseif (!$completed) {
        $state = 'upcoming';
    } elseif ($daysLate > 0) {
        $state = 'overdue';
    } else {
        $state = 'window';
    }

    return [
        'grace' => $grace,
        'end_date' => $end,
        'due_date' => $dueDt ? $dueDt->format('Y-m-d') : '',
        'completed' => $completed,
        'days_since_end' => $daysSinceEnd,
        'days_left' => $daysLeft,
        'days_late' => $daysLate,
        'window_pct' => $state === 'overdue' ? 100 : $windowPct,
        'outstanding' => $outstanding,
        'captured' => $captured,
        'received' => $received,
        'state' => $state,
    ];
}

function event_overspend_amount(array $event, ?array $totals = null): float
{
    $totals ??= event_totals((int) $event['id']);
    $spend = (float) ($totals['expenses'] ?? 0);
    if (event_is_unsponsored($event)) {
        $budget = (float) ($event['budget_estimate'] ?? 0);
        return $budget > 0 ? max(0, $spend - $budget) : 0.0;
    }
    $cap = (float) ($totals['captured'] ?? 0);
    return $cap > 0 ? max(0, $spend - $cap) : 0.0;
}

function event_flags(array $event, ?array $totals = null): array
{
    $totals ??= event_totals((int) $event['id']);
    $clock = collection_clock($event, $totals);
    $flags = [];
    $over = event_overspend_amount($event, $totals);
    if ($over > 0.009) {
        $flags[] = [
            'key' => 'overspent',
            'label' => 'Overspent',
            'class' => 'coral',
            'detail' => money($over) . ' above ' . (event_is_unsponsored($event) ? 'the hospital budget' : 'the sponsorship amount'),
        ];
    }
    if ($clock['state'] === 'overdue') {
        $d = (int) $clock['days_late'];
        $flags[] = [
            'key' => 'collection_late',
            'label' => 'Collection late · ' . $d . ' day' . ($d === 1 ? '' : 's'),
            'class' => 'coral',
            'detail' => money($clock['outstanding']) . ' not received within ' . $clock['grace'] . ' days of the event ending',
        ];
    }
    return $flags;
}

function render_event_flags(array $flags): void
{
    if (!$flags) {
        return;
    }
    echo '<div class="flag-row">';
    foreach ($flags as $f) {
        echo '<span class="flag flag-' . e((string) $f['class']) . '" title="' . e((string) $f['detail']) . '"><span class="flag-mark">⚑</span> ' . e((string) $f['label']) . '</span>';
    }
    echo '</div>';
}

function event_health(array $event, ?array $totals = null): array
{
    $totals ??= event_totals((int) $event['id']);
    if (($event['status'] ?? '') === 'cancelled') {
        return ['key' => 'cancelled', 'label' => 'Cancelled', 'class' => 'muted', 'reason' => 'Event cancelled'];
    }
    $clock = collection_clock($event, $totals);
    $over = event_overspend_amount($event, $totals);
    $overspent = $over > 0.009;
    $late = $clock['state'] === 'overdue';
    if ($overspent && $late) {
        return [
            'key' => 'overspent',
            'label' => 'Overspent · collection late',
            'class' => 'coral',
            'reason' => money($over) . ' above sponsorship · ' . money($clock['outstanding']) . ' still due after ' . $clock['grace'] . ' days',
        ];
    }
    if ($overspent) {
        $what = event_is_unsponsored($event) ? 'budget' : 'sponsorship';
        return ['key' => 'overspent', 'label' => 'Overspent', 'class' => 'coral', 'reason' => 'Approved spend exceeds ' . $what . ' by ' . money($over)];
    }
    if ($late) {
        return [
            'key' => 'overdue',
            'label' => $clock['days_late'] . ' day' . ($clock['days_late'] === 1 ? '' : 's') . ' late',
            'class' => 'coral',
            'reason' => money($clock['outstanding']) . ' still to collect · due within ' . $clock['grace'] . ' days of the event ending',
        ];
    }
    if ($clock['state'] === 'window') {
        return [
            'key' => 'watch',
            'label' => $clock['days_left'] . ' day' . ($clock['days_left'] === 1 ? '' : 's') . ' left to collect',
            'class' => 'warn',
            'reason' => money($clock['outstanding']) . ' due by ' . dmy($clock['due_date']),
        ];
    }
    if ($clock['state'] === 'upcoming' || ((float) ($totals['outstanding'] ?? 0) > 0.009)) {
        return ['key' => 'watch', 'label' => 'Collecting', 'class' => 'info', 'reason' => money($totals['outstanding']) . ' outstanding · ' . $clock['grace'] . ' days after the event ends'];
    }
    return ['key' => 'on_track', 'label' => 'On track', 'class' => 'ok', 'reason' => 'Sponsorship, spend and receipts in line'];
}

function overdue_collections(): array
{
    $grace = collection_grace_days();
    $sql = "SELECT s.id, s.event_id, s.promised_amount, s.promised_date, s.status,
                   ev.code, ev.title, ev.end_date, ev.unit_code, sp.name sponsor_name,
                   (s.promised_amount - COALESCE((SELECT SUM(r.amount) FROM sponsorship_receipts r WHERE r.sponsorship_id = s.id),0)) outstanding,
                   DATEDIFF(CURDATE(), DATE_ADD(ev.end_date, INTERVAL {$grace} DAY)) days_late
            FROM sponsorships s
            JOIN events ev ON ev.id = s.event_id
            JOIN sponsors sp ON sp.id = s.sponsor_id
            WHERE s.status IN ('promised','partial')
              AND ev.status <> 'cancelled'
              AND ev.end_date < DATE_SUB(CURDATE(), INTERVAL {$grace} DAY)
              AND (s.promised_amount - COALESCE((SELECT SUM(r.amount) FROM sponsorship_receipts r WHERE r.sponsorship_id = s.id),0)) > 0.009";
    $params = [];
    $sql .= unit_where('ev', $params);
    $sql .= ' ORDER BY days_late DESC, outstanding DESC LIMIT 12';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function overdue_bucket(string $endDate): string
{
    $grace = collection_grace_days();
    $days = (int) floor((time() - strtotime($endDate)) / 86400);
    $late = $days - $grace;
    if ($late < 1) {
        return max(0, $grace - $days) . 'd left';
    }
    return $late . 'd late';
}

function overspent_events(): array
{
    $sql = "SELECT e.id, e.code, e.title, e.end_date, e.unit_code, e.funding_mode, e.budget_estimate, e.sponsorship_target, e.status,
                   COALESCE((SELECT SUM(x.amount) FROM expenses x WHERE x.event_id = e.id AND x.deleted_at IS NULL AND x.approval_status = 'approved'),0) expenses,
                   COALESCE((SELECT SUM(s.promised_amount) FROM sponsorships s WHERE s.event_id = e.id AND s.status <> 'cancelled'),0) promised
            FROM events e
            WHERE e.status <> 'cancelled'";
    $params = [];
    $sql .= unit_where('e', $params);
    $sql .= ' ORDER BY e.end_date DESC';
    $st = db()->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $captured = event_is_unsponsored($row) ? (float) $row['budget_estimate'] : sponsorship_captured((float) $row['sponsorship_target'], (float) $row['promised']);
        $spend = (float) $row['expenses'];
        if ($captured > 0 && $spend > $captured + 0.009) {
            $row['captured'] = $captured;
            $row['over_by'] = $spend - $captured;
            $out[] = $row;
        }
        if (count($out) >= 12) {
            break;
        }
    }
    return $out;
}

function is_on_flag($value): bool
{
    if (is_array($value)) {
        foreach ($value as $part) {
            if (is_on_flag($part)) {
                return true;
            }
        }
        return false;
    }
    $v = strtolower(trim((string) $value));
    return in_array($v, ['1', 'on', 'yes', 'true', 'y'], true);
}

function record_event_expense(int $eventId, array $post, ?string $billPath = null, ?int $expenseId = null): int
{
    $event = event_row($eventId);
    assert_event_writable($event);
    assert_unit_access($event);

    $type = ($post['booking_type'] ?? 'purchase') === 'ecm' ? 'ecm' : 'purchase';
    $title = trim((string) ($post['title'] ?? ''));
    $amount = (float) ($post['amount'] ?? 0);
    $categoryId = (int) ($post['category_id'] ?? 0);
    if ($categoryId < 1) {
        throw new RuntimeException('Choose a category for this expense.');
    }
    if ($title === '' || $amount <= 0) {
        throw new RuntimeException('Item description and amount are required.');
    }
    $po = trim((string) ($post['po_no'] ?? ''));
    $wo = trim((string) ($post['wo_no'] ?? ''));
    $orderDate = (string) ($post['order_date'] ?? '');
    $ecmNo = trim((string) ($post['ecm_no'] ?? ''));
    $ecmDate = (string) ($post['ecm_date'] ?? '');
    $claimant = trim((string) ($post['claimant'] ?? ''));
    $gstin = strtoupper(trim((string) ($post['vendor_gstin'] ?? '')));
    assert_gstin($gstin);

    if ($type === 'purchase') {
        if ($po === '' && $wo === '') {
            throw new RuntimeException('Enter a PO number or a WO number for a purchase expense.');
        }
        if ($orderDate === '') {
            throw new RuntimeException('PO / WO date is required.');
        }
        if (trim((string) ($post['vendor'] ?? '')) === '') {
            throw new RuntimeException('Vendor is required for a purchase expense.');
        }
        assert_doc_date_against_event($event, $orderDate, 'PO / WO date');
        $expenseDate = $orderDate;
        $ecmNo = $ecmDate = $claimant = null;
        $ecmApprovedBy = null;
    } else {
        if ($ecmNo === '' || $ecmDate === '') {
            throw new RuntimeException('ECM number and ECM date are required.');
        }
        assert_doc_date_against_event($event, $ecmDate, 'ECM date');
        $expenseDate = $ecmDate;
        $po = $wo = $orderDate = null;
        $ecmApprovedBy = trim((string) ($post['ecm_approved_by'] ?? '')) ?: null;
    }

    assert_unique_doc('ecm_no', $ecmNo, $expenseId ?? 0);

    $status = $post['payment_status'] ?? 'unpaid';
    if (!in_array($status, ['unpaid', 'partial', 'paid'], true)) {
        $status = 'unpaid';
    }
    $paid = (float) ($post['paid_amount'] ?? 0);
    if ($paid > $amount + 0.009) {
        throw new RuntimeException('Paid amount cannot be more than the expense amount.');
    }
    if ($status === 'paid' && $paid <= 0) {
        $paid = $amount;
    }
    if ($paid + 0.009 >= $amount) {
        $status = 'paid';
        $paid = $amount;
    }

    $old = null;
    if ($expenseId) {
        $st = db()->prepare('SELECT * FROM expenses WHERE id = ? AND event_id = ? AND deleted_at IS NULL');
        $st->execute([$expenseId, $eventId]);
        $old = $st->fetch();
        if (!$old) {
            throw new RuntimeException('Expense not found.');
        }
        if ($old['approval_status'] === 'approved' && !can('expenses.approve')) {
            throw new RuntimeException('Approved expenses can only be changed by finance.');
        }
    }

    $limit = approval_limit();
    $autoApprove = can('expenses.approve') || $amount <= $limit;
    $approval = $autoApprove ? 'approved' : 'pending';
    if ($old && $old['approval_status'] === 'approved' && can('expenses.approve')) {
        $approval = 'approved';
    } elseif ($old && !can('expenses.approve')) {
        $approval = $autoApprove ? 'approved' : 'pending';
    }

    $totals = event_totals($eventId);
    $oldApprovedAmt = ($old && $old['approval_status'] === 'approved') ? (float) $old['amount'] : 0.0;
    $cap = event_is_unsponsored($event) ? (float) $event['budget_estimate'] : $totals['captured'];
    $confirm = is_on_flag($post['confirm_overspend'] ?? null);
    $projected = $totals['expenses'] - $oldApprovedAmt + ($approval === 'approved' ? $amount : 0);
    if ($cap > 0 && $projected > $cap + 0.009) {
        $what = event_is_unsponsored($event) ? 'budget' : 'sponsorship amount';
        if (can('expenses.approve') && $confirm) {
            // Finance ticked Allow overspend — book it.
        } elseif (can('expenses.approve')) {
            throw new RuntimeException('This takes approved spend over the ' . $what . ' (' . money($cap) . '). Choose “Allow overspend”, or reduce the amount.');
        } else {
            $approval = 'pending';
        }
    }

    $fields = [
        $eventId,
        (int) ($post['category_id'] ?? 0),
        $type,
        $title,
        trim((string) ($post['vendor'] ?? '')) ?: null,
        $amount,
        $expenseDate,
        $status,
        $paid,
        ($post['payment_mode'] ?? '') !== '' ? $post['payment_mode'] : null,
        trim((string) ($post['invoice_no'] ?? '')) ?: null,
        $po ?: null,
        $wo ?: null,
        $orderDate ?: null,
        $gstin !== '' ? $gstin : null,
        $ecmNo ?: null,
        $ecmDate ?: null,
        $claimant ?: null,
        $type === 'ecm' ? $ecmApprovedBy : null,
        $billPath ?? ($old['bill_path'] ?? null),
        trim((string) ($post['notes'] ?? '')) ?: null,
        $approval,
    ];

    if ($expenseId) {
        $fields[] = $expenseId;
        db()->prepare(
            'UPDATE expenses SET event_id=?, category_id=?, booking_type=?, title=?, vendor=?, amount=?, expense_date=?,
             payment_status=?, paid_amount=?, payment_mode=?, invoice_no=?, po_no=?, wo_no=?, order_date=?, vendor_gstin=?,
             ecm_no=?, ecm_date=?, claimant=?, ecm_approved_by=?, bill_path=?, notes=?, approval_status=?
             WHERE id=?'
        )->execute($fields);
        if ($approval === 'approved' && $old['approval_status'] !== 'approved') {
            db()->prepare('UPDATE expenses SET approved_by=?, approved_at=NOW() WHERE id=?')->execute([uid(), $expenseId]);
        }
        log_expense_change($expenseId, $eventId, 'update', $title . ' · ' . $amount . ' · ' . $approval);
        log_activity('expense.update', 'expense', $expenseId, $title);
        if ($approval === 'pending' && $old['approval_status'] !== 'pending') {
            notify('expense.pending', [
                'event' => event_notify_context($eventId),
                'title' => $title,
                'amount' => $amount,
                'entity_type' => 'expense',
                'entity_id' => $expenseId,
            ]);
        }
        return $expenseId;
    }

    $fields[] = uid();
    if ($approval === 'approved') {
        db()->prepare(
            'INSERT INTO expenses (
                event_id, category_id, booking_type, title, vendor, amount, expense_date,
                payment_status, paid_amount, payment_mode, invoice_no,
                po_no, wo_no, order_date, vendor_gstin,
                ecm_no, ecm_date, claimant, ecm_approved_by,
                bill_path, notes, approval_status, recorded_by, approved_by, approved_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? ,NOW())'
        )->execute(array_merge($fields, [uid()]));
    } else {
        db()->prepare(
            'INSERT INTO expenses (
                event_id, category_id, booking_type, title, vendor, amount, expense_date,
                payment_status, paid_amount, payment_mode, invoice_no,
                po_no, wo_no, order_date, vendor_gstin,
                ecm_no, ecm_date, claimant, ecm_approved_by,
                bill_path, notes, approval_status, recorded_by
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute($fields);
    }
    $newId = (int) db()->lastInsertId();
    log_expense_change($newId, $eventId, 'create', strtoupper($type) . ' · ' . $title . ' · ' . $approval);
    log_activity('expense.create', 'event', $eventId, strtoupper($type) . ' · ' . $title);
    if ($approval === 'pending') {
        notify('expense.pending', [
            'event' => event_notify_context($eventId),
            'title' => $title,
            'amount' => $amount,
            'entity_type' => 'expense',
            'entity_id' => $newId,
        ]);
    }
    return $newId;
}

function approve_expense(int $eventId, int $expenseId): void
{
    if (!can('expenses.approve')) {
        throw new RuntimeException('Only finance can approve expenses.');
    }
    $st = db()->prepare('SELECT * FROM expenses WHERE id = ? AND event_id = ? AND deleted_at IS NULL');
    $st->execute([$expenseId, $eventId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Expense not found.');
    }
    $event = event_row($eventId);
    $totals = event_totals($eventId);
    $projected = $totals['expenses'] + (float) $row['amount'];
    $cap = event_is_unsponsored($event) ? (float) $event['budget_estimate'] : $totals['captured'];
    $note = '';
    if ($cap > 0 && $projected > $cap + 0.009) {
        $note = ' Approved with overspend.';
    }
    db()->prepare("UPDATE expenses SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([uid(), $expenseId]);
    log_expense_change($expenseId, $eventId, 'approve', $row['title'] . $note);
    log_activity('expense.approve', 'expense', $expenseId, $row['title']);
    notify('expense.approved', [
        'event' => event_notify_context($eventId),
        'title' => $row['title'],
        'amount' => (float) $row['amount'],
        'requester_id' => (int) ($row['recorded_by'] ?? 0),
        'entity_type' => 'expense',
        'entity_id' => $expenseId,
    ]);
}

function reject_expense(int $eventId, int $expenseId): void
{
    if (!can('expenses.approve')) {
        throw new RuntimeException('Only finance can reject expenses.');
    }
    $st = db()->prepare('SELECT * FROM expenses WHERE id = ? AND event_id = ? AND deleted_at IS NULL');
    $st->execute([$expenseId, $eventId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Expense not found.');
    }
    db()->prepare("UPDATE expenses SET approval_status='rejected' WHERE id=?")->execute([$expenseId]);
    log_expense_change($expenseId, $eventId, 'reject', $row['title']);
    log_activity('expense.reject', 'expense', $expenseId, $row['title']);
    notify('expense.rejected', [
        'event' => event_notify_context($eventId),
        'title' => $row['title'],
        'amount' => (float) $row['amount'],
        'requester_id' => (int) ($row['recorded_by'] ?? 0),
        'entity_type' => 'expense',
        'entity_id' => $expenseId,
    ]);
}

function cancel_expense(int $eventId, int $expenseId): void
{
    if (!can('expenses.edit') && role() !== 'admin') {
        throw new RuntimeException('You cannot cancel this expense.');
    }
    $st = db()->prepare('SELECT * FROM expenses WHERE id = ? AND event_id = ? AND deleted_at IS NULL');
    $st->execute([$expenseId, $eventId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Expense not found.');
    }
    if ($row['approval_status'] === 'approved' && !can('expenses.approve') && role() !== 'admin') {
        throw new RuntimeException('Approved expenses can only be cancelled by finance.');
    }
    db()->prepare('UPDATE expenses SET deleted_at = NOW() WHERE id = ?')->execute([$expenseId]);
    log_expense_change($expenseId, $eventId, 'cancel', $row['title']);
    log_activity('expense.cancel', 'expense', $expenseId, $row['title']);
}

function record_receipt(int $eventId, array $post): void
{
    if (!can('receipts')) {
        throw new RuntimeException('You cannot post receipts.');
    }
    $event = event_row($eventId);
    assert_event_writable($event, true);
    assert_unit_access($event);
    $sid = (int) ($post['sponsorship_id'] ?? 0);
    $st = db()->prepare('SELECT * FROM sponsorships WHERE id = ? AND event_id = ?');
    $st->execute([$sid, $eventId]);
    $sp = $st->fetch();
    if (!$sp) {
        throw new RuntimeException('Invalid sponsorship.');
    }
    if ($sp['status'] === 'cancelled') {
        throw new RuntimeException('This promise is cancelled.');
    }
    $amount = (float) ($post['amount'] ?? 0);
    if ($amount <= 0) {
        throw new RuntimeException('Receipt amount must be greater than zero.');
    }
    $receivedDate = (string) ($post['received_date'] ?: date('Y-m-d'));
    if ($receivedDate < $sp['promised_date']) {
        throw new RuntimeException('Receipt date cannot be before the promise date (' . dmy($sp['promised_date']) . ').');
    }
    $sum = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM sponsorship_receipts WHERE sponsorship_id = ?');
    $sum->execute([$sid]);
    $already = (float) $sum->fetchColumn();
    $promised = (float) $sp['promised_amount'];
    if ($already + $amount > $promised + 0.009) {
        $left = max(0, $promised - $already);
        throw new RuntimeException('Cannot receive more than promised. Balance left: ' . money_dec($left) . '.');
    }
    db()->prepare(
        'INSERT INTO sponsorship_receipts (sponsorship_id, amount, received_date, payment_mode, reference_no, notes, recorded_by)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $sid,
        $amount,
        $receivedDate,
        $post['payment_mode'] ?: 'bank',
        trim((string) ($post['reference_no'] ?? '')),
        trim((string) ($post['notes'] ?? '')),
        uid(),
    ]);
    refresh_sponsorship_status($sid);
    log_activity('receipt.create', 'sponsorship', $sid, 'Receipt ' . $amount);
    $newTotal = $already + $amount;
    notify(($newTotal + 0.009 >= $promised) ? 'sponsorship.received' : 'sponsorship.partial', [
        'event' => event_notify_context($eventId),
        'sponsor_name' => sponsor_name_by_id((int) $sp['sponsor_id']),
        'amount' => $amount,
        'balance' => max(0, $promised - $newTotal),
        'liaison_user_id' => (int) ($sp['liaison_user_id'] ?? 0),
        'entity_type' => 'sponsorship',
        'entity_id' => $sid,
    ]);
}

function expense_import_headers(bool $withEventCode = false): array
{
    $cols = [
        'booking_type', 'category', 'title', 'amount', 'vendor',
        'po_no', 'wo_no', 'order_date', 'ecm_no', 'ecm_date',
        'claimant', 'ecm_approved_by', 'vendor_gstin', 'invoice_no',
        'payment_status', 'paid_amount', 'payment_mode', 'notes',
    ];
    if ($withEventCode) {
        array_unshift($cols, 'event_code');
    }
    return $cols;
}

function send_expense_import_template(bool $withEventCode = false): void
{
    $file = $withEventCode ? 'eventgrant-expenses-template.csv' : 'eventgrant-event-expenses-template.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "%s", "\xEF\xBB\xBF");
    fputcsv($out, expense_import_headers($withEventCode));
    $purchase = [
        'purchase', 'Accommodation', 'Faculty rooms — 8 keys', '95000', 'Hotel Conrad',
        'PO-UPLOAD-0001', '', '10/04/2026', '', '',
        '', '', '', 'INV-88',
        'unpaid', '0', '', '',
    ];
    $purchaseLine2 = [
        'purchase', 'Audio Visual', 'Hall AV — 3 days', '42000', 'Hotel Conrad',
        'PO-UPLOAD-0001', '', '10/04/2026', '', '',
        '', '', '', 'INV-88',
        'unpaid', '0', '', '',
    ];
    $ecm = [
        'ecm', 'Food & Beverage', 'Tea and snacks', '12000', 'Hospital Kitchen',
        '', '', '', 'ECM-UPLOAD-0001', '18/04/2026',
        'Neha Joshi', 'Sanjay Kulkarni', '', '',
        'unpaid', '0', '', '',
    ];
    if ($withEventCode) {
        array_unshift($purchase, 'EVT-2026-001');
        array_unshift($purchaseLine2, 'EVT-2026-001');
        array_unshift($ecm, 'EVT-2026-001');
    }
    fputcsv($out, $purchase);
    fputcsv($out, $purchaseLine2);
    fputcsv($out, $ecm);
    fclose($out);
    exit;
}

function expense_import_alias_map(): array
{
    return [
        'event_code' => ['event_code', 'event', 'eventcode', 'code', 'event no', 'event number', 'event id'],
        'booking_type' => ['booking_type', 'booked_as', 'booked as', 'type', 'book', 'booking', 'booked'],
        'category' => ['category', 'category_name', 'expense_head', 'head', 'expense category', 'expense head'],
        'title' => ['title', 'item', 'description', 'what was spent', 'particulars', 'expense', 'narration'],
        'amount' => ['amount', 'amt', 'value', 'cost', 'rs', 'inr'],
        'vendor' => ['vendor', 'payee', 'supplier', 'party'],
        'po_no' => ['po_no', 'po', 'po number', 'po no', 'purchase order'],
        'wo_no' => ['wo_no', 'wo', 'wo number', 'wo no', 'work order'],
        'order_date' => ['order_date', 'po date', 'wo date', 'po/wo date', 'date', 'expense date'],
        'ecm_no' => ['ecm_no', 'ecm', 'ecm number', 'ecm no', 'memo no'],
        'ecm_date' => ['ecm_date', 'ecm date', 'memo date'],
        'claimant' => ['claimant', 'raised by', 'raised_by'],
        'ecm_approved_by' => ['ecm_approved_by', 'approved by', 'approved_by', 'ecm approved by'],
        'vendor_gstin' => ['vendor_gstin', 'gstin', 'gst', 'vendor gstin'],
        'invoice_no' => ['invoice_no', 'invoice', 'invoice no', 'bill no'],
        'payment_status' => ['payment_status', 'payment', 'status'],
        'paid_amount' => ['paid_amount', 'paid', 'paid amt'],
        'payment_mode' => ['payment_mode', 'mode', 'pay mode'],
        'notes' => ['notes', 'remark', 'remarks', 'comment'],
    ];
}

function normalize_import_header(string $raw): string
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $raw = strtolower(trim($raw));
    $raw = str_replace(['_', '-'], ' ', $raw);
    $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
    return $raw;
}

function map_sheet_headers(array $headerRow, array $aliases): array
{
    $lookup = [];
    foreach ($aliases as $canon => $names) {
        foreach ($names as $name) {
            $lookup[normalize_import_header($name)] = $canon;
        }
    }
    $map = [];
    foreach ($headerRow as $i => $cell) {
        $key = normalize_import_header((string) $cell);
        if ($key === '' || !isset($lookup[$key])) {
            continue;
        }
        $map[$lookup[$key]] = (int) $i;
    }
    return $map;
}

function map_import_headers(array $headerRow): array
{
    return map_sheet_headers($headerRow, expense_import_alias_map());
}

function parse_import_date(string $raw, string $label): string
{
    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException($label . ' is required.');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $raw, $m)) {
        $y = (int) $m[3];
        if ($y < 100) {
            $y += $y >= 70 ? 1900 : 2000;
        }
        $day = (int) $m[1];
        $month = (int) $m[2];
        if (!checkdate($month, $day, $y)) {
            throw new RuntimeException($label . ' is not a valid date.');
        }
        return sprintf('%04d-%02d-%02d', $y, $month, $day);
    }
    if (is_numeric($raw)) {
        $n = (float) $raw;
        if ($n > 20000 && $n < 80000) {
            $unix = (int) round(($n - 25569) * 86400);
            return gmdate('Y-m-d', $unix);
        }
    }
    throw new RuntimeException($label . ' is not a valid date. Use YYYY-MM-DD or DD/MM/YYYY.');
}

function parse_import_amount($raw): float
{
    $s = trim((string) $raw);
    $s = str_replace(["\xC2\xA0", ',', '₹', 'Rs.', 'Rs', 'INR', ' '], '', $s);
    return (float) $s;
}

function spreadsheet_cell_text($value): string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_float($value) || is_int($value)) {
        if (is_float($value) && floor($value) == $value) {
            return (string) (int) $value;
        }
        return rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');
    }
    return trim((string) $value);
}

function parse_csv_upload(string $path): array
{
    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('Could not read the uploaded file.');
    }
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        throw new RuntimeException('The file is empty.');
    }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delim = array_key_first($counts);
    if ($counts[$delim] < 1) {
        $delim = ',';
    }
    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $rows[] = array_map(static fn($c) => spreadsheet_cell_text($c ?? ''), $row);
    }
    fclose($fh);
    return $rows;
}

function parse_xlsx_upload(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Excel (.xlsx) is not available on this server. Save the sheet as CSV and upload that.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the Excel file.');
    }
    $strings = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $sxml = @simplexml_load_string($ss);
        if ($sxml) {
            foreach ($sxml->xpath('//*[local-name()="si"]') ?: [] as $si) {
                $parts = [];
                foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                    $parts[] = (string) $t;
                }
                $strings[] = implode('', $parts);
            }
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('The Excel file has no first sheet. Save as CSV and try again.');
    }
    $xml = @simplexml_load_string($sheetXml);
    if (!$xml) {
        throw new RuntimeException('Could not read the Excel sheet. Save as CSV and try again.');
    }
    $grid = [];
    foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
        $line = [];
        foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $c) {
            $ref = strtoupper((string) $c['r']);
            if (!preg_match('/^([A-Z]+)/', $ref, $m)) {
                continue;
            }
            $col = 0;
            foreach (str_split($m[1]) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $col--;
            $type = (string) $c['t'];
            $text = '';
            if ($type === 's') {
                $v = $c->xpath('./*[local-name()="v"]');
                $idx = (int) (string) ($v[0] ?? '0');
                $text = $strings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $parts = [];
                foreach ($c->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                    $parts[] = (string) $t;
                }
                $text = implode('', $parts);
            } else {
                $v = $c->xpath('./*[local-name()="v"]');
                $text = spreadsheet_cell_text((string) ($v[0] ?? ''));
            }
            $line[$col] = $text;
        }
        if ($line === []) {
            continue;
        }
        $max = max(array_keys($line));
        $out = array_fill(0, $max + 1, '');
        foreach ($line as $i => $val) {
            $out[$i] = $val;
        }
        $grid[] = $out;
    }
    return $grid;
}

function parse_spreadsheet_upload(string $tmp, string $origName): array
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (in_array($ext, ['csv', 'txt'], true)) {
        return parse_csv_upload($tmp);
    }
    if ($ext === 'xlsx') {
        return parse_xlsx_upload($tmp);
    }
    if ($ext === 'xls') {
        throw new RuntimeException('Old Excel (.xls) is not supported. Save as .xlsx or CSV and upload that.');
    }
    throw new RuntimeException('Upload a CSV or Excel (.xlsx) file.');
}

function row_is_empty(array $row): bool
{
    foreach ($row as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }
    return true;
}

function import_row_value(array $row, array $map, string $key): string
{
    if (!isset($map[$key])) {
        return '';
    }
    $i = $map[$key];
    return spreadsheet_cell_text($row[$i] ?? '');
}

function match_expense_category(string $name): int
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Category is required.');
    }
    if (ctype_digit($name)) {
        $id = (int) $name;
        $st = db()->prepare('SELECT id FROM expense_categories WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            return $id;
        }
    }
    $cats = db()->query('SELECT id, name, slug FROM expense_categories')->fetchAll();
    $want = preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? '';
    $want2 = str_replace('and', '', $want);
    foreach ($cats as $c) {
        if (strcasecmp((string) $c['name'], $name) === 0) {
            return (int) $c['id'];
        }
        $slug = strtolower((string) ($c['slug'] ?? ''));
        if ($slug !== '' && strcasecmp($slug, $name) === 0) {
            return (int) $c['id'];
        }
        $compact = preg_replace('/[^a-z0-9]+/', '', strtolower((string) $c['name'])) ?? '';
        $slugCompact = preg_replace('/[^a-z0-9]+/', '', $slug) ?? '';
        if ($want !== '' && ($want === $compact || $want === $slugCompact || $want2 === str_replace('and', '', $compact))) {
            return (int) $c['id'];
        }
    }
    throw new RuntimeException('Unknown category “' . $name . '”. Use a name from Settings.');
}

function resolve_import_booking_type(array $assoc): string
{
    $raw = strtolower(trim($assoc['booking_type'] ?? ''));
    $raw = str_replace([' ', '_'], '', $raw);
    if (in_array($raw, ['ecm', 'memo', 'claim', 'eventcostmemo'], true)) {
        return 'ecm';
    }
    if (in_array($raw, ['purchase', 'po', 'wo', 'powo', 'po/wo', 'p.o.', 'purchaseorder', 'workorder'], true)) {
        return 'purchase';
    }
    if (($assoc['ecm_no'] ?? '') !== '' && ($assoc['po_no'] ?? '') === '' && ($assoc['wo_no'] ?? '') === '') {
        return 'ecm';
    }
    if (($assoc['po_no'] ?? '') !== '' || ($assoc['wo_no'] ?? '') !== '') {
        return 'purchase';
    }
    throw new RuntimeException('booking_type must be purchase or ecm.');
}

function resolve_import_payment_status(string $raw): string
{
    $raw = strtolower(trim($raw));
    if (in_array($raw, ['paid', 'full', 'settled'], true)) {
        return 'paid';
    }
    if (in_array($raw, ['partial', 'part'], true)) {
        return 'partial';
    }
    return 'unpaid';
}

function resolve_import_payment_mode(string $raw): string
{
    $raw = strtolower(trim($raw));
    if ($raw === '') {
        return '';
    }
    $aliases = [
        'bank transfer' => 'bank', 'neft' => 'bank', 'rtgs' => 'bank', 'imps' => 'bank',
        'online' => 'bank', 'transfer' => 'bank',
    ];
    $key = $aliases[$raw] ?? $raw;
    return array_key_exists($key, payment_modes()) ? $key : '';
}

function event_from_import_code(string $code): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        throw new RuntimeException('event_code is required.');
    }
    $st = db()->prepare('SELECT * FROM events WHERE code = ?');
    $st->execute([$code]);
    $event = $st->fetch();
    if (!$event) {
        throw new RuntimeException('Unknown event code “' . $code . '”.');
    }
    assert_unit_access($event);
    return $event;
}

function import_assoc_to_post(array $assoc, bool $confirmOverspend): array
{
    $type = resolve_import_booking_type($assoc);
    $post = [
        'booking_type' => $type,
        'category_id' => match_expense_category($assoc['category'] ?? ''),
        'title' => $assoc['title'] ?? '',
        'amount' => parse_import_amount($assoc['amount'] ?? ''),
        'vendor' => $assoc['vendor'] ?? '',
        'po_no' => $assoc['po_no'] ?? '',
        'wo_no' => $assoc['wo_no'] ?? '',
        'ecm_no' => $assoc['ecm_no'] ?? '',
        'claimant' => $assoc['claimant'] ?? '',
        'ecm_approved_by' => $assoc['ecm_approved_by'] ?? '',
        'vendor_gstin' => $assoc['vendor_gstin'] ?? '',
        'invoice_no' => $assoc['invoice_no'] ?? '',
        'payment_status' => resolve_import_payment_status($assoc['payment_status'] ?? ''),
        'paid_amount' => parse_import_amount($assoc['paid_amount'] ?? '0'),
        'payment_mode' => resolve_import_payment_mode($assoc['payment_mode'] ?? ''),
        'notes' => $assoc['notes'] ?? '',
    ];
    if ($type === 'purchase') {
        $post['order_date'] = parse_import_date((string) ($assoc['order_date'] ?? ''), 'PO / WO date');
    } else {
        $post['ecm_date'] = parse_import_date((string) ($assoc['ecm_date'] ?? ''), 'ECM date');
    }
    if ($confirmOverspend) {
        $post['confirm_overspend'] = '1';
    }
    return $post;
}

function import_expenses_from_upload(array $file, ?int $fixedEventId, bool $confirmOverspend): array
{
    if (!can('expenses.create')) {
        throw new RuntimeException('You cannot book expenses.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a CSV or Excel file to upload.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file did not upload. Try again.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('The file must be under 2 MB.');
    }
    $grid = parse_spreadsheet_upload((string) $file['tmp_name'], (string) ($file['name'] ?? 'upload.csv'));
    $headerIdx = null;
    $map = [];
    foreach ($grid as $i => $row) {
        if (row_is_empty($row)) {
            continue;
        }
        $try = map_import_headers($row);
        if (isset($try['title'], $try['amount'], $try['category'])) {
            $headerIdx = $i;
            $map = $try;
            break;
        }
    }
    if ($headerIdx === null) {
        throw new RuntimeException('Could not find a header row. Download the template and keep the column names.');
    }
    if ($fixedEventId === null && !isset($map['event_code'])) {
        throw new RuntimeException('Add an event_code column, or upload from an event page.');
    }

    $saved = 0;
    $pending = 0;
    $errors = [];
    $ids = [];
    $dataRows = 0;
    foreach ($grid as $i => $row) {
        if ($i <= $headerIdx || row_is_empty($row)) {
            continue;
        }
        $dataRows++;
        if ($dataRows > 250) {
            $errors[] = 'Stopped after 250 rows. Split the file and upload the rest.';
            break;
        }
        $line = $i + 1;
        $assoc = [];
        foreach (array_keys(expense_import_alias_map()) as $key) {
            $assoc[$key] = import_row_value($row, $map, $key);
        }
        try {
            $eventId = $fixedEventId;
            if ($eventId === null) {
                $eventId = (int) event_from_import_code($assoc['event_code'])['id'];
            }
            $post = import_assoc_to_post($assoc, $confirmOverspend);
            $newId = record_event_expense($eventId, $post, null, null);
            $ids[] = $newId;
            $saved++;
            $st = db()->prepare('SELECT approval_status FROM expenses WHERE id = ?');
            $st->execute([$newId]);
            if ((string) $st->fetchColumn() === 'pending') {
                $pending++;
            }
        } catch (RuntimeException $e) {
            $errors[] = 'Row ' . $line . ': ' . $e->getMessage();
        }
    }
    if ($dataRows === 0) {
        throw new RuntimeException('The file has a header but no expense rows.');
    }
    return ['saved' => $saved, 'pending' => $pending, 'errors' => $errors, 'ids' => $ids];
}

function flash_expense_import(array $result): void
{
    $_SESSION['expense_import_report'] = $result;
    $n = (int) $result['saved'];
    $skipped = count($result['errors']);
    if ($n > 0 && $skipped === 0) {
        $msg = $n . ' expense' . ($n === 1 ? '' : 's') . ' uploaded.';
        if (($result['pending'] ?? 0) > 0) {
            $msg .= ' ' . (int) $result['pending'] . ' waiting for finance approval.';
        }
        flash('ok', $msg);
        return;
    }
    if ($n > 0) {
        flash('ok', $n . ' expense' . ($n === 1 ? '' : 's') . ' uploaded. ' . $skipped . ' row' . ($skipped === 1 ? '' : 's') . ' skipped — see the note below.');
        return;
    }
    flash('err', $result['errors'][0] ?? 'No expenses were imported.');
}

function take_expense_import_report(): ?array
{
    $r = $_SESSION['expense_import_report'] ?? null;
    unset($_SESSION['expense_import_report']);
    return is_array($r) ? $r : null;
}

function render_expense_import_report(): void
{
    $r = take_expense_import_report();
    if (!$r || empty($r['errors'])) {
        return;
    }
    echo '<div class="import-report"><strong>Upload notes</strong><ul>';
    foreach (array_slice($r['errors'], 0, 20) as $err) {
        echo '<li>' . e($err) . '</li>';
    }
    if (count($r['errors']) > 20) {
        echo '<li>' . e('… and ' . (count($r['errors']) - 20) . ' more') . '</li>';
    }
    echo '</ul></div>';
}

function render_expense_import_modal(string $actionUrl, string $templateUrl, bool $needsEventCode, bool $unsponsored = false): void
{
    if (!can('expenses.create')) {
        return;
    }
    $cols = implode(', ', expense_import_headers($needsEventCode));
    ?>
<div class="modal-bg" id="expUploadModal">
  <form class="modal" method="post" action="<?= e($actionUrl) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_expenses">
    <h3>Upload expenses</h3>
    <p class="muted">Same rules as Add expense: Purchase needs a PO or WO plus date and vendor; ECM needs an ECM number and date. Several rows may share one PO or WO number (line items). ECM numbers stay unique. Category names must match Settings. Dates as YYYY-MM-DD or DD/MM/YYYY. Max 250 rows.</p>
    <?php if ($needsEventCode): ?>
      <p class="muted">Include an <strong>event_code</strong> column (for example EVT-2026-001) so each row lands on the right programme.</p>
    <?php endif; ?>
    <p class="template-cols"><strong>Columns:</strong> <?= e($cols) ?></p>
    <div class="form-grid">
      <div class="field full">
        <label>CSV or Excel file *</label>
        <input type="file" name="expense_file" accept=".csv,.txt,.xlsx" required>
      </div>
      <?php if (can('expenses.approve')): ?>
      <div class="field full">
        <label>If a row takes spend over the <?= $needsEventCode ? 'budget or sponsorship amount' : ($unsponsored ? 'budget' : 'sponsorship amount') ?></label>
        <select name="confirm_overspend">
          <option value="0">Stop — do not overspend</option>
          <option value="1">Allow overspend</option>
        </select>
        <p class="muted" style="margin:6px 0 0">Choose <strong>Allow overspend</strong> before you upload, or those rows will be skipped.</p>
      </div>
      <?php endif; ?>
    </div>
    <div class="modal-actions" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
      <a class="btn btn-ghost" href="<?= e($templateUrl) ?>">Download template</a>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn btn-ghost" onclick="closeModal('expUploadModal')">Cancel</button>
        <button class="btn btn-teal" type="submit">Upload expenses</button>
      </div>
    </div>
  </form>
</div>
    <?php
}
