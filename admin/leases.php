<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireAdminOrAccountant();

$errors = [];
$success = '';

/* ------------------------------------------------------------------ */
/* Handle POST actions                                                */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {

        /* ── Create new lease ── */
        case 'create_lease':
            if (!verifyCsrf()) {
                $errors[] = 'Invalid CSRF token. Please try again.';
                break;
            }

            $unit_id        = (int)($_POST['unit_id'] ?? 0);
            $tenant_name    = trim($_POST['tenant_name'] ?? '');
            $tenant_phone   = trim($_POST['tenant_phone'] ?? '');
            $tenant_email   = trim($_POST['tenant_email'] ?? '');
            $tin_number     = trim($_POST['tin_number'] ?? '');
            $monthly_rent   = (float)($_POST['monthly_rent'] ?? 0);
            $deposit_amount = (float)($_POST['deposit_amount'] ?? 0);
            $start_date     = $_POST['start_date'] ?? '';
            $end_date       = $_POST['end_date'] ?? '';

            // ── Validation ──
            if ($unit_id < 1) {
                $errors[] = 'Please select a vacant unit.';
            }
            if ($tenant_name === '') {
                $errors[] = 'Tenant name is required.';
            }
            if ($tenant_phone === '') {
                $errors[] = 'Tenant phone is required.';
            }
            if ($monthly_rent <= 0) {
                $errors[] = 'Monthly rent must be greater than 0.';
            }
            if ($deposit_amount < 0) {
                $errors[] = 'Deposit amount cannot be negative.';
            }
            if ($start_date === '') {
                $errors[] = 'Start date is required.';
            }
            if ($end_date === '') {
                $errors[] = 'End date is required.';
            }
            if ($start_date !== '' && $end_date !== '' && $end_date <= $start_date) {
                $errors[] = 'End date must be after the start date.';
            }

            // ── Verify unit is still vacant ──
            if ($unit_id > 0) {
                $chk = $pdo->prepare('SELECT status, base_monthly_rent FROM units WHERE id = ?');
                $chk->execute([$unit_id]);
                $unitRow = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$unitRow) {
                    $errors[] = 'Selected unit does not exist.';
                } elseif ($unitRow['status'] !== 'vacant') {
                    $errors[] = 'Selected unit is no longer vacant (status: ' . e($unitRow['status']) . ').';
                }
            }

            if (empty($errors)) {
                try {
                    $accessCode = generateAccessCode();

                    $pdo->beginTransaction();

                    // Insert lease
                    $stmt = $pdo->prepare('
                        INSERT INTO leases
                            (unit_id, tenant_name, tenant_phone, tenant_email, tin_number,
                             access_code, monthly_rent, deposit_amount, start_date, end_date, status)
                        VALUES
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")
                    ');
                    $stmt->execute([
                        $unit_id, $tenant_name, $tenant_phone, $tenant_email, $tin_number,
                        $accessCode, $monthly_rent, $deposit_amount, $start_date, $end_date,
                    ]);

                    // Mark unit as occupied
                    $upd = $pdo->prepare('UPDATE units SET status = "occupied" WHERE id = ?');
                    $upd->execute([$unit_id]);

                    $pdo->commit();

                    $success = "Lease created successfully for \"{$tenant_name}\". Access code: " . substr($accessCode, 0, 8) . '…';
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $errors[] = 'Failed to create lease: ' . $e->getMessage();
                }
            }
            break;

        /* ── Terminate lease ── */
        case 'terminate_lease':
            if (!verifyCsrf()) {
                $errors[] = 'Invalid CSRF token. Please try again.';
                break;
            }

            $lease_id = (int)($_POST['lease_id'] ?? 0);
            if ($lease_id < 1) {
                $errors[] = 'Invalid lease ID.';
                break;
            }

            try {
                // Fetch lease to get unit_id
                $fetch = $pdo->prepare('SELECT unit_id, status FROM leases WHERE id = ?');
                $fetch->execute([$lease_id]);
                $lease = $fetch->fetch(PDO::FETCH_ASSOC);

                if (!$lease) {
                    $errors[] = 'Lease not found.';
                    break;
                }
                if ($lease['status'] === 'terminated') {
                    $errors[] = 'Lease is already terminated.';
                    break;
                }

                $pdo->beginTransaction();

                // Update lease status
                $stmt = $pdo->prepare('UPDATE leases SET status = "terminated" WHERE id = ?');
                $stmt->execute([$lease_id]);

                // Set corresponding unit back to vacant
                $upd = $pdo->prepare('UPDATE units SET status = "vacant" WHERE id = ?');
                $upd->execute([(int)$lease['unit_id']]);

                $pdo->commit();

                $success = "Lease #{$lease_id} terminated. Unit marked as vacant.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to terminate lease: ' . $e->getMessage();
            }
            break;
    }
}

/* ------------------------------------------------------------------ */
/* Fetch vacant units for the create-lease dropdown                   */
/* ------------------------------------------------------------------ */
$vacantUnits = $pdo->query('
    SELECT u.id, u.unit_code, u.base_monthly_rent, b.name AS building_name
    FROM units u
    JOIN buildings b ON u.building_id = b.id
    WHERE u.status = "vacant"
    ORDER BY b.name ASC, u.unit_code ASC
')->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------------------------------------------ */
/* Status filter (GET)                                                */
/* ------------------------------------------------------------------ */
$statusFilter = $_GET['status'] ?? '';

$validStatuses = ['active', 'expired', 'terminated'];
$whereSql  = '';
$params    = [];

if (in_array($statusFilter, $validStatuses, true)) {
    $whereSql = 'WHERE l.status = :status';
    $params[':status'] = $statusFilter;
}

/* ------------------------------------------------------------------ */
/* Fetch all leases with building & unit info                         */
/* ------------------------------------------------------------------ */
$sql = "
    SELECT  l.*,
            u.unit_code,
            b.name AS building_name
      FROM  leases l
      JOIN  units    u ON l.unit_id    = u.id
      JOIN  buildings b ON u.building_id = b.id
      {$whereSql}
      ORDER BY  l.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leases = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------------------------------------------ */
/* Count leases per status (for filter badges)                        */
/* ------------------------------------------------------------------ */
$countAll   = $pdo->query('SELECT COUNT(*) FROM leases')->fetchColumn();
$countActive    = $pdo->query('SELECT COUNT(*) FROM leases WHERE status = "active"')->fetchColumn();
$countExpired   = $pdo->query('SELECT COUNT(*) FROM leases WHERE status = "expired"')->fetchColumn();
$countTerminated = $pdo->query('SELECT COUNT(*) FROM leases WHERE status = "terminated"')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leases - Property Management System</title>
<style>
  :root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --primary-light: #dbeafe;
    --success: #16a34a;
    --success-bg: #dcfce7;
    --danger: #dc2626;
    --danger-bg: #fee2e2;
    --warn: #f59e0b;
    --warn-bg: #fef3c7;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    --sidebar-w: 260px;
    --radius: 8px;
    --transition: all 0.2s ease;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: var(--gray-100);
    color: var(--gray-800);
    line-height: 1.6;
  }

  /* ===== Sidebar ===== */
  .sidebar {
    position: fixed;
    top: 0; left: 0;
    width: var(--sidebar-w);
    height: 100vh;
    background: var(--gray-900);
    color: #fff;
    display: flex;
    flex-direction: column;
    z-index: 1000;
    transition: transform 0.3s ease;
  }
  .sidebar-header {
    padding: 24px 20px;
    border-bottom: 1px solid var(--gray-700);
  }
  .sidebar-header h1 {
    font-size: 1.25rem;
    font-weight: 700;
    color: #fff;
  }
  .sidebar-header p {
    font-size: 0.8rem;
    color: var(--gray-400);
    margin-top: 4px;
  }
  .sidebar-nav {
    flex: 1;
    overflow-y: auto;
    padding: 12px 0;
  }
  .sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    color: var(--gray-300);
    text-decoration: none;
    font-size: 0.9rem;
    transition: var(--transition);
    border-left: 3px solid transparent;
  }
  .sidebar-nav a:hover {
    background: var(--gray-800);
    color: #fff;
  }
  .sidebar-nav a.active {
    background: var(--primary);
    color: #fff;
    border-left-color: #60a5fa;
  }
  .sidebar-nav .nav-icon {
    width: 20px; text-align: center;
    font-size: 1.1rem;
  }

  /* ===== Main content ===== */
  .main-content {
    margin-left: var(--sidebar-w);
    padding: 24px;
    min-height: 100vh;
  }
  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
  }
  .page-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--gray-900);
  }
  .page-header p {
    font-size: 0.85rem;
    color: var(--gray-500);
  }

  /* ===== Cards ===== */
  .card {
    background: #fff;
    border-radius: var(--radius);
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 24px;
    overflow: hidden;
  }
  .card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--gray-200);
    background: var(--gray-50);
  }
  .card-header h3 {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--gray-700);
  }
  .card-body { padding: 20px; }

  /* ===== Form ===== */
  .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
  }
  .form-group { display: flex; flex-direction: column; }
  .form-group label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 6px;
  }
  .form-group label .req { color: var(--danger); }
  .form-control {
    padding: 10px 12px;
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    font-size: 0.9rem;
    transition: var(--transition);
    background: #fff;
  }
  .form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
  }
  select.form-control { cursor: pointer; }
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 18px;
    border: none;
    border-radius: 6px;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
  }
  .btn-primary { background: var(--primary); color: #fff; }
  .btn-primary:hover { background: var(--primary-dark); }
  .btn-danger { background: var(--danger); color: #fff; padding: 6px 12px; font-size: 0.8rem; }
  .btn-danger:hover { background: #b91c1c; }
  .btn-outline {
    background: #fff;
    border: 1px solid var(--gray-300);
    color: var(--gray-600);
    padding: 6px 12px;
    font-size: 0.8rem;
  }
  .btn-outline:hover {
    border-color: var(--primary);
    color: var(--primary);
  }
  .btn-sm { padding: 6px 12px; font-size: 0.8rem; }
  .form-actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: flex-end;
    margin-top: 4px;
  }

  /* ===== Tables ===== */
  .table-wrap { overflow-x: auto; }
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
  }
  thead th {
    text-align: left;
    padding: 10px 14px;
    background: var(--gray-50);
    color: var(--gray-600);
    font-weight: 600;
    border-bottom: 2px solid var(--gray-200);
    white-space: nowrap;
  }
  tbody td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--gray-100);
    color: var(--gray-700);
  }
  tbody tr:hover { background: var(--gray-50); }
  .text-right { text-align: right; }
  .text-center { text-align: center; }
  .text-mono { font-family: 'Consolas', 'Courier New', monospace; font-size: 0.82rem; }

  /* ===== Badges ===== */
  .badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
  }
  .badge-active     { background: var(--success-bg); color: var(--success); }
  .badge-expired    { background: var(--warn-bg);    color: var(--warn); }
  .badge-terminated  { background: var(--danger-bg); color: var(--danger); }

  /* ===== Alerts ===== */
  .alert {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 0.88rem;
    display: flex;
    align-items: flex-start;
    gap: 10px;
  }
  .alert-success { background: var(--success-bg); color: #15803d; border: 1px solid #86efac; }
  .alert-error   { background: var(--danger-bg);  color: #b91c1c; border: 1px solid #fca5a5; }
  .alert ul { margin: 0; padding-left: 20px; }

  /* ===== Empty state ===== */
  .empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--gray-400);
  }
  .empty-state .icon { font-size: 2.5rem; margin-bottom: 8px; }

  /* ===== Section title ===== */
  .section-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--gray-800);
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--gray-200);
  }

  /* ===== Filter bar ===== */
  .filter-bar {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 16px;
  }
  .filter-bar form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .filter-bar label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--gray-600);
  }
  .filter-bar select {
    padding: 8px 30px 8px 12px;
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    font-size: 0.85rem;
    background: #fff;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
  }
  .filter-bar select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
  }
  .filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
    background: var(--gray-100);
    color: var(--gray-600);
  }
  .filter-chip a {
    color: var(--gray-500);
    text-decoration: none;
    font-size: 0.9rem;
    line-height: 1;
    margin-left: 2px;
  }
  .filter-chip a:hover { color: var(--danger); }

  /* ===== Action cell ===== */
  .action-group {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
  }

  /* ===== Mobile toggle ===== */
  .mobile-toggle {
    display: none;
    position: fixed;
    top: 16px; left: 16px;
    z-index: 1100;
    background: var(--gray-900);
    color: #fff;
    border: none;
    border-radius: 6px;
    width: 40px; height: 40px;
    font-size: 1.3rem;
    cursor: pointer;
  }
  .overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.4);
    z-index: 999;
  }
  .overlay.active { display: block; }

  /* ===== Responsive ===== */
  @media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main-content { margin-left: 0; padding: 16px; padding-top: 60px; }
    .mobile-toggle { display: block; }
    .form-grid { grid-template-columns: 1fr; }
    .page-header { flex-direction: column; align-items: flex-start; }
    table { font-size: 0.82rem; }
    thead th, tbody td { padding: 8px 10px; }
    .filter-bar { flex-direction: column; align-items: flex-start; }
    .filter-bar form { width: 100%; }
  }
</style>
</head>
<body>

<!-- Mobile toggle -->
<button class="mobile-toggle" onclick="toggleSidebar()">&#9776;</button>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <h1>🏢 Property MS</h1>
    <p>Building &amp; Apartment Management</p>
  </div>
  <nav class="sidebar-nav">
    <a href="dashboard.php"><span class="nav-icon">📊</span> Dashboard</a>
    <a href="buildings_units.php"><span class="nav-icon">🏢</span> Buildings &amp; Units</a>
    <a href="tenants.php"><span class="nav-icon">👥</span> Tenants</a>
    <a href="leases.php" class="active"><span class="nav-icon">📄</span> Leases</a>
    <a href="rent_invoices.php"><span class="nav-icon">🧾</span> Rent Invoices</a>
    <a href="payments.php"><span class="nav-icon">💳</span> Payments</a>
    <a href="expense.php"><span class="nav-icon">💸</span> Expenses</a>
    <a href="maintenance.php"><span class="nav-icon">🔧</span> Maintenance</a>
    <a href="reports.php"><span class="nav-icon">📈</span> Reports</a>
    <a href="users.php"><span class="nav-icon">🔐</span> Users</a>
    <a href="settings.php"><span class="nav-icon">⚙️</span> Settings</a>
    <a href="logout.php"><span class="nav-icon">🚪</span> Logout</a>
  </nav>
</aside>

<!-- Main Content -->
<div class="main-content">
  <div class="page-header">
    <div>
      <h2>📄 Leases Management</h2>
      <p>Create lease contracts, manage tenants, and track lease status.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <span class="filter-chip">Active: <?= (int)$countActive ?></span>
      <span class="filter-chip">Expired: <?= (int)$countExpired ?></span>
      <span class="filter-chip">Terminated: <?= (int)$countTerminated ?></span>
      <span class="filter-chip">Total: <?= (int)$countAll ?></span>
    </div>
  </div>

  <!-- Success / Error Messages -->
  <?php if (!empty($success)): ?>
    <div class="alert alert-success">
      <span>✅</span>
      <span><?= e($success) ?></span>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <ul>
        <?php foreach ($errors as $err): ?>
          <li><?= e($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- ===== Create Lease Form ===== -->
  <h3 class="section-title">📝 New Lease Contract</h3>

  <div class="card">
    <div class="card-header">
      <h3>Create Lease</h3>
    </div>
    <div class="card-body">
      <?php if (empty($vacantUnits)): ?>
        <div class="empty-state">
          <div class="icon">🏠</div>
          <p>No vacant units available. Add or free up a unit before creating a lease.</p>
        </div>
      <?php else: ?>
      <form method="POST" action="" id="leaseForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_lease">
        <div class="form-grid">

          <!-- Unit selection -->
          <div class="form-group" style="grid-column: span 2;">
            <label for="unit_id">Unit <span class="req">*</span></label>
            <select id="unit_id" name="unit_id" class="form-control" required onchange="prefillRent()">
              <option value="">— Select Vacant Unit —</option>
              <?php foreach ($vacantUnits as $vu): ?>
                <option value="<?= (int)$vu['id'] ?>" data-rent="<?= (float)$vu['base_monthly_rent'] ?>">
                  <?= e($vu['building_name']) ?> — <?= e($vu['unit_code']) ?>
                  (Base: <?= etb((float)$vu['base_monthly_rent']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Tenant Name -->
          <div class="form-group">
            <label for="tenant_name">Tenant Name <span class="req">*</span></label>
            <input type="text" id="tenant_name" name="tenant_name" class="form-control"
                   placeholder="e.g. Abebe Bekele" required>
          </div>

          <!-- Tenant Phone -->
          <div class="form-group">
            <label for="tenant_phone">Tenant Phone <span class="req">*</span></label>
            <input type="text" id="tenant_phone" name="tenant_phone" class="form-control"
                   placeholder="e.g. +251911234567" required>
          </div>

          <!-- Tenant Email -->
          <div class="form-group">
            <label for="tenant_email">Tenant Email</label>
            <input type="email" id="tenant_email" name="tenant_email" class="form-control"
                   placeholder="e.g. abebe@example.com">
          </div>

          <!-- TIN Number -->
          <div class="form-group">
            <label for="tin_number">TIN Number</label>
            <input type="text" id="tin_number" name="tin_number" class="form-control"
                   placeholder="e.g. 0001234567">
          </div>

          <!-- Monthly Rent -->
          <div class="form-group">
            <label for="monthly_rent">Monthly Rent (ETB) <span class="req">*</span></label>
            <input type="number" id="monthly_rent" name="monthly_rent" class="form-control"
                   min="0" step="0.01" placeholder="e.g. 25000.00" required>
          </div>

          <!-- Deposit Amount -->
          <div class="form-group">
            <label for="deposit_amount">Deposit Amount (ETB)</label>
            <input type="number" id="deposit_amount" name="deposit_amount" class="form-control"
                   min="0" step="0.01" placeholder="e.g. 50000.00" value="0">
          </div>

          <!-- Start Date -->
          <div class="form-group">
            <label for="start_date">Start Date <span class="req">*</span></label>
            <input type="date" id="start_date" name="start_date" class="form-control" required>
          </div>

          <!-- End Date -->
          <div class="form-group">
            <label for="end_date">End Date <span class="req">*</span></label>
            <input type="date" id="end_date" name="end_date" class="form-control" required>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary">📝 Create Lease</button>
          </div>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== Leases Table ===== -->
  <h3 class="section-title">📋 All Leases</h3>

  <!-- Filter bar -->
  <div class="filter-bar">
    <form method="GET" action="" id="filterForm">
      <label for="statusFilter">Filter by status:</label>
      <select name="status" id="statusFilter" onchange="document.getElementById('filterForm').submit();">
        <option value="">All Statuses (<?= (int)$countAll ?>)</option>
        <option value="active"     <?= $statusFilter === 'active'     ? 'selected' : '' ?>>Active (<?= (int)$countActive ?>)</option>
        <option value="expired"    <?= $statusFilter === 'expired'    ? 'selected' : '' ?>>Expired (<?= (int)$countExpired ?>)</option>
        <option value="terminated" <?= $statusFilter === 'terminated' ? 'selected' : '' ?>>Terminated (<?= (int)$countTerminated ?>)</option>
      </select>
    </form>
    <?php if ($statusFilter !== ''): ?>
      <span class="filter-chip">
        <?= e($statusFilter) ?>
        <a href="leases.php" title="Clear filter">&times;</a>
      </span>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>Leases (<?= count($leases) ?>)</h3>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Tenant</th>
              <th>Phone</th>
              <th>Building / Unit</th>
              <th class="text-right">Monthly Rent</th>
              <th>Start</th>
              <th>End</th>
              <th class="text-center">Status</th>
              <th>Access Code</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($leases)): ?>
              <tr>
                <td colspan="9">
                  <div class="empty-state">
                    <div class="icon">📄</div>
                    <p>No leases found. Create your first lease above.</p>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($leases as $l): ?>
                <?php
                  $status = $l['status'] ?? 'active';
                  $badgeClass = 'badge-' . htmlspecialchars($status);
                  $accessCode = $l['access_code'] ?? '';
                  $truncatedCode = strlen($accessCode) > 8
                      ? substr($accessCode, 0, 8) . '…'
                      : $accessCode;
                  $canTerminate = ($status === 'active' || $status === 'expired');
                ?>
                <tr>
                  <td>
                    <strong><?= e($l['tenant_name']) ?></strong>
                    <?php if (!empty($l['tenant_email'])): ?>
                      <br><span style="font-size:0.78rem;color:var(--gray-400);"><?= e($l['tenant_email']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><?= e($l['tenant_phone']) ?></td>
                  <td>
                    <?= e($l['building_name']) ?><br>
                    <span style="font-size:0.78rem;color:var(--gray-400);">Unit <?= e($l['unit_code']) ?></span>
                  </td>
                  <td class="text-right"><?= etb((float)$l['monthly_rent']) ?></td>
                  <td><?= e($l['start_date']) ?></td>
                  <td><?= e($l['end_date']) ?></td>
                  <td class="text-center">
                    <span class="badge <?= $badgeClass ?>"><?= e($status) ?></span>
                  </td>
                  <td class="text-mono" title="<?= e($accessCode) ?>"><?= e($truncatedCode) ?></td>
                  <td class="text-center">
                    <div class="action-group">
                      <?php if ($canTerminate): ?>
                        <form method="POST" action="" style="display:inline;"
                              onsubmit="return confirm('Terminate lease for &quot;<?= e($l['tenant_name']) ?>&quot;? The unit will be marked as vacant.');">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="terminate_lease">
                          <input type="hidden" name="lease_id" value="<?= (int)$l['id'] ?>">
                          <button type="submit" class="btn btn-danger btn-sm">Terminate</button>
                        </form>
                      <?php else: ?>
                        <span style="font-size:0.78rem;color:var(--gray-400);">—</span>
                      <?php endif; ?>
                      <a href="receipt.php?lease_id=<?= (int)$l['id'] ?>" class="btn btn-outline btn-sm"
                         target="_blank">Print Contract</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('active');
}

/**
 * Prefill monthly_rent from the selected unit's base_monthly_rent
 * (stored in the option's data-rent attribute).
 */
function prefillRent() {
  var sel   = document.getElementById('unit_id');
  var rent  = document.getElementById('monthly_rent');
  if (!sel || !rent) return;

  var opt = sel.options[sel.selectedIndex];
  if (!opt) return;

  var baseRent = opt.getAttribute('data-rent');
  if (baseRent !== null && baseRent !== '') {
    rent.value = parseFloat(baseRent).toFixed(2);
  }
}
</script>

</body>
</html>
