-- ============================================================
-- Building & Apartment Property Management System
-- MySQL Database Schema
-- Run: mysql -u root -p < schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS property_mgmt
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE property_mgmt;

-- 1. Admin / Accountant / Staff Users
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(120) UNIQUE NOT NULL,
    phone         VARCHAR(30),
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','accountant','staff') DEFAULT 'accountant',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Buildings
CREATE TABLE buildings (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    location   VARCHAR(255),
    total_floors INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Units (Shops / Apartments / Offices / Warehouses)
CREATE TABLE units (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    building_id       INT NOT NULL,
    floor_number      INT NOT NULL,
    unit_code         VARCHAR(50) NOT NULL,
    unit_type         ENUM('shop','apartment','office','warehouse') DEFAULT 'shop',
    area_sqm          DECIMAL(8,2) DEFAULT 0.00,
    base_monthly_rent DECIMAL(12,2) NOT NULL,
    service_charge    DECIMAL(12,2) DEFAULT 0.00,
    status            ENUM('vacant','occupied','maintenance') DEFAULT 'vacant',
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
    UNIQUE KEY unique_unit_per_building (building_id, unit_code)
) ENGINE=InnoDB;

-- 4. Tenants & Lease Contracts
CREATE TABLE leases (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    unit_id         INT NOT NULL,
    tenant_name     VARCHAR(150) NOT NULL,
    tenant_phone    VARCHAR(30) NOT NULL,
    tenant_email    VARCHAR(120),
    tin_number      VARCHAR(50),
    access_code     VARCHAR(64) UNIQUE NOT NULL,
    monthly_rent    DECIMAL(12,2) NOT NULL,
    deposit_amount  DECIMAL(12,2) DEFAULT 0.00,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    status          ENUM('active','expired','terminated') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Monthly Invoices (Rent + Service Charge + Shared Utility + VAT)
CREATE TABLE invoices (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    lease_id      INT NOT NULL,
    billing_month CHAR(7) NOT NULL,
    rent_amount     DECIMAL(12,2) NOT NULL,
    service_amount  DECIMAL(12,2) DEFAULT 0.00,
    utility_share   DECIMAL(12,2) DEFAULT 0.00,
    vat_amount      DECIMAL(12,2) DEFAULT 0.00,
    total_amount    DECIMAL(12,2) NOT NULL,
    paid_amount     DECIMAL(12,2) DEFAULT 0.00,
    due_date      DATE NOT NULL,
    status        ENUM('unpaid','partial','pending_verification','paid','overdue') DEFAULT 'unpaid',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    UNIQUE KEY unique_lease_month (lease_id, billing_month)
) ENGINE=InnoDB;

-- 6. Payments (Telebirr / CBE / Bank Transfer / Chapa / Cash)
CREATE TABLE payments (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id       INT NOT NULL,
    amount_etb       DECIMAL(12,2) NOT NULL,
    payment_method   ENUM('telebirr','cbe_birr','bank_transfer','chapa','cash') NOT NULL,
    transaction_ref  VARCHAR(120),
    receipt_path     VARCHAR(255),
    status           ENUM('pending','approved','rejected') DEFAULT 'pending',
    rejection_reason VARCHAR(255),
    verified_by      INT NULL,
    verified_at      DATETIME NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 7. Shared Building Expenses (Generator fuel, water, guards, cleaning)
CREATE TABLE building_expenses (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    building_id  INT NOT NULL,
    billing_month CHAR(7) NOT NULL,
    category     ENUM('generator_fuel','water','security','cleaning','other') NOT NULL,
    amount_etb   DECIMAL(12,2) NOT NULL,
    split_method ENUM('equal_occupied','by_area_sqm') DEFAULT 'by_area_sqm',
    notes        TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. Maintenance / Repair Requests (submitted by tenants)
CREATE TABLE maintenance_requests (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    lease_id    INT NOT NULL,
    title       VARCHAR(200) NOT NULL,
    description TEXT,
    priority    ENUM('low','medium','high','urgent') DEFAULT 'medium',
    status      ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    admin_notes TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Seed Data — Default Admin User
-- Password: admin123 (change immediately after first login!)
-- Hash generated with: password_hash('admin123', PASSWORD_DEFAULT)
-- ============================================================
INSERT INTO users (full_name, email, phone, password_hash, role)
VALUES (
    'System Administrator',
    'admin@property.local',
    '+251900000000',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin'
);

-- Sample Building
INSERT INTO buildings (name, location, total_floors)
VALUES ('Bole Tower', 'Bole Road, Addis Ababa', 5);

-- Sample Units
INSERT INTO units (building_id, floor_number, unit_code, unit_type, area_sqm, base_monthly_rent, service_charge, status) VALUES
(1, 0, 'G-01', 'shop',      45.00,  25000.00, 2000.00, 'vacant'),
(1, 0, 'G-02', 'shop',      55.00,  30000.00, 2500.00, 'vacant'),
(1, 1, '1st-101', 'office',   80.00,  40000.00, 3500.00, 'vacant'),
(1, 1, '1st-102', 'office',   65.00,  35000.00, 3000.00, 'vacant'),
(1, 2, '2nd-201', 'apartment', 90.00,  20000.00, 1500.00, 'vacant'),
(1, 2, '2nd-202', 'apartment', 85.00,  18000.00, 1500.00, 'vacant'),
(1, 3, '3rd-301', 'apartment', 95.00,  22000.00, 1800.00, 'vacant'),
(1, 3, '3rd-302', 'apartment', 88.00,  20000.00, 1800.00, 'vacant'),
(1, 4, '4th-401', 'apartment',100.00,  25000.00, 2000.00, 'vacant'),
(1, 4, '4th-402', 'apartment', 92.00,  23000.00, 2000.00, 'vacant');
