<?php
/**
 * Monthly Invoice & Shared Expense Generator
 * Generates rent + service charge invoices for all active leases in a building,
 * optionally splits a shared building expense (generator fuel, water, etc.)
 * across occupied units by area (m²) or equal share.
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireAdminOrAccountant();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error   = '';

/* ─── Shared Expense Distribution ─── */
function distributeBuildingExpense(PDO $pdo, int $buildingId, string $billingMonth, float $expenseAmount, string $method = 'by_area_sqm'): void
{
    $stmt = $pdo->prepare("
        SELECT i.id AS invoice_id, i.rent_amount, i.service_amount, i.vat_amount, u.area_sqm
        FROM invoices i
        JOIN leases l  ON i.lease_id = l.id
        JOIN units u   ON l.unit_id = u.id
        WHERE u.building_id = :building_id
          AND i.billing_month = :billing_month
          AND l.status = 'active'
    ");
    $stmt->execute([
        ':building_id'  => $buildingId,
        ':billing_month' => $billingMonth,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return;
    }

    // Calculate total weight
    $totalWeight = 0.0;
    if ($method === 'by_area_sqm') {
        foreach ($rows as $r) {
            $totalWeight += max(0.0, (float)$r['area_sqm']);
        }
    }
    // Fallback to equal split if area sum is 0
    if ($totalWeight <= 0) {
        $method = 'equal_occupied';
        $totalWeight = (float)count($rows);
    }

    $updateStmt = $pdo->prepare("
        UPDATE invoices
        SET utility_share = :utility_share,
            total_amount  = :total_amount
        WHERE id = :invoice_id
    ");

    foreach ($rows as $r) {
        $weight = ($method === 'by_area_sqm') ? max(0.0, (float)$r['area_sqm']) : 1.0;
        $share = round(($expenseAmount * $weight) / $totalWeight, 2);
        $newTotal = round((float)$r['rent_amount'] + (float)$r['service_amount'] + $share + (float)$r['vat_amount'], 2);

        $updateStmt->execute([
            ':utility_share' => $share,
            ':total_amount'  => $newTotal,
            ':invoice_id'    => $r['invoice_id'],
        ]);
    }
}

/* ─── Generate Monthly Invoices ─── */
function generateMonthlyInvoices(
    PDO $pdo,
    int $buildingId,
    string $billingMonth,
    string $dueDate,
    float $vatRatePercent,
    ?float $sharedExpenseAmount,
    string $expenseCategory,
    string $splitMethod,
    string $expenseNotes
): array {
    $pdo->beginTransaction();
    try {
        // Fetch all active leases for the building
        $leaseStmt = $pdo->prepare("
            SELECT l.id AS lease_id, l.monthly_rent, u.id AS unit_id,
                   u.service_charge, u.area_sqm
            FROM leases l
            JOIN units u ON l.unit_id = u.id
            WHERE u.building_id = :building_id
              AND l.status = 'active'
        ");
        $leaseStmt->execute([':building_id' => $buildingId]);
        $leases = $leaseStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($leases)) {
            throw new RuntimeException('No active leases found for this building.');
        }

        $insInv = $pdo->prepare("
            INSERT IGNORE INTO invoices
                (lease_id, billing_month, rent_amount, service_amount,
                 utility_share, vat_amount, total_amount, paid_amount, due_date, status)
            VALUES
                (:lease_id, :billing_month, :rent_amount, :service_amount,
                 0.00, :vat_amount, :total_amount, 0.00, :due_date, 'unpaid')
        ");

        $createdCount = 0;
        $vatRate = max(0.0, $vatRatePercent) / 100.0;

        foreach ($leases as $l) {
            $rent    = round((float)$l['monthly_rent'], 2);
            $service = round((float)$l['service_charge'], 2);
            $vat     = round(($rent + $service) * $vatRate, 2);
            $total   = round($rent + $service + $vat, 2);

            $insInv->execute([
                ':lease_id'      => (int)$l['lease_id'],
                ':billing_month' => $billingMonth,
                ':rent_amount'   => $rent,
                ':service_amount'=> $service,
                ':vat_amount'    => $vat,
                ':total_amount'  => $total,
                ':due_date'      => $dueDate,
            ]);
            if ($insInv->rowCount() > 0) {
                $createdCount++;
            }
        }

        // Optional: record and split shared building expense
        $sharedExpenseAmount = $sharedExpenseAmount ?? 0.0;
        if ($sharedExpenseAmount > 0) {
            $expStmt = $pdo->prepare("
                INSERT INTO building_expenses
                    (building_id, billing_month, category, amount_etb, split_method, notes)
                VALUES
                    (:building_id, :billing_month, :category, :amount_etb, :split_method, :notes)
            ");
            $expStmt->execute([
                ':building_id'  => $buildingId,
                ':billing_month'=> $billingMonth,
                ':category'     => $expenseCategory,
                ':amount_etb'   => round($sharedExpenseAmount, 2),
                ':split_method' => $splitMethod,
                ':notes'        => $expenseNotes !== '' ? $expenseNotes : null,
            ]);

            // Distribute across invoices
            distributeBuildingExpense($pdo, $buildingId, $billingMonth, $sharedExpenseAmount, $splitMethod);
        }

        $pdo->commit();
        return ['created' => $createdCount, 'total_leases' => count($leases)];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ─── Handle POST ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid CSRF token.';
    } else {
        try {
            $buildingId     = (int)($_POST['building_id'] ?? 0);
            $billingMonth   = trim($_POST['billing_month'] ?? '');
            $dueDate        = trim($_POST['due_date'] ?? '');
            $vatRatePercent = (float)($_POST['vat_rate'] ?? 0);
            $sharedExpense  = $_POST['shared_expense'] !== '' ? (float)$_POST['shared_expense'] : null;
            $expenseCategory= $_POST['expense_category'] ?? 'generator_fuel';
            $splitMethod    = $_POST['split_method'] ?? 'by_area_sqm';
            $expenseNotes   = trim($_POST['expense_notes'] ?? '');

            if ($buildingId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $billingMonth) || !$dueDate) {
                $error = 'Please fill in all required fields with valid values.';
            } else {
                $result = generateMonthlyInvoices(
                    $pdo, $buildingId, $billingMonth, $dueDate,
                    $vatRatePercent, $sharedExpense, $expenseCategory,
                    $splitMethod, $expenseNotes
                );
                $message = "Successfully created {$result['created']} of {$result['total_leases']} invoices for {$billingMonth}.";
                if ($sharedExpense && $sharedExpense > 0) {
                    $message .= " Shared expense of " . etb($sharedExpense) . " distributed via {$splitMethod}.";
                }
            }
        } catch (Throwable $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

/* ─── Fetch buildings for dropdown ─── */
$buildings = $pdo->query("SELECT id, name, location FROM buildings ORDER BY name")->fetchAll();

/* ─── Fetch recent invoices ─── */
$recentInvoices = $pdo->query("
    SELECT i.billing_month, i.total_amount, i.status, i.due_date,
           l.tenant_name, u.unit_code, b.name AS building_name
    FROM invoices i
    JOIN leases l   ON i.lease_id = l.id
    JOIN units u    ON l.unit_id = u.id
    JOIN buildings b ON u.building_id = b.id
    ORDER BY i.created_at DESC
    LIMIT 15
")->fetchAll();

$defaultMonth = date('Y-m');
$defaultDue   = date('Y-m-d', strtotime('last day of this month'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Generate Bills — Property Management</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}body{font-family:Segoe UI,Arial,sans-serif;background:#f0f2f5;color:#333}
  .layout{display:flex;min-height:100vh}
  .sidebar{width:230px;background:#1a2332;color:#fff;padding:0;flex-shrink:0;position:sticky;top:0;height:100vh}
  .sidebar-header{padding:20px;border-bottom:1px solid #2a3a52}
  .sidebar-header h2{font-size:1.1rem}.sidebar-header p{font-size:.75rem;color:#7a8da8;margin-top:4px}
  .nav{list-style:none;padding:10px 0}.nav a{display:block;padding:12px 20px;color:#a0b0c8;text-decoration:none;font-size:.9rem;border-left:3px solid transparent;transition:.15s}
  .nav a:hover{background:#1e2d42;color:#fff}.nav a.active{background:#1e2d42;border-left-color:#1a73e8;color:#fff}
  .main{flex:1;padding:24px;overflow-x:auto}
  .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
  .topbar h1{font-size:1.4rem}.user-info{font-size:.85rem;color:#666}
  .card{background:#fff;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-bottom:20px}
  .card h2{font-size:1.1rem;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #eee}
  .alert{padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:.9rem}
  .alert-success{background:#e6f4ea;color:#1e7e34;border:1px solid #b7dfc1}
  .alert-error{background:#fce8e8;color:#c5221f;border:1px solid #f5c6cb}
  .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .form-group{display:flex;flex-direction:column}.form-group.full{grid-column:1/-1}
  label{font-size:.85rem;font-weight:600;margin-bottom:6px;color:#555}
  input,select,textarea{padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:.9rem;transition:.15s}
  input:focus,select:focus,textarea:focus{outline:none;border-color:#1a73e8;box-shadow:0 0 0 3px rgba(26,115,232,.1)}
  textarea{resize:vertical;min-height:60px}
  .btn{padding:10px 24px;border:none;border-radius:6px;font-size:.9rem;font-weight:600;cursor:pointer;transition:.15s}
  .btn-primary{background:#1a73e8;color:#fff}.btn-primary:hover{background:#1557b0}
  .btn-danger{background:#dc3545;color:#fff}.btn-danger:hover{background:#c82333}
  table{width:100%;border-collapse:collapse;font-size:.85rem}
  th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #eee}
  th{background:#f8f9fa;font-weight:600;color:#555;font-size:.8rem;text-transform:uppercase;letter-spacing:.5px}
  .badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:.75rem;font-weight:600}
  .badge-unpaid{background:#fce8e8;color:#c5221f}.badge-paid{background:#e6f4ea;color:#1e7e34}
  .badge-partial{background:#fff4e0;color:#856404}.badge-overdue{background:#dc3545;color:#fff}
  .badge-pending_verification{background:#e8f0fe;color:#1a73e8}
  .expense-section{background:#f8f9fa;padding:16px;border-radius:6px;margin-top:8px}
  .expense-section h3{font-size:.9rem;margin-bottom:12px;color:#555}
  @media(max-width:768px){.sidebar{display:none}.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">
  <nav class="sidebar">
    <div class="sidebar-header"><h2>Property Mgmt</h2><p>Admin Panel</p></div>
    <ul class="nav">
      <li><a href="dashboard.php">Dashboard</a></li>
      <li><a href="buildings_units.php">Buildings &amp; Units</a></li>
      <li><a href="leases.php">Leases</a></li>
      <li><a href="generate_bills.php" class="active">Generate Bills</a></li>
      <li><a href="verify_payments.php">Verify Payments</a></li>
      <li><a href="maintenance.php">Maintenance</a></li>
      <li><a href="../logout.php">Logout</a></li>
    </ul>
  </nav>
  <div class="main">
    <div class="topbar">
      <h1>Generate Monthly Bills</h1>
      <div class="user-info">Logged in as: <?= e($_SESSION['user_name'] ?? 'Admin') ?></div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <h2>Invoice Generation</h2>
      <form method="POST">
        <?= csrfField() ?>
        <div class="form-grid">
          <div class="form-group">
            <label for="building_id">Building *</label>
            <select id="building_id" name="building_id" required>
              <option value="">— Select Building —</option>
              <?php foreach ($buildings as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?> (<?= e($b['location']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="billing_month">Billing Month (YYYY-MM) *</label>
            <input type="month" id="billing_month" name="billing_month" value="<?= e($defaultMonth) ?>" required>
          </div>
          <div class="form-group">
            <label for="due_date">Due Date *</label>
            <input type="date" id="due_date" name="due_date" value="<?= e($defaultDue) ?>" required>
          </div>
          <div class="form-group">
            <label for="vat_rate">VAT Rate (%)</label>
            <input type="number" id="vat_rate" name="vat_rate" value="0" min="0" max="100" step="0.01">
          </div>
        </div>

        <div class="expense-section" style="margin-top:16px">
          <h3>Optional: Shared Building Expense</h3>
          <div class="form-grid">
            <div class="form-group">
              <label for="shared_expense">Shared Expense Amount (ETB)</label>
              <input type="number" id="shared_expense" name="shared_expense" min="0" step="0.01" placeholder="e.g. 5000.00 (leave empty for none)">
            </div>
            <div class="form-group">
              <label for="expense_category">Expense Category</label>
              <select id="expense_category" name="expense_category">
                <option value="generator_fuel">Generator Fuel</option>
                <option value="water">Water</option>
                <option value="security">Security / Guards</option>
                <option value="cleaning">Cleaning</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="form-group">
              <label for="split_method">Split Method</label>
              <select id="split_method" name="split_method">
                <option value="by_area_sqm">By Area (m²)</option>
                <option value="equal_occupied">Equal Share (Occupied Units)</option>
              </select>
            </div>
            <div class="form-group">
              <label for="expense_notes">Notes</label>
              <input type="text" id="expense_notes" name="expense_notes" placeholder="Optional notes about this expense">
            </div>
          </div>
        </div>

        <div style="margin-top:20px">
          <button type="submit" class="btn btn-primary">Generate Invoices</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>Recently Generated Invoices</h2>
      <?php if (empty($recentInvoices)): ?>
        <p style="color:#999;padding:12px 0">No invoices generated yet.</p>
      <?php else: ?>
      <table>
        <thead>
          <tr><th>Month</th><th>Building</th><th>Unit</th><th>Tenant</th><th>Total</th><th>Due Date</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentInvoices as $inv): ?>
          <tr>
            <td><?= e($inv['billing_month']) ?></td>
            <td><?= e($inv['building_name']) ?></td>
            <td><?= e($inv['unit_code']) ?></td>
            <td><?= e($inv['tenant_name']) ?></td>
            <td><?= etb((float)$inv['total_amount']) ?></td>
            <td><?= e($inv['due_date']) ?></td>
            <td><span class="badge badge-<?= e($inv['status']) ?>"><?= e(ucwords(str_replace('_',' ',$inv['status']))) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
