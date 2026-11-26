<?php
/**
 * Tenant Portal — Login
 * Tenants authenticate with phone number + access_code (no email/password).
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

/* ---------- Already logged in? Go to payments ---------- */
if (!empty($_SESSION['tenant_lease_id'])) {
    header('Location: pay.php');
    exit;
}

/* ---------- Handle login submission ---------- */
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* CSRF check (no requireAdminOrAccountant here) */
    if (!verifyCsrf()) {
        $error = 'Security token expired. Please try again.';
    } else {
        $phone        = trim($_POST['phone'] ?? '');
        $access_code  = trim($_POST['access_code'] ?? '');

        if ($phone === '' || $access_code === '') {
            $error = 'Please enter both your phone number and access code.';
        } else {
            /* Look up active lease matching phone + access_code */
            $stmt = $pdo->prepare("
                SELECT id AS lease_id, tenant_name, status
                FROM leases
                WHERE tenant_phone = :phone
                  AND access_code = :access_code
                  AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute([
                ':phone'       => $phone,
                ':access_code' => $access_code,
            ]);
            $lease = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($lease) {
                $_SESSION['tenant_lease_id']   = $lease['lease_id'];
                $_SESSION['tenant_name']       = $lease['tenant_name'];
                header('Location: pay.php');
                exit;
            } else {
                $error = 'Invalid phone number or access code';
            }
        }
    }
}

/* csrfField() and e() are provided by config/auth.php */
$csrfField = csrfField();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tenant Portal — Property Management</title>
<style>
    :root {
        --blue:   #1a73e8;
        --blue-d: #1557b0;
        --bg:     #f0f4f8;
        --text:   #202124;
        --muted:  #5f6368;
        --error:  #d93025;
        --card:   #ffffff;
        --radius: 12px;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Segoe UI', Roboto, Arial, sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .login-card {
        background: var(--card);
        border-radius: var(--radius);
        box-shadow: 0 2px 12px rgba(0,0,0,.08);
        max-width: 420px;
        width: 100%;
        padding: 40px 36px;
    }

    .logo {
        text-align: center;
        margin-bottom: 28px;
    }
    .logo .icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 56px;
        height: 56px;
        background: var(--blue);
        border-radius: 14px;
        font-size: 28px;
        margin-bottom: 12px;
    }
    .logo h1 {
        font-size: 22px;
        font-weight: 600;
        color: var(--text);
    }
    .logo p {
        font-size: 14px;
        color: var(--muted);
        margin-top: 4px;
    }

    .field {
        margin-bottom: 18px;
    }
    .field label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: var(--muted);
        margin-bottom: 6px;
    }
    .field input {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid #dadce0;
        border-radius: 8px;
        font-size: 15px;
        color: var(--text);
        transition: border-color .15s, box-shadow .15s;
    }
    .field input:focus {
        outline: none;
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(26,115,232,.12);
    }

    .btn-login {
        width: 100%;
        padding: 13px;
        background: var(--blue);
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: background .15s;
        margin-top: 4px;
    }
    .btn-login:hover { background: var(--blue-d); }

    .alert-error {
        background: #fce8e6;
        color: var(--error);
        border: 1px solid #f3c3c0;
        padding: 12px 16px;
        border-radius: 8px;
        font-size: 14px;
        margin-bottom: 20px;
    }

    .instructions {
        background: #e8f0fe;
        border-left: 3px solid var(--blue);
        padding: 12px 16px;
        border-radius: 0 8px 8px 0;
        font-size: 13px;
        color: var(--muted);
        margin-bottom: 24px;
        line-height: 1.5;
    }

    .footer-link {
        text-align: center;
        margin-top: 24px;
        font-size: 13px;
        color: var(--muted);
    }
    .footer-link a {
        color: var(--blue);
        text-decoration: none;
        font-weight: 500;
    }
    .footer-link a:hover { text-decoration: underline; }

    @media (max-width: 480px) {
        .login-card { padding: 32px 24px; }
        .logo h1 { font-size: 20px; }
    }
</style>
</head>
<body>

<div class="login-card">

    <div class="logo">
        <div class="icon">🏠</div>
        <h1>Tenant Portal</h1>
        <p>Property Management System</p>
    </div>

    <div class="instructions">
        Enter the phone number and access code provided in your lease contract.
    </div>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <?= $csrfField ?>
        <div class="field">
            <label for="phone">Phone Number</label>
            <input type="text" id="phone" name="phone"
                   placeholder="e.g. 0912345678"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                   required>
        </div>
        <div class="field">
            <label for="access_code">Access Code</label>
            <input type="text" id="access_code" name="access_code"
                   placeholder="Enter your lease access code"
                   required>
        </div>
        <button type="submit" class="btn-login">Sign In</button>
    </form>

    <div class="footer-link">
        <a href="../admin/login.php">← Admin Login</a>
    </div>

</div>

</body>
</html>
