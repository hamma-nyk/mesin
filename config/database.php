<?php
// Cegah akses langsung dari browser (kunci hanya boleh di-include oleh script internal)
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'database.php') {
    http_response_code(403);
    die("Forbidden");
}
// ... koneksi P1, P2, Auth tetap ada ...

// KONFIGURASI MS SQL SERVER VIA ODBC
//$ms_host   = '192.168.12.239'; // Ganti dengan IP Server
//$ms_dbname = 'OEE_AAC';
//$ms_user   = 'sa';
//$ms_pass   = 'qazqaz-123';

// KONFIGURASI MS SQL SERVER VIA ODBC
$ms_host2   = '192.168.31.42'; // Ganti dengan IP Server
$ms_dbname2 = 'Quality_Product_FC_NBI';
$ms_user2   = 'sa';
$ms_pass2   = '130300';

// KONFIGURASI MS SQL SERVER VIA ODBC
$ms_host3   = '192.168.31.42'; // Ganti dengan IP Server
$ms_dbname3 = 'Quality_Product_AAC1_NBI';
$ms_user3   = 'sa';
$ms_pass3   = '130300';

// KONFIGURASI MS SQL SERVER VIA ODBC
$ms_host4   = '192.168.31.42'; // Ganti dengan IP Server
$ms_dbname4 = 'Quality_Product_AAC2_NBI';
$ms_user4   = 'sa';
$ms_pass4   = '130300';

// Tambahkan di dalam config/database.php

$host   = '192.168.10.16'; 
$port   = '5432';
$dbname = 'ikoit_mesin';
$user   = 'postgres';
$pass   = 'postgres';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Koneksi Database Gagal."); // Jangan tampilkan error message asli
}

// try {
    // String Koneksi ODBC (DSN-less)
    // Gunakan 'ODBC Driver 17 for SQL Server' atau 'SQL Server' (driver lama)
//     $dsn_odbc = "odbc:Driver={ODBC Driver 17 for SQL Server};Server=$ms_host;Database=$ms_dbname;";
    
//     $pdo_ms = new PDO($dsn_odbc, $ms_user, $ms_pass);
    
//     $pdo_ms->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//     $pdo_ms->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// } catch(PDOException $e) {
//     die("Koneksi ODBC SQL Server gagal: " . $e->getMessage());
// }

try {
    // String Koneksi ODBC (DSN-less)
    // Gunakan 'ODBC Driver 17 for SQL Server' atau 'SQL Server' (driver lama)
    $dsn_odbc2 = "odbc:Driver={ODBC Driver 17 for SQL Server};Server=$ms_host2;Database=$ms_dbname2;";
    
    $pdo_ms2 = new PDO($dsn_odbc2, $ms_user2, $ms_pass2);
    
    $pdo_ms2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_ms2->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Koneksi ODBC SQL Server gagal."); // Jangan tampilkan error message asli
}

try {
    // String Koneksi ODBC (DSN-less)
    // Gunakan 'ODBC Driver 17 for SQL Server' atau 'SQL Server' (driver lama)
    $dsn_odbc3 = "odbc:Driver={ODBC Driver 17 for SQL Server};Server=$ms_host3;Database=$ms_dbname3;";
    
    $pdo_ms3 = new PDO($dsn_odbc3, $ms_user3, $ms_pass3);
    
    $pdo_ms3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_ms3->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Koneksi ODBC SQL Server gagal."); // Jangan tampilkan error message asli
}

try {
    // String Koneksi ODBC (DSN-less)
    // Gunakan 'ODBC Driver 17 for SQL Server' atau 'SQL Server' (driver lama)
    $dsn_odbc4 = "odbc:Driver={ODBC Driver 17 for SQL Server};Server=$ms_host4;Database=$ms_dbname4;";
    
    $pdo_ms4 = new PDO($dsn_odbc4, $ms_user4, $ms_pass4);
    
    $pdo_ms4->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_ms4->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Koneksi ODBC SQL Server gagal."); // Jangan tampilkan error message asli
}

// --- KONEKSI SQLITE (Data Login) ---
try {
    $db_path = __DIR__ . '/users_internal.db';
    $pdo_auth = new PDO("sqlite:" . $db_path);
    $pdo_auth->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Otomatis buat tabel jika belum ada
    $pdo_auth->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        nama TEXT
    )");

    // Cek jika belum ada user, buat admin default dgn password acak (bukan hardcoded 'kontol')
    $check = $pdo_auth->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($check == 0) {
        $defaultPass = bin2hex(random_bytes(8)); // 16 karakter acak, bukan password publik
        $hash = password_hash($defaultPass, PASSWORD_BCRYPT);
        $pdo_auth->prepare("INSERT INTO users (username, password, nama) VALUES ('IT', ?, 'IT NBI')")
                 ->execute([$hash]);
        error_log("[mesin] Admin IT default terbuat. Password awal: " . $defaultPass);
    }
} catch (PDOException $e) {
    die("Koneksi SQLite Gagal."); // Jangan tampilkan error message asli
}
?>
