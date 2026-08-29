<?php
require __DIR__ . '/includes/init.php';
require_login();
if (role() !== 'admin') {
    http_response_code(403);
    die('Administrators only.');
}

$pdo = db();
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'hospital') {
        foreach (['hospital_name', 'hospital_city'] as $k) {
            $val = trim($_POST[$k] ?? '');
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$k, $val]);
        }
        $limit = max(0, (float) ($_POST['expense_approval_limit'] ?? 50000));
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute(['expense_approval_limit', (string) $limit]);
        $grace = max(1, min(365, (int) ($_POST['collection_grace_days'] ?? 30)));
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute(['collection_grace_days', (string) $grace]);
        flash('ok', 'Hospital profile and money rules saved.');
        redirect('settings.php');
    }
    if ($action === 'units') {
        foreach (unit_codes() as $code) {
            $name = trim((string) ($_POST['name'][$code] ?? $code)) ?: $code;
            $notes = trim((string) ($_POST['notes'][$code] ?? ''));
            $pdo->prepare('UPDATE units SET name = ?, notes = ? WHERE code = ?')->execute([$name, $notes, $code]);
        }
        flash('ok', 'Unit names and control notes saved.');
        redirect('settings.php');
    }
    if ($action === 'notifications') {
        $bools = ['notify_inapp_enabled', 'notify_email_enabled', 'notify_whatsapp_enabled', 'notify_on_sponsorship', 'notify_on_overdue', 'notify_on_expense_approval', 'notify_on_event_reminder'];
        $texts = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_from_email', 'smtp_from_name', 'whatsapp_provider', 'whatsapp_endpoint', 'whatsapp_sid', 'whatsapp_from', 'app_base_url'];
        $save = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($bools as $k) {
            $save->execute([$k, isset($_POST[$k]) ? '1' : '0']);
        }
        foreach ($texts as $k) {
            $save->execute([$k, trim((string) ($_POST[$k] ?? ''))]);
        }
        // Password-style secrets: only overwrite when a new value is actually typed,
        // so the masked placeholder shown in the form never blanks out a saved one.
        foreach (['smtp_pass', 'whatsapp_token'] as $k) {
            if (trim((string) ($_POST[$k] ?? '')) !== '') {
                $save->execute([$k, $_POST[$k]]);
            }
        }
        $days = array_filter(array_map('trim', explode(',', (string) ($_POST['event_reminder_days'] ?? '7,1'))), fn ($d) => $d !== '' && ctype_digit($d));
        $save->execute(['event_reminder_days', $days ? implode(',', $days) : '7,1']);
        flash('ok', 'Notification settings saved.');
        redirect('settings.php');
    }
    if ($action === 'test_email') {
        $to = trim((string) ($_POST['test_email_to'] ?? ''));
        $res = send_test_email($to);
        flash($res['ok'] ? 'ok' : 'err', $res['ok'] ? "Test email sent to {$to}." : 'Test email failed: ' . $res['error']);
        redirect('settings.php');
    }
    if ($action === 'test_whatsapp') {
        $to = trim((string) ($_POST['test_whatsapp_to'] ?? ''));
        $res = send_test_whatsapp($to);
        flash($res['ok'] ? 'ok' : 'err', $res['ok'] ? "Test WhatsApp message sent to {$to}." : 'Test WhatsApp failed: ' . $res['error']);
        redirect('settings.php');
    }
    if ($action === 'category') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash('err', 'Category name is required.');
            redirect('settings.php');
        }
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $color = $_POST['color'] ?: '#1b6e64';
        $pdo->prepare('INSERT INTO expense_categories (name, slug, icon, color, sort_order) VALUES (?,?,?,?,?)')
            ->execute([$name, $slug . '-' . bin2hex(random_bytes(2)), 'circle', $color, 99]);
        flash('ok', 'Category added.');
        redirect('settings.php');
    }
}

$cats = $pdo->query('SELECT * FROM expense_categories ORDER BY sort_order, name')->fetchAll();

$pageTitle = 'Settings';
$pageCrumb = 'Hospital profile and expense heads';
$active = 'settings';
require __DIR__ . '/includes/header.php';
?>
<div class="grid-2">
  <div class="card">
    <div class="card-h"><h3>Hospital</h3></div>
    <div class="card-b">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="hospital">
        <div class="field" style="margin-bottom:12px"><label>Name</label><input name="hospital_name" required value="<?= e(setting('hospital_name')) ?>"></div>
        <div class="field" style="margin-bottom:12px"><label>City</label><input name="hospital_city" value="<?= e(setting('hospital_city')) ?>"></div>
        <div class="field" style="margin-bottom:12px"><label>Expense approval limit (₹)</label>
          <input type="number" min="0" step="1" name="expense_approval_limit" value="<?= e(setting('expense_approval_limit', '50000')) ?>">
          <p class="muted" style="margin:6px 0 0">Amounts above this booked by non-finance staff wait for finance approval before they count in the tracker.</p>
        </div>
        <div class="field" style="margin-bottom:12px"><label>Days to collect after the event ends</label>
          <input type="number" min="1" max="365" step="1" name="collection_grace_days" value="<?= e(setting('collection_grace_days', '30')) ?>">
          <p class="muted" style="margin:6px 0 0">Default 30 days. After this, a red flag shows if sponsorship money is still outstanding.</p>
        </div>
        <button class="btn btn-teal" type="submit">Save</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><h3>Expense categories</h3></div>
    <div class="card-b">
      <table class="data">
        <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= e($c['color']) ?>;margin-right:8px"></span><?= e($c['name']) ?></td>
            <td class="muted"><?= e($c['slug']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" class="form-grid" style="margin-top:16px">
        <?= csrf_field() ?><input type="hidden" name="action" value="category">
        <div class="field"><label>New category</label><input name="name" required placeholder="e.g. Security"></div>
        <div class="field"><label>Colour</label><input type="color" name="color" value="#1b6e64"></div>
        <div class="field" style="justify-content:end"><button class="btn btn-ghost" type="submit">Add</button></div>
      </form>
    </div>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-h"><h3>Units</h3><span>HTC, SEC, SMJ, MLK — marketing is local, purchase &amp; finance stay central</span></div>
  <div class="card-b">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="units">
      <table class="data">
        <thead><tr><th>Code</th><th>Display name</th><th>How this unit is controlled</th></tr></thead>
        <tbody>
        <?php foreach (units() as $code => $u): ?>
          <tr>
            <td><span class="badge unit"><?= e($code) ?></span></td>
            <td><input name="name[<?= e($code) ?>]" value="<?= e($u['name']) ?>"></td>
            <td><input name="notes[<?= e($code) ?>]" value="<?= e($u['notes'] ?? '') ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button class="btn btn-teal" type="submit" style="margin-top:12px">Save units</button>
    </form>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-h"><h3>Notifications</h3><span>Email &amp; WhatsApp alerts for sponsorship money, overdue collections, expense approvals, and event reminders</span></div>
  <div class="card-b">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="notifications">

      <div class="form-grid" style="margin-bottom:18px">
        <label class="field-check"><input type="checkbox" name="notify_inapp_enabled" <?= setting('notify_inapp_enabled') === '1' ? 'checked' : '' ?>> In-app bell</label>
        <label class="field-check"><input type="checkbox" name="notify_email_enabled" <?= setting('notify_email_enabled') === '1' ? 'checked' : '' ?>> Email</label>
        <label class="field-check"><input type="checkbox" name="notify_whatsapp_enabled" <?= setting('notify_whatsapp_enabled') === '1' ? 'checked' : '' ?>> WhatsApp</label>
      </div>

      <p class="muted" style="margin:0 0 8px;font-size:12px;letter-spacing:.08em;text-transform:uppercase">Send on</p>
      <div class="form-grid" style="margin-bottom:18px">
        <label class="field-check"><input type="checkbox" name="notify_on_sponsorship" <?= setting('notify_on_sponsorship') === '1' ? 'checked' : '' ?>> Sponsorship promised / received</label>
        <label class="field-check"><input type="checkbox" name="notify_on_overdue" <?= setting('notify_on_overdue') === '1' ? 'checked' : '' ?>> Overdue collections</label>
        <label class="field-check"><input type="checkbox" name="notify_on_expense_approval" <?= setting('notify_on_expense_approval') === '1' ? 'checked' : '' ?>> Expense approvals</label>
        <label class="field-check"><input type="checkbox" name="notify_on_event_reminder" <?= setting('notify_on_event_reminder') === '1' ? 'checked' : '' ?>> Event reminders</label>
      </div>
      <div class="field" style="margin-bottom:18px;max-width:320px">
        <label>Remind this many days before an event starts</label>
        <input name="event_reminder_days" value="<?= e(setting('event_reminder_days', '7,1')) ?>" placeholder="7,1">
        <p class="muted" style="margin:6px 0 0">Comma-separated days, e.g. "7,1" for a week-before and a day-before nudge.</p>
      </div>

      <div class="grid-2" style="align-items:start">
        <div>
          <p class="muted" style="margin:0 0 8px;font-size:12px;letter-spacing:.08em;text-transform:uppercase">SMTP (outgoing email)</p>
          <div class="field" style="margin-bottom:10px"><label>Host</label><input name="smtp_host" value="<?= e(setting('smtp_host')) ?>" placeholder="smtp.gmail.com"></div>
          <div class="choice-row" style="margin-bottom:10px">
            <div class="field"><label>Port</label><input name="smtp_port" value="<?= e(setting('smtp_port', '587')) ?>" placeholder="587"></div>
            <div class="field"><label>Encryption</label>
              <select name="smtp_encryption">
                <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $v): ?>
                  <option value="<?= e($k) ?>" <?= setting('smtp_encryption', 'tls') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field" style="margin-bottom:10px"><label>Username</label><input name="smtp_user" value="<?= e(setting('smtp_user')) ?>" placeholder="alerts@hospital.org"></div>
          <div class="field" style="margin-bottom:10px"><label>Password</label><input type="password" name="smtp_pass" placeholder="<?= setting('smtp_pass') !== '' ? '•••••••• (saved — leave blank to keep)' : '' ?>"></div>
          <div class="choice-row" style="margin-bottom:10px">
            <div class="field"><label>From email</label><input name="smtp_from_email" value="<?= e(setting('smtp_from_email')) ?>" placeholder="alerts@hospital.org"></div>
            <div class="field"><label>From name</label><input name="smtp_from_name" value="<?= e(setting('smtp_from_name')) ?>" placeholder="<?= e(product_name()) ?>"></div>
          </div>
          <div class="choice-row" style="align-items:end;margin-top:6px">
            <div class="field"><label>Send a test email to</label><input name="test_email_to" form="test-email-form" type="email" value="<?= e($u['email'] ?? '') ?>"></div>
            <div class="field"><button class="btn btn-ghost" type="submit" form="test-email-form">Send test</button></div>
          </div>
        </div>
        <div>
          <p class="muted" style="margin:0 0 8px;font-size:12px;letter-spacing:.08em;text-transform:uppercase">WhatsApp</p>
          <div class="field" style="margin-bottom:10px"><label>Provider</label>
            <select name="whatsapp_provider">
              <?php foreach (['generic' => 'Generic webhook', 'meta' => 'Meta WhatsApp Cloud API', 'twilio' => 'Twilio WhatsApp API'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= setting('whatsapp_provider', 'generic') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="margin-bottom:10px">
            <label>Endpoint URL</label>
            <input name="whatsapp_endpoint" value="<?= e(setting('whatsapp_endpoint')) ?>" placeholder="https://graph.facebook.com/v20.0/&lt;phone-number-id&gt;/messages">
            <p class="muted" style="margin:6px 0 0">Meta: your Cloud API messages URL. Twilio: leave blank to use the standard Twilio endpoint. Generic: your own gateway URL.</p>
          </div>
          <div class="field" style="margin-bottom:10px"><label>Access token / Auth token</label><input type="password" name="whatsapp_token" placeholder="<?= setting('whatsapp_token') !== '' ? '•••••••• (saved — leave blank to keep)' : '' ?>"></div>
          <div class="choice-row" style="margin-bottom:10px">
            <div class="field"><label>Twilio Account SID <span class="muted">(Twilio only)</span></label><input name="whatsapp_sid" value="<?= e(setting('whatsapp_sid')) ?>" placeholder="ACxxxxxxxx"></div>
            <div class="field"><label>Sending number <span class="muted">(Twilio, no +)</span></label><input name="whatsapp_from" value="<?= e(setting('whatsapp_from')) ?>" placeholder="14155238886"></div>
          </div>
          <div class="choice-row" style="align-items:end;margin-top:6px">
            <div class="field"><label>Send a test message to</label><input name="test_whatsapp_to" form="test-whatsapp-form" value="<?= e($u['phone'] ?? '') ?>" placeholder="9198xxxxxxx"></div>
            <div class="field"><button class="btn btn-ghost" type="submit" form="test-whatsapp-form">Send test</button></div>
          </div>
        </div>
      </div>

      <div class="field" style="margin:18px 0;max-width:420px">
        <label>App URL <span class="muted">(used for links inside notification emails)</span></label>
        <input name="app_base_url" value="<?= e(setting('app_base_url')) ?>" placeholder="https://events.yourhospital.org">
      </div>

      <button class="btn btn-teal" type="submit">Save notification settings</button>
    </form>
    <form id="test-email-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_email"></form>
    <form id="test-whatsapp-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_whatsapp"></form>

    <div class="template-cols" style="margin-top:18px">
      <strong>Scheduled jobs (overdue digests, event reminders, retrying failed sends)</strong> run from <code>cron.php</code>.
      Ask your host to run <code>php <?= e(__DIR__) ?>/cron.php</code> once a day, or — if only URL-based cron is available —
      fetch <code><?= e(rtrim(app_base_url(), '/') ?: 'https://your-domain') ?>/cron.php?token=<?= e(setting('cron_secret')) ?></code> on a daily schedule.
      Keep that token private — anyone with it can trigger the job.
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
