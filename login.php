<?php
require_once 'auth.php';
require_once 'users.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// ─── Simple brute-force throttle ─────────────────────────────────
// After 5 failed attempts the form is locked for 5 minutes for this session.
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECS = 300;

$attempts  = $_SESSION['login_attempts']  ?? 0;
$lockUntil = $_SESSION['login_lock_until'] ?? 0;
$lockedFor = max(0, $lockUntil - time());

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($lockedFor > 0) {
        $error = 'Too many attempts. Try again in ' . ceil($lockedFor / 60) . ' minute(s).';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $hash = $DASHBOARD_USERS[$username] ?? null;

        // Always run a verify so a wrong username and a wrong password take
        // the same amount of time (no user enumeration via response timing).
        $dummy = '$2y$10$usesomesillystringforsalttoavoidtimingleaksxxxxxxxxxxxxxx';
        $ok = password_verify($password, $hash ?? $dummy) && $hash !== null;

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['authenticated']  = true;
            $_SESSION['username']       = $username;
            $_SESSION['last_activity']  = time();
            unset($_SESSION['login_attempts'], $_SESSION['login_lock_until']);
            header('Location: index.php');
            exit;
        }

        $attempts++;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            $_SESSION['login_lock_until'] = time() + LOGIN_LOCKOUT_SECS;
            $_SESSION['login_attempts']   = 0;
            $error = 'Too many attempts. Try again in '
                     . ceil(LOGIN_LOCKOUT_SECS / 60) . ' minute(s).';
        } else {
            $error = 'Invalid username or password.';
        }
        error_log('[AmeenBill] failed login for "' . $username . '" from '
                  . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        usleep(400000); // 0.4s — blunt the rate of automated guessing
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - Sales Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
<!-- Local icon font — Tabler Icons (MIT) -->
    <link rel="stylesheet" href="assets/icons/tabler/tabler-icons.min.css">
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Tajawal', 'Segoe UI', Arial, sans-serif;
        background: linear-gradient(135deg, #eef2ff 0%, #f4f5fa 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0;
        padding: 20px;
    }
    .login-card {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(16,24,40,0.10);
        padding: 32px 28px;
        width: 100%;
        max-width: 360px;
    }
    .login-card h1 {
        font-size: 1.3rem;
        margin: 0 0 4px;
        color: #1e1b2e;
        text-align: center;
    }
    .login-card p.sub {
        text-align: center;
        color: #6b7280;
        font-size: 0.85rem;
        margin: 0 0 22px;
    }
    label {
        display: block;
        font-size: 0.8rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 6px;
    }
    input[type="text"], input[type="password"] {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        font-size: 0.95rem;
        font-family: inherit;
        margin-bottom: 16px;
        background: #f8f8fd;
    }
    input:focus {
        outline: none;
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px #eef2ff;
    }
    button {
        width: 100%;
        padding: 11px;
        background: #4f46e5;
        color: white;
        border: none;
        border-radius: 9px;
        font-size: 0.95rem;
        font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        transition: background 0.15s;
    }
    button:hover { background: #4338ca; }
    .error-msg {
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
        padding: 9px 12px;
        border-radius: 8px;
        font-size: 0.82rem;
        margin-bottom: 16px;
        text-align: center;
    }
</style>
</head>
<body>
    <div class="login-card">
        <h1>📊 Sales Dashboard</h1>
        <p class="sub">Sign in to continue</p>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="post">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="submit">Sign In</button>
        </form>
    </div>
</body>
</html>
