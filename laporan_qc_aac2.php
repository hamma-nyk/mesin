<?php
// 1. Inisialisasi Session
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

date_default_timezone_set('Asia/Jakarta');

require_once 'includes/auth.php';

// =========================================================================
// 🛡️ PROTEKSI HALAMAN (RBAC)
// =========================================================================
requireAccess(['Admin IT', 'Admin QCL AAC2', 'Supervisor QCL AAC', 'Kabag QCL']);

require_once 'config/database.php'; // Menyediakan koneksi $pdo_ms4
require_once 'includes/std_helper.php';

// Identitas User
$nama_user = $_SESSION['nama_lengkap'] ?? 'Pengguna';
$role_aktif = $_SESSION['role'] ?? 'Guest';

require_once 'includes/header.php';

// Set default filter tanggal (Awal bulan s/d hari ini)
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');
$filter_shift = isset($_GET['shift']) ? $_GET['shift'] : 'ALL';

// =========================================================================
// QUERY AMBIL DATA DARI SQL SERVER (parameter_aac2)
// =========================================================================
$queryStr = "SELECT * FROM parameter_aac2 WHERE CAST(tanggal AS DATE) BETWEEN :tgl_awal AND :tgl_akhir";
$params = [':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir];

if ($filter_shift !== 'ALL') {
    $queryStr .= " AND shift = :shift";
    $params[':shift'] = $filter_shift;
}
$queryStr .= " ORDER BY tanggal ASC"; 

try {
    $stmt = $pdo_ms4->prepare($queryStr);
    $stmt->execute($params);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $raw_data = [];
    $db_error = "Terjadi kesalahan sistem saat memuat laporan.";
}

// =========================================================================
// LOGIKA ALGORITMA STATISTIK QC (EVALUASI MULTI-TANGKI AAC 2)
// =========================================================================
$total_inspeksi = count($raw_data);
$total_ok = 0;
$total_ng = 0;

$arr_labels = [];
$arr_dens_t1 = [];
$arr_dens_t5 = [];
$arr_visco_pouring = [];

foreach ($raw_data as $row) {
    $arr_labels[] = date('d/m H:i', strtotime($row['tanggal']));
    
    $arr_dens_t1[] = $row['densitas_t1'] !== null ? round((float)$row['densitas_t1'], 3) : null;
    $arr_dens_t5[] = $row['densitas_t5'] !== null ? round((float)$row['densitas_t5'], 3) : null;
    $arr_visco_pouring[] = $row['visco_pouring'] !== null ? round((float)$row['visco_pouring'], 1) : null;

    $snap = !empty($row['std_snapshot']) ? json_decode($row['std_snapshot'], true) : null;
    $is_ng = false;

    for ($i = 1; $i <= 6; $i++) {
        $d = $row["densitas_t$i"];
        $v = $row["viskositas_t$i"];
        $s = $row["suhu_t$i"];

        if ($d !== null) {
            $min_d = $snap && isset($snap["densitas_t$i"]) ? ($snap["densitas_t$i"]['nilai_min'] !== null ? (float)$snap["densitas_t$i"]['nilai_min'] : null) : (($i <= 4) ? 1600 : 1330);
            $max_d = $snap && isset($snap["densitas_t$i"]) ? ($snap["densitas_t$i"]['nilai_max'] !== null ? (float)$snap["densitas_t$i"]['nilai_max'] : null) : (($i <= 4) ? 1620 : 1360);
            if (($min_d !== null && $d < $min_d) || ($max_d !== null && $d > $max_d)) $is_ng = true;
        }
        if ($v !== null) {
            $min_v = $snap && isset($snap["viskositas_t$i"]) ? ($snap["viskositas_t$i"]['nilai_min'] !== null ? (float)$snap["viskositas_t$i"]['nilai_min'] : null) : 33;
            $max_v = $snap && isset($snap["viskositas_t$i"]) ? ($snap["viskositas_t$i"]['nilai_max'] !== null ? (float)$snap["viskositas_t$i"]['nilai_max'] : null) : 40;
            if (($min_v !== null && $v < $min_v) || ($max_v !== null && $v > $max_v)) $is_ng = true;
        }
        if ($s !== null) {
            $max_s = $snap && isset($snap["suhu_t$i"]) ? ($snap["suhu_t$i"]['nilai_max'] !== null ? (float)$snap["suhu_t$i"]['nilai_max'] : null) : (($i <= 4) ? 45 : 40);
            if ($max_s !== null && $s > $max_s) $is_ng = true;
        }
    }

    $vp = $row['visco_pouring'];
    if ($vp !== null) {
        $min_vp = $snap && isset($snap["visco_pouring"]) ? ($snap["visco_pouring"]['nilai_min'] !== null ? (float)$snap["visco_pouring"]['nilai_min'] : null) : 22;
        $max_vp = $snap && isset($snap["visco_pouring"]) ? ($snap["visco_pouring"]['nilai_max'] !== null ? (float)$snap["visco_pouring"]['nilai_max'] : null) : 24;
        if (($min_vp !== null && $vp < $min_vp) || ($max_vp !== null && $vp > $max_vp)) $is_ng = true;
    }

    $is_ng ? $total_ng++ : $total_ok++;
}

// Balik urutan untuk tabel log supaya data paling baru muncul di atas
$table_data = array_reverse($raw_data);
$yield_rate = $total_inspeksi > 0 ? ($total_ok / $total_inspeksi) * 100 : 0;

// Fungsi helper untuk memformat angka desimal
function formatAngka($val, $desimal = 3) {
    return ($val !== null && $val !== '') ? number_format((float)$val, $desimal, '.', '') : '-';
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<style>
    .metric-value { font-size: 1.5rem; font-weight: bold; margin-bottom: 0; }
    .metric-label { font-size: 0.85rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }
    .badge-status { font-size: 11px; padding: 5px 8px; font-weight: bold; }
    .bg-light-danger { background-color: #fee2e2 !important; }
    .bg-light-warning { background-color: #fff3cd !important; }
    .table-qc th, .table-qc td { vertical-align: middle !important; text-align: center; }
    .table-responsive { max-height: 600px; overflow-y: auto; overflow-x: auto; }
    .user-info-bar { background: #f8f9fa; border-bottom: 1px solid #e9ecef; padding: 10px 20px; font-size: 14px; }
</style>

<div class="content-wrapper">
    <!-- BARIS INFO USER -->
    <div class="user-info-bar">
        <i class="fas fa-user-circle mr-1"></i> Login sebagai: <b><?= htmlspecialchars($nama_user) ?></b> | 
        <i class="fas fa-id-badge ml-2 mr-1"></i> Role: <b><?= htmlspecialchars($role_aktif) ?></b>
    </div>

    <div class="content-header">
        <div class="container-fluid">
            <h1><i class="fas fa-flask text-success mr-2"></i>Dashboard Laporan QC - AAC 2</h1>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">

            <?php if(isset($db_error)): ?>
                <div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-triangle"></i> Gagal mengambil data database: <?= $db_error ?></div>
            <?php endif; ?>

            <!-- KARTU FILTER -->
            <div class="card card-default shadow-sm mb-3">
                <div class="card-body">
                    <form method="GET" action="" class="row align-items-end">
                        <div class="col-md-3 form-group mb-0">
                            <label>Mulai Tanggal</label>
                            <input type="date" name="tgl_awal" class="form-control form-control-sm" value="<?= e($tgl_awal) ?>">
                        </div>
                        <div class="col-md-3 form-group mb-0">
                            <label>Sampai Tanggal</label>
                            <input type="date" name="tgl_akhir" class="form-control form-control-sm" value="<?= e($tgl_akhir) ?>">
                        </div>
                        <div class="col-md-3 form-group mb-0">
                            <label>Saring Berdasarkan Shift</label>
                            <select name="shift" class="form-control form-control-sm">
                                <option value="ALL" <?= $filter_shift == 'ALL' ? 'selected':'' ?>>-- Semua Shift --</option>
                                <option value="Shift 1" <?= $filter_shift == 'Shift 1' ? 'selected':'' ?>>Shift 1</option>
                                <option value="Shift 2" <?= $filter_shift == 'Shift 2' ? 'selected':'' ?>>Shift 2</option>
                                <option value="Shift 3" <?= $filter_shift == 'Shift 3' ? 'selected':'' ?>>Shift 3</option>
                            </select>
                        </div>
                        <div class="col-md-3 form-group mb-0">
                            <button type="submit" class="btn btn-success btn-sm btn-block font-weight-bold"><i class="fas fa-filter"></i> Aplikasikan Saringan</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- KARTU METRIK -->
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-primary">
                        <p class="metric-value text-primary"><?= $total_inspeksi ?> <span style="font-size:12px;">Kali</span></p>
                        <p class="metric-label">Total Inspeksi QC</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-success">
                        <p class="metric-value text-success"><?= $total_ok ?> <span style="font-size:12px;">Cek</span></p>
                        <p class="metric-label">Total Lolos (STATUS OK)</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-danger">
                        <p class="metric-value text-danger"><?= $total_ng ?> <span style="font-size:12px;">Kasus</span></p>
                        <p class="metric-label">Total Deviasi (STATUS NG)</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-info">
                        <p class="metric-value text-info"><?= number_format($yield_rate, 2, ',', '.') ?>%</p>
                        <p class="metric-label">Persentase Kelayakan Mutu</p>
                    </div>
                </div>
            </div>

            <!-- GRAFIK TREN (DUAL Y-AXIS) -->
            <div class="card card-success card-outline shadow-sm">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold"><i class="fas fa-chart-line mr-1"></i> Grafik Tren Densitas & Visco Pouring</h3>
                </div>
                <div class="card-body">
                    <div style="position: relative; height: 320px; width: 100%;"><canvas id="chartQCAAC2"></canvas></div>
                </div>
            </div>

            <!-- TABEL HISTORIS -->
            <div class="card card-primary card-outline shadow-sm">
                <div class="card-header bg-white">
                    <h3 class="card-title text-primary font-weight-bold"><i class="fas fa-history mr-1"></i> Log Historis Pemeriksaan Matriks Tangki</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="tableQCAAC2" class="table table-bordered table-hover text-center table-sm text-nowrap table-qc m-0">
                            <thead class="bg-light">
                                <tr>
                                    <th rowspan="2">Aksi</th>
                                    <th rowspan="2">Waktu & Tanggal</th>
                                    <th rowspan="2">Regu Shift</th>
                                    <th rowspan="2">Status Global</th>
                                    <th rowspan="2" class="bg-warning">Visco Pouring</th>
                                    <?php for($i=1; $i<=6; $i++): ?>
                                        <th colspan="4" class="bg-success text-white">TANGKI <?= $i ?></th>
                                    <?php endfor; ?>
                                </tr>
                                <tr>
                                    <?php for($i=1; $i<=6; $i++): ?>
                                        <th class="bg-secondary" style="font-size: 11px;">Densitas</th>
                                        <th class="bg-secondary" style="font-size: 11px;">Visko</th>
                                        <th class="bg-secondary" style="font-size: 11px;">Suhu</th>
                                        <th class="bg-secondary" style="font-size: 11px;">Stok</th>
                                    <?php endfor; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($table_data as $r): 
                                    $snap = !empty($r['std_snapshot']) ? json_decode($r['std_snapshot'], true) : null;
                                    $row_ng = false;
                                    for ($i = 1; $i <= 6; $i++) {
                                        $d = $r["densitas_t$i"]; $v = $r["viskositas_t$i"]; $s = $r["suhu_t$i"];
                                        if ($d !== null) {
                                            $min_d = $snap && isset($snap["densitas_t$i"]) ? ($snap["densitas_t$i"]['nilai_min'] !== null ? (float)$snap["densitas_t$i"]['nilai_min'] : null) : (($i <= 4) ? 1600 : 1330);
                                            $max_d = $snap && isset($snap["densitas_t$i"]) ? ($snap["densitas_t$i"]['nilai_max'] !== null ? (float)$snap["densitas_t$i"]['nilai_max'] : null) : (($i <= 4) ? 1620 : 1360);
                                            if (($min_d !== null && $d < $min_d) || ($max_d !== null && $d > $max_d)) $row_ng = true;
                                        }
                                        if ($v !== null) {
                                            $min_v = $snap && isset($snap["viskositas_t$i"]) ? ($snap["viskositas_t$i"]['nilai_min'] !== null ? (float)$snap["viskositas_t$i"]['nilai_min'] : null) : 33;
                                            $max_v = $snap && isset($snap["viskositas_t$i"]) ? ($snap["viskositas_t$i"]['nilai_max'] !== null ? (float)$snap["viskositas_t$i"]['nilai_max'] : null) : 40;
                                            if (($min_v !== null && $v < $min_v) || ($max_v !== null && $v > $max_v)) $row_ng = true;
                                        }
                                        if ($s !== null) {
                                            $max_s = $snap && isset($snap["suhu_t$i"]) ? ($snap["suhu_t$i"]['nilai_max'] !== null ? (float)$snap["suhu_t$i"]['nilai_max'] : null) : (($i <= 4) ? 45 : 40);
                                            if ($max_s !== null && $s > $max_s) $row_ng = true;
                                        }
                                    }
                                    
                                    $vp = $r['visco_pouring'];
                                    if ($vp !== null) {
                                        $min_vp = $snap && isset($snap["visco_pouring"]) ? ($snap["visco_pouring"]['nilai_min'] !== null ? (float)$snap["visco_pouring"]['nilai_min'] : null) : 22;
                                        $max_vp = $snap && isset($snap["visco_pouring"]) ? ($snap["visco_pouring"]['nilai_max'] !== null ? (float)$snap["visco_pouring"]['nilai_max'] : null) : 24;
                                        if (($min_vp !== null && $vp < $min_vp) || ($max_vp !== null && $vp > $max_vp)) $row_ng = true;
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <button class="btn btn-xs btn-info" data-toggle="modal" data-target="#modalDetail<?= $r['id'] ?>" title="Lihat Detail"><i class="fas fa-search-plus"></i> Detail</button>
                                        </td>
                                        <td class="font-weight-bold text-nowrap"><?= date('d-m-Y H:i', strtotime($r['tanggal'])) ?></td>
                                        <td><span class="badge badge-secondary"><?= e($r['shift']) ?></span></td>
                                        <td>
                                            <?= $row_ng ? '<span class="badge badge-danger badge-status">NG</span>' : '<span class="badge badge-success badge-status">OK</span>' ?>
                                        </td>
                                        
                                        <!-- VISCO POURING COLUMN -->
                                        <?php 
                                            $err_vp = false;
                                            if ($vp !== null) {
                                                $err_vp = ($min_vp !== null && $vp < $min_vp) || ($max_vp !== null && $vp > $max_vp);
                                            }
                                        ?>
                                        <td class="<?= $err_vp ? 'text-danger font-weight-bold bg-light-danger' : 'font-weight-bold' ?>">
                                            <?= formatAngka($vp, 1) ?>
                                        </td>
                                        
                                        <!-- TANGKI 1 - 6 -->
                                        <?php for ($i = 1; $i <= 6; $i++): 
                                            $d = $r["densitas_t$i"]; $v = $r["viskositas_t$i"]; $s = $r["suhu_t$i"]; $k = $r["stok_t$i"];
                                            
                                            $err_d = false; $err_v = false; $err_s = false;
                                            if ($d !== null) {
                                                $min_d = $snap && isset($snap["densitas_t$i"]) ? ($snap["densitas_t$i"]['nilai_min'] !== null ? (float)$snap["densitas_t$i"]['nilai_min'] : null) : (($i <= 4) ? 1600 : 1330);
                                                $max_d = $snap && isset($snap["densitas_t$i"]) ? ($snap["densitas_t$i"]['nilai_max'] !== null ? (float)$snap["densitas_t$i"]['nilai_max'] : null) : (($i <= 4) ? 1620 : 1360);
                                                if (($min_d !== null && $d < $min_d) || ($max_d !== null && $d > $max_d)) $err_d = true;
                                            }
                                            if ($v !== null) {
                                                $min_v = $snap && isset($snap["viskositas_t$i"]) ? ($snap["viskositas_t$i"]['nilai_min'] !== null ? (float)$snap["viskositas_t$i"]['nilai_min'] : null) : 33;
                                                $max_v = $snap && isset($snap["viskositas_t$i"]) ? ($snap["viskositas_t$i"]['nilai_max'] !== null ? (float)$snap["viskositas_t$i"]['nilai_max'] : null) : 40;
                                                if (($min_v !== null && $v < $min_v) || ($max_v !== null && $v > $max_v)) $err_v = true;
                                            }
                                            if ($s !== null) {
                                                $max_s = $snap && isset($snap["suhu_t$i"]) ? ($snap["suhu_t$i"]['nilai_max'] !== null ? (float)$snap["suhu_t$i"]['nilai_max'] : null) : (($i <= 4) ? 45 : 40);
                                                if ($max_s !== null && $s > $max_s) $err_s = true;
                                            }

                                            $d_val = formatAngka($d, 3);
                                            $v_val = formatAngka($v, 2);
                                            $s_val = formatAngka($s, 1);
                                            $k_val = formatAngka($k, 1);
                                        ?>
                                            <td class="<?= $err_d ? 'text-danger font-weight-bold bg-light-danger':'' ?>"><?= $d_val ?></td>
                                            <td class="<?= $err_v ? 'text-danger font-weight-bold bg-light-danger':'' ?>"><?= $v_val ?></td>
                                            <td class="<?= $err_s ? 'text-danger font-weight-bold bg-light-danger':'' ?>"><?= $s_val ?></td>
                                            <td class="text-muted"><?= $k_val !== '-' ? $k_val.'%' : '-' ?></td>
                                        <?php endfor; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- MODAL DETAIL POPUP -->
<?php foreach ($table_data as $r): 
    $snap = !empty($r['std_snapshot']) ? json_decode($r['std_snapshot'], true) : null;
?>
    <div class="modal fade" id="modalDetail<?= $r['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info">
                    <h5 class="modal-title text-white"><i class="fas fa-search-plus"></i> Detail QC AAC 2 - <?= date('d M Y, H:i', strtotime($r['tanggal'])) ?></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-bordered table-striped m-0 text-center">
                        <thead class="bg-light">
                            <tr>
                                <th>TANGKI</th>
                                <th>DENSITAS (gr/ltr)</th>
                                <th>VISKOSITAS (cm)</th>
                                <th>SUHU (°C)</th>
                                <th>STOK (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = 1; $i <= 6; $i++): 
                                $d = $r["densitas_t$i"]; $v = $r["viskositas_t$i"]; $s = $r["suhu_t$i"]; $k = $r["stok_t$i"];
                                
                                $err_d = false; $err_v = false; $err_s = false;

                                $fb_min_d = ($i <= 4) ? 1600 : 1330; $fb_max_d = ($i <= 4) ? 1620 : 1360;
                                $min_d = $snap && isset($snap["densitas_t$i"]) ? ($snap["densitas_t$i"]['nilai_min'] !== null ? (float)$snap["densitas_t$i"]['nilai_min'] : null) : $fb_min_d;
                                $max_d = $snap && isset($snap["densitas_t$i"]) ? ($snap["densitas_t$i"]['nilai_max'] !== null ? (float)$snap["densitas_t$i"]['nilai_max'] : null) : $fb_max_d;
                                if ($d !== null && (($min_d !== null && $d < $min_d) || ($max_d !== null && $d > $max_d))) $err_d = true;

                                $min_v = $snap && isset($snap["viskositas_t$i"]) ? ($snap["viskositas_t$i"]['nilai_min'] !== null ? (float)$snap["viskositas_t$i"]['nilai_min'] : null) : 33;
                                $max_v = $snap && isset($snap["viskositas_t$i"]) ? ($snap["viskositas_t$i"]['nilai_max'] !== null ? (float)$snap["viskositas_t$i"]['nilai_max'] : null) : 40;
                                if ($v !== null && (($min_v !== null && $v < $min_v) || ($max_v !== null && $v > $max_v))) $err_v = true;

                                $fb_max_s = ($i <= 4) ? 45 : 40;
                                $max_s = $snap && isset($snap["suhu_t$i"]) ? ($snap["suhu_t$i"]['nilai_max'] !== null ? (float)$snap["suhu_t$i"]['nilai_max'] : null) : $fb_max_s;
                                if ($s !== null && $max_s !== null && $s > $max_s) $err_s = true;

                                $std_d_label = ($min_d !== null ? $min_d : '~') . ' - ' . ($max_d !== null ? $max_d : '~');
                                $std_v_label = ($min_v !== null ? $min_v : '~') . ' - ' . ($max_v !== null ? $max_v : '~');
                                $std_s_label = $max_s !== null ? 'Maks. ' . $max_s : '-';
                            ?>
                            <tr>
                                <td class="font-weight-bold">Tangki <?= $i ?></td>
                                <td>
                                    <span class="<?= $err_d ? 'text-danger font-weight-bold' : '' ?>"><?= formatAngka($d, 3) ?></span>
                                    <br><small class="text-muted">Std: <?= $std_d_label ?></small>
                                </td>
                                <td>
                                    <span class="<?= $err_v ? 'text-danger font-weight-bold' : '' ?>"><?= formatAngka($v, 2) ?></span>
                                    <br><small class="text-muted">Std: <?= $std_v_label ?></small>
                                </td>
                                <td>
                                    <span class="<?= $err_s ? 'text-danger font-weight-bold' : '' ?>"><?= formatAngka($s, 1) ?></span>
                                    <br><small class="text-muted">Std: <?= $std_s_label ?></small>
                                </td>
                                <td><?= formatAngka($k, 1) !== '-' ? formatAngka($k, 1).'%' : '-' ?></td>
                            </tr>
                            <?php endfor; ?>
                            
                            <!-- BARIS VISCO POURING DI MODAL -->
                            <?php
                                $vp = $r['visco_pouring'];
                                $min_vp = $snap && isset($snap["visco_pouring"]) ? ($snap["visco_pouring"]['nilai_min'] !== null ? (float)$snap["visco_pouring"]['nilai_min'] : null) : 22;
                                $max_vp = $snap && isset($snap["visco_pouring"]) ? ($snap["visco_pouring"]['nilai_max'] !== null ? (float)$snap["visco_pouring"]['nilai_max'] : null) : 24;
                                $err_vp = $vp !== null && (($min_vp !== null && $vp < $min_vp) || ($max_vp !== null && $vp > $max_vp));
                                $std_vp_label = ($min_vp !== null ? $min_vp : '~') . ' - ' . ($max_vp !== null ? $max_vp : '~');
                            ?>
                            <tr class="bg-warning">
                                <td class="font-weight-bold text-dark">Visco Pouring</td>
                                <td colspan="4" class="text-left pl-4">
                                    <span class="font-weight-bold" style="font-size: 16px;">
                                        Nilai Pengukuran: 
                                        <span class="<?= $err_vp ? 'text-danger' : 'text-success' ?>">
                                            <?= formatAngka($vp, 1) ?> cm
                                        </span>
                                    </span>
                                    <br><small class="text-dark font-weight-bold">Standar Acuan: <?= $std_vp_label ?> cm</small>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <?php if(!empty($r['catatan'])): ?>
                    <div class="p-3 bg-light border-top">
                        <strong>Catatan Proses Kontrol:</strong><br>
                        <span class="text-muted"><?= htmlspecialchars($r['catatan']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    // Jalankan DataTables log QC dengan Horizontal Scroll Aktif
    $('#tableQCAAC2').DataTable({
        "scrollX": true, 
        "paging": true, 
        "searching": true, 
        "ordering": false, 
        "info": true, 
        "autoWidth": false, 
        "pageLength": 10
    });

    // KONFIGURASI DUAL Y-AXIS UNTUK GRAFIK TREN
    var ctx = document.getElementById('chartQCAAC2').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($arr_labels) ?>,
            datasets: [
                {
                    label: 'Densitas Tangki 1 (Std: 1600-1620)',
                    data: <?= json_encode($arr_dens_t1) ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.1,
                    yAxisID: 'y' // Sumbu Kiri
                },
                {
                    label: 'Densitas Tangki 5 (Std: 1330-1360)',
                    data: <?= json_encode($arr_dens_t5) ?>,
                    borderColor: '#17a2b8',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.1,
                    yAxisID: 'y' // Sumbu Kiri
                },
                {
                    label: 'Visco Pouring (Std: 22-24)',
                    data: <?= json_encode($arr_visco_pouring) ?>,
                    borderColor: '#dc3545',
                    backgroundColor: 'transparent',
                    borderWidth: 3,
                    pointRadius: 4,
                    tension: 0.1,
                    borderDash: [5, 5],
                    yAxisID: 'y1' // Sumbu Kanan
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { 
                    type: 'linear',
                    display: true,
                    position: 'left',
                    min: 1200, // Range untuk Densitas
                    max: 1700,
                    title: { display: true, text: 'Nilai Densitas (gr/ltr)', color: '#28a745', font: {weight: 'bold'} }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    min: 10,   // Range untuk Visco Pouring
                    max: 40,
                    title: { display: true, text: 'Visco Pouring (cm)', color: '#dc3545', font: {weight: 'bold'} },
                    grid: { drawOnChartArea: false } // Hilangkan grid overlap
                }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) { label += ': '; }
                            if (context.parsed.y !== null) {
                                label += context.parsed.y + (context.dataset.yAxisID === 'y1' ? ' cm' : ' gr/ltr');
                            }
                            return label;
                        }
                    }
                }
            }
        }
    });
});
</script>
