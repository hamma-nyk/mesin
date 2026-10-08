<?php
// ==========================================
// FILE: proxy_wa.php
// FUNGSI: Jembatan Bypass CORS & Mixed Content
// ==========================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php'; // Pastikan hanya user login yang bisa akses
require_once 'config/database.php';

// 1. Ambil IP Lokal & Port Node.js dari database
try {
    $server = $pdo->query("SELECT ip_address, port FROM db_master.tb_alamat_server_wa WHERE is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $ip_local = $server ? trim($server['ip_address']) : 'localhost';
    $port = $server ? trim($server['port']) : '3003';
} catch (Exception $e) {
    $ip_local = 'localhost';
    $port = '3003';
}

// 2. Tangkap rute API yang mau dituju (misal: /status, /qr, /groups)
$endpoint = isset($_GET['path']) ? $_GET['path'] : '/status';
// Validasi endpoint untuk mencegah SSRF & Path Traversal
$allowed_endpoints = ['/status', '/qr', '/groups', '/logout'];
if (!in_array($endpoint, $allowed_endpoints)) {
    die(json_encode(['status' => false, 'message' => 'Invalid endpoint']));
}
// Cegah SSRF via IP Manipulasi Database
if (!filter_var($ip_local, FILTER_VALIDATE_IP) && $ip_local !== 'localhost') {
    die(json_encode(['status' => false, 'message' => 'Invalid IP address']));
}
if (!is_numeric($port) || $port < 1 || $port > 65535) {
    die(json_encode(['status' => false, 'message' => 'Invalid port']));
}

$target_url = "http://{$ip_local}:{$port}/api{$endpoint}";

// 3. Eksekusi cURL (PHP bertindak sebagai browser internal)
$ch = curl_init($target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 8); // Tunggu maks 8 detik

// Jika request-nya POST (seperti saat Logout)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    curl_setopt($ch, CURLOPT_POST, true);
    $inputJSON = file_get_contents('php://input'); 
    if (!empty($inputJSON)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $inputJSON);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
}

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// 4. Kirim balasan ke Frontend Browser
header('Content-Type: application/json');

if ($error) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'status' => 'offline',
        'message' => 'Proxy Gagal Terhubung ke Engine WA Internal. Pastikan Node.js menyala di IP: ' . sanitize_input($ip_local),
        'error_detail' => sanitize_input($error)
    ]);
} else {
    http_response_code($httpcode ?: 200);
    echo $response;
}
?>
