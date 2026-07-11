-- ADENA STORE - KPI, KEUANGAN, DAN FONDASI SINKRONISASI
-- Aman untuk database berjalan: hanya CREATE TABLE IF NOT EXISTS dan INSERT IGNORE.
-- Tidak menghapus/mengubah tabel transaksi POS lama.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS store_kpi_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  kpi_name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  weight DECIMAL(8,4) NOT NULL DEFAULT 0,
  max_score DECIMAL(8,4) NOT NULL DEFAULT 100,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_kpi_types_uuid (record_uuid),
  KEY idx_store_kpi_types_active (is_active,sort_order),
  UNIQUE KEY uq_store_kpi_types_name (kpi_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_kpi_assessments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  period_month DATE NOT NULL,
  employee_id BIGINT NOT NULL,
  employee_name_snapshot VARCHAR(160) NOT NULL,
  employee_role_snapshot VARCHAR(100) NULL,
  status ENUM('draft','final','locked','cancelled') NOT NULL DEFAULT 'draft',
  total_weight DECIMAL(8,4) NOT NULL DEFAULT 0,
  final_score DECIMAL(10,4) NOT NULL DEFAULT 0,
  general_notes TEXT NULL,
  assessed_by BIGINT NULL,
  finalized_by BIGINT NULL,
  finalized_at DATETIME NULL,
  locked_at DATETIME NULL,
  version_no INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_kpi_assessment_period_employee (period_month,employee_id),
  UNIQUE KEY uq_store_kpi_assessment_uuid (record_uuid),
  KEY idx_store_kpi_assessment_period (period_month,status),
  KEY idx_store_kpi_assessment_employee (employee_id,period_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_kpi_assessment_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  assessment_id BIGINT UNSIGNED NOT NULL,
  kpi_type_id BIGINT UNSIGNED NULL,
  kpi_name_snapshot VARCHAR(160) NOT NULL,
  max_score_snapshot DECIMAL(8,4) NOT NULL DEFAULT 100,
  weight_snapshot DECIMAL(8,4) NOT NULL DEFAULT 0,
  score DECIMAL(8,4) NOT NULL DEFAULT 0,
  weighted_score DECIMAL(10,4) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_kpi_item_uuid (record_uuid),
  KEY idx_store_kpi_item_assessment (assessment_id,sort_order),
  KEY idx_store_kpi_item_type (kpi_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_kpi_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assessment_id BIGINT UNSIGNED NULL,
  action_key VARCHAR(50) NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  payload_json LONGTEXT NULL,
  acted_by BIGINT NULL,
  acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_store_kpi_audit_assessment (assessment_id,acted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  category_code VARCHAR(80) NOT NULL,
  category_name VARCHAR(160) NOT NULL,
  group_name VARCHAR(120) NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  requires_approval TINYINT(1) NOT NULL DEFAULT 0,
  requires_evidence TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_categories_code (category_code),
  UNIQUE KEY uq_expense_categories_uuid (record_uuid),
  KEY idx_expense_categories_active (is_active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  expense_no VARCHAR(80) NOT NULL,
  expense_date DATE NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  category_name_snapshot VARCHAR(160) NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  vendor_name VARCHAR(190) NULL,
  payment_method VARCHAR(80) NULL,
  reference_no VARCHAR(120) NULL,
  evidence_reference VARCHAR(255) NULL,
  status ENUM('draft','submitted','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'paid',
  due_date DATE NULL,
  approved_by BIGINT NULL,
  approved_at DATETIME NULL,
  paid_by BIGINT NULL,
  paid_at DATETIME NULL,
  created_by BIGINT NULL,
  version_no INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expenses_no (expense_no),
  UNIQUE KEY uq_expenses_uuid (record_uuid),
  KEY idx_expenses_date_status (expense_date,status),
  KEY idx_expenses_category (category_id,expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  request_no VARCHAR(80) NOT NULL,
  request_date DATE NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  category_name_snapshot VARCHAR(160) NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  vendor_name VARCHAR(190) NULL,
  due_date DATE NULL,
  reference_no VARCHAR(120) NULL,
  evidence_reference VARCHAR(255) NULL,
  status ENUM('draft','submitted','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  requested_by BIGINT NULL,
  approved_by BIGINT NULL,
  approved_at DATETIME NULL,
  paid_by BIGINT NULL,
  paid_at DATETIME NULL,
  linked_expense_id BIGINT UNSIGNED NULL,
  rejection_reason TEXT NULL,
  version_no INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_requests_no (request_no),
  UNIQUE KEY uq_payment_requests_uuid (record_uuid),
  KEY idx_payment_requests_date_status (request_date,status),
  KEY idx_payment_requests_due (due_date,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  action_key VARCHAR(50) NOT NULL,
  payload_json LONGTEXT NULL,
  acted_by BIGINT NULL,
  acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_finance_audit_entity (entity_type,entity_id,acted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_uuid CHAR(36) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id VARCHAR(120) NOT NULL,
  operation VARCHAR(30) NOT NULL,
  entity_version INT NOT NULL DEFAULT 1,
  payload_json LONGTEXT NOT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sync_status ENUM('pending','processing','synced','failed') NOT NULL DEFAULT 'pending',
  retry_count INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  synced_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_outbox_event (event_uuid),
  KEY idx_sync_outbox_status (sync_status,available_at),
  KEY idx_sync_outbox_entity (entity_type,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO store_kpi_types(record_uuid,kpi_name,description,weight,max_score,sort_order,is_active)
VALUES
(UUID(),'Absensi','Kehadiran dan ketepatan waktu pegawai.',25,100,10,1),
(UUID(),'Performance','Kualitas dan pencapaian pekerjaan bulanan.',35,100,20,1),
(UUID(),'Disiplin','Kepatuhan terhadap SOP dan tata tertib.',20,100,30,1),
(UUID(),'Pelayanan','Kualitas pelayanan kepada pelanggan.',20,100,40,1);

INSERT IGNORE INTO expense_categories(record_uuid,category_code,category_name,group_name,sort_order,is_active)
VALUES
(UUID(),'UTIL-ELECTRICITY','Listrik','Utilitas',10,1),
(UUID(),'UTIL-WATER','Air','Utilitas',20,1),
(UUID(),'UTIL-INTERNET','Internet','Utilitas',30,1),
(UUID(),'RENT','Sewa','Operasional',40,1),
(UUID(),'MAINTENANCE','Perawatan / Servis','Operasional',50,1),
(UUID(),'TRANSPORT','Transportasi / Bahan Bakar','Operasional',60,1),
(UUID(),'CLEANING','Kebersihan','Operasional',70,1),
(UUID(),'OFFICE-SUPPLIES','Perlengkapan Kecil / ATK','Operasional',80,1),
(UUID(),'BANK-ADMIN','Administrasi Bank','Administrasi',90,1),
(UUID(),'OTHER','Lain-lain','Lainnya',999,1);
