# Building & Apartment Property Management System

A PHP/MySQL web application for managing buildings, rental units (shops, apartments, offices, warehouses), tenant leases, monthly billing, payment verification (Telebirr / CBE / Bank), shared building expenses, and maintenance requests.

Built for the Ethiopian property market — all amounts in **ETB** (Ethiopian Birr).

---

## Features

### Admin Panel (`/admin/`)
- **Dashboard** — KPIs: Total Expected, Collected, Overdue, Expiring Leases
- **Buildings & Units** — Add/manage buildings, floors, units with area (m²), rent, service charge
- **Leases** — Assign tenants, set lease dates, generate access codes, terminate leases
- **Generate Bills** — Monthly invoice generation with VAT + shared expense distribution (by area or equal split)
- **Verify Payments** — Review uploaded Telebirr/CBE/bank receipts, approve or reject with transaction safety
- **Maintenance** — Track and update tenant repair requests with priority and status

### Tenant Portal (`/tenant/`)
- **Login** — Secure access via phone number + access code (no password needed)
- **Pay** — View invoices, submit payment proof (upload receipt image/PDF)
- **Request Repair** — Submit maintenance issues with priority levels

### Printable Documents
- **Invoice / Receipt** (`/receipt.php`) — Clean printable invoice or payment receipt (Ctrl+P → Save as PDF)
- **Lease Contract** (`/receipt.php?lease_id=X`) — Full lease contract summary with signature lines

---

## Requirements

- PHP 8.0+ with PDO MySQL extension
- MySQL 5.7+ / MariaDB 10.3+
- Apache/Nginx web server (Apache recommended for .htaccess support)

---

## Installation

### 1. Clone or copy the project
```bash
# Copy property-system/ to your web server document root
# e.g., for XAMPP: C:\xampp\htdocs\property-system\
# e.g., for LAMP: /var/www/html/property-system/
```

### 2. Create the database
```bash
mysql -u root -p < schema.sql
```
This creates the `property_mgmt` database with all 8 tables and seed data (1 admin user + sample building with 10 units).

### 3. Configure database connection
Edit `config/db.php` and update:
```php
const DB_HOST = '127.0.0.1';
const DB_NAME = 'property_mgmt';
const DB_USER = 'root';
const DB_PASS = '';  // ← Your MySQL password
```

### 4. Set permissions
```bash
# Ensure uploads/receipts/ is writable by the web server
chmod 755 uploads/receipts/
```

### 5. Access the application
```
Admin Login:  http://localhost/property-system/admin/login.php
              Email: admin@property.local
              Password: admin123

Tenant Portal: http://localhost/property-system/tenant/index.php
               (Use phone + access code from lease contract)
```

⚠️ **Change the admin password immediately after first login!**

---

## Project Structure
```
property-system/
├── config/
│   ├── db.php                # PDO database connection + helpers
│   └── auth.php              # Auth guards + CSRF helpers
├── uploads/
│   └── receipts/
│       └── .htaccess         # Blocks script execution in upload dir
├── admin/
│   ├── login.php             # Admin/staff login
│   ├── dashboard.php         # KPI dashboard
│   ├── buildings_units.php   # Building & unit management
│   ├── leases.php            # Tenant lease management
│   ├── generate_bills.php    # Monthly invoice + expense split
│   ├── verify_payments.php   # Payment receipt verification
│   └── maintenance.php       # Maintenance request tracking
├── tenant/
│   ├── index.php             # Tenant login (phone + access code)
│   ├── pay.php               # View invoice + upload payment receipt
│   └── request_repair.php    # Submit maintenance request
├── receipt.php               # Printable invoice/receipt/lease contract
├── logout.php                # Session destroy
├── schema.sql                # MySQL database schema + seed data
└── README.md
```

---

## Database Tables
| Table | Description |
|-------|-------------|
| `users` | Admin / accountant / staff accounts |
| `buildings` | Building master records |
| `units` | Individual rental units (shops, apartments, offices) |
| `leases` | Tenant lease contracts with access codes |
| `invoices` | Monthly invoices (rent + service + utility + VAT) |
| `payments` | Payment records with receipt uploads |
| `building_expenses` | Shared building expenses for distribution |
| `maintenance_requests` | Tenant-submitted repair requests |

---

## Security Features
- PDO prepared statements (SQL injection prevention)
- CSRF tokens on all forms
- Password hashing with `password_hash()` / `password_verify()`
- File upload validation (MIME type check, extension whitelist, size limit)
- `.htaccess` blocking script execution in upload directory
- Role-based access control (admin/accountant/staff)
- Transaction-safe payment approval/rejection

---

## Payment Workflow
1. Admin generates monthly invoices (`generate_bills.php`)
2. Tenant logs in and views their invoice (`tenant/pay.php`)
3. Tenant pays via Telebirr/CBE/Bank and uploads receipt
4. Payment status → `pending_verification`
5. Admin reviews receipt and approves/rejects (`verify_payments.php`)
6. On approval: `paid_amount` and invoice status updated atomically
7. Printable receipt available (`receipt.php`)

---

## License
This project is provided as-is for educational and commercial use.
