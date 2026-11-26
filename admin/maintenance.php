<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireAdminOrAccountant();

/* ------------------------------------------------------------------ */
/* Handle POST: update status                                         */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    // Verify CSRF token
    if (!verifyCsrf()) {
        die('CSRF token verification failed.');
    }

    $id         = (int)($_POST['request_id'] ?? 0);
    $status     = $_POST['status'] ?? '';
    $adminNotes = trim($_POST['admin_notes'] ?? '');

    $allowed = ['open', 'in_progress', 'resolved', 'closed'];
    if ($id > 0 && in_array($status, $allowed, true)) {
        $stmt = $pdo->prepare(
            "UPDATE maintenance_requests
                SET status = :status,
                    admin_notes = :admin_notes,
                    updated_at = NOW()
              WHERE id = :id"
        );
        $stmt->execute([
            ':status'      => $status,
            ':admin_notes' => $adminNotes,
            ':id'          => $id,
        ]);
    }

    // Redirect to avoid re-submit
    $redirect = 'maintenance.php?status=' . urlencode($_GET['status'] ?? '') . '&priority=' . urlencode($_GET['priority'] ?? '');
    header('Location: ' . $redirect);
    exit;
}

/* ------------------------------------------------------------------ */
/* Filters                                                            */
/* ------------------------------------------------------------------ */
$statusFilter   = $_GET['status']   ?? '';
$priorityFilter = $_GET['priority'] ?? '';

$where  = [];
$params = [];

if ($statusFilter !== '') {
    $where[]  = 'mr.status = :status';
    $params[':status'] = $statusFilter;
}
if ($priorityFilter !== '') {
    $where[]  = 'mr.priority = :priority';
    $params[':priority'] = $priorityFilter;
}

$whereSql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

/* ------------------------------------------------------------------ */
/* Fetch all maintenance requests                                     */
/* ------------------------------------------------------------------ */
$sql = "SELECT  mr.*,
                l.tenant_name,
                u.unit_number,
                b.name AS building_name
          FROM  maintenance_requests mr
          LEFT JOIN leases   l  ON mr.lease_id   = l.id
          LEFT JOIN units    u  ON l.unit_id     = u.id
          LEFT JOIN buildings b ON u.building_id = b.id
        {$whereSql}
        ORDER BY  mr.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------------------------------------------ */
/* Count badges                                                       */
/* ------------------------------------------------------------------ */
$countStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM maintenance_requests GROUP BY status");
$counts   = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
while ($row = $countStmt->fetch(PDO::FETCH_ASSOC)) {
    $counts[$row['status']] = (int)$row['cnt'];
}
$totalCount = array_sum($counts);

/* ------------------------------------------------------------------ */
/* Helpers                                                            */
/* ------------------------------------------------------------------ */
function priorityBadge($priority)
{
    $styles = [
        'low'    => 'badge badge-low',
        'medium' => 'badge badge-medium',
        'high'   => 'badge badge-high',
        'urgent' => 'badge badge-urgent',
    ];
    $cls   = $styles[$priority] ?? 'badge badge-low';
    $label = ucfirst($priority);
    return "<span class=\"$cls\">$label</span>";
}

function statusBadge($status)
{
    $styles = [
        'open'        => 'badge badge-open',
        'in_progress' => 'badge badge-in-progress',
        'resolved'    => 'badge badge-resolved',
        'closed'      => 'badge badge-closed',
    ];
    $cls   = $styles[$status] ?? 'badge badge-open';
    $label = str_replace('_', ' ', $status);
    $label = ucwords($label);
    return "<span class=\"$cls\">$label</span>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Requests — Property Management System</title>
    <style>
        /* ---------- Reset & Base ---------- */
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        html{font-size:14px;}
        body{
            font-family:'Segoe UI',system-ui,-apple-system,sans-serif;
            background:#f0f2f5;
            color:#333;
            line-height:1.5;
        }
        a{text-decoration:none;color:inherit;}

        /* ---------- Layout ---------- */
        .layout{display:flex;min-height:100vh;}

        /* ---------- Sidebar ---------- */
        .sidebar{
            width:240px;
            background:#1e293b;
            color:#cbd5e1;
            flex-shrink:0;
            display:flex;
            flex-direction:column;
            transition:width .2s ease;
        }
        .sidebar-header{
            padding:24px 20px;
            border-bottom:1px solid #334155;
        }
        .sidebar-header h1{
            font-size:1.15rem;
            color:#fff;
            display:flex;
            align-items:center;
            gap:8px;
        }
        .sidebar-header .logo-icon{font-size:1.4rem;}
        .sidebar-header p{font-size:.72rem;color:#64748b;margin-top:4px;}
        .nav{padding:12px 0;flex:1;}
        .nav-item{
            display:flex;
            align-items:center;
            gap:12px;
            padding:11px 20px;
            font-size:.875rem;
            color:#94a3b8;
            transition:all .15s ease;
            border-left:3px solid transparent;
        }
        .nav-item:hover{background:#334155;color:#fff;}
        .nav-item.active{
            background:#0f766e;
            color:#fff;
            border-left-color:#14b8a6;
            font-weight:600;
        }
        .nav-item .nav-icon{font-size:1.1rem;width:22px;text-align:center;}

        /* ---------- Main ---------- */
        .main{flex:1;display:flex;flex-direction:column;overflow-x:hidden;}
        .topbar{
            background:#fff;
            padding:16px 28px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            box-shadow:0 1px 3px rgba(0,0,0,.08);
            position:sticky;
            top:0;
            z-index:10;
        }
        .topbar h2{font-size:1.3rem;color:#1e293b;}
        .topbar .breadcrumb{font-size:.78rem;color:#94a3b8;margin-top:2px;}
        .topbar .user-area{display:flex;align-items:center;gap:14px;}
        .topbar .user-avatar{
            width:38px;height:38px;border-radius:50%;
            background:#0f766e;color:#fff;
            display:flex;align-items:center;justify-content:center;
            font-weight:700;font-size:.9rem;
        }

        /* ---------- Content ---------- */
        .content{padding:24px 28px;flex:1;}

        /* ---------- Stats Cards ---------- */
        .stats-row{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
            gap:16px;
            margin-bottom:24px;
        }
        .stat-card{
            background:#fff;
            border-radius:12px;
            padding:18px 20px;
            box-shadow:0 1px 4px rgba(0,0,0,.06);
            display:flex;
            align-items:center;
            gap:14px;
            transition:transform .15s ease, box-shadow .15s ease;
        }
        .stat-card:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.1);}
        .stat-card .stat-icon{
            width:46px;height:46px;border-radius:12px;
            display:flex;align-items:center;justify-content:center;
            font-size:1.3rem;flex-shrink:0;
        }
        .stat-card.open .stat-icon      {background:#fee2e2;color:#dc2626;}
        .stat-card.in-progress .stat-icon{background:#fef9c3;color:#ca8a04;}
        .stat-card.resolved .stat-icon   {background:#dcfce7;color:#16a34a;}
        .stat-card.total .stat-icon      {background:#e0e7ff;color:#4f46e5;}
        .stat-card .stat-info h3{font-size:1.6rem;color:#1e293b;line-height:1;}
        .stat-card .stat-info p{font-size:.75rem;color:#94a3b8;margin-top:4px;text-transform:uppercase;letter-spacing:.03em;}

        /* ---------- Toolbar / Filters ---------- */
        .toolbar{
            background:#fff;
            border-radius:12px;
            padding:16px 20px;
            box-shadow:0 1px 4px rgba(0,0,0,.06);
            margin-bottom:20px;
            display:flex;
            flex-wrap:wrap;
            align-items:center;
            gap:12px;
        }
        .filter-group{display:flex;align-items:center;gap:8px;}
        .filter-group label{font-size:.8rem;font-weight:600;color:#64748b;white-space:nowrap;}
        select.filter-select{
            padding:7px 30px 7px 12px;
            border:1px solid #d1d5db;
            border-radius:8px;
            font-size:.82rem;
            background:#fff;
            cursor:pointer;
            appearance:none;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat:no-repeat;
            background-position:right 10px center;
        }
        select.filter-select:focus{outline:2px solid #0f766e;outline-offset:-1px;}
        .btn{
            padding:7px 18px;
            border-radius:8px;
            border:none;
            font-size:.82rem;
            font-weight:600;
            cursor:pointer;
            transition:all .15s ease;
        }
        .btn-primary{background:#0f766e;color:#fff;}
        .btn-primary:hover{background:#0d5f57;}
        .btn-secondary{background:#e5e7eb;color:#4b5563;}
        .btn-secondary:hover{background:#d1d5db;}
        .toolbar .spacer{flex:1;}

        /* ---------- Table ---------- */
        .table-wrap{
            background:#fff;
            border-radius:12px;
            box-shadow:0 1px 4px rgba(0,0,0,.06);
            overflow:hidden;
        }
        .table-scroll{overflow-x:auto;}
        table{width:100%;border-collapse:collapse;}
        thead th{
            background:#f8fafc;
            padding:12px 16px;
            text-align:left;
            font-size:.72rem;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.05em;
            color:#64748b;
            border-bottom:2px solid #e2e8f0;
            white-space:nowrap;
        }
        tbody td{
            padding:14px 16px;
            border-bottom:1px solid #f1f5f9;
            font-size:.83rem;
            vertical-align:top;
        }
        tbody tr:hover{background:#f8fafc;}
        tbody tr:last-child td{border-bottom:none;}
        .tenant-name{font-weight:600;color:#1e293b;}
        .building-unit{font-size:.78rem;color:#64748b;}
        .request-title{font-weight:500;color:#1e293b;max-width:220px;}
        .request-desc{font-size:.76rem;color:#94a3b8;margin-top:2px;}
        .date-cell{white-space:nowrap;font-size:.78rem;color:#64748b;}
        .empty-row td{text-align:center;padding:48px 16px;color:#94a3b8;}

        /* ---------- Badges ---------- */
        .badge{
            display:inline-flex;
            align-items:center;
            padding:3px 11px;
            border-radius:20px;
            font-size:.72rem;
            font-weight:600;
            white-space:nowrap;
            letter-spacing:.02em;
        }
        /* Priority */
        .badge-low     {background:#f3f4f6;color:#6b7280;}
        .badge-medium  {background:#dbeafe;color:#2563eb;}
        .badge-high    {background:#ffedd5;color:#ea580c;}
        .badge-urgent  {background:#fee2e2;color:#dc2626;}
        /* Status */
        .badge-open        {background:#fee2e2;color:#dc2626;}
        .badge-in-progress {background:#fef9c3;color:#a16207;}
        .badge-resolved    {background:#dcfce7;color:#16a34a;}
        .badge-closed      {background:#f3f4f6;color:#6b7280;}

        /* ---------- Action form (inline per row) ---------- */
        .action-form{display:flex;flex-direction:column;gap:6px;min-width:180px;}
        .action-form select.action-select{
            padding:5px 26px 5px 10px;
            border:1px solid #d1d5db;
            border-radius:6px;
            font-size:.78rem;
            background:#fff;
            cursor:pointer;
            appearance:none;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat:no-repeat;
            background-position:right 8px center;
        }
        .action-form select.action-select:focus{outline:2px solid #0f766e;outline-offset:-1px;}
        .action-form textarea{
            padding:5px 8px;
            border:1px solid #d1d5db;
            border-radius:6px;
            font-size:.75rem;
            resize:vertical;
            min-height:36px;
            font-family:inherit;
        }
        .action-form textarea:focus{outline:2px solid #0f766e;outline-offset:-1px;}
        .action-form .btn{padding:5px 14px;font-size:.76rem;}

        /* ---------- Mobile sidebar toggle ---------- */
        .mobile-toggle{
            display:none;
            background:none;border:none;
            font-size:1.4rem;cursor:pointer;
            color:#1e293b;
            padding:4px;
        }
        .overlay{
            display:none;
            position:fixed;inset:0;
            background:rgba(0,0,0,.4);
            z-index:50;
        }

        /* ---------- Responsive ---------- */
        @media(max-width:768px){
            .sidebar{
                position:fixed;
                left:-260px;
                top:0;bottom:0;
                z-index:100;
                width:240px;
            }
            .sidebar.open{left:0;}
            .overlay.show{display:block;}
            .mobile-toggle{display:block;}
            .content{padding:16px;}
            .topbar{padding:12px 16px;}
            .topbar h2{font-size:1.1rem;}
            .stats-row{grid-template-columns:repeat(2,1fr);}
            .stat-card{padding:14px;}
            .toolbar{flex-direction:column;align-items:stretch;}
            .filter-group{width:100%;}
            .filter-group select{flex:1;}
            .toolbar .btn{width:100%;text-align:center;}
            .toolbar .spacer{display:none;}
            .request-title{max-width:none;}
        }
        @media(max-width:480px){
            .stats-row{grid-template-columns:1fr;}
            .sidebar-header h1{font-size:1rem;}
            thead th{font-size:.66rem;padding:10px;}
            tbody td{padding:10px;}
        }
    </style>
</head>
<body>
<div class="layout">
    <!-- ============ Sidebar ============ -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h1><span class="logo-icon">🏢</span> Property System</h1>
            <p>Building &amp; Apartment Management</p>
        </div>
        <div class="nav">
            <a href="dashboard.php"      class="nav-item"><span class="nav-icon">📊</span> Dashboard</a>
            <a href="buildings.php"      class="nav-item"><span class="nav-icon">🏛️</span> Buildings</a>
            <a href="units.php"          class="nav-item"><span class="nav-icon">🏠</span> Units</a>
            <a href="tenants.php"        class="nav-item"><span class="nav-icon">👤</span> Tenants</a>
            <a href="leases.php"         class="nav-item"><span class="nav-icon">📄</span> Leases</a>
            <a href="payments.php"       class="nav-item"><span class="nav-icon">💳</span> Payments</a>
            <a href="maintenance.php"    class="nav-item active"><span class="nav-icon">🔧</span> Maintenance</a>
            <a href="invoices.php"       class="nav-item"><span class="nav-icon">🧾</span> Invoices</a>
            <a href="reports.php"        class="nav-item"><span class="nav-icon">📈</span> Reports</a>
            <a href="settings.php"       class="nav-item"><span class="nav-icon">⚙️</span> Settings</a>
            <a href="logout.php"         class="nav-item"><span class="nav-icon">🚪</span> Logout</a>
        </div>
    </nav>
    <div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

    <!-- ============ Main ============ -->
    <div class="main">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                <div>
                    <h2>Maintenance Requests</h2>
                    <div class="breadcrumb">Admin / Maintenance</div>
                </div>
            </div>
            <div class="user-area">
                <div class="user-avatar">A</div>
            </div>
        </header>

        <div class="content">
            <!-- ===== Stats Cards ===== -->
            <div class="stats-row">
                <div class="stat-card open">
                    <div class="stat-icon">🔴</div>
                    <div class="stat-info">
                        <h3><?= $counts['open'] ?></h3>
                        <p>Open</p>
                    </div>
                </div>
                <div class="stat-card in-progress">
                    <div class="stat-icon">🟡</div>
                    <div class="stat-info">
                        <h3><?= $counts['in_progress'] ?></h3>
                        <p>In Progress</p>
                    </div>
                </div>
                <div class="stat-card resolved">
                    <div class="stat-icon">🟢</div>
                    <div class="stat-info">
                        <h3><?= $counts['resolved'] ?></h3>
                        <p>Resolved</p>
                    </div>
                </div>
                <div class="stat-card total">
                    <div class="stat-icon">📋</div>
                    <div class="stat-info">
                        <h3><?= $totalCount ?></h3>
                        <p>Total Requests</p>
                    </div>
                </div>
            </div>

            <!-- ===== Filters ===== -->
            <div class="toolbar">
                <form method="GET" action="maintenance.php" id="filterForm" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;width:100%;">
                    <div class="filter-group">
                        <label for="statusFilter">Status</label>
                        <select name="status" id="statusFilter" class="filter-select" onchange="document.getElementById('filterForm').submit();">
                            <option value="">All Statuses</option>
                            <option value="open"        <?= $statusFilter === 'open'        ? 'selected' : '' ?>>Open</option>
                            <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="resolved"    <?= $statusFilter === 'resolved'    ? 'selected' : '' ?>>Resolved</option>
                            <option value="closed"      <?= $statusFilter === 'closed'      ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="priorityFilter">Priority</label>
                        <select name="priority" id="priorityFilter" class="filter-select" onchange="document.getElementById('filterForm').submit();">
                            <option value="">All Priorities</option>
                            <option value="low"    <?= $priorityFilter === 'low'    ? 'selected' : '' ?>>Low</option>
                            <option value="medium" <?= $priorityFilter === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="high"   <?= $priorityFilter === 'high'   ? 'selected' : '' ?>>High</option>
                            <option value="urgent" <?= $priorityFilter === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                        </select>
                    </div>
                    <div class="spacer"></div>
                    <a href="maintenance.php" class="btn btn-secondary">Clear Filters</a>
                </form>
            </div>

            <!-- ===== Table ===== -->
            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Tenant</th>
                                <th>Building / Unit</th>
                                <th>Title</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($requests)): ?>
                                <tr class="empty-row">
                                    <td colspan="7">No maintenance requests found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($requests as $req): ?>
                                    <?php
                                        $dateFormatted = date('M j, Y', strtotime($req['created_at']));
                                        $tenantName    = e($req['tenant_name'] ?? 'Unknown');
                                        $buildingName  = e($req['building_name'] ?? 'N/A');
                                        $unitNumber    = e($req['unit_number'] ?? 'N/A');
                                        $title         = e($req['title'] ?? '');
                                        $description   = e($req['description'] ?? '');
                                        $priority      = e($req['priority'] ?? 'low');
                                        $status        = e($req['status'] ?? 'open');
                                        $adminNotes    = e($req['admin_notes'] ?? '');
                                        $requestId     = (int)$req['id'];
                                    ?>
                                    <tr>
                                        <td class="date-cell"><?= $dateFormatted ?></td>
                                        <td>
                                            <div class="tenant-name"><?= $tenantName ?></div>
                                        </td>
                                        <td>
                                            <div><?= $buildingName ?></div>
                                            <div class="building-unit">Unit <?= $unitNumber ?></div>
                                        </td>
                                        <td>
                                            <div class="request-title"><?= $title ?></div>
                                            <?php if ($description): ?>
                                                <div class="request-desc"><?= $description ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= priorityBadge($priority) ?></td>
                                        <td><?= statusBadge($status) ?></td>
                                        <td>
                                            <form method="POST" action="maintenance.php?status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>" class="action-form">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                                <select name="status" class="action-select" required>
                                                    <option value="open"        <?= $status === 'open'        ? 'selected' : '' ?>>Open</option>
                                                    <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                                    <option value="resolved"    <?= $status === 'resolved'    ? 'selected' : '' ?>>Resolved</option>
                                                    <option value="closed"      <?= $status === 'closed'      ? 'selected' : '' ?>>Closed</option>
                                                </select>
                                                <textarea name="admin_notes" placeholder="Admin notes…" rows="2"><?= $adminNotes ?></textarea>
                                                <button type="submit" class="btn btn-primary">Save</button>
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
</div>

<script>
    // Mobile sidebar toggle
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('overlay').classList.toggle('show');
    }
</script>
</body>
</html>
