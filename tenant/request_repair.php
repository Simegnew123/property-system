<?php
/**
 * Tenant Portal — Maintenance / Repair Request
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

/* ---------- Auth guard ---------- */
if (empty($_SESSION['tenant_lease_id'])) {
    header('Location: index.php');
    exit;
}

$lease_id    = (int) $_SESSION['tenant_lease_id'];
$tenant_name = $_SESSION['tenant_name'] ?? 'Tenant';

/* ---------- Load lease info (need unit_id + tenant_name for INSERT) ---------- */
$stmt = $pdo->prepare("
    SELECT l.id, l.tenant_name, l.unit_id,
           u.unit_code, b.name AS building_name
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

$unit_id     = (int) $lease['unit_id'];
$tenant_name = $lease['tenant_name'];

/* e() and csrfField() are provided by config/auth.php */

/* ---------- Handle submission ---------- */
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* CSRF check */
    if (!verifyCsrf()) {
        $error_msg = 'Security token expired. Please refresh and try again.';
    } else {
        $category    = $_POST['category'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $priority    = $_POST['priority'] ?? 'medium';
        $allowed_cat = ['plumbing', 'electrical', 'structural', 'appliance', 'other'];
        $allowed_pri = ['low', 'medium', 'high', 'urgent'];

        if (!in_array($category, $allowed_cat, true)) {
            $error_msg = 'Please select a valid category.';
        } elseif ($description === '') {
            $error_msg = 'Please describe the issue.';
        } elseif (!in_array($priority, $allowed_pri, true)) {
            $error_msg = 'Invalid priority level.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO maintenance_requests
                        (lease_id, unit_id, tenant_name, category,
                         description, priority, status, created_at)
                    VALUES
                        (:lease_id, :unit_id, :tenant_name, :category,
                         :description, :priority, 'open', NOW())
                ");
                $stmt->execute([
                    ':lease_id'    => $lease_id,
                    ':unit_id'     => $unit_id,
                    ':tenant_name' => $tenant_name,
                    ':category'    => $category,
                    ':description' => $description,
                    ':priority'    => $priority,
                ]);

                $success_msg = 'Your maintenance request has been submitted successfully. The management team will review it shortly.';
            } catch (PDOException $ex) {
                $error_msg = 'A database error occurred. Please try again.';
            }
        }
    }
}

/* ---------- Load previous requests ---------- */
$stmt = $pdo->prepare("
    SELECT *
    FROM maintenance_requests
    WHERE lease_id = :lease_id
    ORDER BY created_at DESC
");
$stmt->execute([':lease_id' => $lease_id]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrfField = csrfField();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Request Repair — Tenant Portal</title>
<style>
    :root {
        --blue:   #1a73e8;
        --blue-d: #1557b0;
        --bg:     #f0f4f8;
        --text:   #202124;
        --muted:  #5f6368;
        --green:  #1e8e3e;
        --orange: #f9ab00;
        --red:    #d93025;
        --card:   #ffffff;
        --border: #e0e0e0;
        --radius: 12px;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Segoe UI', Roboto, Arial, sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        padding: 20px;
    }

    /* ---------- Top bar ---------- */
    .topbar {
        background: var(--card);
        border-radius: var(--radius);
        box-shadow: 0 1px 6px rgba(0,0,0,.06);
        padding: 16px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .topbar-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .topbar-left .icon {
        width: 40px; height: 40px;
        background: var(--blue);
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 20px; color: #fff;
    }
    .topbar-left h1 { font-size: 18px; font-weight: 600; }
    .topbar-left p  { font-size: 13px; color: var(--muted); }

    .topbar-right {
        display: flex; gap: 8px; flex-wrap: wrap;
    }
    .nav-link {
        padding: 8px 16px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: background .15s;
    }
    .nav-link.primary { background: var(--blue); color: #fff; }
    .nav-link.primary:hover { background: var(--blue-d); }
    .nav-link.ghost { background: transparent; color: var(--muted); border: 1px solid var(--border); }
    .nav-link.ghost:hover { background: #f5f5f5; }

    /* ---------- Card ---------- */
    .card {
        background: var(--card);
        border-radius: var(--radius);
        box-shadow: 0 1px 6px rgba(0,0,0,.06);
        padding: 24px;
        margin-bottom: 24px;
    }
    .card h2 {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--border);
    }

    /* ---------- Form ---------- */
    .repair-form {
        display: grid;
        gap: 16px;
    }
    .field label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: var(--muted);
        margin-bottom: 6px;
    }
    .field input[type="text"],
    .field select,
    .field textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 14px;
        color: var(--text);
        background: #fff;
        font-family: inherit;
        transition: border-color .15s, box-shadow .15s;
    }
    .field input:focus,
    .field select:focus,
    .field textarea:focus {
        outline: none;
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(26,115,232,.12);
    }
    .field textarea {
        resize: vertical;
        min-height: 100px;
    }
    .field .hint {
        font-size: 12px;
        color: var(--muted);
        margin-top: 4px;
    }

    .btn-submit {
        padding: 14px;
        background: var(--blue);
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: background .15s;
        justify-self: start;
        min-width: 200px;
    }
    .btn-submit:hover { background: var(--blue-d); }

    /* ---------- Alerts ---------- */
    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        font-size: 14px;
        margin-bottom: 20px;
        line-height: 1.5;
    }
    .alert-success {
        background: #e6f4ea;
        color: var(--green);
        border: 1px solid #b7e1c5;
    }
    .alert-error {
        background: #fce8e6;
        color: var(--red);
        border: 1px solid #f3c3c0;
    }

    /* ---------- Request list ---------- */
    .request-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .request-item {
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 16px;
        background: #fafafa;
    }
    .request-item .req-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }
    .request-item .req-title {
        font-size: 15px;
        font-weight: 600;
        text-transform: capitalize;
    }
    .request-item .req-date {
        font-size: 12px;
        color: var(--muted);
    }
    .request-item .req-desc {
        font-size: 14px;
        color: var(--text);
        line-height: 1.5;
        margin-bottom: 10px;
    }
    .request-item .req-meta {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    /* ---------- Badges ---------- */
    .badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .3px;
    }
    .badge-open        { background: #e8f0fe; color: var(--blue); }
    .badge-in_progress { background: #fef7e0; color: #b06000; }
    .badge-resolved    { background: #e6f4ea; color: var(--green); }
    .badge-closed      { background: #e6e6e6; color: var(--muted); }

    .pri-low      { background: #e6e6e6; color: var(--muted); }
    .pri-medium   { background: #e8f0fe; color: var(--blue); }
    .pri-high     { background: #fef7e0; color: #b06000; }
    .pri-urgent   { background: #fce8e6; color: var(--red); }

    .no-data {
        text-align: center;
        color: var(--muted);
        padding: 24px;
        font-size: 14px;
    }

    /* ---------- Responsive ---------- */
    @media (max-width: 640px) {
        body { padding: 12px; }
        .card { padding: 18px; }
        .topbar { padding: 14px 16px; }
        .topbar-left h1 { font-size: 16px; }
        .btn-submit { width: 100%; justify-self: stretch; }
        .request-item { padding: 14px; }
    }
</style>
</head>
<body>

<!-- ============ TOP BAR ============ -->
<div class="topbar">
    <div class="topbar-left">
        <div class="icon">🏠</div>
        <div>
            <h1>Tenant Portal</h1>
            <p>Welcome, <?= e($tenant_name) ?> — <?= e(tr($lease['building_name'] ?? '')) ?>, Unit <?= e($lease['unit_code'] ?? '') ?></p>
        </div>
    </div>
    <div class="topbar-right">
        <a href="pay.php" class="nav-link ghost">💳 Payments</a>
        <a href="../logout.php" class="nav-link ghost">Logout</a>
    </div>
</div>

<!-- ============ MESSAGES ============ -->
<?php if ($success_msg): ?>
    <div class="alert alert-success">✅ <?= e($success_msg) ?></div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-error">⚠️ <?= e($error_msg) ?></div>
<?php endif; ?>

<!-- ============ REQUEST FORM ============ -->
<div class="card">
    <h2>Submit a Maintenance Request</h2>
    <form method="POST" action="" class="repair-form">
        <?= $csrfField ?>

        <div class="field">
            <label for="category">Category</label>
            <select id="category" name="category" required>
                <option value="">— Select category —</option>
                <option value="plumbing"    <?= (($_POST['category'] ?? '') === 'plumbing')    ? 'selected' : '' ?>>🔧 Plumbing — Water, pipes, drainage</option>
                <option value="electrical"  <?= (($_POST['category'] ?? '') === 'electrical')  ? 'selected' : '' ?>>⚡ Electrical — Wiring, outlets, lighting</option>
                <option value="structural"  <?= (($_POST['category'] ?? '') === 'structural')  ? 'selected' : '' ?>>🏗️ Structural — Walls, doors, windows, roof</option>
                <option value="appliance"   <?= (($_POST['category'] ?? '') === 'appliance')   ? 'selected' : '' ?>>🔌 Appliance — Built-in fixtures, AC, etc.</option>
                <option value="other"       <?= (($_POST['category'] ?? '') === 'other')       ? 'selected' : '' ?>>📦 Other — Anything not listed above</option>
            </select>
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description"
                      placeholder="Describe the problem in detail — when it started, what you've noticed, etc."
                      required><?= e($_POST['description'] ?? '') ?></textarea>
        </div>

        <div class="field">
            <label for="priority">Priority</label>
            <select id="priority" name="priority">
                <option value="low"    <?= (($_POST['priority'] ?? '') === 'low')    ? 'selected' : '' ?>>Low — Minor inconvenience</option>
                <option value="medium" <?= (($_POST['priority'] ?? '') === 'medium' || ($_POST['priority'] ?? '') === '') ? 'selected' : '' ?>>Medium — Needs attention</option>
                <option value="high"   <?= (($_POST['priority'] ?? '') === 'high')   ? 'selected' : '' ?>>High — Urgent issue</option>
                <option value="urgent" <?= (($_POST['priority'] ?? '') === 'urgent') ? 'selected' : '' ?>>Urgent — Emergency</option>
            </select>
            <div class="hint">Use "Urgent" only for emergencies (water leak, electrical hazard, gas, etc.).</div>
        </div>

        <button type="submit" class="btn-submit">Submit Request</button>
    </form>
</div>

<!-- ============ PREVIOUS REQUESTS ============ -->
<div class="card">
    <h2>Your Previous Requests</h2>

    <?php if ($requests): ?>
    <div class="request-list">
        <?php foreach ($requests as $req): ?>
            <?php
                $status_class = match($req['status']) {
                    'open'        => 'badge-open',
                    'in_progress' => 'badge-in_progress',
                    'resolved'    => 'badge-resolved',
                    'closed'      => 'badge-closed',
                    default       => 'badge-closed',
                };
                $pri_class = match($req['priority']) {
                    'low'    => 'pri-low',
                    'medium' => 'pri-medium',
                    'high'   => 'pri-high',
                    'urgent' => 'pri-urgent',
                    default  => 'pri-medium',
                };
            ?>
        <div class="request-item">
            <div class="req-header">
                <div>
                    <div class="req-title"><?= e($req['category']) ?></div>
                    <div class="req-date"><?= e(date('M j, Y \a\t g:i A', strtotime($req['created_at']))) ?></div>
                </div>
            </div>
            <div class="req-desc"><?= nl2br(e(tr($req['description']))) ?></div>
            <div class="req-meta">
                <span class="badge <?= $pri_class ?>"><?= e(ucfirst($req['priority'])) ?></span>
                <span class="badge <?= $status_class ?>"><?= e(ucwords(str_replace('_', ' ', $req['status']))) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="no-data">You have not submitted any maintenance requests yet.</div>
    <?php endif; ?>
</div>

</body>
</html>
