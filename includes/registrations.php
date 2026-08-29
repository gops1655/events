<?php
declare(strict_types=1);

function ensure_registrations_schema(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS registrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            registration_no VARCHAR(80) DEFAULT NULL,
            email VARCHAR(160) DEFAULT NULL,
            phone VARCHAR(24) DEFAULT NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'delegate',
            organization VARCHAR(160) DEFAULT NULL,
            designation VARCHAR(120) DEFAULT NULL,
            city VARCHAR(80) DEFAULT NULL,
            registration_date DATE DEFAULT NULL,
            fee_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            payment_status ENUM('unpaid','paid','complimentary') NOT NULL DEFAULT 'unpaid',
            notes TEXT,
            recorded_by INT UNSIGNED DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_reg_event (event_id),
            INDEX idx_reg_name (name),
            UNIQUE KEY uq_reg_event_no (event_id, registration_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $cols = [
        'registration_no' => "VARCHAR(80) DEFAULT NULL AFTER name",
        'organization' => "VARCHAR(160) DEFAULT NULL AFTER category",
        'designation' => "VARCHAR(120) DEFAULT NULL AFTER organization",
        'city' => "VARCHAR(80) DEFAULT NULL AFTER designation",
        'registration_date' => "DATE DEFAULT NULL AFTER city",
        'fee_amount' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER registration_date",
        'payment_status' => "ENUM('unpaid','paid','complimentary') NOT NULL DEFAULT 'unpaid' AFTER fee_amount",
        'deleted_at' => "DATETIME DEFAULT NULL AFTER recorded_by",
    ];
    foreach ($cols as $name => $ddl) {
        try {
            $hit = db()->query("SHOW COLUMNS FROM registrations LIKE " . db()->quote($name))->fetch();
            if (!$hit) {
                db()->exec("ALTER TABLE registrations ADD COLUMN {$name} {$ddl}");
            }
        } catch (Throwable $e) {
        }
    }
}

function attendee_categories(): array
{
    return [
        'delegate' => 'Delegate',
        'faculty' => 'Faculty',
        'student' => 'Student / resident',
        'staff' => 'Hospital staff',
        'exhibitor' => 'Exhibitor',
        'guest' => 'Guest',
        'other' => 'Other',
    ];
}

function registration_payment_statuses(): array
{
    return [
        'unpaid' => 'Unpaid',
        'paid' => 'Paid',
        'complimentary' => 'Complimentary',
    ];
}

function event_registration_count(int $eventId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM registrations WHERE event_id = ? AND deleted_at IS NULL');
    $st->execute([$eventId]);
    return (int) $st->fetchColumn();
}

function blank_to_null(string $value): ?string
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

function normalize_reg_email(string $email): ?string
{
    $email = strtolower(trim($email));
    return $email === '' ? null : $email;
}

function normalize_reg_phone(string $phone): ?string
{
    $phone = preg_replace('/\s+/', '', trim($phone)) ?? '';
    return $phone === '' ? null : $phone;
}

function resolve_attendee_category(string $raw): string
{
    $raw = strtolower(trim($raw));
    $raw = str_replace(['_', '-'], ' ', $raw);
    $aliases = [
        'delegate' => 'delegate',
        'participant' => 'delegate',
        'attendee' => 'delegate',
        'faculty' => 'faculty',
        'speaker' => 'faculty',
        'faculty speaker' => 'faculty',
        'student' => 'student',
        'resident' => 'student',
        'student / resident' => 'student',
        'pg' => 'student',
        'staff' => 'staff',
        'hospital staff' => 'staff',
        'employee' => 'staff',
        'exhibitor' => 'exhibitor',
        'stall' => 'exhibitor',
        'guest' => 'guest',
        'other' => 'other',
    ];
    $key = $aliases[$raw] ?? $raw;
    return array_key_exists($key, attendee_categories()) ? $key : 'delegate';
}

function resolve_reg_payment(string $raw): string
{
    $raw = strtolower(trim($raw));
    if (in_array($raw, ['paid', 'full', 'settled', 'yes'], true)) {
        return 'paid';
    }
    if (in_array($raw, ['complimentary', 'comp', 'free', 'waived'], true)) {
        return 'complimentary';
    }
    return 'unpaid';
}

function parse_optional_import_date(string $raw, string $label): ?string
{
    if (trim($raw) === '') {
        return null;
    }
    return parse_import_date($raw, $label);
}

function assert_registration_unique(int $eventId, array $data, int $ignoreId = 0): void
{
    $no = $data['registration_no'] ?? null;
    if ($no) {
        $st = db()->prepare('SELECT id FROM registrations WHERE event_id = ? AND registration_no = ? AND deleted_at IS NULL AND id <> ?');
        $st->execute([$eventId, $no, $ignoreId]);
        if ($st->fetchColumn()) {
            throw new RuntimeException('Registration number ' . $no . ' is already on this event.');
        }
    }
    $email = $data['email'] ?? null;
    if ($email) {
        $st = db()->prepare('SELECT id FROM registrations WHERE event_id = ? AND email = ? AND deleted_at IS NULL AND id <> ?');
        $st->execute([$eventId, $email, $ignoreId]);
        if ($st->fetchColumn()) {
            throw new RuntimeException('This email is already registered on this event.');
        }
    }
    $phone = $data['phone'] ?? null;
    if ($phone) {
        $st = db()->prepare('SELECT id FROM registrations WHERE event_id = ? AND phone = ? AND deleted_at IS NULL AND id <> ?');
        $st->execute([$eventId, $phone, $ignoreId]);
        if ($st->fetchColumn()) {
            throw new RuntimeException('This phone number is already registered on this event.');
        }
    }
}

function record_registration(int $eventId, array $post, ?int $registrationId = null): int
{
    if ($registrationId) {
        if (!can('registrations.edit') && role() !== 'admin') {
            throw new RuntimeException('You cannot edit this registration.');
        }
    } elseif (!can('registrations.create')) {
        throw new RuntimeException('You cannot add registrations.');
    }
    $event = event_row($eventId);
    assert_unit_access($event);
    if (($event['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('This event is cancelled. No further registrations.');
    }

    $name = trim((string) ($post['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Attendee name is required.');
    }
    $data = [
        'name' => $name,
        'registration_no' => ($no = blank_to_null((string) ($post['registration_no'] ?? ''))) ? strtoupper($no) : null,
        'email' => normalize_reg_email((string) ($post['email'] ?? '')),
        'phone' => normalize_reg_phone((string) ($post['phone'] ?? '')),
        'category' => resolve_attendee_category((string) ($post['category'] ?? 'delegate')),
        'organization' => blank_to_null((string) ($post['organization'] ?? '')),
        'designation' => blank_to_null((string) ($post['designation'] ?? '')),
        'city' => blank_to_null((string) ($post['city'] ?? '')),
        'registration_date' => parse_optional_import_date((string) ($post['registration_date'] ?? ''), 'Registration date'),
        'fee_amount' => max(0, parse_import_amount($post['fee_amount'] ?? 0)),
        'payment_status' => resolve_reg_payment((string) ($post['payment_status'] ?? 'unpaid')),
        'notes' => blank_to_null((string) ($post['notes'] ?? '')),
    ];
    if ($data['payment_status'] === 'complimentary') {
        $data['fee_amount'] = 0;
    }
    assert_registration_unique($eventId, $data, $registrationId ?? 0);

    if ($registrationId) {
        $st = db()->prepare('SELECT id FROM registrations WHERE id = ? AND event_id = ? AND deleted_at IS NULL');
        $st->execute([$registrationId, $eventId]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('Registration not found.');
        }
        db()->prepare(
            'UPDATE registrations SET name=?, registration_no=?, email=?, phone=?, category=?, organization=?, designation=?, city=?,
             registration_date=?, fee_amount=?, payment_status=?, notes=? WHERE id=?'
        )->execute([
            $data['name'], $data['registration_no'], $data['email'], $data['phone'], $data['category'],
            $data['organization'], $data['designation'], $data['city'], $data['registration_date'],
            $data['fee_amount'], $data['payment_status'], $data['notes'], $registrationId,
        ]);
        log_activity('registration.update', 'event', $eventId, $data['name']);
        sync_registration_target($eventId);
        return $registrationId;
    }

    db()->prepare(
        'INSERT INTO registrations (event_id, name, registration_no, email, phone, category, organization, designation, city,
         registration_date, fee_amount, payment_status, notes, recorded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $eventId, $data['name'], $data['registration_no'], $data['email'], $data['phone'], $data['category'],
        $data['organization'], $data['designation'], $data['city'], $data['registration_date'],
        $data['fee_amount'], $data['payment_status'], $data['notes'], uid() ?: null,
    ]);
    $newId = (int) db()->lastInsertId();
    log_activity('registration.create', 'event', $eventId, $data['name']);
    sync_registration_target($eventId);
    return $newId;
}

function cancel_registration(int $eventId, int $registrationId): void
{
    if (!can('registrations.edit') && role() !== 'admin') {
        throw new RuntimeException('You cannot remove this registration.');
    }
    $event = event_row($eventId);
    assert_unit_access($event);
    $st = db()->prepare('SELECT * FROM registrations WHERE id = ? AND event_id = ? AND deleted_at IS NULL');
    $st->execute([$registrationId, $eventId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Registration not found.');
    }
    db()->prepare('UPDATE registrations SET deleted_at = NOW() WHERE id = ?')->execute([$registrationId]);
    log_activity('registration.cancel', 'event', $eventId, $row['name']);
    sync_registration_target($eventId);
}

function registration_import_headers(bool $withEventCode = false): array
{
    $cols = [
        'name', 'registration_no', 'email', 'phone', 'category',
        'organization', 'designation', 'city', 'registration_date',
        'fee_amount', 'payment_status', 'notes',
    ];
    if ($withEventCode) {
        array_unshift($cols, 'event_code');
    }
    return $cols;
}

function registration_import_alias_map(): array
{
    return [
        'event_code' => ['event_code', 'event', 'eventcode', 'code', 'event no', 'event number'],
        'name' => ['name', 'attendee', 'participant', 'full name', 'delegate name'],
        'registration_no' => ['registration_no', 'reg no', 'reg_no', 'registration number', 'ticket', 'id'],
        'email' => ['email', 'e-mail', 'mail'],
        'phone' => ['phone', 'mobile', 'mobile no', 'contact', 'whatsapp'],
        'category' => ['category', 'type', 'attendee type', 'delegate type'],
        'organization' => ['organization', 'organisation', 'hospital', 'institute', 'company'],
        'designation' => ['designation', 'title', 'role'],
        'city' => ['city', 'place'],
        'registration_date' => ['registration_date', 'reg date', 'date', 'registered on'],
        'fee_amount' => ['fee_amount', 'fee', 'amount', 'registration fee'],
        'payment_status' => ['payment_status', 'payment', 'fee status', 'paid'],
        'notes' => ['notes', 'remark', 'remarks', 'comment'],
    ];
}

function send_registration_import_template(bool $withEventCode = false): void
{
    $file = $withEventCode ? 'eventgrant-registrations-template.csv' : 'eventgrant-event-registrations-template.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "%s", "\xEF\xBB\xBF");
    fputcsv($out, registration_import_headers($withEventCode));
    $one = [
        'Dr. Meera Kulkarni', 'REG-0001', 'meera.k@example.com', '9876501111', 'faculty',
        'City Care Hospital', 'Consultant Cardiology', 'Pune', '01/08/2026',
        '0', 'complimentary', '',
    ];
    $two = [
        'Amit Pawar', 'REG-0002', 'amit.pawar@example.com', '9876502222', 'delegate',
        'District Hospital', 'MO', 'Satara', '02/08/2026',
        '2500', 'paid', '',
    ];
    if ($withEventCode) {
        array_unshift($one, 'EVT-2026-001');
        array_unshift($two, 'EVT-2026-001');
    }
    fputcsv($out, $one);
    fputcsv($out, $two);
    fclose($out);
    exit;
}

function import_registrations_from_upload(array $file, ?int $fixedEventId): array
{
    if (!can('registrations.create')) {
        throw new RuntimeException('You cannot add registrations.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a CSV or Excel file to upload.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file did not upload. Try again.');
    }
    if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
        throw new RuntimeException('The file must be under 4 MB.');
    }
    $grid = parse_spreadsheet_upload((string) $file['tmp_name'], (string) ($file['name'] ?? 'upload.csv'));
    $headerIdx = null;
    $map = [];
    foreach ($grid as $i => $row) {
        if (row_is_empty($row)) {
            continue;
        }
        $try = map_sheet_headers($row, registration_import_alias_map());
        if (isset($try['name'])) {
            $headerIdx = $i;
            $map = $try;
            break;
        }
    }
    if ($headerIdx === null) {
        throw new RuntimeException('Could not find a header row with a Name column. Download the template and keep the column names.');
    }
    if ($fixedEventId === null && !isset($map['event_code'])) {
        throw new RuntimeException('Add an event_code column, or upload from an event page.');
    }

    $saved = 0;
    $errors = [];
    $dataRows = 0;
    foreach ($grid as $i => $row) {
        if ($i <= $headerIdx || row_is_empty($row)) {
            continue;
        }
        $dataRows++;
        if ($dataRows > 1000) {
            $errors[] = 'Stopped after 1,000 rows. Split the file and upload the rest.';
            break;
        }
        $line = $i + 1;
        $assoc = [];
        foreach (array_keys(registration_import_alias_map()) as $key) {
            $assoc[$key] = import_row_value($row, $map, $key);
        }
        try {
            $eventId = $fixedEventId;
            if ($eventId === null) {
                $eventId = (int) event_from_import_code($assoc['event_code'])['id'];
            }
            record_registration($eventId, [
                'name' => $assoc['name'],
                'registration_no' => $assoc['registration_no'],
                'email' => $assoc['email'],
                'phone' => $assoc['phone'],
                'category' => $assoc['category'],
                'organization' => $assoc['organization'],
                'designation' => $assoc['designation'],
                'city' => $assoc['city'],
                'registration_date' => $assoc['registration_date'],
                'fee_amount' => $assoc['fee_amount'],
                'payment_status' => $assoc['payment_status'],
                'notes' => $assoc['notes'],
            ]);
            $saved++;
        } catch (RuntimeException $e) {
            $errors[] = 'Row ' . $line . ': ' . $e->getMessage();
        }
    }
    if ($dataRows === 0) {
        throw new RuntimeException('The file has a header but no attendee rows.');
    }
    return ['saved' => $saved, 'errors' => $errors];
}

function flash_registration_import(array $result): void
{
    $_SESSION['registration_import_report'] = $result;
    $n = (int) $result['saved'];
    $skipped = count($result['errors']);
    if ($n > 0 && $skipped === 0) {
        flash('ok', $n . ' registration' . ($n === 1 ? '' : 's') . ' uploaded.');
        return;
    }
    if ($n > 0) {
        flash('ok', $n . ' registration' . ($n === 1 ? '' : 's') . ' uploaded. ' . $skipped . ' row' . ($skipped === 1 ? '' : 's') . ' skipped — see the note below.');
        return;
    }
    flash('err', $result['errors'][0] ?? 'No registrations were imported.');
}

function render_registration_import_report(): void
{
    $r = $_SESSION['registration_import_report'] ?? null;
    unset($_SESSION['registration_import_report']);
    if (!is_array($r) || empty($r['errors'])) {
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

function render_registration_form_fields(array $edit = [], bool $pickEvent = false, array $events = []): void
{
    $cats = attendee_categories();
    $pays = registration_payment_statuses();
    ?>
    <div class="form-grid">
      <?php if ($pickEvent): ?>
      <div class="field full"><label>Event *</label>
        <select name="event_id" required>
          <option value="">Select programme…</option>
          <?php foreach ($events as $ev): ?>
            <option value="<?= (int) $ev['id'] ?>" <?= (int) ($edit['event_id'] ?? 0) === (int) $ev['id'] ? 'selected' : '' ?>>
              <?= e($ev['code']) ?> · <?= e($ev['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="field full"><label>Name *</label><input name="name" required value="<?= e($edit['name'] ?? '') ?>" placeholder="Dr. / Mr. / Ms."></div>
      <div class="field"><label>Registration no.</label><input name="registration_no" value="<?= e($edit['registration_no'] ?? '') ?>" placeholder="REG-0001"></div>
      <div class="field"><label>Category</label>
        <select name="category">
          <?php foreach ($cats as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= ($edit['category'] ?? 'delegate') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Phone</label><input name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
      <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
      <div class="field"><label>Organisation / hospital</label><input name="organization" value="<?= e($edit['organization'] ?? '') ?>"></div>
      <div class="field"><label>Designation</label><input name="designation" value="<?= e($edit['designation'] ?? '') ?>"></div>
      <div class="field"><label>City</label><input name="city" value="<?= e($edit['city'] ?? '') ?>"></div>
      <div class="field"><label>Registered on</label><input type="date" name="registration_date" value="<?= e($edit['registration_date'] ?? date('Y-m-d')) ?>"></div>
      <div class="field"><label>Fee (₹)</label><input type="number" step="0.01" min="0" name="fee_amount" value="<?= e((string) ($edit['fee_amount'] ?? '0')) ?>"></div>
      <div class="field"><label>Fee status</label>
        <select name="payment_status">
          <?php foreach ($pays as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= ($edit['payment_status'] ?? 'unpaid') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field full"><label>Notes</label><textarea name="notes"><?= e($edit['notes'] ?? '') ?></textarea></div>
    </div>
    <?php
}

function render_registration_upload_modal(string $actionUrl, string $templateUrl, bool $needsEventCode): void
{
    if (!can('registrations.create')) {
        return;
    }
    $cols = implode(', ', registration_import_headers($needsEventCode));
    ?>
<div class="modal-bg" id="regUploadModal">
  <form class="modal" method="post" action="<?= e($actionUrl) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_registrations">
    <h3>Upload registration list</h3>
    <p class="muted">CSV or Excel. One attendee per row. Name is required. Category names: Delegate, Faculty, Student, Staff, Exhibitor, Guest. Dates as YYYY-MM-DD or DD/MM/YYYY. Duplicate email, phone, or registration number on the same event is skipped. Max 1,000 rows.</p>
    <?php if ($needsEventCode): ?>
      <p class="muted">Include an <strong>event_code</strong> column (for example EVT-2026-001) so each row lands on the right programme.</p>
    <?php endif; ?>
    <p class="template-cols"><strong>Columns:</strong> <?= e($cols) ?></p>
    <div class="form-grid">
      <div class="field full">
        <label>CSV or Excel file *</label>
        <input type="file" name="registration_file" accept=".csv,.txt,.xlsx" required>
      </div>
    </div>
    <div class="modal-actions" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
      <a class="btn btn-ghost" href="<?= e($templateUrl) ?>">Download template</a>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn btn-ghost" onclick="closeModal('regUploadModal')">Cancel</button>
        <button class="btn btn-teal" type="submit">Upload list</button>
      </div>
    </div>
  </form>
</div>
    <?php
}
