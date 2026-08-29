<?php
declare(strict_types=1);
session_start();

if (is_file(__DIR__ . '/includes/config.php')) {
    header('Location: index.php');
    exit;
}

$error = '';
$ok = false;

function run_schema(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

function seed(PDO $pdo): void
{
    $cats = [
        ['Accommodation', 'accommodation', 'bed', '#3a6ea5', 1],
        ['Food & Beverage', 'food-beverage', 'utensils', '#1b6e64', 2],
        ['Liquor', 'liquor', 'glass', '#8a3a6e', 3],
        ['AV Setup', 'av-setup', 'monitor', '#c4892a', 4],
        ['Music', 'music', 'music', '#6b4c9a', 4],
        ['Stalls', 'stalls', 'store', '#3d6b9a', 5],
        ['Gifts', 'gifts', 'gift', '#c4a35a', 6],
        ['Travel Tickets', 'travel', 'plane', '#2f7d5b', 7],
        ['Venue Hire', 'venue', 'building', '#5a6d80', 8],
        ['Printing & Collaterals', 'printing', 'printer', '#6d7a86', 9],
        ['Honorarium', 'honorarium', 'award', '#c45c4a', 10],
        ['Miscellaneous', 'misc', 'more', '#6d7a86', 11],
    ];
    $insCat = $pdo->prepare('INSERT INTO expense_categories (name, slug, icon, color, sort_order) VALUES (?,?,?,?,?)');
    foreach ($cats as $c) {
        $insCat->execute($c);
    }

    $hash = password_hash('Admin@123', PASSWORD_BCRYPT);
    $demo = password_hash('Demo@123', PASSWORD_BCRYPT);
    $users = [
        ['Dr. Ananya Mehta', 'admin@hospital.local', $hash, 'admin', 'Administration', 'Medical Superintendent', '9876500001', null],
        ['Rahul Deshpande', 'marketing@hospital.local', $demo, 'marketing', 'Marketing · HTC', 'Head of Marketing', '9876500002', 'HTC'],
        ['Dr. Vikram Shah', 'doctor@hospital.local', $demo, 'doctor', 'Cardiology', 'Senior Consultant', '9876500003', 'HTC'],
        ['Priya Nair', 'pharmacy@hospital.local', $demo, 'pharmacy', 'Pharmacy · HTC', 'Pharmacy Head', '9876500004', 'HTC'],
        ['Sanjay Kulkarni', 'finance@hospital.local', $demo, 'finance', 'Finance', 'Accounts Manager', '9876500005', null],
        ['Neha Joshi', 'coordinator@hospital.local', $demo, 'coordinator', 'Events · HTC', 'Event Coordinator', '9876500006', 'HTC'],
        ['Kavya Patil', 'marketing.sec@hospital.local', $demo, 'marketing', 'Marketing · SEC', 'Unit Marketing Head', '9876500007', 'SEC'],
        ['Sameer Khan', 'marketing.smj@hospital.local', $demo, 'marketing', 'Marketing · SMJ', 'Unit Marketing Head', '9876500008', 'SMJ'],
        ['Anita Lopes', 'marketing.mlk@hospital.local', $demo, 'marketing', 'Marketing · MLK', 'Unit Marketing Head', '9876500009', 'MLK'],
        ['Ravi More', 'coordinator.sec@hospital.local', $demo, 'coordinator', 'Events · SEC', 'Event Coordinator', '9876500010', 'SEC'],
        ['Sneha Kadam', 'coordinator.smj@hospital.local', $demo, 'coordinator', 'Events · SMJ', 'Event Coordinator', '9876500011', 'SMJ'],
        ['Imran Shaikh', 'coordinator.mlk@hospital.local', $demo, 'coordinator', 'Events · MLK', 'Event Coordinator', '9876500012', 'MLK'],
    ];
    $insU = $pdo->prepare('INSERT INTO users (name, email, password, role, department, designation, phone, unit_code) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($users as $u) {
        $insU->execute($u);
    }

    $settings = [
        ['hospital_name', 'City Care Multispeciality Hospital'],
        ['hospital_city', 'Pune'],
        ['currency', 'INR'],
        ['fy_start_month', '4'],
        ['expense_approval_limit', '50000'],
    ];
    $insS = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)');
    foreach ($settings as $s) {
        $insS->execute($s);
    }

    $units = [
        ['HTC', 'HTC', 'Unit marketing, doctors and coordinators run local programmes. Purchase (PO/WO) and finance approval are hospital-wide.', 1],
        ['SEC', 'SEC', 'Unit marketing, doctors and coordinators run local programmes. Purchase (PO/WO) and finance approval are hospital-wide.', 2],
        ['SMJ', 'SMJ', 'Unit marketing, doctors and coordinators run local programmes. Purchase (PO/WO) and finance approval are hospital-wide.', 3],
        ['MLK', 'MLK', 'Unit marketing, doctors and coordinators run local programmes. Purchase (PO/WO) and finance approval are hospital-wide.', 4],
    ];
    $insUnit = $pdo->prepare('INSERT INTO units (code, name, notes, sort_order) VALUES (?,?,?,?)');
    foreach ($units as $u) {
        $insUnit->execute($u);
    }

    $sponsors = [
        ['Apex Pharma Ltd', 'pharma', 'Meera Kapoor', 'meera@apexpharma.example', '9811100101', 'Mumbai', '27AAPCA1234A1Z1'],
        ['MediLife Laboratories', 'pharma', 'Arjun Rao', 'arjun@medilife.example', '9811100102', 'Hyderabad', '36AAPCM5678B1Z2'],
        ['Nova Devices', 'device', 'Kavita Iyer', 'kavita@novadevices.example', '9811100103', 'Bengaluru', '29AAPCN9012C1Z3'],
        ['Sunrise Wellness Trust', 'ngo', 'Imran Khan', 'imran@sunrise.example', '9811100104', 'Pune', ''],
    ];
    $insSp = $pdo->prepare('INSERT INTO sponsors (name, type, contact_person, email, phone, city, gstin) VALUES (?,?,?,?,?,?,?)');
    foreach ($sponsors as $s) {
        $insSp->execute($s);
    }

    $events = [
        ['EVT-2026-001', 'Cardiology CME — Heart Failure Update', 'CME', 'Full-day CME for consultants and residents with live case discussion.', 'Hotel Conrad, Bund Garden', 'Pune', '2026-04-18', '2026-04-18', 140, 850000, 'completed', 'HTC', 'sponsored', 700000, 2, 3, 4, 6, 1],
        ['EVT-2026-002', 'Oncology Advisory Board', 'Advisory Board', 'Closed-door advisory with visiting KOLs.', 'Hospital Auditorium', 'Pune', '2026-06-12', '2026-06-13', 28, 420000, 'planned', 'SEC', 'sponsored', 250000, 7, 3, 4, 10, 7],
        ['EVT-2026-003', 'World Diabetes Day Camp', 'Health Camp', 'Free screening camp with pharmacy stall and patient education.', 'City Care OPD Block', 'Pune', '2026-08-14', '2026-08-14', 400, 180000, 'ongoing', 'SMJ', 'sponsored', 120000, 8, 3, 4, 11, 8],
        ['EVT-2026-004', 'Ortho Device Launch Evening', 'Product Launch', 'Device showcase with cocktail dinner for surgeons.', 'JW Marriott', 'Pune', '2026-09-05', '2026-09-05', 90, 620000, 'draft', 'MLK', 'sponsored', 450000, 9, 3, 4, 12, 9],
        ['EVT-2026-005', 'Nursing Skills Workshop', 'Workshop', 'In-house skills drill. No industry support — hospital funded.', 'Skill Lab, 3rd Floor', 'Pune', '2026-08-28', '2026-08-28', 40, 45000, 'planned', 'HTC', 'unsponsored', 0, 2, 3, 4, 6, 6],
    ];
    $insE = $pdo->prepare('INSERT INTO events (code, title, event_type, description, venue, city, start_date, end_date, expected_attendees, budget_estimate, status, unit_code, funding_mode, sponsorship_target, marketing_lead_id, doctor_id, pharmacy_head_id, coordinator_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($events as $e) {
        $insE->execute($e);
    }

    $expenses = [
        [1, 1, 'Faculty rooms — 8 keys', 'Hotel Conrad', 168000, '2026-04-17', 'paid', 168000, 'bank', 'INV-C-441'],
        [1, 2, 'Lunch & high tea for 140', 'Conrad Banquets', 210000, '2026-04-18', 'paid', 210000, 'bank', 'INV-C-442'],
        [1, 4, 'LED wall, mics, recording', 'SoundCraft AV', 95000, '2026-04-18', 'paid', 95000, 'upi', 'SC-8891'],
        [1, 6, 'Delegate kits & mementos', 'PrintMint', 42000, '2026-04-10', 'paid', 42000, 'upi', 'PM-220'],
        [1, 7, 'Faculty air tickets (3)', 'MakeMyTrip Biz', 54000, '2026-04-12', 'paid', 54000, 'card', 'MMT-19'],
        [2, 1, 'KOL stay — 4 rooms, 2 nights', 'Conrad', 96000, '2026-06-11', 'unpaid', 0, null, ''],
        [2, 2, 'Working lunch', 'In-house F&B', 38000, '2026-06-12', 'unpaid', 0, null, ''],
        [3, 5, 'Pharmacy & screening stalls', 'EventWorks', 45000, '2026-08-14', 'partial', 20000, 'cash', 'EW-77'],
        [3, 2, 'Patient refreshments', 'Hospital Kitchen', 18000, '2026-08-14', 'paid', 18000, 'cash', 'KIT-08'],
        [3, 9, 'Banners and standees', 'PrintMint', 12000, '2026-08-08', 'paid', 12000, 'upi', 'PM-301'],
        [5, 9, 'Workbooks and badges', 'PrintMint', 8000, '2026-08-20', 'paid', 8000, 'upi', 'PM-410'],
        [5, 2, 'Tea and snacks', 'Hospital Kitchen', 6000, '2026-08-28', 'unpaid', 0, null, ''],
    ];
    $insX = $pdo->prepare('INSERT INTO expenses (event_id, category_id, title, vendor, amount, expense_date, payment_status, paid_amount, payment_mode, invoice_no, recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,6)');
    foreach ($expenses as $x) {
        $insX->execute($x);
    }
    $pdo->exec("UPDATE expenses SET approval_status = 'approved', approved_by = 5, approved_at = NOW() WHERE approval_status IS NULL OR approval_status = '' OR approval_status = 'approved'");
    $pdo->exec("UPDATE expenses SET po_no = CONCAT('PO-', LPAD(id,4,'0')), order_date = expense_date, booking_type = 'purchase'");
    $pdo->exec("UPDATE expenses SET booking_type = 'ecm', po_no = NULL, order_date = NULL,
        ecm_no = CONCAT('ECM-', LPAD(id,4,'0')), ecm_date = expense_date, claimant = 'Neha Joshi', ecm_approved_by = 'Sanjay Kulkarni'
        WHERE title LIKE '%ticket%' OR title LIKE '%memento%' OR title LIKE '%Tea and snacks%' OR title LIKE '%refreshment%' OR title LIKE '%Workbooks%' OR title LIKE '%air tickets%' OR title LIKE '%Delegate kit%'");

    $sps = [
        [1, 1, 500000, '2026-03-01', 'received', 2, 'Platinum scientific grant'],
        [1, 2, 200000, '2026-03-12', 'partial', 4, 'Satellite symposium'],
        [2, 1, 250000, '2026-05-02', 'promised', 2, 'Advisory honorarium support'],
        [3, 2, 80000, '2026-07-01', 'received', 4, 'Camp glucometer kits'],
        [3, 4, 40000, '2026-07-15', 'promised', 2, 'Patient education grant'],
        [4, 3, 450000, '2026-08-01', 'promised', 2, 'Device launch underwriting'],
    ];
    $insP = $pdo->prepare('INSERT INTO sponsorships (event_id, sponsor_id, promised_amount, promised_date, status, liaison_user_id, notes, created_by) VALUES (?,?,?,?,?,?,?,2)');
    foreach ($sps as $p) {
        $insP->execute($p);
    }

    $receipts = [
        [1, 500000, '2026-04-02', 'bank', 'NEFT-AX12391'],
        [2, 100000, '2026-04-10', 'bank', 'RTGS-ML4401'],
        [4, 80000, '2026-08-05', 'upi', 'UPI-ML-881'],
    ];
    $insR = $pdo->prepare('INSERT INTO sponsorship_receipts (sponsorship_id, amount, received_date, payment_mode, reference_no, recorded_by) VALUES (?,?,?,?,?,5)');
    foreach ($receipts as $r) {
        $insR->execute($r);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? 'localhost');
    $port = trim($_POST['port'] ?? '3306');
    $name = trim($_POST['name'] ?? 'hospital_sponsorship');
    $user = trim($_POST['user'] ?? 'root');
    $pass = (string) ($_POST['pass'] ?? '');
    $hospital = trim($_POST['hospital'] ?? 'City Care Multispeciality Hospital');

    $safeName = str_replace('`', '', $name);

    try {
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        try {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $safeName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $e) {
            // cPanel MySQL users usually cannot CREATE DATABASE; the DB must already exist.
        }
        $pdo->exec('USE `' . $safeName . '`');
        $existing = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
        if ($existing) {
            throw new RuntimeException('This database already has EventGrant tables. Create an empty database in cPanel, or drop the old tables first.');
        }
        run_schema($pdo);
        seed($pdo);
        $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = "hospital_name"')->execute([$hospital]);

        $cfg = "<?php\nreturn [\n    'host' => " . var_export($host, true) . ",\n    'port' => " . var_export($port, true) . ",\n    'name' => " . var_export($name, true) . ",\n    'user' => " . var_export($user, true) . ",\n    'pass' => " . var_export($pass, true) . ",\n];\n";
        if (file_put_contents(__DIR__ . '/includes/config.php', $cfg) === false) {
            throw new RuntimeException('Could not write includes/config.php. Check folder permissions.');
        }
        $ok = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Install EventGrant</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="install-body">
  <div class="install-card">
    <p class="muted" style="letter-spacing:.16em;text-transform:uppercase;font-size:11px">EventGrant · Hospital event sponsorships</p>
    <h2 style="font-family:Fraunces,serif;margin:6px 0 8px">Set up your workspace</h2>
    <p class="muted">On cPanel, create the MySQL database and user first, then enter those details here. Tables, demo users, and sample events are created in one step.</p>
    <?php if ($ok): ?>
      <div class="alert ok">Installed. You can sign in now.</div>
      <p><strong>Admin:</strong> admin@hospital.local / Admin@123</p>
      <p class="muted">Department logins use Demo@123 (marketing, doctor, pharmacy, finance, coordinator).</p>
      <p><a class="btn btn-brass" href="index.php">Open sign in</a></p>
    <?php else: ?>
      <?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post" class="stack" style="margin-top:16px">
        <div class="form-grid">
          <div class="field"><label>MySQL host</label><input name="host" value="localhost" required></div>
          <div class="field"><label>Port</label><input name="port" value="3306" required></div>
          <div class="field full"><label>Database name</label><input name="name" value="hospital_sponsorship" required></div>
          <div class="field"><label>MySQL user</label><input name="user" value="" placeholder="cpaneluser_dbuser" required></div>
          <div class="field"><label>MySQL password</label><input name="pass" type="password" placeholder="Database user password"></div>
          <div class="field full"><label>Hospital name</label><input name="hospital" value="City Care Multispeciality Hospital" required></div>
        </div>
        <div class="modal-actions" style="margin:0">
          <button class="btn btn-brass" type="submit">Create database & continue</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
