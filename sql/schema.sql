-- EventGrant — Hospital event sponsorships
-- MySQL 5.7+ / MariaDB 10.4+  |  utf8mb4

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','marketing','doctor','pharmacy','finance','coordinator') NOT NULL DEFAULT 'coordinator',
  department VARCHAR(80) DEFAULT NULL,
  unit_code VARCHAR(8) DEFAULT NULL,
  designation VARCHAR(80) DEFAULT NULL,
  phone VARCHAR(24) DEFAULT NULL,
  avatar VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(24) NOT NULL UNIQUE,
  title VARCHAR(200) NOT NULL,
  event_type ENUM('CME','Conference','Workshop','Health Camp','Product Launch','Advisory Board','Dinner Meeting','Webinar','Other') NOT NULL DEFAULT 'CME',
  description TEXT,
  venue VARCHAR(200) DEFAULT NULL,
  city VARCHAR(80) DEFAULT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  expected_attendees INT UNSIGNED DEFAULT 0,
  budget_estimate DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status ENUM('draft','planned','ongoing','completed','cancelled') NOT NULL DEFAULT 'draft',
  unit_code VARCHAR(8) NOT NULL DEFAULT 'HTC',
  funding_mode ENUM('sponsored','unsponsored') NOT NULL DEFAULT 'sponsored',
  sponsorship_target DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  registration_target DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  marketing_lead_id INT UNSIGNED DEFAULT NULL,
  doctor_id INT UNSIGNED DEFAULT NULL,
  pharmacy_head_id INT UNSIGNED DEFAULT NULL,
  coordinator_id INT UNSIGNED DEFAULT NULL,
  notes TEXT,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ev_mkt FOREIGN KEY (marketing_lead_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ev_doc FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ev_pharm FOREIGN KEY (pharmacy_head_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ev_coord FOREIGN KEY (coordinator_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ev_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
  code VARCHAR(8) NOT NULL PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  notes TEXT,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL UNIQUE,
  icon VARCHAR(40) DEFAULT 'circle',
  color VARCHAR(16) DEFAULT '#1a7a6d',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  booking_type ENUM('purchase','ecm') NOT NULL DEFAULT 'purchase',
  title VARCHAR(200) NOT NULL,
  vendor VARCHAR(160) DEFAULT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  expense_date DATE NOT NULL,
  payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  payment_mode ENUM('cash','bank','upi','card','cheque','other') DEFAULT NULL,
  invoice_no VARCHAR(80) DEFAULT NULL,
  po_no VARCHAR(80) DEFAULT NULL,
  wo_no VARCHAR(80) DEFAULT NULL,
  order_date DATE DEFAULT NULL,
  vendor_gstin VARCHAR(20) DEFAULT NULL,
  ecm_no VARCHAR(80) DEFAULT NULL,
  ecm_date DATE DEFAULT NULL,
  claimant VARCHAR(120) DEFAULT NULL,
  ecm_approved_by VARCHAR(120) DEFAULT NULL,
  bill_path VARCHAR(255) DEFAULT NULL,
  notes TEXT,
  recorded_by INT UNSIGNED DEFAULT NULL,
  approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  approved_by INT UNSIGNED DEFAULT NULL,
  approved_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_exp_po (po_no),
  INDEX idx_exp_wo (wo_no),
  UNIQUE KEY uq_exp_ecm (ecm_no),
  CONSTRAINT fk_ex_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_ex_cat FOREIGN KEY (category_id) REFERENCES expense_categories(id),
  CONSTRAINT fk_ex_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_id INT UNSIGNED NOT NULL,
  event_id INT UNSIGNED DEFAULT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(40) NOT NULL,
  details TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eh_exp (expense_id),
  INDEX idx_eh_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  type ENUM('pharma','device','corporate','individual','ngo','other') NOT NULL DEFAULT 'pharma',
  contact_person VARCHAR(120) DEFAULT NULL,
  email VARCHAR(160) DEFAULT NULL,
  phone VARCHAR(24) DEFAULT NULL,
  city VARCHAR(80) DEFAULT NULL,
  gstin VARCHAR(20) DEFAULT NULL,
  notes TEXT,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsorships (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  sponsor_id INT UNSIGNED NOT NULL,
  promised_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  promised_date DATE NOT NULL,
  status ENUM('promised','partial','received','cancelled') NOT NULL DEFAULT 'promised',
  liaison_user_id INT UNSIGNED DEFAULT NULL,
  notes TEXT,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sp_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors(id),
  CONSTRAINT fk_sp_liaison FOREIGN KEY (liaison_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sp_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsorship_receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sponsorship_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  received_date DATE NOT NULL,
  payment_mode ENUM('cash','bank','upi','card','cheque','other') DEFAULT 'bank',
  reference_no VARCHAR(80) DEFAULT NULL,
  notes TEXT,
  recorded_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rc_sp FOREIGN KEY (sponsorship_id) REFERENCES sponsorships(id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrations (
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
  UNIQUE KEY uq_reg_event_no (event_id, registration_no),
  CONSTRAINT fk_reg_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_reg_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) DEFAULT NULL,
  entity_id INT UNSIGNED DEFAULT NULL,
  details TEXT,
  ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(80) NOT NULL UNIQUE,
  setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
