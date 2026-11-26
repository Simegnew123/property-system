<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']       ?? '';

        $email_value = $email;

        if ($email === '' || $password === '') {
            $error = 'Please enter both email and password.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role, status FROM users WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $error = 'No account found with that email.';
                } elseif ($user['status'] !== 'active') {
                    $error = 'Your account has been suspended. Please contact the administrator.';
                } elseif (!password_verify($password, $user['password_hash'])) {
                    $error = 'Incorrect password. Please try again.';
                } else {
                    // Success — set session
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['full_name'];

                    // Regenerate session ID to prevent fixation
                    session_regenerate_id(true);

                    header('Location: dashboard.php');
                    exit;
                }
            } catch (PDOException $ex) {
                $error = 'A system error occurred. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Management System &mdash; Admin Login</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --brand:      #1a73e8;
            --brand-dark: #1557b0;
            --sidebar:    #1e1e2f;
            --sidebar-2:  #27293d;
            --text-light: #a9a9b8;
            --text-dim:   #6c7293;
            --bg:         #f0f2f5;
            --card:       #ffffff;
            --danger:     #e74c3c;
            --success:    #2ecc71;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
        }

        /* ---- Left dark sidebar (branding) ---- */
        .sidebar {
            flex: 0 0 380px;
            background: linear-gradient(160deg, var(--sidebar) 0%, var(--sidebar-2) 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 3rem 2.5rem;
            position: relative;
        }

        .sidebar .logo {
            width: 72px;
            height: 72px;
            border-radius: 16px;
            background: var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2rem;
            font-size: 2rem;
            font-weight: 700;
            color: #fff;
            box-shadow: 0 4px 20px rgba(26, 115, 232, 0.35);
        }

        .sidebar h1 {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 0.75rem;
        }

        .sidebar p {
            color: var(--text-light);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .sidebar .feature-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .sidebar .feature-list li {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .sidebar .feature-list .icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: rgba(26, 115, 232, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .sidebar .tenant-link {
            margin-top: 3rem;
            color: var(--text-dim);
            font-size: 0.85rem;
        }

        .sidebar .tenant-link a {
            color: var(--brand);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .sidebar .tenant-link a:hover {
            color: #4da3ff;
        }

        .sidebar-footer {
            position: absolute;
            bottom: 1.5rem;
            left: 2.5rem;
            right: 2.5rem;
            color: var(--text-dim);
            font-size: 0.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 1rem;
        }

        /* ---- Right form area ---- */
        .main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .login-card {
            background: var(--card);
            border-radius: 16px;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.08);
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
        }

        .login-card h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 0.25rem;
        }

        .login-card .subtitle {
            color: var(--text-dim);
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }

        /* ---- Error banner ---- */
        .error-banner {
            background: #fdf2f2;
            border: 1px solid #f8d0d0;
            border-radius: 10px;
            padding: 0.85rem 1rem;
            color: var(--danger);
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .error-banner::before {
            content: '\26A0';
            font-size: 1.1rem;
        }

        /* ---- Form fields ---- */
        .field-group {
            margin-bottom: 1.25rem;
        }

        .field-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #444;
            margin-bottom: 0.4rem;
        }

        .field-group .input-wrap {
            position: relative;
        }

        .field-group .input-wrap .field-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1rem;
            color: var(--text-dim);
            pointer-events: none;
        }

        .field-group input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 2px solid #e2e5e9;
            border-radius: 10px;
            font-size: 0.95rem;
            color: #1a1a2e;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }

        .field-group input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.12);
        }

        .field-group input::placeholder {
            color: #c0c4cc;
        }

        .toggle-pw {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-dim);
            font-size: 0.85rem;
            user-select: none;
            background: none;
            border: none;
            padding: 0;
        }

        .toggle-pw:hover {
            color: var(--brand);
        }

        /* ---- Submit button ---- */
        .btn-submit {
            width: 100%;
            padding: 0.85rem;
            background: var(--brand);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            margin-top: 0.5rem;
        }

        .btn-submit:hover {
            background: var(--brand-dark);
        }

        .btn-submit:active {
            transform: scale(0.98);
        }

        /* ---- Demo hint ---- */
        .demo-hint {
            margin-top: 1.75rem;
            padding: 0.85rem 1rem;
            background: #f0f7ff;
            border: 1px dashed #b3d4ff;
            border-radius: 10px;
            font-size: 0.8rem;
            color: #555;
            text-align: center;
            line-height: 1.6;
        }

        .demo-hint strong {
            color: var(--brand);
        }

        .demo-hint .demo-creds {
            margin-top: 0.4rem;
            font-family: 'Courier New', monospace;
            font-size: 0.82rem;
            color: #333;
        }

        /* ---- Bottom link ---- */
        .bottom-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.85rem;
            color: var(--text-dim);
        }

        .bottom-link a {
            color: var(--brand);
            text-decoration: none;
            font-weight: 600;
        }

        .bottom-link a:hover {
            text-decoration: underline;
        }

        /* ---- Mobile sidebar toggle (hidden on desktop) ---- */
        .mobile-header {
            display: none;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .mobile-header .logo-sm {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .mobile-header h1 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1a1a2e;
        }

        .mobile-header p {
            font-size: 0.75rem;
            color: var(--text-dim);
        }

        /* ---- Responsive ---- */
        @media (max-width: 860px) {
            body { flex-direction: column; }

            .sidebar {
                display: none;
            }

            .mobile-header {
                display: flex;
            }

            .main {
                padding: 1.5rem 1rem;
            }

            .login-card {
                padding: 2rem 1.5rem;
                max-width: 100%;
            }
        }

        @media (max-width: 420px) {
            .login-card {
                padding: 1.5rem 1.25rem;
                border-radius: 12px;
            }

            .login-card h2 { font-size: 1.3rem; }
        }
    </style>
</head>
<body>

<!-- ============ LEFT SIDEBAR (branding) ============ -->
<aside class="sidebar">
    <div class="logo">PMS</div>
    <h1>Property Management System</h1>
    <p>Building &amp; Apartment property management for Ethiopian residential and commercial properties.</p>

    <ul class="feature-list">
        <li><span class="icon">&#127968;</span> Manage buildings, units &amp; tenants</li>
        <li><span class="icon">&#128176;</span> Track rent payments in ETB</li>
        <li><span class="icon">&#128203;</span> Maintenance requests &amp; reports</li>
        <li><span class="icon">&#128202;</span> Dashboards &amp; financial analytics</li>
    </ul>

    <div class="tenant-link">
        Are you a tenant?
        <a href="../tenant/index.php">Go to Tenant Portal &rarr;</a>
    </div>

    <div class="sidebar-footer">
        &copy; <?= date('Y') ?> Property Management System &middot; Admin Panel
    </div>
</aside>

<!-- ============ RIGHT FORM AREA ============ -->
<main class="main">
    <div class="login-card">
        <!-- Mobile-only header (replaces sidebar on small screens) -->
        <div class="mobile-header">
            <div class="logo-sm">PMS</div>
            <div>
                <h1>Property Management System</h1>
                <p>Admin &amp; Staff Login</p>
            </div>
        </div>

        <h2>Admin Login</h2>
        <p class="subtitle">Sign in to the management dashboard</p>

        <?php if ($error !== ''): ?>
            <div class="error-banner"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="field-group">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <span class="field-icon">&#9993;</span>
                    <input type="email"
                           id="email"
                           name="email"
                           placeholder="you@example.com"
                           value="<?= e($email_value) ?>"
                           required
                           autofocus>
                </div>
            </div>

            <div class="field-group">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <span class="field-icon">&#128274;</span>
                    <input type="password"
                           id="password"
                           name="password"
                           placeholder="Enter your password"
                           required>
                    <button type="button" class="toggle-pw" id="togglePw">Show</button>
                </div>
            </div>

            <button type="submit" class="btn-submit">Sign In</button>
        </form>

        <!-- Demo credentials hint -->
        <div class="demo-hint">
            <strong>Demo Credentials</strong> &mdash; for testing only
            <div class="demo-creds">
                admin@property.local / admin123
            </div>
        </div>

        <!-- Tenant portal link (mobile-friendly) -->
        <div class="bottom-link">
            Tenant? <a href="../tenant/index.php">Go to Tenant Portal &rarr;</a>
        </div>
    </div>
</main>

<script>
    // Toggle password visibility
    (function () {
        var btn   = document.getElementById('togglePw');
        var input = document.getElementById('password');
        btn.addEventListener('click', function () {
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = 'Hide';
            } else {
                input.type = 'password';
                btn.textContent = 'Show';
            }
            input.focus();
        });
    })();
</script>

</body>
</html>
