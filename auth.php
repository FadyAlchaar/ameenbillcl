<?php
// auth.php - session-based login guard for the dashboard.
// Separate from the SQL Server database: credentials live only in users.php
// on this machine. This is the access gate for the dashboard itself, not a
// replacement for real network/DB security.

define('SESSION_TIMEOUT_SECONDS', 8 * 60 * 60); // auto-logout after 8 hours idle

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Harden the session cookie before the session starts.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['SERVER_PORT'] ?? null) == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,      // not readable from JavaScript
        'secure'   => $https,    // only sent over HTTPS when available
        'samesite' => 'Lax',     // blocks cross-site submission of the cookie
    ]);
    session_name('AMEENBILLSESS');
    session_start();
}

function isLoggedIn() {
    if (empty($_SESSION['authenticated'])) {
        return false;
    }
    if (isset($_SESSION['last_activity'])
        && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Call this at the very top of any protected page or endpoint.
 *
 * @param bool $isAjax true for JSON/SSE endpoints (401 instead of a redirect),
 *                     false for full page loads (redirect to login.php).
 */
function requireLogin($isAjax = false) {
    if (isLoggedIn()) {
        // A long-running request (SSE) must not hold the session file lock,
        // or every other request from the same browser blocks behind it.
        if ($isAjax) {
            session_write_close();
        }
        return;
    }
    if ($isAjax) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(401);
        }
        echo json_encode(['error' => 'unauthenticated']);
    } else {
        header('Location: login.php');
    }
    exit;
}
