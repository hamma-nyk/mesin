<?php
// 1. Inisialisasi Session di baris paling pertama script
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

date_default_timezone_set('Asia/Jakarta');

require_once 'includes/auth.php';
require_once 'config/database.php'; // Menggunakan koneksi PostgreSQL sentral via objek $pdo

// =========================================================================
// INISIALISASI ROLE BASE ACCESS (Hanya Admin IT yang bisa CRUD)
// =========================================================================
$role_aktif = $_SESSION['role'] ?? 'Guest';
$has_full_access = in_array($role_aktif, ['Admin IT', 'Supervisor QCL FC', 'Supervisor QCL AAC', 'Kabag QCL']);

$status_message = "";

// =========================================================================
// LOGIKA POST (PRG PATTERN & BACKEND PROTECTION)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Log semua POST untuk diagnosa
    write_security_log('info', "POST qc_wa_config: aksi=" . ($_POST['action'] ?? $_POST['btn_toggle_status'] ?? $_POST['btn_delete_number'] ?? $_POST['action_add_number'] ?? $_POST['action_update_server'] ?? $_POST['action_edit_number'] ?? 'unknown'), ['post_keys' => implode(',', array_keys($_POST))]);

    // Verifikasi CSRF dengan pesan jelas (jangan die() diam-diam)
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        write_security_log('security', "CSRF gagal di qc_wa_config");
        $_SESSION['wa_flash_status'] = "error: Token keamanan tidak valid. Silakan muat ulang halaman.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Tolak manipulasi POST dari Inspect Element jika role tidak berhak
    if (!$has_full_access) {
        write_security_log('security', "Akses ditolak POST qc_wa_config oleh role=$role_aktif");
        $_SESSION['wa_flash_status'] = "error: Akses Ditolak! Anda tidak memiliki wewenang memodifikasi konfigurasi WA Gateway.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // ACTION A: PERBARUI KONFIGURASI ALAMAT JARINGAN SERVER WA
    if (isset($_POST['action_update_server'])) {
        try {
            $ip_address  = sanitize_input($_POST['ip_address']);
            $port        = sanitize_input($_POST['port']);
            $nama_server = sanitize_input($_POST['nama_server']);

            $sqlUpServer = "UPDATE db_master.tb_alamat_server_wa SET 
                            nama_server = ?, 
                            ip_address = ?, 
                            port = ?, 
                            updated_at = CURRENT_TIMESTAMP 
                            WHERE is_default = 1";
            $stmt = $pdo->prepare($sqlUpServer);
            $stmt->execute([$nama_server, $ip_address, $port]);
            $_SESSION['wa_flash_status'] = "success_server";
        } catch (PDOException $e) {
            $_SESSION['wa_flash_status'] = "error: Gagal memproses data.";
        }
    }

    // ACTION B: MENDAFTARKAN KONTAK NOMOR BARU (MULTIMODE)
    if (isset($_POST['action_add_number'])) {
        try {
            $mode_input = $_POST['mode_input'];
            $kategori_notif = $_POST['kategori_notif'] ?? 'ALL'; // Tangkap data kategori lini
            $nama = ""; $jabatan = ""; $nomor_wa = "";

            if ($mode_input === 'manual') {
                $nama      = sanitize_input($_POST['nama']);
                $jabatan   = sanitize_input($_POST['jabatan']);
                $nomor_raw = sanitize_input($_POST['nomor_wa']);
            } 
            elseif ($mode_input === 'karyawan') {
                $id_karyawan = (int)$_POST['select_karyawan'];
                $stmtKaryawan = $pdo->prepare("SELECT nama, jabatan, no_hp FROM db_master.tb_karyawan WHERE id = :id");
                $stmtKaryawan->execute([':id' => $id_karyawan]);
                $karyawan = $stmtKaryawan->fetch(PDO::FETCH_ASSOC);
                
                if ($karyawan) {
                    $nama      = $karyawan['nama'];
                    $jabatan   = $karyawan['jabatan'] ? : 'Staff Internal';
                    $nomor_raw = $karyawan['no_hp'];
                }
            } 
            elseif ($mode_input === 'grup_wa') {
                $grup_raw  = $_POST['select_grup_wa']; 
                if (!empty($grup_raw)) {
                    $exp       = explode('|', $grup_raw);
                    $nomor_raw = sanitize_input($exp[0]); 
                    $nama      = sanitize_input($exp[1]); 
                    $jabatan   = "Grup WhatsApp";
                }
            }

            if (!str_contains($nomor_raw, '@g.us')) {
                $nomor_raw = preg_replace('/[^0-9]/', '', $nomor_raw);
                if (strpos($nomor_raw, '0') === 0) {
                    $nomor_wa = '62' . substr($nomor_raw, 1);
                } else {
                    $nomor_wa = $nomor_raw;
                }
            } else {
                $nomor_wa = $nomor_raw; 
            }

            if (!empty($nomor_wa)) {
                $sqlAdd = "INSERT INTO db_master.tb_nomor_penerima (nama, nomor_wa, jabatan, status_aktif, kategori_notif) 
                           VALUES (?, ?, ?, 1, ?)";
                $stmt = $pdo->prepare($sqlAdd);
                $stmt->execute([$nama, $nomor_wa, $jabatan, $kategori_notif]);
                $_SESSION['wa_flash_status'] = "success_add";
            } else {
                throw new Exception("Data input tidak valid. Nomor WA atau JID kosong.");
            }

        } catch (Exception $e) {
            $_SESSION['wa_flash_status'] = "error: Gagal memproses data.";
        }
    }

    // ACTION C: UBAH STATUS AKTIF / NONAKTIF
    if (isset($_POST['btn_toggle_status'])) {
        try {
            $id = (int)($_POST['id_nomor'] ?? 0);
            $status_sekarang = (int)($_POST['status_sekarang'] ?? 0);
            $status_baru = ($status_sekarang === 1) ? 0 : 1;

            if ($id <= 0) throw new Exception("ID tidak valid");

            $stmt = $pdo->prepare("UPDATE db_master.tb_nomor_penerima SET status_aktif = ? WHERE id = ?");
            $stmt->execute([$status_baru, $id]);
            if ($stmt->rowCount() === 0) {
                throw new Exception("Tidak ada baris yang diupdate, id=$id mungkin tidak ada");
            }
            write_security_log('info', "Toggle status penerima id=$id -> $status_baru");
            $_SESSION['wa_flash_status'] = "success_toggle";
        } catch (Exception $e) {
            write_security_log('security', "Gagal toggle id=" . ($_POST['id_nomor'] ?? '?') . ": " . $e->getMessage());
            $_SESSION['wa_flash_status'] = "error: Gagal memproses toggle (" . $e->getMessage() . ")";
        }
    }

    // ACTION D: HAPUS KONTAK PERMANEN
    if (isset($_POST['btn_delete_number'])) {
        try {
            $id = (int)($_POST['id_nomor'] ?? 0);
            if ($id <= 0) throw new Exception("ID tidak valid");
            $stmt = $pdo->prepare("DELETE FROM db_master.tb_nomor_penerima WHERE id = ?");
            $stmt->execute([$id]);
            write_security_log('info', "Hapus penerima id=$id");
            $_SESSION['wa_flash_status'] = "success_delete";
        } catch (Exception $e) {
            write_security_log('security', "Gagal hapus id=" . ($_POST['id_nomor'] ?? '?') . ": " . $e->getMessage());
            $_SESSION['wa_flash_status'] = "error: Gagal memproses hapus (" . $e->getMessage() . ")";
        }
    }

    // ACTION E: EDIT KONTAK & KATEGORI
    if (isset($_POST['action_edit_number'])) {
        try {
            $id_edit = (int)($_POST['id_nomor'] ?? 0);
            if ($id_edit <= 0) throw new Exception("ID tidak valid");
            $nama_edit = sanitize_input($_POST['nama']);
            $jabatan_edit = sanitize_input($_POST['jabatan']);
            $kategori_edit = sanitize_input($_POST['kategori_notif']);

            $stmtEdit = $pdo->prepare("UPDATE db_master.tb_nomor_penerima SET nama = ?, jabatan = ?, kategori_notif = ? WHERE id = ?");
            $stmtEdit->execute([$nama_edit, $jabatan_edit, $kategori_edit, $id_edit]);
            write_security_log('info', "Edit penerima id=$id_edit");
            $_SESSION['wa_flash_status'] = "success_edit";
        } catch (Exception $e) {
            write_security_log('security', "Gagal edit id=" . ($_POST['id_nomor'] ?? '?') . ": " . $e->getMessage());
            $_SESSION['wa_flash_status'] = "error: Gagal memproses edit (" . $e->getMessage() . ")";
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_SESSION['wa_flash_status'])) {
    $status_message = $_SESSION['wa_flash_status'];
    unset($_SESSION['wa_flash_status']); 
}

// =========================================================================
// QUERY VIEW UTAMA
// =========================================================================
$server = $pdo->query("SELECT * FROM db_master.tb_alamat_server_wa WHERE is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$daftar_nomor = $pdo->query("SELECT * FROM db_master.tb_nomor_penerima ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$api_base_url = "http://localhost:3003/api";
if ($server) {
    $api_base_url = "http://" . trim($server['ip_address']) . ":" . trim($server['port']) . "/api";
}

try {
    $list_karyawan = $pdo->query("SELECT id, nama, jabatan FROM db_master.tb_karyawan ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $list_karyawan = [];
}

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <h1><i class="fas fa-cogs text-secondary mr-2"></i>Konfigurasi Pusat WhatsApp Gateway</h1>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">

            <?php if(!$has_full_access): ?>
            <div class="alert alert-warning shadow-sm">
                <h5><i class="icon fas fa-exclamation-triangle"></i> Akses Dibatasi!</h5>
                Role Anda saat ini (<b><?= htmlspecialchars($role_aktif) ?></b>) hanya memiliki izin membaca (Read-Only). Otorisasi memodifikasi data WA Gateway hanya diberikan kepada <b>Admin IT</b>.
            </div>
            <?php endif; ?>

            <div class="card card-primary card-outline card-tabs shadow-sm">
                <div class="card-header p-0 pt-1 border-bottom-0 bg-light">
                    <ul class="nav nav-tabs" id="waConfigTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active font-weight-bold" id="tab-nomor-link" data-toggle="pill" href="#tab-nomor" role="tab"><i class="fas fa-users-cog mr-1"></i> CRUD Nomor Penerima Alert</a>
                        </li>
                        <?php if(in_array($role_aktif, ['Admin IT'])): ?>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" id="tab-server-link" data-toggle="pill" href="#tab-server" role="tab"><i class="fas fa-server mr-1"></i> Alamat & Port Server API</a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <div class="card-body">
                    <div class="tab-content" id="waConfigTabContent">
                        
                        <div class="tab-pane fade show active" id="tab-nomor" role="tabpanel">
                            <div class="row">
                                <div class="col-md-4 border-right pr-3">
                                    <h5 class="text-primary font-weight-bold mb-3"><i class="fas fa-user-plus"></i> Tambah Target Penerima</h5>
                                    
                                    <div class="form-group bg-light p-2 rounded border mb-3">
                                        <label class="d-block mb-1 text-xs text-uppercase text-muted">Pilih Metode Input Target:</label>
                                        <div class="form-check form-check-inline mr-3">
                                            <input class="form-check-input rad-mode" type="radio" name="mode_selector" id="modeManual" value="manual" checked <?= !$has_full_access ? 'disabled':'' ?>>
                                            <label class="form-check-label font-weight-bold text-sm" for="modeManual">Manual</label>
                                        </div>
                                        <div class="form-check form-check-inline mr-3">
                                            <input class="form-check-input rad-mode" type="radio" name="mode_selector" id="modeKaryawan" value="karyawan" <?= !$has_full_access ? 'disabled':'' ?>>
                                            <label class="form-check-label font-weight-bold text-sm" for="modeKaryawan">List Karyawan</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input rad-mode" type="radio" name="mode_selector" id="modeGrup" value="grup_wa" <?= !$has_full_access ? 'disabled':'' ?>>
                                            <label class="form-check-label font-weight-bold text-sm" for="modeGrup">Grup WA</label>
                                        </div>
                                    </div>

                                <form method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="mode_input" id="hidden_mode_input" value="manual">

                                        <div id="section-manual">
                                            <div class="form-group">
                                                <label class="text-sm">Nama Penerima</label>
                                                <input type="text" name="nama" class="form-control form-control-sm input-field-manual" placeholder="Contoh: Pak Reza - QC" <?= !$has_full_access ? 'disabled':'' ?>>
                                            </div>
                                            <div class="form-group">
                                                <label class="text-sm">Jabatan / Otoritas</label>
                                                <input type="text" name="jabatan" class="form-control form-control-sm input-field-manual" placeholder="Contoh: Shift 1 Leader" <?= !$has_full_access ? 'disabled':'' ?>>
                                            </div>
                                            <div class="form-group">
                                                <label class="text-sm">Nomor WhatsApp</label>
                                                <input type="text" name="nomor_wa" class="form-control form-control-sm input-field-manual" placeholder="Contoh: 0812xxxxxxxx" <?= !$has_full_access ? 'disabled':'' ?>>
                                            </div>
                                        </div>

                                        <div id="section-karyawan" style="display: none;">
                                            <div class="form-group">
                                                <label class="text-sm">Pilih Karyawan Aktif</label>
                                                <select name="select_karyawan" class="form-control form-control-sm select2 w-100 input-field-karyawan" <?= !$has_full_access ? 'disabled':'' ?>>
                                                    <option value="">-- Cari Nama Karyawan --</option>
                                                    <?php foreach($list_karyawan as $kar): ?>
                                                        <option value="<?= $kar['id'] ?>"><?= htmlspecialchars($kar['nama']) ?> (<?= htmlspecialchars($kar['jabatan']) ?>)</option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div id="section-grup" style="display: none;">
                                            <div class="form-group">
                                                <label class="text-sm">Pilih Grup Koordinasi Aktif</label>
                                                <select name="select_grup_wa" id="selectGrupWa" class="form-control form-control-sm select2 w-100 input-field-grup" <?= !$has_full_access ? 'disabled':'' ?>>
                                                    <option value="">-- Membaca Sesi Jaringan WhatsApp... --</option>
                                                </select>
                                                <div id="grupStatusAlert" class="mt-2"></div>
                                            </div>
                                        </div>

                                        <div class="form-group mt-3 border-top pt-3">
                                            <label class="text-sm text-danger"><i class="fas fa-filter"></i> Spesifikasi Distribusi Lini</label>
                                            <select name="kategori_notif" class="form-control form-control-sm font-weight-bold" required <?= !$has_full_access ? 'disabled':'' ?>>
                                                <option value="ALL">Semua Notif</option>
                                                <option value="QCL FC">Alert QCL FC</option>
                                                <option value="QCL AAC1">Alert QCL AAC1</option>
                                                <option value="QCL AAC2">Alert QCL AAC2</option>
                                                <option value="QCL AAC">Alert QCL AAC</option>
                                            </select>
                                            <small class="text-muted text-xs">Pilih agar nomor ini tidak di-spam notif dari lini lain.</small>
                                        </div>

                                        <?php if($has_full_access): ?>
                                            <button type="submit" name="action_add_number" class="btn btn-primary btn-sm btn-block font-weight-bold mt-4 shadow-sm">
                                                <i class="fas fa-save mr-1"></i> Simpan & Daftarkan Target
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-secondary btn-sm btn-block font-weight-bold mt-4 shadow-sm disabled" style="cursor:not-allowed;">
                                                <i class="fas fa-lock mr-1"></i> Form Terkunci
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                                
                                <div class="col-md-8 pl-3">
                                    <h5 class="text-bold mb-3"><i class="fas fa-list text-secondary"></i> Target Distribusi Notifikasi Aktif</h5>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm text-center text-sm table-hover shadow-sm">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>Nama Target / Grup</th>
                                                    <th>Jabatan</th>
                                                    <th>Distribusi Lini</th>
                                                    <th>Nomor / ID JID</th>
                                                    <th>Status</th>
                                                    <th>Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if(empty($daftar_nomor)): ?>
                                                    <tr><td colspan="6" class="text-muted py-3">Belum ada target penerima yang didaftarkan.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach($daftar_nomor as $no): 
                                                    $is_group = str_contains($no['nomor_wa'], '@g.us');
                                                    $kat = $no['kategori_notif'] ?? 'ALL'; 
                                                ?>
                                                    <tr>
                                                        <td class="text-left font-weight-bold text-wrap">
                                                            <?= $is_group ? '<i class="fas fa-users text-success mr-1"></i>':'' ?>
                                                            <?= htmlspecialchars($no['nama'], ENT_QUOTES, 'UTF-8') ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($no['jabatan'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td>
                                                            <?php 
                                                            if($kat === 'ALL') echo '<span class="badge badge-dark">Semua Notif</span>';
                                                            elseif($kat === 'QCL FC') echo '<span class="badge badge-primary">Alert QCL FC</span>';
                                                            elseif($kat === 'QCL AAC1') echo '<span class="badge badge-info">Alert QCL AAC1</span>';
                                                            elseif($kat === 'QCL AAC2') echo '<span class="badge badge-warning">Alert QCL AAC2</span>';
                                                            elseif($kat === 'QCL AAC') echo '<span class="badge badge-secondary">Alert QCL AAC</span>';
                                                            else echo '<span class="badge badge-secondary">'.htmlspecialchars($kat, ENT_QUOTES, 'UTF-8').'</span>';
                                                            ?>
                                                        </td>
                                                        <td class="text-left text-xs text-monospace font-weight-bold text-primary">
                                                            <?= $is_group ? htmlspecialchars($no['nomor_wa'], ENT_QUOTES, 'UTF-8') : '+'.htmlspecialchars($no['nomor_wa'], ENT_QUOTES, 'UTF-8') ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge badge-<?= $no['status_aktif'] == 1 ? 'success':'danger' ?>">
                                                                <?= $no['status_aktif'] == 1 ? 'Aktif':'Nonaktif' ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if($has_full_access): ?>
                                                                <button type="button" class="btn btn-xs btn-info" data-toggle="modal" data-target="#modalEditKontak<?= $no['id'] ?>" title="Edit Kontak">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>

                                                                <form method="POST" action="" class="d-inline">
                                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                                    <input type="hidden" name="id_nomor" value="<?= $no['id'] ?>">
                                                                    <input type="hidden" name="status_sekarang" value="<?= $no['status_aktif'] ?>">
                                                                    <button type="submit" name="btn_toggle_status" value="1" class="btn btn-xs btn-<?= $no['status_aktif'] == 1 ? 'warning':'success' ?>" title="Ubah Status">
                                                                        <i class="fas <?= $no['status_aktif'] == 1 ? 'fa-ban':'fa-check-circle' ?>"></i>
                                                                    </button>
                                                                </form>
                                                                
                                                                <form method="POST" action="" class="d-inline form-hapus-kontak">
                                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                                    <input type="hidden" name="id_nomor" value="<?= $no['id'] ?>">
                                                                    <button type="submit" name="btn_delete_number" value="1" class="btn btn-xs btn-danger btn-click-hapus" title="Hapus Kontak">
                                                                        <i class="fas fa-trash-alt"></i>
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <button type="button" class="btn btn-xs btn-secondary disabled" title="Akses Dibatasi">
                                                                    <i class="fas fa-lock"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if(in_array($role_aktif, ['Admin IT'])): ?>
                        <div class="tab-pane fade" id="tab-server" role="tabpanel">
                            <div class="mx-auto py-3" style="max-width: 500px;">
                                <h5 class="text-bold mb-3"><i class="fas fa-network-wired text-info"></i> Endpoint Jaringan Engine Node.js</h5>
                                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <div class="form-group">
                                        <label>Nama Server Label</label>
                                        <input type="text" name="nama_server" class="form-control" value="<?= htmlspecialchars($server['nama_server'] ?? 'Server WA', ENT_QUOTES, 'UTF-8') ?>" <?= !$has_full_access ? 'readonly':'' ?>>
                                    </div>
                                    <div class="form-group">
                                        <label>IP Address / Domain Server</label>
                                        <input type="text" name="ip_address" class="form-control text-bold text-primary" value="<?= htmlspecialchars($server['ip_address'] ?? 'localhost', ENT_QUOTES, 'UTF-8') ?>" <?= !$has_full_access ? 'readonly':'' ?>>
                                    </div>
                                    <div class="form-group">
                                        <label>Port Express Engine</label>
                                        <input type="text" name="port" class="form-control text-bold" value="<?= htmlspecialchars($server['port'] ?? '3003', ENT_QUOTES, 'UTF-8') ?>" <?= !$has_full_access ? 'readonly':'' ?>>
                                    </div>
                                    <div class="form-group">
                                        <label>Target API Path (Default)</label>
                                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($server['endpoint'] ?? '/api/send-message', ENT_QUOTES, 'UTF-8') ?>" readonly>
                                    </div>
                                    <?php if($has_full_access): ?>
                                    <button type="submit" name="action_update_server" class="btn btn-info btn-block font-weight-bold mt-4">
                                        <i class="fas fa-save mr-1"></i> Perbarui Alamat Jaringan Server
                                    </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php if($has_full_access): ?>
    <?php foreach($daftar_nomor as $no): ?>
    <div class="modal fade" id="modalEditKontak<?= $no['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Target Penerima</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    <div class="modal-body text-left">
                        <input type="hidden" name="id_nomor" value="<?= $no['id'] ?>">
                        
                        <div class="form-group">
                            <label>Nama Target / Grup</label>
                            <input type="text" class="form-control" name="nama" value="<?= htmlspecialchars($no['nama'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Jabatan Penerima</label>
                            <input type="text" class="form-control" name="jabatan" value="<?= htmlspecialchars($no['jabatan'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Nomor Tujuan / JID Group</label>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($no['nomor_wa'], ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                        
                        <div class="form-group mt-3 border-top pt-3">
                            <label class="text-danger"><i class="fas fa-filter"></i> Spesifikasi Distribusi Lini</label>
                            <select name="kategori_notif" class="form-control font-weight-bold" required>
                                <?php $kat_edit = $no['kategori_notif'] ?? 'ALL'; ?>
                                <option value="ALL" <?= $kat_edit === 'ALL' ? 'selected' : '' ?>>Semua Notif</option>
                                <option value="QCL FC" <?= $kat_edit === 'QCL FC' ? 'selected' : '' ?>>Alert QCL FC</option>
                                <option value="QCL AAC1" <?= $kat_edit === 'QCL AAC1' ? 'selected' : '' ?>>Alert QCL AAC1</option>
                                <option value="QCL AAC2" <?= $kat_edit === 'QCL AAC2' ? 'selected' : '' ?>>Alert QCL AAC2</option>
                                <option value="QCL AAC" <?= $kat_edit === 'QCL AAC' ? 'selected' : '' ?>>Alert QCL AAC</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" name="action_edit_number" class="btn btn-info"><i class="fas fa-save"></i> Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(function() {
    // Mencegah form resubmission dan spam klik
    // PENTING: jangan disable tombol submit yang namanya dipakai backend (mis. btn_toggle_status, btn_delete_number, action_*),
    // karena tombol submit yang disabled TIDAK ikut terkirim ke $_POST -> action di backend tak dijalankan.
    // Salin nama+nilai tombol ke hidden field terlebih dahulu (nilai default '1' jika tombol tak punya value),
    // lalu disable tombol untuk anti-spam.
    $('form[method="POST"]').on('submit', function() {
        var $form = $(this);
        var btn = $form.find('button[type="submit"][name]');
        btn.each(function() {
            var b = $(this);
            var name = b.attr('name');
            var val = (b.attr('value') !== undefined) ? b.attr('value') : '1';
            if ($form.find('input[type="hidden"][data-submit-name="' + name + '"]').length === 0) {
                var valEsc = $('<div>').text(val).html();
                $form.append('<input type="hidden" name="' + name + '" value="' + valEsc + '" data-submit-name="' + name + '">');
            }
        });
        // Disable untuk cegah klik ganda (kecuali tombol berkelas btn-delete)
        btn.not('.btn-delete').prop('disabled', true);
        var icon = btn.first().find('i').attr('class');
        if (icon) {
            btn.not('.btn-delete').html('<i class="fas fa-spinner fa-spin"></i> Proses...');
        } else {
            btn.not('.btn-delete').text('Proses...');
        }
    });

    // URL dasar tetap dideklarasi, tapi untuk AJAX Groups kita pakai proxy
    const apiUrl = <?= json_encode($api_base_url, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    var msg = <?= json_encode($status_message, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    if (msg === "success_server") {
        Swal.fire({ icon: 'success', title: 'Berhasil!', text: 'Alamat Jaringan Server WA Berhasil Diperbarui.', confirmButtonColor: '#17a2b8' });
    } else if (msg === "success_add") {
        Swal.fire({ icon: 'success', title: 'Target Didaftarkan!', text: 'Target Penerima Baru Berhasil Disimpan.', confirmButtonColor: '#28a745' });
    } else if (msg === "success_edit") {
        Swal.fire({ icon: 'success', title: 'Diperbarui!', text: 'Data target penerima & kategori lini berhasil diubah.', confirmButtonColor: '#17a2b8' });
    } else if (msg === "success_toggle") {
        Swal.fire({ icon: 'success', title: 'Status Diubah!', text: 'Status Aktif Kontak Berhasil Dialihkan.', confirmButtonColor: '#ffc107' });
    } else if (msg === "success_delete") {
        Swal.fire({ icon: 'success', title: 'Dihapus!', text: 'Target Penerima Berhasil Dihapus Selamanya.', confirmButtonColor: '#dc3545' });
    } else if (msg.startsWith("error")) {
        Swal.fire({ icon: 'error', title: 'Gagal Operasi!', text: msg, confirmButtonColor: '#dc3545' });
    }

    $('.rad-mode').on('change', function() {
        var mode = $(this).val();
        $('#hidden_mode_input').val(mode);
        $('#section-manual, #section-karyawan, #section-grup').hide();
        $('.input-field-manual, .input-field-karyawan, .input-field-grup').attr('required', false);

        if (mode === 'manual') {
            $('#section-manual').fadeIn(200);
            if (!$(this).prop('disabled')) $('[name="nama"], [name="nomor_wa"]').attr('required', true);
        } else if (mode === 'karyawan') {
            $('#section-karyawan').fadeIn(200);
            if (!$(this).prop('disabled')) $('[name="select_karyawan"]').attr('required', true);
        } else if (mode === 'grup_wa') {
            $('#section-grup').fadeIn(200);
            if (!$(this).prop('disabled')) $('#selectGrupWa').attr('required', true);
            loadWhatsAppGroups();
        }
    });

    function loadWhatsAppGroups() {
        $('#selectGrupWa').html('<option value="">-- Sedang memuat grup dari WhatsApp... --</option>');
        $('#grupStatusAlert').html('');
        $.ajax({
            // ===== UBAH URL INI MENJADI PROXY UNTUK MENGHINDARI CORS/MIXED CONTENT =====
            url: 'proxy_wa.php?path=/groups', 
            // ===========================================================================
            type: 'GET', 
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    var htmlOptions = '<option value="">-- Pilih Grup WhatsApp Target --</option>';
                    response.data.forEach(function(grup) {
                        var valuePayload = grup.id + '|' + grup.name;
                        htmlOptions += '<option value="'+ valuePayload +'">'+ grup.name +' ('+ grup.participants_count +' Anggota)</option>';
                    });
                    $('#selectGrupWa').html(htmlOptions);
                    $('#grupStatusAlert').html('<span class="text-xs text-success font-weight-bold">✅ Berhasil memuat ' + response.data.length + ' grup.</span>');
                } else {
                    $('#selectGrupWa').html('<option value="">-- Tidak ditemukan grup di WhatsApp bot --</option>');
                }
            },
            error: function(xhr) {
                $('#selectGrupWa').html('<option value="">⚠️ WhatsApp Gateway Disconnected</option>');
                $('#grupStatusAlert').html('<div class="alert alert-warning p-1 text-xs mb-0"><b>Gagal:</b> Engine offline atau terblokir CORS. Pastikan bot WA terkoneksi dan Proxy aktif.</div>');
            }
        });
    }

    if (!$('.rad-mode').prop('disabled')) { $('[name="nama"], [name="nomor_wa"]').attr('required', true); }

    $('.btn-click-hapus').on('click', function(e) {
        e.preventDefault();
        var form = $(this).closest('form');
        Swal.fire({
            title: 'Hapus Target Kontak Ini?',
            text: "Tujuan ini tidak akan menerima notifikasi alert lagi dari sistem Lab QC.",
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', cancelButtonColor: '#3085d6', confirmButtonText: 'Ya, Hapus!', cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) { form.append('<input type="hidden" name="btn_delete_number" value="1">'); form.submit(); }
        });
    });
});
</script>
