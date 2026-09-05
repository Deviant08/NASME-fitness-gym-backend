-- ════════════════════════════════════════
--  NASME GYM — Database Setup
--  Run this once in phpMyAdmin or MySQL CLI
--  mysql -u root nasme_gym < setup.sql
-- ════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS nasme_gym CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nasme_gym;

-- ── USERS (staff / admin accounts) ───────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(60)  NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,          -- bcrypt hash
  full_name  VARCHAR(120) NOT NULL,
  role       ENUM('superadmin','admin','staff') NOT NULL DEFAULT 'staff',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed: admin / admin123  |  staff / staff123
INSERT IGNORE INTO users (username, password, full_name, role) VALUES
  ('admin', '$2b$10$UUUQtcJHdC7KJ/PzCvE.5.XTcmRtA63pBDOfvoUsxqkjQuaZSMEcG', 'Admin User',  'superadmin'),
  ('staff', '$2b$10$d7OlFMzMQRX0x4S3wOzUl./eluHKGn6RRb9056kNhkWbUMFmKFxz6', 'Staff Member', 'staff');

-- ── MEMBERS ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS members (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  member_code      VARCHAR(20)  NOT NULL UNIQUE,  -- e.g. MBR-001
  full_name        VARCHAR(120) NOT NULL,
  phone            VARCHAR(20)  NOT NULL,
  email            VARCHAR(120),
  date_of_birth    DATE,
  gender           ENUM('Male','Female','Other'),
  plan             ENUM('Daily','Weekly','Monthly','Yearly') NOT NULL DEFAULT 'Monthly',
  status           ENUM('Active','Expired','Pending','Suspended') NOT NULL DEFAULT 'Active',
  checkins         INT NOT NULL DEFAULT 0,
  emergency_contact VARCHAR(120),
  fitness_goal     VARCHAR(200),
  medical_notes    TEXT,
  joined_at        DATE NOT NULL DEFAULT (CURDATE()),
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO members (member_code, full_name, phone, plan, status, checkins, joined_at) VALUES
  ('MBR-001', 'Nwokolo Precious',  '08012345678', 'Monthly', 'Active',    28, '2025-01-10'),
  ('MBR-002', 'Olalekan Adeyemi',  '08023456789', 'Yearly',  'Active',    45, '2024-11-20'),
  ('MBR-003', 'Tunde Fashola',     '08034567890', 'Weekly',  'Expired',    8, '2025-04-01'),
  ('MBR-004', 'Okpe Confidence',   '08045678901', 'Monthly', 'Active',    19, '2025-02-14'),
  ('MBR-005', 'Usiju Meduju',      '08056789012', 'Daily',   'Pending',    2, '2025-05-15');

-- ── PAYMENTS ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  txn_code    VARCHAR(20)  NOT NULL UNIQUE,        -- e.g. TXN-001
  member_id   INT          NOT NULL,
  plan        ENUM('Daily','Weekly','Monthly','Yearly') NOT NULL,
  amount      DECIMAL(10,2) NOT NULL,
  method      ENUM('Cash','Card','Mobile Money','Bank Transfer') NOT NULL DEFAULT 'Cash',
  status      ENUM('Completed','Pending','Failed') NOT NULL DEFAULT 'Completed',
  notes       TEXT,
  paid_at     DATE NOT NULL DEFAULT (CURDATE()),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT
);

INSERT IGNORE INTO payments (txn_code, member_id, plan, amount, method, status, paid_at) VALUES
  ('TXN-001', 1, 'Monthly', 12000,  'Card',          'Completed', '2025-05-01'),
  ('TXN-002', 2, 'Yearly',  100000, 'Bank Transfer', 'Completed', '2025-04-20'),
  ('TXN-003', 3, 'Weekly',   5000,  'Cash',          'Completed', '2025-04-28'),
  ('TXN-004', 4, 'Monthly', 12000,  'Mobile Money',  'Pending',   '2025-05-10');

-- ── EQUIPMENT ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS equipment (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  eq_code     VARCHAR(20)  NOT NULL UNIQUE,
  name        VARCHAR(100) NOT NULL,
  category    ENUM('Cardio','Strength','Flexibility','Free Weights','Accessories') NOT NULL,
  quantity    INT NOT NULL DEFAULT 1,
  condition_  ENUM('Excellent','Good','Fair','Needs Repair') NOT NULL DEFAULT 'Good',
  status      ENUM('Available','In Use','Under Maintenance','Retired') NOT NULL DEFAULT 'Available',
  location    VARCHAR(100),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO equipment (eq_code, name, category, quantity, condition_, status, location) VALUES
  ('EQ-01', 'Treadmill',      'Cardio',       4,  'Good',      'Available',         'Floor A'),
  ('EQ-02', 'Bench Press',    'Strength',     6,  'Excellent', 'In Use',            'Floor B'),
  ('EQ-03', 'Rowing Machine', 'Cardio',       2,  'Good',      'Available',         'Floor A'),
  ('EQ-04', 'Dumbbells Set',  'Free Weights', 20, 'Good',      'Available',         'Floor C'),
  ('EQ-05', 'Cycling Bike',   'Cardio',       5,  'Fair',      'Under Maintenance', 'Floor A'),
  ('EQ-06', 'Pull-up Bar',    'Strength',     4,  'Excellent', 'Available',         'Floor B');

-- ── PRODUCTS (store) ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  prd_code    VARCHAR(20)  NOT NULL UNIQUE,
  name        VARCHAR(120) NOT NULL,
  category    ENUM('Gym Wear','Supplements','Accessories','Equipment') NOT NULL,
  price       DECIMAL(10,2) NOT NULL,
  stock       INT NOT NULL DEFAULT 0,
  description TEXT,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO products (prd_code, name, category, price, stock, description) VALUES
  ('PRD-001', 'Protein Whey 1kg',  'Supplements', 8500,  30, 'Premium whey protein blend'),
  ('PRD-002', 'Gym Gloves',        'Accessories', 2500,  50, 'Anti-slip lifting gloves'),
  ('PRD-003', 'Resistance Bands',  'Accessories', 1800,  40, 'Set of 5 resistance levels'),
  ('PRD-004', 'IronCore Hoodie',   'Gym Wear',    7000,  25, 'Premium cotton blend hoodie'),
  ('PRD-005', 'Jump Rope',         'Equipment',   1200,  60, 'Speed skipping rope'),
  ('PRD-006', 'Water Bottle 1L',   'Accessories', 2000,   8, 'BPA-free insulated bottle');

-- ── ORDERS ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS orders (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  order_code  VARCHAR(20)  NOT NULL UNIQUE,
  member_id   INT          NOT NULL,
  product_id  INT          NOT NULL,
  quantity    INT NOT NULL DEFAULT 1,
  total       DECIMAL(10,2) NOT NULL,
  status      ENUM('Pending','Shipped','Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
  ordered_at  DATE NOT NULL DEFAULT (CURDATE()),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id)  REFERENCES members(id)  ON DELETE RESTRICT,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
);

-- ── AUDIT LOG ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT,
  action     TEXT NOT NULL,
  color_tag  VARCHAR(20) DEFAULT 'info',
  logged_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
