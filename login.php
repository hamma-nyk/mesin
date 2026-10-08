<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_name('APP_MESIN');
    session_start();
}

require_once 'config/database.php';

if (isset($_SESSION['login']) && $_SESSION['login'] === true) {
    header("Location: index.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Logger keamanan (isolasi agar login.php tidak perlu include auth.php)
if (!function_exists('write_security_log')) {
    function write_security_log($level, $message, $extra = []) {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
        $line = date('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] ' . $message
              . ' | IP=' . ($_SERVER['REMOTE_ADDR'] ?? '')
              . ' | UA=' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120)
              . (!empty($extra) ? ' | ' . http_build_query($extra) : '') . PHP_EOL;
        @file_put_contents($logDir . '/security_' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}

$error = null;

// --- Brute-force protection: batasi percobaan login gagal ---
$maxAttempts = 5;
$lockTime = 300; // detik (5 menit)
if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= $maxAttempts) {
    $wait = $lockTime - (time() - ($_SESSION['login_first_fail'] ?? time()));
    if ($wait > 0) {
        $error = "Terlalu banyak percobaan. Coba lagi dalam " . ceil($wait / 60) . " menit.";
    } else {
        unset($_SESSION['login_attempts'], $_SESSION['login_first_fail']);
    }
}

if (isset($_POST['login']) && !$error) {
    // Proteksi Login CSRF
    $postedToken = $_POST['csrf_token'] ?? '';
    if (empty($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
        $error = "Token keamanan tidak valid. Silakan muat ulang halaman.";
    } else {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM db_master.tb_user WHERE username = ?");
    $stmt->execute([$user]);
    $data = $stmt->fetch();

    if ($data && password_verify($pass, $data['password'])) {
        session_regenerate_id(true);

        $_SESSION['login']        = true;
        $_SESSION['username']     = $data['username'];
        $_SESSION['nama_lengkap'] = $data['nama_lengkap'];
        $_SESSION['role']         = $data['role'];
        $_SESSION['user_agent']   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        unset($_SESSION['login_attempts'], $_SESSION['login_first_fail']);

        write_security_log('info', "Login berhasil: {$data['username']}");

        header("Location: index.php");
        exit;
    } else {
        // Catat percobaan gagal + log audit
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] === 1) {
            $_SESSION['login_first_fail'] = time();
        }
        write_security_log('security', "Login gagal untuk username: " . ($user !== '' ? $user : '(kosong)'));
        $error = "Username atau password tidak valid.";
    }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PT. NBI - Login Factory Support</title>
    <link rel="icon" href="assets/icon/favicon.ico" type="image/png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <style>
        body.login-page { background-color: #e9ecef !important; }
        .login-box .card { border-top: 3px solid #28a745; box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2); border-radius: 0.25rem; }
        .login-logo b { color: #495057; }
        .btn-success { background-color: #28a745; border-color: #28a745; }
        .btn-success:hover { background-color: #218838; border-color: #1e7e34; }
        .form-control:focus { border-color: #28a745; box-shadow: none; }
        .form-control:focus + .input-group-append .input-group-text { border-color: #28a745; color: #28a745; }
    </style>
</head>
<body class="hold-transition login-page">
<div class="login-box">
    <div class="card">
        <div class="card-body login-card-body">
            <p class="login-box-msg">PT. NBI - SISTEM FACTORY SUPPORT</p>

            <?php if($error): ?>
                <div class="alert alert-danger btn-sm">
                    <i class="fas fa-exclamation-triangle mr-2"></i> <?= $error ?>
                </div>
            <?php endif; ?>

            <form action="" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <div class="input-group mb-3">
                    <input type="text" name="username" class="form-control" placeholder="Username" required autocomplete="off">
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-user"></span></div>
                    </div>
                </div>
                <div class="input-group mb-3">
                    <input type="password" name="password" class="form-control" placeholder="Password" required>
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-lock"></span></div>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-12">
                        <button type="submit" name="login" class="btn btn-success btn-block">
                            <b>Masuk ke Sistem</b>
                        </button>
                    </div>
                </div>
            </form>

            <p class="mt-4 mb-0 text-center text-muted text-sm">
                &copy; 2026 PT. NBI <br>
                <span class="text-xs">Confidential & Internal Only</span>
            </p>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
