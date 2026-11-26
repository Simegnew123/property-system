<?php
/**
 * Admin Payment Verification
 * Review uploaded Telebirr/CBE/Bank receipts, approve or reject payments.
 * Uses database transactions to keep payments, invoices, and paid_amount consistent.
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

/* ─── Approve Payment (Transaction) ─── */
function approvePaymentTx(PDO $pdo, int $paymentId, int $verifierId): bool
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.invoice_id, p.amount_etb, p.status AS pay_status,
                   i.total_amount, i.paid_amount
            FROM payments p
            JOIN invoices i ON p.invoice_id = i.id
            WHERE p.id = :payment_id
            FOR UPDATE
        ");
        $stmt->execute([':payment_id' => $paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['pay_status'] !== 'pending') {
            $pdo->rollBack();
            return false;
        }

        // 1. Mark payment as approved
        $updPay = $pdo->prepare("
            UPDATE payments
            SET status = 'approved', verified_by = :verifier_id, verified_at = NOW(),
                rejection_reason = NULL
            WHERE id = :payment_id
        ");
        $updPay->execute([
            ':verifier_id' => $verifierId,
            ':payment_id'  => $paymentId,
        ]);

        // 2. Recalculate paid amount and invoice status
        $newPaid = round((float)$row['paid_amount'] + (float)$row['amount_etb'], 2);
        $total   = (float)$row['total_amount'];

        if ($newPaid >= $total) {
            $invStatus = 'paid';
        } elseif ($newPaid > 0) {
            $invStatus = 'partial';
        } else {
            $invStatus = 'unpaid';
        }

        $updInv = $pdo->prepare("
            UPDATE invoices
            SET paid_amount = :paid_amount, status = :status
            WHERE id = :invoice_id
        ");
        $updInv->execute([
            ':paid_amount' => $newPaid,
            ':status'      => $invStatus,
            ':invoice_id'  => $row['invoice_id'],
        ]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ─── Reject Payment (Transaction) ─── */
function rejectPaymentTx(PDO $pdo, int $paymentId, int $verifierId, string $reason): bool
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.invoice_id, p.status AS pay_status,
                   i.total_amount, i.paid_amount, i.due_date
            FROM payments p
            JOIN invoices i ON p.invoice_id = i.id
            WHERE p.id = :payment_id
            FOR UPDATE
        ");
        $stmt->execute([':payment_id' => $paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['pay_status'] !== 'pending') {
            $pdo->rollBack();
            return false;
        }

        // Mark payment rejected
        $updPay = $pdo->prepare("
            UPDATE payments
            SET status = 'rejected', rejection_reason = :reason,
                verified_by = :verifier_id, verified_at = NOW()
            WHERE id = :payment_id
        ");
        $updPay->execute([
            ':reason'      => $reason !== '' ? $reason : 'Receipt could not be verified.',
            ':verifier_id' => $verifierId,
            ':payment_id'  => $paymentId,
        ]);

        // Check if any other pending payment remains on this invoice
        $pendingCheck = $pdo->prepare("
            SELECT COUNT(*) FROM payments
            WHERE invoice_id = :invoice_id AND status = 'pending'
        ");
        $pendingCheck->execute([':invoice_id' => $row['invoice_id']]);
        $remainingPending = (int)$pendingCheck->fetchColumn();

        if ($remainingPending === 0) {
            $paid  = (float)$row['paid_amount'];
            $total = (float)$row['total_amount'];
            if ($paid >= $total) {
                $nextStatus = 'paid';
            } elseif ($paid > 0) {
                $nextStatus = 'partial';
            } elseif ($row['due_date'] < date('Y-m-d')) {
                $nextStatus = 'overdue';
            } else {
                $nextStatus = 'unpaid';
            }
            $updInv = $pdo->prepare("UPDATE invoices SET status = :status WHERE id = :invoice_id");
            $updInv->execute([
                ':status'     => $nextStatus,
                ':invoice_id' => $row['invoice_id'],
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ─── Handle POST Actions ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid CSRF token.';
    } else {
        $action    = $_POST['action'] ?? '';
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $verifierId = (int)($_SESSION['user_id'] ?? 0);

        try {
            if ($action === 'approve' && $paymentId > 0) {
                if (approvePaymentTx($pdo, $paymentId, $verifierId)) {
                    $message = "Payment #{$paymentId} approved successfully.";
                } else {
                    $error = "Payment #{$paymentId} could not be approved (not pending or not found).";
                }
            } elseif ($action === 'reject' && $paymentId > 0) {
                $reason = trim($_POST['rejection_reason'] ?? '');
                if (rejectPaymentTx($pdo, $paymentId, $verifierId, $reason)) {
                    $message = "Payment #{$paymentId} rejected.";
                } else {
                    $error = "Payment #{$paymentId} could not be rejected.";
                }
            }
        } catch (Throwable $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

/* ─── Fetch Pending Payments ─── */
$pendingPayments = $pdo->query("
    SELECT p.id, p.amount_etb, p.payment_method, p.transaction_ref,
           p.receipt_path, p.created_at,
           i.id AS invoice_id, i.billing_month, i.total_amount, i.paid_amount,
           l.tenant_name, l.tenant_phone,
           u.unit_code, b.name AS building_name
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    JOIN leases l   ON i.lease_id = l.id
    JOIN units u    ON l.unit_id = u.id
    JOIN buildings b ON u.building_id = b.id
    WHERE p.status = 'pending'
    ORDER BY p.created_at DESC
")->fetchAll();

/* ─── Fetch Recent Decisions ─── */
$recentDecisions = $pdo->query("
    SELECT p.id, p.amount_etb, p.payment_method, p.status, p.rejection_reason,
           p.verified_at, p.transaction_ref,
           l.tenant_name, u.unit_code, b.name AS building_name
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    JOIN leases l   ON i.lease_id = l.id
    JOIN units u    ON l.unit_id = u.id
    JOIN buildings b ON u.building_id = b.id
    WHERE p.status IN ('approved','rejected')
    ORDER BY p.verified_at DESC
    LIMIT 10
")->fetchAll();

$pendingCount = count($pendingPayments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify Payments — Property Management</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}body{font-family:Segoe UI,Arial,sans-serif;background:#f0f2f5;color:#333}
  .layout{display:flex;min-height:100vh}
  .sidebar{width:230px;background:#1a2332;color:#fff;flex-shrink:0;position:sticky;top:0;height:100vh}
  .sidebar-header{padding:20px;border-bottom:1px solid #2a3a52}
  .sidebar-header h2{font-size:1.1rem}.sidebar-header p{font-size:.75rem;color:#7a8da8;margin-top:4px}
  .nav{list-style:none;padding:10px 0}.nav a{display:block;padding:12px 20px;color:#a0b0c8;text-decoration:none;font-size:.9rem;border-left:3px solid transparent;transition:.15s}
  .nav a:hover{background:#1e2d42;color:#fff}.nav a.active{background:#1e2d42;border-left-color:#1a73e8;color:#fff}
  .main{flex:1;padding:24px;overflow-x:auto}
  .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
  .topbar h1{font-size:1.4rem}.badge-count{background:#dc3545;color:#fff;border-radius:12px;padding:2px 10px;font-size:.8rem;font-weight:600;margin-left:8px}
  .user-info{font-size:.85rem;color:#666}
  .card{background:#fff;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-bottom:20px}
  .card h2{font-size:1.1rem;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #eee}
  .alert{padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:.9rem}
  .alert-success{background:#e6f4ea;color:#1e7e34;border:1px solid #b7dfc1}
  .alert-error{background:#fce8e8;color:#c5221f;border:1px solid #f5c6cb}
  .payment-card{border:1px solid #e0e0e0;border-radius:8px;padding:16px;margin-bottom:16px;display:grid;grid-template-columns:1fr 200px;gap:16px}
  .payment-info{display:flex;flex-direction:column;gap:8px}
  .payment-info .row{display:flex;gap:8px;font-size:.88rem}.payment-info .label{font-weight:600;min-width:120px;color:#555}
  .payment-receipt{border:1px solid #ddd;border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#f8f9fa;min-height:140px}
  .payment-receipt img{max-width:100%;max-height:200px;object-fit:contain}
  .payment-receipt a{font-size:.8rem;color:#1a73e8;text-decoration:none}
  .actions{display:flex;gap:8px;margin-top:8px}
  .btn{padding:8px 16px;border:none;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;transition:.15s}
  .btn-approve{background:#1e7e34;color:#fff}.btn-approve:hover{background:#155724}
  .btn-reject{background:#dc3545;color:#fff}.btn-reject:hover{background:#c82333}
  .reject-reason{margin-top:8px}.reject-reason input{width:100%;padding:6px 10px;border:1px solid #ddd;border-radius:4px;font-size:.85rem}
  .method-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:600;text-transform:uppercase}
  .method-telebirr{background:#fcc000;color:#333}.method-cbe_birr{background:#1a73e8;color:#fff}
  .method-bank_transfer{background:#6c757d;color:#fff}.method-cash{background:#28a745;color:#fff}
  .method-chapa{background:#7b1fa2;color:#fff}
  table{width:100%;border-collapse:collapse;font-size:.85rem}
  th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #eee}
  th{background:#f8f9fa;font-weight:600;color:#555;font-size:.8rem;text-transform:uppercase;letter-spacing:.5px}
  .badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:.75rem;font-weight:600}
  .badge-approved{background:#e6f4ea;color:#1e7e34}.badge-rejected{background:#fce8e8;color:#c5221f}
  .empty{color:#999;padding:20px 0;text-align:center}
  @media(max-width:768px){.sidebar{display:none}.payment-card{grid-template-columns:1fr}}
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
      <li><a href="generate_bills.php">Generate Bills</a></li>
      <li><a href="verify_payments.php" class="active">Verify Payments</a></li>
      <li><a href="maintenance.php">Maintenance</a></li>
      <li><a href="../logout.php">Logout</a></li>
    </ul>
  </nav>
  <div class="main">
    <div class="topbar">
      <h1>Verify Payments <?php if ($pendingCount > 0): ?><span class="badge-count"><?= $pendingCount ?> pending</span><?php endif; ?></h1>
      <div class="user-info">Logged in as: <?= e($_SESSION['user_name'] ?? 'Admin') ?></div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <!-- Pending Payments -->
    <div class="card">
      <h2>Pending Payment Verification</h2>
      <?php if (empty($pendingPayments)): ?>
        <div class="empty">No pending payments to verify.</div>
      <?php else: ?>
        <?php foreach ($pendingPayments as $pay): ?>
          <?php $balanceDue = max(0, round((float)$pay['total_amount'] - (float)$pay['paid_amount'], 2)); ?>
          <div class="payment-card">
            <div class="payment-info">
              <div class="row"><span class="label">Payment ID:</span> #<?= (int)$pay['id'] ?></div>
              <div class="row"><span class="label">Tenant:</span> <?= e($pay['tenant_name']) ?> (<?= e($pay['tenant_phone']) ?>)</div>
              <div class="row"><span class="label">Building/Unit:</span> <?= e($pay['building_name']) ?> / <?= e($pay['unit_code']) ?></div>
              <div class="row"><span class="label">Invoice:</span> #<?= (int)$pay['invoice_id'] ?> — <?= e($pay['billing_month']) ?></div>
              <div class="row"><span class="label">Invoice Total:</span> <?= etb((float)$pay['total_amount']) ?> | Paid: <?= etb((float)$pay['paid_amount']) ?> | Balance: <?= etb($balanceDue) ?></div>
              <div class="row"><span class="label">Amount Paid:</span> <strong><?= etb((float)$pay['amount_etb']) ?></strong></div>
              <div class="row"><span class="label">Method:</span> <span class="method-badge method-<?= e($pay['payment_method']) ?>"><?= e(str_replace('_',' ',ucwords($pay['payment_method']))) ?></span></div>
              <div class="row"><span class="label">Transaction Ref:</span> <?= e($pay['transaction_ref'] ?: '—') ?></div>
              <div class="row"><span class="label">Submitted:</span> <?= e($pay['created_at']) ?></div>

              <div class="actions">
                <form method="POST" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                  <button type="submit" class="btn btn-approve" onclick="return confirm('Approve this payment?')">Approve</button>
                </form>
                <form method="POST" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="reject">
                  <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                  <input type="text" name="rejection_reason" placeholder="Reason (optional)" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;font-size:.85rem;width:200px">
                  <button type="submit" class="btn btn-reject" onclick="return confirm('Reject this payment?')">Reject</button>
                </form>
                <a href="../receipt.php?payment_id=<?= (int)$pay['id'] ?>" target="_blank" class="btn" style="background:#6c757d;color:#fff">View Receipt</a>
              </div>
            </div>
            <div class="payment-receipt">
              <?php if ($pay['receipt_path']): ?>
                <?php $ext = strtolower(pathinfo($pay['receipt_path'], PATHINFO_EXTENSION)); ?>
                <?php if (in_array($ext, ['jpg','jpeg','png','webp'], true)): ?>
                  <img src="../<?= e($pay['receipt_path']) ?>" alt="Receipt">
                <?php elseif ($ext === 'pdf'): ?>
                  <a href="../<?= e($pay['receipt_path']) ?>" target="_blank">View PDF Receipt</a>
                <?php else: ?>
                  <a href="../<?= e($pay['receipt_path']) ?>" target="_blank">Download Receipt</a>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:#999;font-size:.85rem">No receipt uploaded<br>(Cash payment)</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Recent Decisions -->
    <div class="card">
      <h2>Recent Decisions</h2>
      <?php if (empty($recentDecisions)): ?>
        <div class="empty">No recent payment decisions.</div>
      <?php else: ?>
      <table>
        <thead>
          <tr><th>#</th><th>Tenant</th><th>Building</th><th>Unit</th><th>Amount</th><th>Method</th><th>Ref</th><th>Status</th><th>Verified At</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentDecisions as $d): ?>
          <tr>
            <td>#<?= (int)$d['id'] ?></td>
            <td><?= e($d['tenant_name']) ?></td>
            <td><?= e($d['building_name']) ?></td>
            <td><?= e($d['unit_code']) ?></td>
            <td><?= etb((float)$d['amount_etb']) ?></td>
            <td><span class="method-badge method-<?= e($d['payment_method']) ?>"><?= e(str_replace('_',' ',ucwords($d['payment_method']))) ?></span></td>
            <td><?= e($d['transaction_ref'] ?: '—') ?></td>
            <td><span class="badge badge-<?= e($d['status']) ?>"><?= e(ucfirst($d['status'])) ?></span></td>
            <td><?= e($d['verified_at'] ?: '—') ?></td>
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
