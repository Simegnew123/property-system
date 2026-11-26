<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireAdminOrAccountant();

$userName    = $_SESSION['user_name'] ?? 'User';
$currentMonth = date('Y-m');

/* ─────────────────────────────────────────────────────
   KPI 1 — Total Expected (current month's invoices)
   ───────────────────────────────────────────────────── */
try {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount), 0)
         FROM invoices
         WHERE billing_month = ?"
    );
    $stmt->execute([$currentMonth]);
    $totalExpected = (float)$stmt->fetchColumn();

    /* ─────────────────────────────────────────────────
       KPI 2 — Total Collected (current month's invoices)
       ───────────────────────────────────────────────── */
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(paid_amount), 0)
         FROM invoices
         WHERE billing_month = ?"
    );
    $stmt->execute([$currentMonth]);
    $totalCollected = (float)$stmt->fetchColumn();

    /* ─────────────────────────────────────────────────
       KPI 3 — Overdue Count
       ───────────────────────────────────────────────── */
    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM invoices
         WHERE status = 'overdue'
            OR (due_date < CURDATE() AND status != 'paid')"
    );
    $overdueCount = (int)$stmt->fetchColumn();

    /* ─────────────────────────────────────────────────
       KPI 4 — Expiring Leases (within 30 days, active)
       ───────────────────────────────────────────────── */
    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM leases
         WHERE end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           AND status = 'active'"
    );
    $expiringLeases = (int)$stmt->fetchColumn();

    /* ─────────────────────────────────────────────────
       Recent Payments (last 10)
       ───────────────────────────────────────────────── */
    $stmt = $pdo->query(
        "SELECT p.amount_etb,
                p.payment_method,
                p.status,
                p.created_at,
                l.tenant_name
         FROM payments p
         JOIN invoices i ON p.invoice_id = i.id
         JOIN leases   l ON i.lease_id   = l.id
         ORDER BY p.created_at DESC
         LIMIT 10"
    );
    $recentPayments = $stmt->fetchAll();

    /* ─────────────────────────────────────────────────
       Outstanding Invoices (top 10 unpaid / partial)
       ───────────────────────────────────────────────── */
    $stmt = $pdo->query(
        "SELECT i.id,
                i.total_amount,
                i.paid_amount,
                i.due_date,
                i.status,
                l.tenant_name,
                u.unit_code
         FROM invoices i
         JOIN leases l ON i.lease_id = l.id
         JOIN units   u ON l.unit_id  = u.id
         WHERE i.status IN ('unpaid', 'partial')
         ORDER BY i.due_date ASC
         LIMIT 10"
    );
    $outstandingInvoices = $stmt->fetchAll();
} catch (PDOException $ex) {
    $totalExpected      = 0;
    $totalCollected     = 0;
    $overdueCount       = 0;
    $expiringLeases     = 0;
    $recentPayments     = [];
    $outstandingInvoices = [];
}

$collectionRate = $totalExpected > 0
    ? round(($totalCollected / $totalExpected) * 100)
    : 0;

/* ── Helper: payment-method human label ── */
$methodLabels = [
    'telebirr'      => 'Telebirr',
    'cbe_birr'      => 'CBE Birr',
    'bank_transfer' => 'Bank Transfer',
    'chapa'         => 'Chapa',
    'cash'          => 'Cash',
];

/* ── Helper: status badge CSS class ── */
function statusBadgeClass(string $status): string {
    $map = [
        'paid'                  => 'badge-success',
        'approved'              => 'badge-success',
        'partial'               => 'badge-warning',
        'unpaid'                => 'badge-danger',
        'overdue'               => 'badge-danger',
        'rejected'              => 'badge-danger',
        'pending'               => 'badge-info',
        'pending_verification'  => 'badge-info',
    ];
    return $map[$status] ?? 'badge-default';
}

/* ── Helper: status human label ── */
function statusLabel(string $status): string {
    $map = [
        'pending_verification' => 'Pending Verification',
        'pending'              => 'Pending',
        'approved'             => 'Approved',
        'rejected'             => 'Rejected',
        'unpaid'               => 'Unpaid',
        'partial'              => 'Partial',
        'paid'                 => 'Paid',
        'overdue'              => 'Overdue',
    ];
    return $map[$status] ?? ucfirst($status);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard &mdash; Property Management System</title>
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --brand:        #1a73e8;
            --brand-dark:   #1557b0;
            --sidebar:      #1e1e2f;
            --sidebar-2:    #27293d;
            --text-light:   #a9a9b8;
            --text-dim:     #6c7293;
            --bg:           #f0f2f5;
            --card:         #ffffff;
            --border:       #e5e7eb;
            --text-dark:    #1f2937;
            --text-secondary: #6b7280;
            --success:      #16a34a;
            --warning:      #f59e0b;
            --danger:       #dc2626;
            --shadow:       0 2px 8px rgba(0, 0, 0, 0.06);
            --shadow-hover: 0 4px 16px rgba(0, 0, 0, 0.10);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                         'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            color: var(--text-dark);
            min-height: 100vh;
            line-height: 1.5;
        }

        /* ───────────────── Layout ───────────────── */
        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* ───────────────── Sidebar ───────────────── */
        .sidebar {
            width: 250px;
            background: linear-gradient(160deg, var(--sidebar) 0%, var(--sidebar-2) 100%);
            color: #fff;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 1.5rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar-header .logo {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
            box-shadow: 0 2px 12px rgba(26, 115, 232, 0.35);
        }

        .sidebar-header h1 {
            font-size: 0.9rem;
            font-weight: 700;
            line-height: 1.3;
        }

        .sidebar-header p {
            font-size: 0.7rem;
            color: var(--text-light);
            margin-top: 2px;
        }

        .sidebar-nav {
            flex: 1;
            padding: 0.75rem 0.625rem;
            overflow-y: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.7rem 0.875rem;
            color: var(--text-light);
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 2px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background 0.2s, color 0.2s;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .nav-item.active {
            background: var(--brand);
            color: #fff;
            box-shadow: 0 2px 10px rgba(26, 115, 232, 0.4);
        }

        .nav-item svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .nav-item.logout {
            color: #ff6b6b;
        }

        .nav-item.logout:hover {
            background: rgba(231, 76, 60, 0.15);
            color: #ff8a8a;
        }

        .sidebar-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 0.7rem;
            color: var(--text-dim);
            text-align: center;
        }

        /* ───────────────── Main Content ───────────────── */
        .main {
            flex: 1;
            margin-left: 250px;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* ───────────────── Top Bar ───────────────── */
        .topbar {
            background: var(--card);
            padding: 0.875rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .hamburger {
            display: none;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 8px;
            background: transparent;
            cursor: pointer;
            color: var(--text-dark);
        }

        .hamburger:hover {
            background: var(--bg);
        }

        .hamburger svg {
            width: 24px;
            height: 24px;
        }

        .topbar h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .user-info .name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .user-info .role {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--brand);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        /* ───────────────── Content Area ───────────────── */
        .content {
            padding: 1.5rem;
        }

        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .page-header p {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        /* ───────────────── KPI Cards ───────────────── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .kpi-card {
            background: var(--card);
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: var(--shadow);
            transition: box-shadow 0.2s, transform 0.2s;
            position: relative;
            overflow: hidden;
        }

        .kpi-card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .kpi-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .kpi-icon svg {
            width: 24px;
            height: 24px;
        }

        .kpi-icon.blue   { background: #dbeafe; color: var(--brand); }
        .kpi-icon.green  { background: #dcfce7; color: var(--success); }
        .kpi-icon.red    { background: #fee2e2; color: var(--danger); }
        .kpi-icon.orange { background: #fef3c7; color: var(--warning); }

        .kpi-label {
            font-size: 0.78rem;
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: 0.3rem;
        }

        .kpi-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.2;
        }

        .kpi-sub {
            font-size: 0.72rem;
            color: var(--text-secondary);
            margin-top: 0.6rem;
        }

        .kpi-sub .positive { color: var(--success); font-weight: 600; }
        .kpi-sub .negative { color: var(--danger); font-weight: 600; }

        /* Collection-rate mini progress bar */
        .progress-track {
            height: 6px;
            background: #f3f4f6;
            border-radius: 3px;
            margin-top: 0.6rem;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.6s ease;
        }

        /* ───────────────── Tables Grid ───────────────── */
        .tables-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .card {
            background: var(--card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header h4 {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-header .badge-count {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--brand);
            background: #eff6ff;
            padding: 0.2rem 0.55rem;
            border-radius: 20px;
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
        }

        thead th {
            text-align: left;
            padding: 0.7rem 1rem;
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-secondary);
            background: #f9fafb;
            border-bottom: 1px solid var(--border);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        tbody td {
            padding: 0.7rem 1rem;
            font-size: 0.84rem;
            border-bottom: 1px solid #f3f4f6;
            color: var(--text-dark);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tbody tr {
            transition: background 0.15s;
        }

        tbody tr:hover {
            background: #f9fafb;
        }

        .tenant-name {
            font-weight: 600;
            color: var(--text-dark);
        }

        .text-muted {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .amount-cell {
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        .amount-due {
            color: var(--danger);
            font-weight: 600;
        }

        .overdue-tag {
            color: var(--danger);
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 0.4rem;
        }

        /* ───────────────── Status Badges ───────────────── */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.22rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger  { background: #fee2e2; color: #991b1b; }
        .badge-info    { background: #dbeafe; color: #1e40af; }
        .badge-default { background: #f3f4f6; color: #374151; }

        /* ───────────────── Empty State ───────────────── */
        .empty-state {
            padding: 2.5rem 1rem;
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .empty-state svg {
            width: 40px;
            height: 40px;
            margin-bottom: 0.75rem;
            opacity: 0.4;
        }

        /* ───────────────── Sidebar Overlay (mobile) ───────────────── */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* ───────────────── Responsive ───────────────── */
        @media (max-width: 1100px) {
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .tables-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .hamburger {
                display: flex;
            }

            .user-info .name,
            .user-info .role {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 1rem;
            }

            .topbar {
                padding: 0.75rem 1rem;
            }

            .topbar h2 {
                font-size: 1rem;
            }

            .page-header h3 {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>

<div class="layout">

    <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">PMS</div>
            <div>
                <h1>Property Management</h1>
                <p>Admin Dashboard</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                </svg>
                Dashboard
            </a>

            <a href="buildings.php" class="nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 21h18M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/>
                    <line x1="9" y1="7" x2="11" y2="7"/>
                    <line x1="9" y1="11" x2="11" y2="11"/>
                    <line x1="9" y1="15" x2="11" y2="15"/>
                    <line x1="13" y1="7" x2="15" y2="7"/>
                    <line x1="13" y1="11" x2="15" y2="11"/>
                    <line x1="13" y1="15" x2="15" y2="15"/>
                </svg>
                Buildings &amp; Units
            </a>

            <a href="leases.php" class="nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="9" y1="13" x2="15" y2="13"/>
                    <line x1="9" y1="17" x2="15" y2="17"/>
                </svg>
                Leases
            </a>

            <a href="generate-bills.php" class="nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1z"/>
                    <line x1="8" y1="7" x2="16" y2="7"/>
                    <line x1="8" y1="11" x2="16" y2="11"/>
                    <line x1="8" y1="15" x2="12" y2="15"/>
                </svg>
                Generate Bills
            </a>

            <a href="verify-payments.php" class="nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                Verify Payments
            </a>

            <a href="maintenance.php" class="nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                </svg>
                Maintenance
            </a>

            <!-- Logout form with CSRF token -->
            <form action="logout.php" method="POST" style="margin-top: 0.5rem;">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <button type="submit" class="nav-item logout" style="width: 100%; border: none; background: none; cursor: pointer; text-align: left;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    Logout
                </button>
            </form>
        </nav>

        <div class="sidebar-footer">
            &copy; <?= date('Y') ?> Property Management System
        </div>
    </aside>

    <!-- Overlay for mobile sidebar -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ═══════════════════ MAIN CONTENT ═══════════════════ -->
    <div class="main">

        <!-- ──────── Top Bar ──────── -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <line x1="3" y1="12" x2="21" y2="12"/>
                        <line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <h2>Dashboard</h2>
            </div>

            <div class="topbar-right">
                <div class="user-info">
                    <div>
                        <div class="name"><?= e($userName) ?></div>
                        <div class="role"><?= e(ucfirst($_SESSION['user_role'] ?? '')) ?></div>
                    </div>
                    <div class="user-avatar">
                        <?= e(strtoupper(substr($userName, 0, 1))) ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- ──────── Dashboard Content ──────── -->
        <div class="content">

            <div class="page-header">
                <h3>Overview</h3>
                <p>Billing period: <?= e(date('F Y')) ?></p>
            </div>

            <!-- ═══════════ KPI Cards ═══════════ -->
            <div class="kpi-grid">

                <!-- KPI 1: Total Expected -->
                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-icon blue">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="1" x2="12" y2="23"/>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                            </svg>
                        </div>
                    </div>
                    <div class="kpi-label">Total Expected</div>
                    <div class="kpi-value"><?= e(etb($totalExpected)) ?></div>
                    <div class="kpi-sub">Current month invoices</div>
                </div>

                <!-- KPI 2: Total Collected -->
                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-icon green">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                        </div>
                    </div>
                    <div class="kpi-label">Total Collected</div>
                    <div class="kpi-value"><?= e(etb($totalCollected)) ?></div>
                    <div class="kpi-sub">
                        <span class="<?= $collectionRate >= 50 ? 'positive' : 'negative' ?>">
                            <?= $collectionRate ?>% collection rate
                        </span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width: <?= $collectionRate ?>%; background: <?= $collectionRate >= 50 ? 'var(--success)' : 'var(--warning)' ?>;"></div>
                    </div>
                </div>

                <!-- KPI 3: Overdue Count -->
                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-icon red">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                        </div>
                    </div>
                    <div class="kpi-label">Overdue Invoices</div>
                    <div class="kpi-value"><?= $overdueCount ?></div>
                    <div class="kpi-sub">
                        <?php if ($overdueCount > 0): ?>
                            <span class="negative">Requires attention</span>
                        <?php else: ?>
                            <span class="positive">All clear</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KPI 4: Expiring Leases -->
                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-icon orange">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                        </div>
                    </div>
                    <div class="kpi-label">Expiring Leases</div>
                    <div class="kpi-value"><?= $expiringLeases ?></div>
                    <div class="kpi-sub">Within next 30 days</div>
                </div>

            </div><!-- /.kpi-grid -->

            <!-- ═══════════ Tables ═══════════ -->
            <div class="tables-grid">

                <!-- ──── Recent Payments ──── -->
                <div class="card">
                    <div class="card-header">
                        <h4>Recent Payments</h4>
                        <span class="badge-count"><?= count($recentPayments) ?> records</span>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tenant</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentPayments)): ?>
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-state">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                                </svg>
                                                <div>No recent payments recorded.</div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentPayments as $p): ?>
                                        <tr>
                                            <td class="tenant-name"><?= e($p['tenant_name']) ?></td>
                                            <td class="amount-cell"><?= e(etb((float)$p['amount_etb'])) ?></td>
                                            <td class="text-muted"><?= e($methodLabels[$p['payment_method']] ?? ucfirst($p['payment_method'])) ?></td>
                                            <td>
                                                <span class="badge <?= statusBadgeClass($p['status']) ?>">
                                                    <?= e(statusLabel($p['status'])) ?>
                                                </span>
                                            </td>
                                            <td class="text-muted"><?= e(date('M j, Y', strtotime($p['created_at']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ──── Outstanding Invoices ──── -->
                <div class="card">
                    <div class="card-header">
                        <h4>Outstanding Invoices</h4>
                        <span class="badge-count"><?= count($outstandingInvoices) ?> records</span>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tenant</th>
                                    <th>Unit</th>
                                    <th>Amount Due</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($outstandingInvoices)): ?>
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-state">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                                </svg>
                                                <div>No outstanding invoices.</div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($outstandingInvoices as $inv):
                                        $due = (float)$inv['total_amount'] - (float)$inv['paid_amount'];
                                        $isOverdue = strtotime($inv['due_date']) < time();
                                    ?>
                                        <tr>
                                            <td class="tenant-name"><?= e($inv['tenant_name']) ?></td>
                                            <td class="text-muted"><?= e($inv['unit_code']) ?></td>
                                            <td class="amount-due"><?= e(etb($due)) ?></td>
                                            <td class="text-muted">
                                                <?= e(date('M j, Y', strtotime($inv['due_date']))) ?>
                                                <?php if ($isOverdue): ?>
                                                    <span class="overdue-tag">OVERDUE</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?= statusBadgeClass($inv['status']) ?>">
                                                    <?= e(statusLabel($inv['status'])) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /.tables-grid -->

        </div><!-- /.content -->
    </div><!-- /.main -->
</div><!-- /.layout -->

<script>
    // ── Mobile sidebar toggle ──
    (function () {
        var sidebar  = document.getElementById('sidebar');
        var overlay  = document.getElementById('sidebarOverlay');
        var hamburger = document.getElementById('hamburger');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        }

        hamburger.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    })();
</script>

</body>
</html>
