<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireAdminOrAccountant();

$errors = [];
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add_building':
            if (!verifyCSRF()) {
                $errors[] = 'Invalid CSRF token. Please try again.';
                break;
            }
            $name = trim($_POST['name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $total_floors = (int)($_POST['total_floors'] ?? 0);

            if ($name === '') {
                $errors[] = 'Building name is required.';
            }
            if ($total_floors < 1) {
                $errors[] = 'Total floors must be at least 1.';
            }
            if (empty($errors)) {
                try {
                    $stmt = $pdo->prepare('INSERT INTO buildings (name, location, total_floors) VALUES (?, ?, ?)');
                    $stmt->execute([$name, $location, $total_floors]);
                    $success = "Building \"$name\" added successfully.";
                } catch (PDOException $e) {
                    $errors[] = 'Failed to add building: ' . $e->getMessage();
                }
            }
            break;

        case 'delete_building':
            if (!verifyCSRF()) {
                $errors[] = 'Invalid CSRF token. Please try again.';
                break;
            }
            $building_id = (int)($_POST['building_id'] ?? 0);
            if ($building_id < 1) {
                $errors[] = 'Invalid building ID.';
                break;
            }
            try {
                $pdo->beginTransaction();
                // Cascade delete units first
                $stmt = $pdo->prepare('DELETE FROM units WHERE building_id = ?');
                $stmt->execute([$building_id]);
                $stmt = $pdo->prepare('DELETE FROM buildings WHERE id = ?');
                $stmt->execute([$building_id]);
                $pdo->commit();
                $success = 'Building and all its units deleted successfully.';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to delete building: ' . $e->getMessage();
            }
            break;

        case 'add_unit':
            if (!verifyCSRF()) {
                $errors[] = 'Invalid CSRF token. Please try again.';
                break;
            }
            $building_id = (int)($_POST['building_id'] ?? 0);
            $floor_number = (int)($_POST['floor_number'] ?? 0);
            $unit_code = trim($_POST['unit_code'] ?? '');
            $unit_type = $_POST['unit_type'] ?? '';
            $area_sqm = (float)($_POST['area_sqm'] ?? 0);
            $base_monthly_rent = (float)($_POST['base_monthly_rent'] ?? 0);
            $service_charge = (float)($_POST['service_charge'] ?? 0);

            $valid_types = ['shop', 'apartment', 'office', 'warehouse'];

            if ($building_id < 1) {
                $errors[] = 'Please select a building.';
            }
            if ($floor_number < 0) {
                $errors[] = 'Floor number cannot be negative.';
            }
            if ($unit_code === '') {
                $errors[] = 'Unit code is required.';
            }
            if (!in_array($unit_type, $valid_types)) {
                $errors[] = 'Invalid unit type.';
            }
            if ($area_sqm <= 0) {
                $errors[] = 'Area must be greater than 0.';
            }
            if ($base_monthly_rent < 0) {
                $errors[] = 'Base monthly rent cannot be negative.';
            }
            if ($service_charge < 0) {
                $errors[] = 'Service charge cannot be negative.';
            }
            if (empty($errors)) {
                try {
                    $stmt = $pdo->prepare('INSERT INTO units (building_id, floor_number, unit_code, unit_type, area_sqm, base_monthly_rent, service_charge, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$building_id, $floor_number, $unit_code, $unit_type, $area_sqm, $base_monthly_rent, $service_charge, 'vacant']);
                    $success = "Unit \"$unit_code\" added successfully with status: vacant.";
                } catch (PDOException $e) {
                    $errors[] = 'Failed to add unit: ' . $e->getMessage();
                }
            }
            break;

        case 'delete_unit':
            if (!verifyCSRF()) {
                $errors[] = 'Invalid CSRF token. Please try again.';
                break;
            }
            $unit_id = (int)($_POST['unit_id'] ?? 0);
            if ($unit_id < 1) {
                $errors[] = 'Invalid unit ID.';
                break;
            }
            try {
                $stmt = $pdo->prepare('DELETE FROM units WHERE id = ?');
                $stmt->execute([$unit_id]);
                $success = 'Unit deleted successfully.';
            } catch (PDOException $e) {
                $errors[] = 'Failed to delete unit: ' . $e->getMessage();
            }
            break;
    }
}

// Fetch all buildings
$buildings = $pdo->query('SELECT * FROM buildings ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

// Fetch unit count per building
$unit_counts = [];
foreach ($buildings as $b) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM units WHERE building_id = ?');
    $stmt->execute([$b['id']]);
    $unit_counts[$b['id']] = $stmt->fetchColumn();
}

// Fetch all units with building names
$units = $pdo->query('
    SELECT u.*, b.name AS building_name
    FROM units u
    JOIN buildings b ON u.building_id = b.id
    ORDER BY b.name ASC, u.floor_number ASC, u.unit_code ASC
')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Buildings &amp; Units - Property Management System</title>
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

  /* ===== Badges ===== */
  .badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
  }
  .badge-vacant { background: var(--success-bg); color: var(--success); }
  .badge-occupied { background: var(--warn-bg); color: var(--warn); }
  .badge-rented { background: var(--warn-bg); color: var(--warn); }
  .badge-maintenance { background: var(--danger-bg); color: var(--danger); }

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
  .alert-error { background: var(--danger-bg); color: #b91c1c; border: 1px solid #fca5a5; }
  .alert ul { margin: 0; padding-left: 20px; }

  /* ===== Empty state ===== */
  .empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--gray-400);
  }
  .empty-state .icon { font-size: 2.5rem; margin-bottom: 8px; }

  /* ===== Section spacing ===== */
  .section-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--gray-800);
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--gray-200);
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
    <a href="buildings_units.php" class="active"><span class="nav-icon">🏢</span> Buildings &amp; Units</a>
    <a href="tenants.php"><span class="nav-icon">👥</span> Tenants</a>
    <a href="leases.php"><span class="nav-icon">📄</span> Leases</a>
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
      <h2>🏢 Buildings &amp; Units</h2>
      <p>Manage buildings and individual units across all properties.</p>
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

  <!-- ===== Building Management Section ===== -->
  <h3 class="section-title">🏢 Building Management</h3>

  <!-- Add Building Form -->
  <div class="card">
    <div class="card-header">
      <h3>Add New Building</h3>
    </div>
    <div class="card-body">
      <form method="POST" action="">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_building">
        <div class="form-grid">
          <div class="form-group">
            <label for="b_name">Building Name <span class="req">*</span></label>
            <input type="text" id="b_name" name="name" class="form-control"
                   placeholder="e.g. Riverside Plaza" required>
          </div>
          <div class="form-group">
            <label for="b_location">Location</label>
            <input type="text" id="b_location" name="location" class="form-control"
                   placeholder="e.g. Bole Road, Addis Ababa">
          </div>
          <div class="form-group">
            <label for="b_floors">Total Floors <span class="req">*</span></label>
            <input type="number" id="b_floors" name="total_floors" class="form-control"
                   min="1" placeholder="e.g. 10" required>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">➕ Add Building</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Buildings Table -->
  <div class="card">
    <div class="card-header">
      <h3>All Buildings (<?= count($buildings) ?>)</h3>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Location</th>
              <th class="text-center">Floors</th>
              <th class="text-center">Units</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($buildings)): ?>
              <tr>
                <td colspan="5">
                  <div class="empty-state">
                    <div class="icon">🏢</div>
                    <p>No buildings yet. Add your first building above.</p>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($buildings as $b): ?>
                <tr>
                  <td><strong><?= e($b['name']) ?></strong></td>
                  <td><?= e($b['location'] ?: '—') ?></td>
                  <td class="text-center"><?= (int)$b['total_floors'] ?></td>
                  <td class="text-center"><?= (int)($unit_counts[$b['id']] ?? 0) ?></td>
                  <td class="text-center">
                    <form method="POST" action="" style="display:inline;"
                          onsubmit="return confirm('Delete building &quot;<?= e($b['name']) ?>&quot;? All units under it will also be deleted.');">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete_building">
                      <input type="hidden" name="building_id" value="<?= (int)$b['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ===== Unit Management Section ===== -->
  <h3 class="section-title">🏠 Unit Management</h3>

  <!-- Add Unit Form -->
  <div class="card">
    <div class="card-header">
      <h3>Add New Unit</h3>
    </div>
    <div class="card-body">
      <form method="POST" action="">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_unit">
        <div class="form-grid">
          <div class="form-group">
            <label for="u_building">Building <span class="req">*</span></label>
            <select id="u_building" name="building_id" class="form-control" required>
              <option value="">— Select Building —</option>
              <?php foreach ($buildings as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="u_floor">Floor Number <span class="req">*</span></label>
            <input type="number" id="u_floor" name="floor_number" class="form-control"
                   min="0" placeholder="e.g. 3" required>
          </div>
          <div class="form-group">
            <label for="u_code">Unit Code <span class="req">*</span></label>
            <input type="text" id="u_code" name="unit_code" class="form-control"
                   placeholder="e.g. A-301" required>
          </div>
          <div class="form-group">
            <label for="u_type">Unit Type <span class="req">*</span></label>
            <select id="u_type" name="unit_type" class="form-control" required>
              <option value="">— Select Type —</option>
              <option value="shop">Shop</option>
              <option value="apartment">Apartment</option>
              <option value="office">Office</option>
              <option value="warehouse">Warehouse</option>
            </select>
          </div>
          <div class="form-group">
            <label for="u_area">Area (m²) <span class="req">*</span></label>
            <input type="number" id="u_area" name="area_sqm" class="form-control"
                   min="0.01" step="0.01" placeholder="e.g. 120.50" required>
          </div>
          <div class="form-group">
            <label for="u_rent">Base Monthly Rent (ETB) <span class="req">*</span></label>
            <input type="number" id="u_rent" name="base_monthly_rent" class="form-control"
                   min="0" step="0.01" placeholder="e.g. 15000.00" required>
          </div>
          <div class="form-group">
            <label for="u_service">Service Charge (ETB)</label>
            <input type="number" id="u_service" name="service_charge" class="form-control"
                   min="0" step="0.01" placeholder="e.g. 500.00" value="0">
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">➕ Add Unit</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Units Table -->
  <div class="card">
    <div class="card-header">
      <h3>All Units (<?= count($units) ?>)</h3>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Building</th>
              <th class="text-center">Floor</th>
              <th>Code</th>
              <th>Type</th>
              <th class="text-right">Area (m²)</th>
              <th class="text-right">Rent</th>
              <th class="text-right">Service</th>
              <th class="text-center">Status</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($units)): ?>
              <tr>
                <td colspan="9">
                  <div class="empty-state">
                    <div class="icon">🏠</div>
                    <p>No units yet. Add a building first, then add units.</p>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($units as $u): ?>
                <tr>
                  <td><?= e($u['building_name']) ?></td>
                  <td class="text-center"><?= (int)$u['floor_number'] ?></td>
                  <td><strong><?= e($u['unit_code']) ?></strong></td>
                  <td>
                    <span class="badge" style="background:var(--primary-light);color:var(--primary);">
                      <?= e($u['unit_type']) ?>
                    </span>
                  </td>
                  <td class="text-right"><?= number_format($u['area_sqm'], 2) ?></td>
                  <td class="text-right"><?= etb($u['base_monthly_rent']) ?></td>
                  <td class="text-right"><?= etb($u['service_charge']) ?></td>
                  <td class="text-center">
                    <?php
                      $status = $u['status'] ?? 'vacant';
                      $badge_class = 'badge-' . htmlspecialchars($status);
                    ?>
                    <span class="badge <?= $badge_class ?>"><?= e($status) ?></span>
                  </td>
                  <td class="text-center">
                    <form method="POST" action="" style="display:inline;"
                          onsubmit="return confirm('Delete unit &quot;<?= e($u['unit_code']) ?>&quot;?');">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete_unit">
                      <input type="hidden" name="unit_id" value="<?= (int)$u['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
                    </form>
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
</script>

</body>
</html>
