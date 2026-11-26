<?php
/**
 * Tenant Portal — Payment View & Receipt Upload
 * Tenants view their current invoice and upload payment proof (receipt).
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

/* ---------- Auth guard ---------- */
if (empty($_SESSION['tenant_lease_id'])) {
    header('Location: index.php');
    exit;
}

$lease_id    = (int)$_SESSION['tenant_lease_id'];
$tenant_name = $_SESSION['tenant_name'] ?? 'Tenant';

/* ---------- Load lease info ---------- */
$stmt = $pdo->prepare("
    SELECT l.id, l.tenant_name, l.tenant_phone, l.tenant_email, l.tin_number,
           l.monthly_rent, l.start_date, l.end_date, l.status,
           u.unit_code, u.unit_type, u.area_sqm, u.floor_number,
           b.name AS building_name, b.location AS building_location
    FROM leases l
    JOIN units u      ON u.id = l.unit_id
    JOIN buildings b  ON b.id = u.building_id
    WHERE l.id = :id
");
$stmt->execute([':id' => $lease_id]);
$lease = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lease) {
    session_destroy();
    header('Location: index.php');
    exit;
}

/* ---------- Load current / most recent unpaid invoice ---------- */
$stmt = $pdo->prepare("
    SELECT * FROM invoices
    WHERE lease_id = :lease_id
    ORDER BY
        CASE WHEN status IN ('unpaid','pending_verification','partial') THEN 0 ELSE 1 END,
        billing_month DESC,
        created_at DESC
    LIMIT 1
");
$stmt->execute([':lease_id' => $lease_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

/* ---------- Handle payment submission ---------- */
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error_msg = 'Security token expired. Please refresh and try again.';
    } elseif (!$invoice) {
        $error_msg = 'No invoice available for payment.';
    } else {
        $amount_etb      = (float)($_POST['amount_etb'] ?? 0);
        $payment_method  = $_POST['payment_method'] ?? '';
        $transaction_ref = trim($_POST['transaction_ref'] ?? '');
        $allowed_methods = ['telebirr', 'cbe_birr', 'bank_transfer', 'chapa', 'cash'];
        $allowed_mime = [
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
            'image/webp'       => 'webp',
            'application/pdf'  => 'pdf',
        ];
        $max_size = 5 * 1024 * 1024;

        if ($amount_etb <= 0) {
            $error_msg = 'Please enter a valid payment amount in ETB.';
        } elseif (!in_array($payment_method, $allowed_methods, true)) {
            $error_msg = 'Invalid payment method selected.';
        } elseif ($transaction_ref === '' && $payment_method !== 'cash') {
            $error_msg = 'Please enter the transaction reference number.';
        } elseif (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = 'Please upload your payment receipt (image or PDF).';
        } elseif ($_FILES['receipt']['size'] > $max_size) {
            $error_msg = 'File too large. Maximum size is 5 MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['receipt']['tmp_name']);

            if (!isset($allowed_mime[$mime])) {
                $error_msg = 'Invalid file type. Allowed: JPG, PNG, WEBP, PDF.';
            } else {
                $ext       = $allowed_mime[$mime];
                $safe_name = 'rcpt_' . $lease_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/receipts/';

                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0775, true);
                }

                $dest = $upload_dir . $safe_name;

                if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $dest)) {
                    $error_msg = 'Failed to save receipt file. Please try again.';
                } else {
                    $relative_path = 'uploads/receipts/' . $safe_name;

                    try {
                        $pdo->beginTransaction();

                        // Insert payment record
                        $insPay = $pdo->prepare("
                            INSERT INTO payments
                                (invoice_id, amount_etb, payment_method, transaction_ref,
                                 receipt_path, status)
                            VALUES
                                (:invoice_id, :amount_etb, :payment_method, :transaction_ref,
                                 :receipt_path, 'pending')
                        ");
                        $insPay->execute([
                            ':invoice_id'      => (int)$invoice['id'],
                            ':amount_etb'      => round($amount_etb, 2),
                            ':payment_method'  => $payment_method,
                            ':transaction_ref' => $transaction_ref !== '' ? $transaction_ref : null,
                            ':receipt_path'    => $relative_path,
                        ]);

                        // Update invoice status to pending_verification (unless already paid)
                        if ($invoice['status'] !== 'paid') {
                            $updInv = $pdo->prepare("UPDATE invoices SET status = 'pending_verification' WHERE id = :id");
                            $updInv->execute([':id' => (int)$invoice['id']]);
                        }

                        $pdo->commit();
                        $success_msg = 'Payment receipt uploaded successfully! Your payment is now pending verification by the management team.';
                    } catch (Throwable $ex) {
                        $pdo->rollBack();
                        $error_msg = 'A database error occurred. Please try again or contact support.';
                    }
                }
            }
        }
    }

    /* Reload invoice after possible status change */
    if ($success_msg) {
        $stmt = $pdo->prepare("
            SELECT * FROM invoices
            WHERE lease_id = :lease_id
            ORDER BY billing_month DESC, created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':lease_id' => $lease_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

/* ---------- Load payment history ---------- */
$stmt = $pdo->prepare("
    SELECT p.id, p.amount_etb, p.payment_method, p.transaction_ref,
           p.status, p.created_at, p.verified_at,
           i.billing_month
    FROM payments p
    JOIN invoices i ON i.id = p.invoice_id
    WHERE i.lease_id = :lease_id
    ORDER BY p.created_at DESC
");
$stmt->execute([':lease_id' => $lease_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Computed balance ---------- */
$balance = 0.0;
if ($invoice) {
    $balance = max(0.0, round((float)$invoice['total_amount'] - (float)$invoice['paid_amount'], 2));
}
$can_pay = $invoice && $balance > 0.001
    && in_array($invoice['status'], ['unpaid', 'partial', 'pending_verification'], true);

$csrfField = csrfField();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payments — Tenant Portal</title>
<style>
:root{--blue:#1a73e8;--blue-d:#1557b0;--bg:#f0f4f8;--text:#202124;--muted:#5f6368;--green:#1e8e3e;--orange:#f9ab00;--red:#d93025;--card:#fff;--border:#e0e0e0;--radius:12px}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding:20px}
.topbar{background:var(--card);border-radius:var(--radius);box-shadow:0 1px 6px rgba(0,0,0,.06);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.topbar-left{display:flex;align-items:center;gap:12px}
.topbar-left .icon{width:40px;height:40px;background:var(--blue);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff}
.topbar-left h1{font-size:18px;font-weight:600}.topbar-left p{font-size:13px;color:var(--muted)}
.topbar-right{display:flex;gap:8px;flex-wrap:wrap}
.nav-link{padding:8px 16px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:500;transition:background .15s}
.nav-link.primary{background:var(--blue);color:#fff}.nav-link.primary:hover{background:var(--blue-d)}
.nav-link.ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.nav-link.ghost:hover{background:#f5f5f5}
.card{background:var(--card);border-radius:var(--radius);box-shadow:0 1px 6px rgba(0,0,0,.06);padding:24px;margin-bottom:24px}
.card h2{font-size:16px;font-weight:600;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border)}
.lease-info{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.info-item label{display:block;font-size:12px;color:var(--muted);margin-bottom:2px}
.info-item span{font-size:15px;font-weight:500}
.invoice-table{width:100%;border-collapse:collapse;margin-bottom:16px}
.invoice-table th{text-align:left;font-size:12px;color:var(--muted);padding:8px 12px;border-bottom:1px solid var(--border);font-weight:600}
.invoice-table td{padding:10px 12px;font-size:14px;border-bottom:1px solid #f5f5f5}
.invoice-table td.amount{font-variant-numeric:tabular-nums;text-align:right}
.balance-row{background:#f8f9fa;font-weight:700}.balance-row td{font-size:16px}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.badge-unpaid{background:#fce8e6;color:var(--red)}.badge-pending_verification{background:#fef7e0;color:#b06000}
.badge-paid{background:#e6f4ea;color:var(--green)}.badge-partial{background:#fef7e0;color:#b06000}
.badge-overdue{background:#fce8e6;color:var(--red)}.badge-approved{background:#e6f4ea;color:var(--green)}
.badge-rejected{background:#fce8e6;color:var(--red)}.badge-pending{background:#fef7e0;color:#b06000}
.pay-form{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.pay-form .full{grid-column:1/-1}
.field label{display:block;font-size:13px;font-weight:600;color:var(--muted);margin-bottom:6px}
.field input[type="text"],.field input[type="number"],.field select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:#fff;transition:border-color .15s,box-shadow .15s}
.field input:focus,.field select:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(26,115,232,.12)}
.field .hint{font-size:12px;color:var(--muted);margin-top:4px}
.btn-submit{grid-column:1/-1;padding:14px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer;transition:background .15s;margin-top:4px}
.btn-submit:hover{background:var(--blue-d)}
.alert{padding:12px 16px;border-radius:8px;font-size:14px;margin-bottom:20px;line-height:1.5}
.alert-success{background:#e6f4ea;color:var(--green);border:1px solid #b7e1c5}
.alert-error{background:#fce8e6;color:var(--red);border:1px solid #f3c3c0}
.history-table{width:100%;border-collapse:collapse}
.history-table th{text-align:left;font-size:12px;color:var(--muted);padding:8px 12px;border-bottom:2px solid var(--border);font-weight:600}
.history-table td{padding:10px 12px;font-size:14px;border-bottom:1px solid #f5f5f5}
.history-table td.amount{font-variant-numeric:tabular-nums;text-align:right}
.no-data{text-align:center;color:var(--muted);padding:24px;font-size:14px}
@media(max-width:640px){body{padding:12px}.card{padding:18px}.pay-form{grid-template-columns:1fr}}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <div class="icon">🏠</div>
        <div>
            <h1>Tenant Portal</h1>
            <p>Welcome, <?= e($tenant_name) ?> — <?= e(tr($lease['building_name'] ?? '')) ?>, Unit <?= e($lease['unit_code'] ?? '') ?></p>
        </div>
    </div>
    <div class="topbar-right">
        <a href="request_repair.php" class="nav-link ghost">🔧 Request Repair</a>
        <a href="../logout.php" class="nav-link ghost">Logout</a>
    </div>
</div>

<div class="card">
    <h2>Lease Information</h2>
    <div class="lease-info">
        <div class="info-item"><label>Tenant</label><span><?= e($lease['tenant_name']) ?></span></div>
        <div class="info-item"><label>Phone</label><span><?= e($lease['tenant_phone']) ?></span></div>
        <div class="info-item"><label>Building</label><span><?= e(tr($lease['building_name'])) ?></span></div>
        <div class="info-item"><label>Unit</label><span><?= e($lease['unit_code']) ?></span></div>
        <div class="info-item"><label>Lease Status</label><span><?= e(ucfirst($lease['status'])) ?></span></div>
        <div class="info-item"><label>Lease Period</label><span><?= e($lease['start_date']) ?> → <?= e($lease['end_date']) ?></span></div>
    </div>
</div>

<?php if ($success_msg): ?><div class="alert alert-success">✅ <?= e($success_msg) ?></div><?php endif; ?>
<?php if ($error_msg): ?><div class="alert alert-error">⚠️ <?= e($error_msg) ?></div><?php endif; ?>

<?php if ($invoice): ?>
<?php
    $inv_balance = max(0.0, round((float)$invoice['total_amount'] - (float)$invoice['paid_amount'], 2));
    $status_class = match($invoice['status']) {
        'paid'                 => 'badge-paid',
        'pending_verification' => 'badge-pending_verification',
        'partial'              => 'badge-partial',
        'overdue'              => 'badge-overdue',
        default                => 'badge-unpaid',
    };
?>
<div class="card">
    <h2>Current Invoice — <?= e($invoice['billing_month']) ?></h2>
    <table class="invoice-table">
        <tr>
            <th>Billing Month</th><td><?= e($invoice['billing_month']) ?></td>
            <th>Due Date</th><td><?= e($invoice['due_date']) ?></td>
        </tr>
        <tr>
            <td class="amount">Rent</td><td class="amount"><?= etb((float)$invoice['rent_amount']) ?></td>
            <td class="amount">Service Charge</td><td class="amount"><?= etb((float)$invoice['service_amount']) ?></td>
        </tr>
        <?php if ((float)$invoice['utility_share'] > 0): ?>
        <tr>
            <td class="amount">Utility Share</td><td class="amount"><?= etb((float)$invoice['utility_share']) ?></td>
            <td class="amount">VAT</td><td class="amount"><?= etb((float)$invoice['vat_amount']) ?></td>
        </tr>
        <?php else: ?>
        <tr>
            <td class="amount">VAT</td><td class="amount"><?= etb((float)$invoice['vat_amount']) ?></td>
            <td></td><td></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td class="amount">Total Amount</td><td class="amount"><strong><?= etb((float)$invoice['total_amount']) ?></strong></td>
            <td class="amount">Already Paid</td><td class="amount"><?= etb((float)$invoice['paid_amount']) ?></td>
        </tr>
        <tr class="balance-row">
            <td colspan="3">Balance Due</td>
            <td class="amount" style="color:<?= $inv_balance > 0 ? 'var(--red)' : 'var(--green)' ?>"><?= etb($inv_balance) ?></td>
        </tr>
        <tr>
            <th>Status</th><td><span class="badge <?= $status_class ?>"><?= e(ucwords(str_replace('_',' ',$invoice['status']))) ?></span></td>
            <th>Invoice ID</th><td>#<?= (int)$invoice['id'] ?></td>
        </tr>
    </table>
</div>

<?php if ($can_pay): ?>
<div class="card">
    <h2>Upload Payment Receipt</h2>
    <form method="POST" action="" enctype="multipart/form-data" class="pay-form">
        <?= $csrfField ?>
        <div class="full field">
            <label for="amount_etb">Payment Amount (ETB)</label>
            <input type="number" id="amount_etb" name="amount_etb" step="0.01" min="0.01"
                   value="<?= e(number_format($inv_balance, 2, '.', '')) ?>" required>
            <div class="hint">Prefilled with your balance due. Adjust if paying a partial amount.</div>
        </div>
        <div class="field">
            <label for="payment_method">Payment Method</label>
            <select id="payment_method" name="payment_method" required>
                <option value="">— Select —</option>
                <option value="telebirr">Telebirr</option>
                <option value="cbe_birr">CBE Birr</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="chapa">Chapa</option>
                <option value="cash">Cash</option>
            </select>
        </div>
        <div class="field">
            <label for="transaction_ref">Transaction Reference</label>
            <input type="text" id="transaction_ref" name="transaction_ref" placeholder="e.g. TXN123456789" maxlength="120">
            <div class="hint">Leave blank only for cash payments.</div>
        </div>
        <div class="full field">
            <label for="receipt">Receipt / Proof of Payment</label>
            <input type="file" id="receipt" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
            <div class="hint">JPG, PNG, WEBP, or PDF — max 5 MB</div>
        </div>
        <button type="submit" class="btn-submit">Submit Payment</button>
    </form>
</div>
<?php elseif ($invoice['status'] === 'paid'): ?>
<div class="alert alert-success">✅ This invoice is fully paid. No payment is due at this time.</div>
<?php elseif ($invoice['status'] === 'pending_verification'): ?>
<div class="alert alert-success">⏳ Your payment is pending verification. Please wait for management to confirm.</div>
<?php endif; ?>

<?php else: ?>
<div class="card"><div class="no-data">No invoices found for your lease at this time.</div></div>
<?php endif; ?>

<div class="card">
    <h2>Payment History</h2>
    <?php if ($payments): ?>
    <div style="overflow-x:auto">
    <table class="history-table">
        <thead><tr><th>Date</th><th>Month</th><th>Method</th><th>Reference</th><th class="amount" style="text-align:right">Amount</th><th>Status</th></tr></thead>
        <tbody>
            <?php foreach ($payments as $p): ?>
            <tr>
                <td><?= e(date('M j, Y', strtotime($p['created_at']))) ?></td>
                <td><?= e($p['billing_month'] ?? '—') ?></td>
                <td><?= e(ucwords(str_replace('_',' ',$p['payment_method']))) ?></td>
                <td><?= e($p['transaction_ref'] ?: '—') ?></td>
                <td class="amount"><?= etb((float)$p['amount_etb']) ?></td>
                <td><span class="badge badge-<?= e($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div class="no-data">No payments recorded yet.</div>
    <?php endif; ?>
</div>

</body>
</html>
