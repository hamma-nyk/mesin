<?php
// 1. Inisialisasi Session
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';

// Proteksi Halaman Khusus Admin IT
requireAccess(['Admin IT']);

$user_aktif = $_SESSION['username'] ?? 'Admin';
$role_aktif = $_SESSION['role'] ?? '';

// ==========================================
// KONFIGURASI KEAMANAN (HIDDEN DARI FRONTEND)
// ==========================================
$SECRET_PIN = "787898"; // Ganti dengan PIN rahasia Anda

// ==========================================
// BLOK PROSES CRUD DENGAN PRG PATTERN
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $target_role = $_POST['role'] ?? '';
        
        // PROTEKSI: Selain Admin IT dilarang membuat/mengubah akun menjadi Admin IT
        if ($role_aktif !== 'Admin IT' && $target_role === 'Admin IT') {
            $_SESSION['flash_error'] = "Pelanggaran Hak Akses! Hanya Admin IT yang berhak memberikan kewenangan Admin IT.";
        } 
        else {
            if ($action === 'add') {
                if ($_POST['pin_otorisasi'] !== $SECRET_PIN) {
                    $_SESSION['flash_error'] = "Akses Ditolak! PIN Otorisasi tidak valid.";
                } else {
                    $username = sanitize_input($_POST['username']);
                    $nama_lengkap = sanitize_input($_POST['nama_lengkap']);
                    $stmt_cek = $pdo->prepare("SELECT id FROM db_master.tb_user WHERE username = ?");
                    $stmt_cek->execute([$username]);
                    
                    if ($stmt_cek->fetch()) {
                        $_SESSION['flash_error'] = "Gagal! Username <b>$username</b> sudah terdaftar.";
                    } else {
                        $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO db_master.tb_user (username, password, nama_lengkap, role, created_by) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $hashed_password, $nama_lengkap, $target_role, $user_aktif]);
                        $_SESSION['flash_success'] = "User baru berhasil dibuat.";
                    }
                }
            } 
            elseif ($action === 'edit') {
                if ($_POST['pin_otorisasi'] !== $SECRET_PIN) {
                    $_SESSION['flash_error'] = "Akses Ditolak! PIN Otorisasi tidak valid untuk memperbarui data.";
                } else {
                    $id_edit = $_POST['id'];
                    $username = sanitize_input($_POST['username']);
                    $nama_lengkap = sanitize_input($_POST['nama_lengkap']);
                    
                    // PROTEKSI: Cek role asli dari data yang mau diedit
                    $stmt_orig = $pdo->prepare("SELECT role FROM db_master.tb_user WHERE id = ?");
                    $stmt_orig->execute([$id_edit]);
                    $orig_data = $stmt_orig->fetch();

                    if ($role_aktif !== 'Admin IT' && $orig_data['role'] === 'Admin IT') {
                        $_SESSION['flash_error'] = "Pelanggaran Hak Akses! Anda tidak berhak mengubah data Admin IT.";
                    } else {
                        $stmt_cek = $pdo->prepare("SELECT id FROM db_master.tb_user WHERE username = ? AND id <> ?");
                        $stmt_cek->execute([$username, $id_edit]);
                        
                        if ($stmt_cek->fetch()) {
                            $_SESSION['flash_error'] = "Gagal! Username <b>$username</b> sudah dipakai.";
                        } else {
                            if (!empty($_POST['password'])) {
                                $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("UPDATE db_master.tb_user SET username=?, password=?, nama_lengkap=?, role=?, updated_by=? WHERE id=?");
                                $stmt->execute([$username, $hashed_password, $nama_lengkap, $target_role, $user_aktif, $id_edit]);
                            } else {
                                $stmt = $pdo->prepare("UPDATE db_master.tb_user SET username=?, nama_lengkap=?, role=?, updated_by=? WHERE id=?");
                                $stmt->execute([$username, $nama_lengkap, $target_role, $user_aktif, $id_edit]);
                            }
                            $_SESSION['flash_success'] = "Data user berhasil diperbarui.";
                        }
                    }
                }
            } 
            elseif ($action === 'delete') {
                $id_hapus = $_POST['id'];

                // PROTEKSI: Cek role asli dari data yang mau dihapus
                $stmt_orig = $pdo->prepare("SELECT role FROM db_master.tb_user WHERE id = ?");
                $stmt_orig->execute([$id_hapus]);
                $orig_data = $stmt_orig->fetch();

                if ($role_aktif !== 'Admin IT' && $orig_data['role'] === 'Admin IT') {
                    $_SESSION['flash_error'] = "Pelanggaran Hak Akses! Anda tidak berhak menghapus Admin IT.";
                } elseif ($_POST['pin_hapus'] !== $SECRET_PIN) {
                    $_SESSION['flash_error'] = "Akses Ditolak! PIN salah, user gagal dihapus.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM db_master.tb_user WHERE id=?");
                    $stmt->execute([$id_hapus]);
                    $_SESSION['flash_success'] = "User berhasil dihapus dari sistem.";
                }
            }
        }
    }
    
    // REDIRECT PRG (Post/Redirect/Get) - Mencegah form resubmission saat tekan F5
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Tangkap pesan flash dari session setelah redirect
$error_msg = $_SESSION['flash_error'] ?? "";
$success_msg = $_SESSION['flash_success'] ?? "";
unset($_SESSION['flash_error'], $_SESSION['flash_success']); // Hapus agar tidak muncul dua kali

// ==========================================
// FILTER & SEARCH LOGIC
// ==========================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sql = "SELECT * FROM db_master.tb_user";
$params = [];

if (!empty($search)) {
    $sql .= " WHERE username ILIKE :search OR nama_lengkap ILIKE :search OR role ILIKE :search";
    $params[':search'] = "%{$search}%";
}

$sql .= " ORDER BY role ASC, nama_lengkap ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

require_once 'includes/header.php';
?>

<div class="content-wrapper" style="font-family: 'Plus Jakarta Sans', sans-serif;">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark font-weight-bold">Manajemen Pengguna</h1>
                </div>
                <div class="col-sm-6 text-right">
                    <?php if($role_aktif === 'Admin IT'): ?>
                    <button class="btn btn-primary" data-toggle="modal" data-target="#modalAddUser">
                        <i class="fas fa-user-plus"></i> Tambah User Baru
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content">
        <div class="container-fluid">
            <?php if ($error_msg): ?>
                <div class="alert alert-danger alert-dismissible shadow-sm">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <i class="fas fa-shield-alt"></i> <?= $error_msg; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success_msg): ?>
                <div class="alert alert-success alert-dismissible shadow-sm">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <i class="fas fa-check-circle"></i> <?= $success_msg; ?>
                </div>
            <?php endif; ?>

            <div class="card card-default mb-3 shadow-none border">
                <div class="card-body pb-2">
                    <form method="GET" action="">
                        <div class="row align-items-end">
                            <div class="col-md-6 col-sm-8 mb-3">
                                <label for="search">Cari Pengguna</label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                                    <input type="text" class="form-control" name="search" id="search" value="<?= htmlspecialchars($search); ?>" placeholder="Ketik Username, Nama, atau Role...">
                                </div>
                            </div>
                            <div class="col-md-4 col-sm-4 mb-3">
                                <button type="submit" class="btn btn-primary mr-1">Cari</button>
                                <a href="master_user.php" class="btn btn-default">Reset</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card card-outline card-primary shadow-none border">
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover text-nowrap m-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="text-center" width="5%">No</th>
                                <th>Username</th>
                                <th>Nama Lengkap</th>
                                <th>Akses / Role</th>
                                <th>Ditambahkan Pada</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($users) > 0): ?>
                                <?php $no = 1; foreach($users as $row): 
                                    $can_manage = true;
                                    if ($role_aktif !== 'Admin IT' && $row['role'] === 'Admin IT') {
                                        $can_manage = false;
                                    }
                                ?>
                                <tr>
                                    <td class="text-center"><?= $no++; ?></td>
                                    <td><strong><?= htmlspecialchars($row['username']); ?></strong></td>
                                    <td><?= htmlspecialchars($row['nama_lengkap']); ?></td>
                                    <td>
                                        <?php if($row['role'] == 'Admin IT'): ?>
                                            <span class="badge badge-danger"><?= htmlspecialchars($row['role']); ?></span>
                                        <?php elseif(strpos($row['role'], 'Kabag') !== false): ?>
                                            <span class="badge badge-dark"><?= htmlspecialchars($row['role']); ?></span>
                                        <?php elseif(strpos($row['role'], 'Supervisor') !== false): ?>
                                            <span class="badge badge-warning"><?= htmlspecialchars($row['role']); ?></span>
                                        <?php elseif(strpos($row['role'], 'Admin QCL') !== false): ?>
                                            <span class="badge badge-info"><?= htmlspecialchars($row['role']); ?></span>
                                        <?php elseif(strpos($row['role'], 'Admin Produksi') !== false): ?>
                                            <span class="badge badge-success"><?= htmlspecialchars($row['role']); ?></span>
                                        <?php elseif($row['role'] == 'Admin QMS'): ?>
                                            <span class="badge badge-primary"><?= htmlspecialchars($row['role']); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary"><?= htmlspecialchars($row['role']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?= date('d M Y, H:i', strtotime($row['created_at'])); ?></small></td>
                                    <td class="text-center">
                                        <?php if($can_manage && $role_aktif === 'Admin IT'): ?>
                                            <button class="btn btn-sm btn-warning" data-toggle="modal" data-target="#modalEditUser<?= $row['id']; ?>" title="Edit Akses">
                                                <i class="fas fa-user-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#modalDelete<?= $row['id']; ?>" title="Hapus User">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-secondary disabled" title="Akses Dibatasi" style="cursor: not-allowed;">
                                                <i class="fas fa-lock"></i> Restricted
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <?php if($can_manage && $role_aktif === 'Admin IT'): ?>
                                <div class="modal fade" id="modalDelete<?= $row['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-sm">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <div class="modal-header bg-danger">
                                                    <h5 class="modal-title text-white">Konfirmasi Hapus</h5>
                                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                </div>
                                                <div class="modal-body text-center">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $row['id']; ?>">
                                                    <p>Hapus akun <strong><?= htmlspecialchars($row['username']); ?></strong>?</p>
                                                    <div class="form-group text-left">
                                                        <label>PIN Otorisasi Penghapusan</label>
                                                        <input type="password" class="form-control text-center" name="pin_hapus" required>
                                                    </div>
                                                </div>
                                                <div class="modal-footer justify-content-center">
                                                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Eksekusi Hapus</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal fade" id="modalEditUser<?= $row['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <div class="modal-header bg-warning">
                                                    <h5 class="modal-title font-weight-bold">Edit Data User</h5>
                                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="edit">
                                                    <input type="hidden" name="id" value="<?= $row['id']; ?>">
                                                    
                                                    <div class="form-group">
                                                        <label>Username (Login)</label>
                                                        <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($row['username']); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Nama Lengkap</label>
                                                        <input type="text" class="form-control" name="nama_lengkap" value="<?= htmlspecialchars($row['nama_lengkap']); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Ubah Password (Opsional)</label>
                                                        <input type="password" class="form-control" name="password" placeholder="Kosongkan jika tidak diubah">
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Role Akses</label>
                                                        <select class="form-control" name="role" required>
														<option value="Admin QMS" <?= $row['role'] == 'Admin QMS' ? 'selected' : ''; ?>>Admin QMS</option>
                                                            <option value="Admin QCL FC" <?= $row['role'] == 'Admin QCL FC' ? 'selected' : ''; ?>>Admin QCL FC</option>
                                                            <option value="Admin QCL AAC1" <?= $row['role'] == 'Admin QCL AAC1' ? 'selected' : ''; ?>>Admin QCL AAC1</option>
                                                            <option value="Admin QCL AAC2" <?= $row['role'] == 'Admin QCL AAC2' ? 'selected' : ''; ?>>Admin QCL AAC2</option>
                                                            <option value="Admin Produksi FC" <?= $row['role'] == 'Admin Produksi FC' ? 'selected' : ''; ?>>Admin Produksi FC</option>
                                                            <option value="Admin Produksi AAC1" <?= $row['role'] == 'Admin Produksi AAC1' ? 'selected' : ''; ?>>Admin Produksi AAC1</option>
                                                            <option value="Admin Produksi AAC2" <?= $row['role'] == 'Admin Produksi AAC2' ? 'selected' : ''; ?>>Admin Produksi AAC2</option>
                                                            <option value="Admin Produksi Mortar" <?= $row['role'] == 'Admin Produksi Mortar' ? 'selected' : ''; ?>>Admin Produksi Mortar</option>
                                                            <option value="Supervisor QCL FC" <?= $row['role'] == 'Supervisor QCL FC' ? 'selected' : ''; ?>>Supervisor QCL FC</option>
                                                            <option value="Supervisor QCL AAC" <?= $row['role'] == 'Supervisor QCL AAC' ? 'selected' : ''; ?>>Supervisor QCL AAC</option>
                                                            <option value="Kabag Produksi" <?= $row['role'] == 'Kabag Produksi' ? 'selected' : ''; ?>>Kabag Produksi</option>
                                                            <option value="Kabag QCL" <?= $row['role'] == 'Kabag QCL' ? 'selected' : ''; ?>>Kabag QCL</option>
                                                            <?php if($role_aktif === 'Admin IT'): ?>
                                                                <option value="Admin IT" <?= $row['role'] == 'Admin IT' ? 'selected' : ''; ?>>Admin IT</option>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>
                                                    <hr>
                                                    <div class="form-group">
                                                        <label class="text-danger"><i class="fas fa-lock"></i> PIN Otorisasi Perubahan</label>
                                                        <input type="password" class="form-control border-danger" name="pin_otorisasi" placeholder="Masukkan PIN rahasia untuk menyimpan..." required>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="submit" class="btn btn-warning font-weight-bold">Simpan Perubahan</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="fas fa-users-slash fa-2x mb-2 text-light"></i><br>
                                        <?= !empty($search) ? "User tidak ditemukan." : "Belum ada data user."; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAddUser" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                  <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Buat Akses Pengguna Baru</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label>Username (Untuk Login) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_lengkap" required>
                    </div>
                    <div class="form-group">
                        <label>Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div class="form-group">
                        <label>Role / Akses <span class="text-danger">*</span></label>
                        <select class="form-control" name="role" required>
							<option value="Admin QMS">Admin QMS</option>
                            <option value="Admin QCL FC">Admin QCL FC</option>
                            <option value="Admin QCL AAC1">Admin QCL AAC1</option>
                            <option value="Admin QCL AAC2">Admin QCL AAC2</option>
                            <option value="Admin Produksi FC">Admin Produksi FC</option>
                            <option value="Admin Produksi AAC1">Admin Produksi AAC1</option>
                            <option value="Admin Produksi AAC2">Admin Produksi AAC2</option>
                            <option value="Admin Produksi Mortar">Admin Produksi Mortar</option>
                            <option value="Supervisor QCL FC">Supervisor QCL FC</option>
                            <option value="Supervisor QCL AAC">Supervisor QCL AAC</option>
							<option value="Kabag Produksi">Kabag Produksi</option>
                            <option value="Kabag QCL">Kabag QCL</option>
                            <?php if($role_aktif === 'Admin IT'): ?>
                                <option value="Admin IT">Admin IT</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <hr>
                    <div class="form-group">
                        <label class="text-danger"><i class="fas fa-lock"></i> PIN Otorisasi (Wajib)</label>
                        <input type="password" class="form-control border-danger" name="pin_otorisasi" placeholder="Masukkan PIN khusus..." required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Buat User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Mencegah form resubmission dan spam klik
    $('form[method="POST"]').on('submit', function() {
        let btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> Proses...');
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
