<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
    header("Location: login.php");
    exit;
}

if (!isset($_SESSION['user_agent'])) {
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
} else {
    if (($_SESSION['user_agent'] ?? '') !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit;
    }
}

function verifyCsrfToken($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        die("CSRF Token Validation Failed.");
    }
}

function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function e($data) {
    if (is_array($data)) {
        return array_map('e', $data);
    }
    return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
}

/**
 * Keamanan: catat event ke log audit (file log di folder logs/).
 * Param: $level = 'info' | 'warning' | 'security'
 */
function write_security_log($level, $message, $extra = []) {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $line = date('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] '
          . $message
          . ' | IP=' . ($_SERVER['REMOTE_ADDR'] ?? '')
          . ' | UA=' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120)
          . (!empty($extra) ? ' | ' . http_build_query($extra) : '')
          . PHP_EOL;
    @file_put_contents($logDir . '/security_' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function requireAccess($allowed_roles) {
    $current_role = $_SESSION['role'] ?? 'Guest';
    if (!in_array($current_role, $allowed_roles)) {
        if (basename($_SERVER['PHP_SELF']) !== 'akses_ditolak.php') {
            $_SESSION['global_error'] = "Akses Ditolak! Role '$current_role' tidak berhak.";
            header("Location: akses_ditolak.php");
            exit;
        }
    }
}

function hasAccess($allowed_roles) {
    $current_role = $_SESSION['role'] ?? 'Guest';
    return in_array($current_role, $allowed_roles);
}
?>
