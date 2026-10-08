<?php
// =========================================================================
// SCRIPT SEEDER USER - DB MASTER
// PERINGATAN: Hapus atau ubah nama file ini setelah dijalankan di server produksi!
// =========================================================================

require_once 'config/database.php'; // Sesuaikan path jika file seeder diletakkan di folder lain

// Daftar User yang akan di-generate (Username, Password, Nama Lengkap, Role)
$data_users = [
    // [ 'username', 'password_raw', 'Nama Lengkap', 'Role' ]
    ['34202109', '34202109', 'Andika Nur P', 'Admin QCL AAC'],
    ['42032302', '42032302', 'Kurnia Abadi Mustofa', 'Admin QCL AAC'],
    ['05711005', '05711005', 'Achmad Saiful Mujib', 'Admin QCL AAC'],
    ['42552303', '42552303', 'Taufiqur Rohman', 'Admin QCL AAC'],
];

echo "<div style='font-family: monospace; background: #222; color: #0f0; padding: 20px; border-radius: 5px; line-height: 1.6;'>";
echo "<h3>[ INITIALIZING USER SEEDER ]</h3>";
echo "==============================================<br>";

$count_success = 0;
$count_skip = 0;

foreach ($data_users as $u) {
    $username = $u[0];
    $password_raw = $u[1];
    $nama_lengkap = $u[2];
    $role = $u[3];

    try {
        // 1. Cek apakah username sudah ada di database
        $stmt_cek = $pdo->prepare("SELECT id FROM db_master.tb_user WHERE username = ?");
        $stmt_cek->execute([$username]);
        
        if ($stmt_cek->fetch()) {
            echo "<span style='color: yellow;'>[SKIP]</span> Username <b>$username</b> sudah ada di database.<br>";
            $count_skip++;
        } else {
            // 2. Hash password dan Insert ke database
            $hashed_password = password_hash($password_raw, PASSWORD_DEFAULT);
            $stmt_insert = $pdo->prepare("INSERT INTO db_master.tb_user (username, password, nama_lengkap, role, created_by) VALUES (?, ?, ?, ?, 'System Seeder')");
            $stmt_insert->execute([$username, $hashed_password, $nama_lengkap, $role]);
            
            echo "<span style='color: #0f0;'>[SUCCESS]</span> User <b>$username</b> (Role: $role) berhasil dibuat.<br>";
            $count_success++;
        }
    } catch (PDOException $e) {
        echo "<span style='color: red;'>[ERROR]</span> Gagal memproses user <b>$username</b>.<br>";
    }
}

echo "==============================================<br>";
echo "<b>SEEDING SELESAI.</b><br>";
echo "Total Berhasil : $count_success<br>";
echo "Total Dilewati : $count_skip<br>";
echo "</div>";
?>
