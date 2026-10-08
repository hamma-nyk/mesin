<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';

requireAccess(['Admin IT', 'Kabag QCL', 'Supervisor QCL AAC', 'Supervisor QCL FC']);

$user_aktif = $_SESSION['username'] ?? 'Admin';
$role_aktif = $_SESSION['role'] ?? '';

$SECRET_PIN = "888999";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        if ($_POST['pin_otorisasi'] !== $SECRET_PIN) {
            $_SESSION['flash_error'] = "PIN Otorisasi tidak valid.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO db_master.tb_standar_qc (lini, kode_param, nama_param, satuan, nilai_min, nilai_max, mesin, produk, keterangan, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([
                $_POST['lini'],
                sanitize_input($_POST['kode_param']),
                sanitize_input($_POST['nama_param']),
                sanitize_input($_POST['satuan']),
                $_POST['nilai_min'] !== '' ? (float)$_POST['nilai_min'] : null,
                $_POST['nilai_max'] !== '' ? (float)$_POST['nilai_max'] : null,
                $_POST['mesin'] !== '' ? $_POST['mesin'] : null,
                $_POST['produk'] !== '' ? sanitize_input($_POST['produk']) : null,
                sanitize_input($_POST['keterangan']),
                $user_aktif
            ]);
            $_SESSION['flash_success'] = "Standar parameter baru berhasil ditambahkan.";
        }
    }
    elseif ($action === 'edit') {
        if ($_POST['pin_otorisasi'] !== $SECRET_PIN) {
            $_SESSION['flash_error'] = "PIN Otorisasi tidak valid.";
        } else {
            $stmt = $pdo->prepare("UPDATE db_master.tb_standar_qc SET lini=?, kode_param=?, nama_param=?, satuan=?, nilai_min=?, nilai_max=?, mesin=?, produk=?, keterangan=?, updated_by=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([
                $_POST['lini'],
                sanitize_input($_POST['kode_param']),
                sanitize_input($_POST['nama_param']),
                sanitize_input($_POST['satuan']),
                $_POST['nilai_min'] !== '' ? (float)$_POST['nilai_min'] : null,
                $_POST['nilai_max'] !== '' ? (float)$_POST['nilai_max'] : null,
                $_POST['mesin'] !== '' ? $_POST['mesin'] : null,
                $_POST['produk'] !== '' ? sanitize_input($_POST['produk']) : null,
                sanitize_input($_POST['keterangan']),
                $user_aktif,
                $_POST['id']
            ]);
            $_SESSION['flash_success'] = "Standar parameter berhasil diperbarui.";
        }
    }
    elseif ($action === 'delete') {
        if ($_POST['pin_hapus'] !== $SECRET_PIN) {
            $_SESSION['flash_error'] = "PIN salah, data gagal dihapus.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM db_master.tb_standar_qc WHERE id=?");
            $stmt->execute([$_POST['id']]);
            $_SESSION['flash_success'] = "Standar parameter berhasil dihapus.";
        }
    }

    header("Location: " . $_SERVER['PHP_SELF'] . (!empty($_GET['lini']) ? '?lini=' . urlencode($_GET['lini']) : ''));
    exit;
}

$error_msg = $_SESSION['flash_error'] ?? "";
$success_msg = $_SESSION['flash_success'] ?? "";
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$filter_lini = isset($_GET['lini']) ? $_GET['lini'] : 'AAC1';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT * FROM db_master.tb_standar_qc WHERE lini = :lini";
$params = [':lini' => $filter_lini];

if (!empty($search)) {
    $sql .= " AND (kode_param ILIKE :search OR nama_param ILIKE :search OR mesin ILIKE :search OR produk ILIKE :search)";
    $params[':search'] = "%{$search}%";
}

$sql .= " ORDER BY mesin ASC NULLS FIRST, kode_param ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark font-weight-bold"><i class="fas fa-sliders-h text-primary mr-2"></i>Master Standar Parameter QC</h1>
                </div>
                <div class="col-sm-6 text-right">
                    <button class="btn btn-primary" data-toggle="modal" data-target="#modalAdd">
                        <i class="fas fa-plus-circle"></i> Tambah Standar Baru
                    </button>
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
                            <div class="col-md-3 mb-3">
                                <label>Filter Lini Produksi</label>
                                <select name="lini" class="form-control">
                                    <option value="AAC1" <?= $filter_lini == 'AAC1' ? 'selected' : '' ?>>AAC 1</option>
                                    <option value="AAC2" <?= $filter_lini == 'AAC2' ? 'selected' : '' ?>>AAC 2</option>
                                    <option value="FC" <?= $filter_lini == 'FC' ? 'selected' : '' ?>>FC</option>
                                </select>
                            </div>
                            <div class="col-md-5 mb-3">
                                <label>Cari Parameter</label>
                                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Kode, nama, mesin, produk...">
                            </div>
                            <div class="col-md-4 mb-3">
                                <button type="submit" class="btn btn-primary mr-1">Terapkan</button>
                                <a href="master_standar_qc.php" class="btn btn-default">Reset</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card card-outline card-primary shadow-none border">
                <div class="card-header bg-white">
                    <h3 class="card-title font-weight-bold"><i class="fas fa-list mr-1"></i> Daftar Standar: <?= htmlspecialchars($filter_lini) ?></h3>
                    <span class="float-right badge badge-primary"><?= count($rows) ?> parameter</span>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover text-nowrap m-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="text-center" width="4%">No</th>
                                <th>Kode Parameter</th>
                                <th>Nama Parameter</th>
                                <th>Satuan</th>
                                <th class="text-center">Min</th>
                                <th class="text-center">Max</th>
                                <?php if ($filter_lini === 'FC'): ?>
                                <th>Mesin</th>
                                <th>Produk</th>
                                <?php endif; ?>
                                <th>Terakhir Diubah</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($rows) > 0): ?>
                                <?php $no = 1; foreach ($rows as $row): ?>
                                <tr>
                                    <td class="text-center"><?= $no++; ?></td>
                                    <td><code><?= htmlspecialchars($row['kode_param']); ?></code></td>
                                    <td><strong><?= htmlspecialchars($row['nama_param']); ?></strong></td>
                                    <td><?= htmlspecialchars($row['satuan']); ?></td>
                                    <td class="text-center"><?= $row['nilai_min'] !== null ? number_format((float)$row['nilai_min'], 2) : '<span class="text-muted">-</span>'; ?></td>
                                    <td class="text-center"><?= $row['nilai_max'] !== null ? number_format((float)$row['nilai_max'], 2) : '<span class="text-muted">-</span>'; ?></td>
                                    <?php if ($filter_lini === 'FC'): ?>
                                    <td><?= htmlspecialchars($row['mesin'] ?? '-'); ?></td>
                                    <td><?= htmlspecialchars($row['produk'] ?? 'Semua'); ?></td>
                                    <?php endif; ?>
                                    <td><small class="text-muted"><?= $row['updated_at'] ? date('d M Y H:i', strtotime($row['updated_at'])) : '-'; ?><br>oleh <?= htmlspecialchars($row['updated_by']); ?></small></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-warning" data-toggle="modal" data-target="#modalEdit<?= $row['id']; ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#modalDelete<?= $row['id']; ?>" title="Hapus"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>

                                <div class="modal fade" id="modalEdit<?= $row['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="?lini=<?= urlencode($filter_lini) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="action" value="edit">
                                                <input type="hidden" name="id" value="<?= $row['id']; ?>">
                                                <div class="modal-header bg-warning">
                                                    <h5 class="modal-title font-weight-bold">Edit Standar Parameter</h5>
                                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="row">
                                                        <div class="col-md-6 form-group">
                                                            <label>Lini</label>
                                                            <select class="form-control" name="lini" required>
                                                                <option value="AAC1" <?= $row['lini'] == 'AAC1' ? 'selected' : '' ?>>AAC1</option>
                                                                <option value="AAC2" <?= $row['lini'] == 'AAC2' ? 'selected' : '' ?>>AAC2</option>
                                                                <option value="FC" <?= $row['lini'] == 'FC' ? 'selected' : '' ?>>FC</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6 form-group">
                                                            <label>Kode Parameter</label>
                                                            <input type="text" class="form-control" name="kode_param" value="<?= htmlspecialchars($row['kode_param']); ?>" required>
                                                        </div>
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Nama Parameter</label>
                                                        <input type="text" class="form-control" name="nama_param" value="<?= htmlspecialchars($row['nama_param']); ?>" required>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-4 form-group">
                                                            <label>Satuan</label>
                                                            <input type="text" class="form-control" name="satuan" value="<?= htmlspecialchars($row['satuan']); ?>">
                                                        </div>
                                                        <div class="col-md-4 form-group">
                                                            <label>Nilai Min</label>
                                                            <input type="number" step="0.0001" class="form-control" name="nilai_min" value="<?= $row['nilai_min'] !== null ? (float)$row['nilai_min'] : ''; ?>">
                                                        </div>
                                                        <div class="col-md-4 form-group">
                                                            <label>Nilai Max</label>
                                                            <input type="number" step="0.0001" class="form-control" name="nilai_max" value="<?= $row['nilai_max'] !== null ? (float)$row['nilai_max'] : ''; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-6 form-group">
                                                            <label>Mesin <small class="text-muted">(FC saja)</small></label>
                                                            <input type="text" class="form-control" name="mesin" value="<?= htmlspecialchars($row['mesin'] ?? ''); ?>">
                                                        </div>
                                                        <div class="col-md-6 form-group">
                                                            <label>Produk <small class="text-muted">(FC saja)</small></label>
                                                            <input type="text" class="form-control" name="produk" value="<?= htmlspecialchars($row['produk'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Keterangan</label>
                                                        <input type="text" class="form-control" name="keterangan" value="<?= htmlspecialchars($row['keterangan'] ?? ''); ?>">
                                                    </div>
                                                    <hr>
                                                    <div class="form-group">
                                                        <label class="text-danger"><i class="fas fa-lock"></i> PIN Otorisasi</label>
                                                        <input type="password" class="form-control border-danger" name="pin_otorisasi" required>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="submit" class="btn btn-warning font-weight-bold">Simpan Perubahan</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal fade" id="modalDelete<?= $row['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-sm">
                                        <div class="modal-content">
                                            <form method="POST" action="?lini=<?= urlencode($filter_lini) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $row['id']; ?>">
                                                <div class="modal-header bg-danger">
                                                    <h5 class="modal-title text-white">Konfirmasi Hapus</h5>
                                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                </div>
                                                <div class="modal-body text-center">
                                                    <p>Hapus standar <strong><?= htmlspecialchars($row['nama_param']); ?></strong>?</p>
                                                    <div class="form-group text-left">
                                                        <label>PIN Otorisasi</label>
                                                        <input type="password" class="form-control text-center" name="pin_hapus" required>
                                                    </div>
                                                </div>
                                                <div class="modal-footer justify-content-center">
                                                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Hapus</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox fa-2x mb-2 text-light"></i><br>
                                        Belum ada data standar untuk lini ini.
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

<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?lini=<?= urlencode($filter_lini) ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Tambah Standar Parameter Baru</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Lini <span class="text-danger">*</span></label>
                            <select class="form-control" name="lini" required>
                                <option value="AAC1" <?= $filter_lini == 'AAC1' ? 'selected' : '' ?>>AAC1</option>
                                <option value="AAC2" <?= $filter_lini == 'AAC2' ? 'selected' : '' ?>>AAC2</option>
                                <option value="FC" <?= $filter_lini == 'FC' ? 'selected' : '' ?>>FC</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Kode Parameter <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="kode_param" placeholder="densitas_t1, press, dll" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Nama Parameter <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_param" placeholder="Densitas Tangki 1" required>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Satuan</label>
                            <input type="text" class="form-control" name="satuan" placeholder="gr/ltr">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Nilai Min</label>
                            <input type="number" step="0.0001" class="form-control" name="nilai_min" placeholder="Kosongkan jika tidak ada">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Nilai Max</label>
                            <input type="number" step="0.0001" class="form-control" name="nilai_max" placeholder="Kosongkan jika tidak ada">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Mesin <small class="text-muted">(FC saja)</small></label>
                            <input type="text" class="form-control" name="mesin" placeholder="FS-01, FS-02, dll">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Produk <small class="text-muted">(FC saja)</small></label>
                            <input type="text" class="form-control" name="produk" placeholder="Kosongkan = semua produk">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Keterangan</label>
                        <input type="text" class="form-control" name="keterangan">
                    </div>
                    <hr>
                    <div class="form-group">
                        <label class="text-danger"><i class="fas fa-lock"></i> PIN Otorisasi (Wajib)</label>
                        <input type="password" class="form-control border-danger" name="pin_otorisasi" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('form[method="POST"]').on('submit', function() {
        let btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> Proses...');
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
