<?php
/**
 * Printable ETB Receipt / Invoice
 * Access via:
 *   receipt.php?invoice_id=X  — show invoice with latest approved payment
 *   receipt.php?payment_id=X  — show specific payment receipt
 *   receipt.php?lease_id=X    — show lease contract summary
 * Print or save as PDF via Ctrl+P / Cmd+P.
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/db.php';

$invoiceId = filter_input(INPUT_GET, 'invoice_id', FILTER_VALIDATE_INT);
$paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT);
$leaseId   = filter_input(INPUT_GET, 'lease_id',   FILTER_VALIDATE_INT);

/* ─── Lease Contract Summary ─── */
if ($leaseId && !$invoiceId && !$paymentId) {
    $stmt = $pdo->prepare("
        SELECT l.id, l.tenant_name, l.tenant_phone, l.tenant_email, l.tin_number,
               l.monthly_rent, l.deposit_amount, l.start_date, l.end_date,
               l.status, l.access_code, l.created_at,
               u.unit_code, u.floor_number, u.unit_type, u.area_sqm,
               u.service_charge, u.base_monthly_rent,
               b.name AS building_name, b.location AS building_location, b.total_floors
        FROM leases l
        JOIN units u      ON l.unit_id = u.id
        JOIN buildings b  ON u.building_id = b.id
        WHERE l.id = :lease_id
    ");
    $stmt->execute([':lease_id' => $leaseId]);
    $lease = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lease) {
        http_response_code(404);
        exit('Lease not found.');
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Lease Contract — <?= e($lease['tenant_name']) ?></title>
<style>
body{font-family:Arial,sans-serif;margin:30px;color:#222}
.doc-box{max-width:720px;margin:0 auto;border:1px solid #ccc;padding:32px}
.header{display:flex;justify-content:space-between;border-bottom:2px solid #222;padding-bottom:14px}
.header h2{margin:0;font-size:1.3rem}.header p{margin:2px 0;font-size:.85rem;color:#666}
.section{margin-top:24px}
.section h3{font-size:1rem;border-bottom:1px solid #eee;padding-bottom:6px;margin-bottom:12px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 24px}
.info-grid .row{display:flex;flex-direction:column}.info-grid .lbl{font-size:.75rem;color:#888;text-transform:uppercase;letter-spacing:.5px}
.info-grid .val{font-size:.95rem;font-weight:600}
.lease-terms{margin-top:20px;font-size:.85rem;line-height:1.6;color:#555}
.lease-terms p{margin-bottom:8px}
.signatures{display:flex;justify-content:space-between;margin-top:48px}
.sig-block{text-align:center;width:240px}.sig-block .line{border-top:1px solid #333;margin-top:48px;padding-top:6px;font-size:.85rem}
.actions{max-width:720px;margin:0 auto 14px auto;text-align:right}
@media print{.actions{display:none}body{margin:0}.doc-box{border:none;max-width:100%}}
</style>
</head>
<body>
<div class="actions"><button onclick="window.print()" style="padding:8px 20px;cursor:pointer">Print / Save PDF</button></div>
<div class="doc-box">
  <div class="header">
    <div>
      <h2>LEASE CONTRACT</h2>
      <p>Contract #: L-<?= (int)$lease['id'] ?> | Date: <?= e($lease['created_at']) ?></p>
    </div>
    <div style="text-align:right">
      <h2><?= e($lease['building_name']) ?></h2>
      <p><?= e($lease['building_location']) ?></p>
    </div>
  </div>

  <div class="section">
    <h3>Tenant Information</h3>
    <div class="info-grid">
      <div class="row"><span class="lbl">Full Name</span><span class="val"><?= e($lease['tenant_name']) ?></span></div>
      <div class="row"><span class="lbl">Phone</span><span class="val"><?= e($lease['tenant_phone']) ?></span></div>
      <div class="row"><span class="lbl">Email</span><span class="val"><?= e($lease['tenant_email'] ?: '—') ?></span></div>
      <div class="row"><span class="lbl">TIN Number</span><span class="val"><?= e($lease['tin_number'] ?: '—') ?></span></div>
    </div>
  </div>

  <div class="section">
    <h3>Property Information</h3>
    <div class="info-grid">
      <div class="row"><span class="lbl">Building</span><span class="val"><?= e($lease['building_name']) ?></span></div>
      <div class="row"><span class="lbl">Unit Code</span><span class="val"><?= e($lease['unit_code']) ?></span></div>
      <div class="row"><span class="lbl">Floor</span><span class="val"><?= (int)$lease['floor_number'] ?></span></div>
      <div class="row"><span class="lbl">Type</span><span class="val"><?= e(ucfirst($lease['unit_type'])) ?></span></div>
      <div class="row"><span class="lbl">Area</span><span class="val"><?= e($lease['area_sqm']) ?> m²</span></div>
      <div class="row"><span class="lbl">Location</span><span class="val"><?= e($lease['building_location']) ?></span></div>
    </div>
  </div>

  <div class="section">
    <h3>Financial Terms</h3>
    <div class="info-grid">
      <div class="row"><span class="lbl">Monthly Rent</span><span class="val"><?= etb((float)$lease['monthly_rent']) ?></span></div>
      <div class="row"><span class="lbl">Service Charge</span><span class="val"><?= etb((float)$lease['service_charge']) ?></span></div>
      <div class="row"><span class="lbl">Deposit</span><span class="val"><?= etb((float)$lease['deposit_amount']) ?></span></div>
      <div class="row"><span class="lbl">Total Monthly</span><span class="val"><?= etb((float)$lease['monthly_rent'] + (float)$lease['service_charge']) ?></span></div>
      <div class="row"><span class="lbl">Lease Start</span><span class="val"><?= e($lease['start_date']) ?></span></div>
      <div class="row"><span class="lbl">Lease End</span><span class="val"><?= e($lease['end_date']) ?></span></div>
    </div>
  </div>

  <div class="lease-terms">
    <p><strong>Terms &amp; Conditions:</strong></p>
    <p>1. Rent is due on or before the 5th of each billing month.</p>
    <p>2. Late payment may incur penalties as per building policy.</p>
    <p>3. The deposit is refundable upon lease termination, subject to deductions for damages.</p>
    <p>4. The tenant shall maintain the unit in good condition.</p>
    <p>5. Either party may terminate this lease with 30 days written notice.</p>
    <p>6. Tenant access code for portal: <strong><?= e($lease['access_code']) ?></strong></p>
  </div>

  <div class="signatures">
    <div class="sig-block"><div class="line">Landlord / Authorized Agent</div></div>
    <div class="sig-block"><div class="line">Tenant</div></div>
  </div>
</div>
</body>
</html>
  <?php
  exit;
}

/* ─── Invoice / Payment Receipt ─── */
if (!$invoiceId && !$paymentId) {
    http_response_code(400);
    exit('Missing invoice_id or payment_id.');
}

if ($paymentId) {
    $stmt = $pdo->prepare("
        SELECT i.id AS invoice_id, i.billing_month, i.rent_amount, i.service_amount,
               i.utility_share, i.vat_amount, i.total_amount, i.paid_amount,
               i.due_date, i.status AS invoice_status,
               l.tenant_name, l.tenant_phone, l.tin_number,
               u.unit_code, u.floor_number, u.unit_type,
               b.name AS building_name, b.location AS building_location,
               p.id AS payment_id, p.amount_etb AS payment_amount, p.payment_method,
               p.transaction_ref, p.status AS payment_status, p.verified_at
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN leases l   ON i.lease_id = l.id
        JOIN units u    ON l.unit_id = u.id
        JOIN buildings b ON u.building_id = b.id
        WHERE p.id = :payment_id
        LIMIT 1
    ");
    $stmt->execute([':payment_id' => $paymentId]);
} else {
    $stmt = $pdo->prepare("
        SELECT i.id AS invoice_id, i.billing_month, i.rent_amount, i.service_amount,
               i.utility_share, i.vat_amount, i.total_amount, i.paid_amount,
               i.due_date, i.status AS invoice_status,
               l.tenant_name, l.tenant_phone, l.tin_number,
               u.unit_code, u.floor_number, u.unit_type,
               b.name AS building_name, b.location AS building_location,
               p.id AS payment_id, p.amount_etb AS payment_amount, p.payment_method,
               p.transaction_ref, p.status AS payment_status, p.verified_at
        FROM invoices i
        JOIN leases l   ON i.lease_id = l.id
        JOIN units u    ON l.unit_id = u.id
        JOIN buildings b ON u.building_id = b.id
        LEFT JOIN payments p ON p.invoice_id = i.id AND p.status = 'approved'
        WHERE i.id = :invoice_id
        ORDER BY p.verified_at DESC, p.id DESC
        LIMIT 1
    ");
    $stmt->execute([':invoice_id' => $invoiceId]);
}

$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    http_response_code(404);
    exit('Receipt / Invoice record not found.');
}

$balanceDue = max(0.0, round((float)$record['total_amount'] - (float)$record['paid_amount'], 2));
$paymentLabel = $paymentId ? 'PAYMENT RECEIPT' : 'INVOICE';
$docNumber    = $paymentId ? 'RC-' . $record['payment_id'] : 'INV-' . $record['invoice_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $paymentLabel ?> — #<?= e($docNumber) ?></title>
<style>
  body{font-family:Arial,sans-serif;margin:30px;color:#222}
  .receipt-box{max-width:680px;margin:0 auto;border:1px solid #ccc;padding:24px}
  .header{display:flex;justify-content:space-between;border-bottom:2px solid #222;padding-bottom:12px}
  .header h2{margin:0;font-size:1.2rem}.header p{margin:2px 0;font-size:.85rem;color:#666}
  .doc-label{font-size:1.4rem;font-weight:bold;letter-spacing:1px;color:#1a73e8}
  .meta,.parties{display:flex;justify-content:space-between;margin-top:18px;gap:20px}
  .meta .col,.parties .col{flex:1}
  .meta .lbl,.parties .lbl{font-size:.75rem;color:#888;text-transform:uppercase;letter-spacing:.5px}
  .meta .val,.parties .val{font-size:.9rem;font-weight:600}
  table{width:100%;border-collapse:collapse;margin-top:20px}
  th,td{border:1px solid #ddd;padding:9px;text-align:left;font-size:.85rem}
  th{background:#f5f5f5;font-weight:600}
  .num{text-align:right}
  .total-row td{font-weight:bold;background:#fafafa;font-size:.95rem}
  .payment-box{margin-top:18px;padding:12px;background:#f9f9f9;border-left:4px solid #333;font-size:.85rem}
  .payment-box .row{display:flex;justify-content:space-between;padding:4px 0}
  .balance{margin-top:12px;text-align:right;font-size:1.1rem;font-weight:bold}
  .balance.zero{color:#1e7e34}.balance.due{color:#c5221f}
  .status-badge{display:inline-block;padding:4px 12px;border-radius:4px;font-size:.8rem;font-weight:600;text-transform:uppercase}
  .status-paid{background:#e6f4ea;color:#1e7e34}.status-unpaid{background:#fce8e8;color:#c5221f}
  .status-partial{background:#fff4e0;color:#856404}.status-pending_verification{background:#e8f0fe;color:#1a73e8}
  .status-overdue{background:#dc3545;color:#fff}
  .actions{max-width:680px;margin:0 auto 14px auto;text-align:right}
  .actions button{padding:8px 20px;cursor:pointer;border:none;border-radius:4px;background:#1a73e8;color:#fff;font-size:.9rem}
  .actions button:hover{background:#1557b0}
  .footer{max-width:680px;margin:14px auto 0;text-align:center;font-size:.75rem;color:#999}
  @media print{.actions{display:none}body{margin:0}.receipt-box{border:none;max-width:100%}}
</style>
</head>
<body>
<div class="actions"><button onclick="window.print()">Print / Save PDF</button></div>
<div class="receipt-box">
  <div class="header">
    <div>
      <div class="doc-label"><?= $paymentLabel ?></div>
      <p>Document #: <?= e($docNumber) ?></p>
      <p>Date: <?= e($record['verified_at'] ?? date('Y-m-d')) ?></p>
    </div>
    <div style="text-align:right">
      <h2><?= e($record['building_name']) ?></h2>
      <p><?= e($record['building_location']) ?></p>
      <p>Billing Month: <?= e($record['billing_month']) ?></p>
    </div>
  </div>

  <div class="parties">
    <div class="col">
      <div class="lbl">Tenant</div>
      <div class="val"><?= e($record['tenant_name']) ?></div>
      <div style="font-size:.8rem;color:#666;margin-top:2px">Phone: <?= e($record['tenant_phone']) ?></div>
      <?php if ($record['tin_number']): ?>
        <div style="font-size:.8rem;color:#666">TIN: <?= e($record['tin_number']) ?></div>
      <?php endif; ?>
    </div>
    <div class="col" style="text-align:right">
      <div class="lbl">Unit</div>
      <div class="val"><?= e($record['unit_code']) ?> (Floor <?= (int)$record['floor_number'] ?>)</div>
      <div style="font-size:.8rem;color:#666;margin-top:2px">Type: <?= e(ucfirst($record['unit_type'])) ?></div>
      <div style="margin-top:6px"><span class="status-badge status-<?= e($record['invoice_status']) ?>"><?= e(ucwords(str_replace('_',' ',$record['invoice_status']))) ?></span></div>
    </div>
  </div>

  <table>
    <thead>
      <tr><th>Description</th><th class="num">Amount (ETB)</th></tr>
    </thead>
    <tbody>
      <tr><td>Monthly Rent — <?= e($record['billing_month']) ?></td><td class="num"><?= number_format((float)$record['rent_amount'], 2) ?></td></tr>
      <tr><td>Service Charge</td><td class="num"><?= number_format((float)$record['service_amount'], 2) ?></td></tr>
      <?php if ((float)$record['utility_share'] > 0): ?>
      <tr><td>Shared Utility / Building Expense</td><td class="num"><?= number_format((float)$record['utility_share'], 2) ?></td></tr>
      <?php endif; ?>
      <?php if ((float)$record['vat_amount'] > 0): ?>
      <tr><td>VAT</td><td class="num"><?= number_format((float)$record['vat_amount'], 2) ?></td></tr>
      <?php endif; ?>
      <tr class="total-row"><td>Total Amount</td><td class="num"><?= number_format((float)$record['total_amount'], 2) ?></td></tr>
      <tr><td>Paid Amount</td><td class="num"><?= number_format((float)$record['paid_amount'], 2) ?></td></tr>
      <tr class="total-row"><td>Balance Due</td><td class="num"><?= number_format($balanceDue, 2) ?></td></tr>
    </tbody>
  </table>

  <?php if (!empty($record['payment_id']) && $record['payment_status'] === 'approved'): ?>
  <div class="payment-box">
    <div class="row"><span><strong>Payment Received</strong></span><span><?= etb((float)$record['payment_amount']) ?></span></div>
    <div class="row"><span>Method:</span><span><?= e(ucwords(str_replace('_',' ', $record['payment_method']))) ?></span></div>
    <div class="row"><span>Transaction Ref:</span><span><?= e($record['transaction_ref'] ?: '—') ?></span></div>
    <div class="row"><span>Verified At:</span><span><?= e($record['verified_at'] ?? '—') ?></span></div>
    <div class="row"><span>Receipt #:</span><span>RC-<?= (int)$record['payment_id'] ?></span></div>
  </div>
  <?php endif; ?>

  <div class="balance <?= $balanceDue > 0 ? 'due' : 'zero' ?>">
    <?= $balanceDue > 0 ? 'Balance Due: ' . etb($balanceDue) : 'FULLY PAID' ?>
  </div>

  <div style="margin-top:24px;font-size:.8rem;color:#888">
    <p>Due Date: <?= e($record['due_date']) ?></p>
    <p>Payment methods accepted: Telebirr, CBE Birr, Bank Transfer, Chapa, Cash</p>
  </div>
</div>
<div class="footer">
  This is a computer-generated document. No signature required.
</div>
</body>
</html>
