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
requireAccess(['Admin IT', 'Admin QCL FC', 'Kabag QCL', 'Supervisor QCL FC']);

require_once 'config/database.php'; // Menyediakan koneksi $pdo_ms2 (SQL Server)
require_once 'includes/std_helper.php';
require_once 'includes/header.php';

// Set default filter tanggal
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');
$filter_mesin = isset($_GET['mesin']) ? $_GET['mesin'] : 'ALL';

// =========================================================================
// QUERY AMBIL DATA DARI SQL SERVER (parameter_fc)
// =========================================================================
$queryStr = "SELECT * FROM parameter_fc WHERE CAST(tanggal AS DATE) BETWEEN :tgl_awal AND :tgl_akhir";
$params = [':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir];

if ($filter_mesin !== 'ALL') {
    $queryStr .= " AND mesin = :mesin";
    $params[':mesin'] = $filter_mesin;
}
$queryStr .= " ORDER BY tanggal ASC"; 

try {
    $stmt = $pdo_ms2->prepare($queryStr);
    $stmt->execute($params);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $raw_data = [];
    $db_error = "Terjadi kesalahan sistem saat memuat laporan.";
}

// =========================================================================
// FUNGSI HELPER: FORMAT ANGKA & STANDAR DINAMIS
// =========================================================================
function formatAngka($val, $desimal = 2) {
    return ($val !== null && $val !== '') ? number_format((float)$val, $desimal, '.', '') : '-';
}

function getStandardFC($mesin, $produk, $row = null) {
    // Prioritas: baca dari snapshot jika tersedia
    if ($row && !empty($row['std_snapshot'])) {
        $snap = json_decode($row['std_snapshot'], true);
        if ($snap) {
            return [
                'min_pb' => isset($snap['press_barat']) ? (float)($snap['press_barat']['nilai_min'] ?? 0) : 0,
                'max_pb' => isset($snap['press_barat']) ? (float)($snap['press_barat']['nilai_max'] ?? 0) : 0,
                'min_pt' => isset($snap['press_timur']) ? (float)($snap['press_timur']['nilai_min'] ?? 0) : 0,
                'max_pt' => isset($snap['press_timur']) ? (float)($snap['press_timur']['nilai_max'] ?? 0) : 0,
                'min_kab' => isset($snap['kadar_air_before']) ? (float)($snap['kadar_air_before']['nilai_min'] ?? 0) : 0,
                'max_kab' => isset($snap['kadar_air_before']) ? (float)($snap['kadar_air_before']['nilai_max'] ?? 0) : 0,
                'min_kaa' => isset($snap['kadar_air_after']) ? (float)($snap['kadar_air_after']['nilai_min'] ?? 0) : 0,
                'max_kaa' => isset($snap['kadar_air_after']) ? (float)($snap['kadar_air_after']['nilai_max'] ?? 0) : 0,
                'min_db' => isset($snap['densitas_barat']) ? (float)($snap['densitas_barat']['nilai_min'] ?? 0) : 0,
                'max_db' => isset($snap['densitas_barat']) ? (float)($snap['densitas_barat']['nilai_max'] ?? 0) : 0,
                'min_dt' => isset($snap['densitas_tengah']) ? (float)($snap['densitas_tengah']['nilai_min'] ?? 0) : 0,
                'max_dt' => isset($snap['densitas_tengah']) ? (float)($snap['densitas_tengah']['nilai_max'] ?? 0) : 0,
                'min_dtm' => isset($snap['densitas_timur']) ? (float)($snap['densitas_timur']['nilai_min'] ?? 0) : 0,
                'max_dtm' => isset($snap['densitas_timur']) ? (float)($snap['densitas_timur']['nilai_max'] ?? 0) : 0,
            ];
        }
    }

    // Fallback: hardcoded lama untuk data historis tanpa snapshot
    $std = [
        'min_pb' => 1.6, 'max_pb' => 2.4, 
        'min_pt' => 1.6, 'max_pt' => 2.4, 
        'min_kab' => 35, 'max_kab' => 38, 
        'min_kaa' => 26, 'max_kaa' => 28, 
        'min_db' => 1.82, 'max_db' => 1.90,
        'min_dt' => 1.82, 'max_dt' => 1.90,
        'min_dtm' => 1.82, 'max_dtm' => 1.90,
    ];
    
    if ($mesin === 'FS-02' && strpos($produk, 'NUSABESS 6') !== false) {
        $std = ['min_pb' => 1.5, 'max_pb' => 4.5, 'min_pt' => 1.5, 'max_pt' => 4.5, 'min_kab' => 35, 'max_kab' => 40, 'min_kaa' => 26, 'max_kaa' => 30, 'min_db' => 1.80, 'max_db' => 1.90, 'min_dt' => 1.80, 'max_dt' => 1.90, 'min_dtm' => 1.80, 'max_dtm' => 1.90];
    } elseif ($mesin === 'FS-03' && $produk === 'NUSABOARD/NUSAWOOD') {
        $std = ['min_pb' => 1.5, 'max_pb' => 4.5, 'min_pt' => 1.5, 'max_pt' => 4.5, 'min_kab' => 38, 'max_kab' => 45, 'min_kaa' => 28, 'max_kaa' => 31, 'min_db' => 1.65, 'max_db' => 1.72, 'min_dt' => 1.65, 'max_dt' => 1.72, 'min_dtm' => 1.65, 'max_dtm' => 1.72];
    } elseif ($mesin === 'FS-05' && strpos($produk, 'NUSABESS') !== false) {
        $std = ['min_pb' => 1.5, 'max_pb' => 4.5, 'min_pt' => 1.5, 'max_pt' => 4.5, 'min_kab' => 35, 'max_kab' => 40, 'min_kaa' => 26, 'max_kaa' => 30, 'min_db' => 1.80, 'max_db' => 1.90, 'min_dt' => 1.80, 'max_dt' => 1.90, 'min_dtm' => 1.80, 'max_dtm' => 1.90];
    } elseif (($mesin === 'FS-06' || $mesin === 'FS-07') && $produk === 'NUSABOARD/NUSAWOOD') {
        $std = ['min_pb' => 3.7, 'max_pb' => 4.2, 'min_pt' => 3.7, 'max_pt' => 4.2, 'min_kab' => 42, 'max_kab' => 45, 'min_kaa' => 27, 'max_kaa' => 29, 'min_db' => 1.68, 'max_db' => 1.75, 'min_dt' => 1.68, 'max_dt' => 1.75, 'min_dtm' => 1.68, 'max_dtm' => 1.75];
    } elseif ($mesin === 'FS-08' && strpos($produk, 'NUSABESS') !== false) {
        $std = ['min_pb' => 1.5, 'max_pb' => 4.5, 'min_pt' => 1.5, 'max_pt' => 4.5, 'min_kab' => 35, 'max_kab' => 40, 'min_kaa' => 26, 'max_kaa' => 30, 'min_db' => 1.80, 'max_db' => 1.90, 'min_dt' => 1.80, 'max_dt' => 1.90, 'min_dtm' => 1.80, 'max_dtm' => 1.90];
    }
    
    return $std;
}

// =========================================================================
// LOGIKA ALGORITMA STATISTIK QC & GRAFIK MULTI-DIMENSI
// =========================================================================
$total_inspeksi = count($raw_data);
$total_ok = 0;
$total_ng = 0;

// Penampung Array Grafik
$arr_labels = [];
$arr_press_barat = []; $arr_press_timur = [];
$arr_kab = []; $arr_kaa = [];
$arr_dens_b = []; $arr_dens_t = []; $arr_dens_tm = [];

foreach ($raw_data as $row) {
    $arr_labels[] = date('d/m H:i', strtotime($row['tanggal']));
    
    // Array Grafik
    $arr_press_barat[] = $row['press_barat'] !== null ? (float)$row['press_barat'] : null;
    $arr_press_timur[] = $row['press_timur'] !== null ? (float)$row['press_timur'] : null;
    $arr_kab[] = $row['kadar_air_before'] !== null ? (float)$row['kadar_air_before'] : null;
    $arr_kaa[] = $row['kadar_air_after'] !== null ? (float)$row['kadar_air_after'] : null;
    $arr_dens_b[] = $row['densitas_barat'] !== null ? (float)$row['densitas_barat'] : null;
    $arr_dens_t[] = $row['densitas_tengah'] !== null ? (float)$row['densitas_tengah'] : null;
    $arr_dens_tm[] = $row['densitas_timur'] !== null ? (float)$row['densitas_timur'] : null;

    $std = getStandardFC($row['mesin'], $row['produk'], $row);

    $is_ng = false;
    $pb = $row['press_barat']; $pt = $row['press_timur'];
    $kab = $row['kadar_air_before']; $kaa = $row['kadar_air_after'];
    $db = $row['densitas_barat']; $dt = $row['densitas_tengah']; $dtm = $row['densitas_timur'];

    if ($pb !== null && ($pb < $std['min_pb'] || $pb > $std['max_pb'])) $is_ng = true;
    if ($pt !== null && ($pt < $std['min_pt'] || $pt > $std['max_pt'])) $is_ng = true;
    if ($kab !== null && ($kab < $std['min_kab'] || $kab > $std['max_kab'])) $is_ng = true;
    if ($kaa !== null && ($kaa < $std['min_kaa'] || $kaa > $std['max_kaa'])) $is_ng = true;
    if ($db !== null && ($db < $std['min_db'] || $db > $std['max_db'])) $is_ng = true;
    if ($dt !== null && ($dt < $std['min_dt'] || $dt > $std['max_dt'])) $is_ng = true;
    if ($dtm !== null && ($dtm < $std['min_dtm'] || $dtm > $std['max_dtm'])) $is_ng = true;

    if ($is_ng) {
        $total_ng++;
    } else {
        $total_ok++;
    }
}

$table_data = array_reverse($raw_data);
$yield_rate = $total_inspeksi > 0 ? ($total_ok / $total_inspeksi) * 100 : 0;
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<style>
    .metric-value { font-size: 1.5rem; font-weight: bold; margin-bottom: 0; }
    .metric-label { font-size: 0.85rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }
    .badge-status { font-size: 11px; padding: 5px 8px; font-weight: bold; }
    .bg-light-danger { background-color: #fee2e2 !important; }
    .table-qc th, .table-qc td { vertical-align: middle !important; text-align: center; }
    .table-responsive { max-height: 600px; overflow-y: auto; overflow-x: auto; }
</style>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <h1><i class="fas fa-chart-pie text-info mr-2"></i>Dashboard Analisis & Laporan QC FC</h1>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">
            <?php if(isset($db_error)): ?>
                <div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-triangle"></i> Gagal memuat data database: <?= $db_error ?></div>
            <?php endif; ?>

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
                            <label>Saring Mesin Lini</label>
                            <select name="mesin" class="form-control form-control-sm">
                                <option value="ALL" <?= $filter_mesin == 'ALL' ? 'selected':'' ?>>-- Semua Mesin --</option>
                                <option value="FS-01" <?= $filter_mesin == 'FS-01' ? 'selected':'' ?>>FS-01</option>
                                <option value="FS-02" <?= $filter_mesin == 'FS-02' ? 'selected':'' ?>>FS-02</option>
                                <option value="FS-03" <?= $filter_mesin == 'FS-03' ? 'selected':'' ?>>FS-03</option>
                                <option value="FS-05" <?= $filter_mesin == 'FS-05' ? 'selected':'' ?>>FS-05</option>
                                <option value="FS-06" <?= $filter_mesin == 'FS-06' ? 'selected':'' ?>>FS-06</option>
                                <option value="FS-07" <?= $filter_mesin == 'FS-07' ? 'selected':'' ?>>FS-07</option>
                                <option value="FS-08" <?= $filter_mesin == 'FS-08' ? 'selected':'' ?>>FS-08</option>
                            </select>
                        </div>
                        <div class="col-md-3 form-group mb-0">
                            <button type="submit" class="btn btn-primary btn-sm btn-block"><i class="fas fa-filter"></i> Aplikasikan Saringan</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3 col-sm-6"><div class="card p-3 mb-3 bg-white shadow-sm border-left border-primary"><p class="metric-value text-primary"><?= $total_inspeksi ?> <span style="font-size:12px;">Kali</span></p><p class="metric-label">Total Inspeksi QC</p></div></div>
                <div class="col-md-3 col-sm-6"><div class="card p-3 mb-3 bg-white shadow-sm border-left border-success"><p class="metric-value text-success"><?= $total_ok ?> <span style="font-size:12px;">Cek</span></p><p class="metric-label">Total Lolos (STATUS OK)</p></div></div>
                <div class="col-md-3 col-sm-6"><div class="card p-3 mb-3 bg-white shadow-sm border-left border-danger"><p class="metric-value text-danger"><?= $total_ng ?> <span style="font-size:12px;">Kasus</span></p><p class="metric-label">Total Deviasi (STATUS NG)</p></div></div>
                <div class="col-md-3 col-sm-6"><div class="card p-3 mb-3 bg-white shadow-sm border-left border-info"><p class="metric-value text-info"><?= number_format($yield_rate, 2, ',', '.') ?>%</p><p class="metric-label">Persentase Kelayakan Mutu</p></div></div>
            </div>

            <div class="card card-info card-outline shadow-sm">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-line mr-1"></i> Grafik Multi-Parameter (Press, Kadar Air, Densitas)</h3></div>
                <div class="card-body"><div style="position: relative; height: 350px; width: 100%;"><canvas id="chartQCTrend"></canvas></div></div>
            </div>

            <div class="card card-primary card-outline shadow-sm">
                <div class="card-header bg-white"><h3 class="card-title text-primary"><i class="fas fa-history mr-1"></i> Log Historis Pemeriksaan Laboratorium</h3></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="tableQCMortar" class="table table-bordered table-hover text-center table-sm w-100 text-nowrap table-qc">
                            <thead class="bg-light">
                                <tr>
                                    <th>Aksi</th>
                                    <th>Waktu & Tanggal</th>
                                    <th>Mesin</th>
                                    <th>Produk</th>
                                    <th>Status Global</th>
                                    <th class="bg-info text-white">Press B</th>
                                    <th class="bg-info text-white">Press T</th>
                                    <th class="bg-warning">K.Air Bf</th>
                                    <th class="bg-warning">K.Air Af</th>
                                    <th class="bg-success text-white">Densitas B</th>
                                    <th class="bg-success text-white">Densitas Tgh</th>
                                    <th class="bg-success text-white">Densitas Tmr</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($table_data as $r): 
                                    $std = getStandardFC($r['mesin'], $r['produk'], $r);
                                    
                                    $pb = $r['press_barat']; $pt = $r['press_timur'];
                                    $kab = $r['kadar_air_before']; $kaa = $r['kadar_air_after'];
                                    $db = $r['densitas_barat']; $dt = $r['densitas_tengah']; $dtm = $r['densitas_timur'];

                                    $err_pb = ($pb !== null && ($pb < $std['min_pb'] || $pb > $std['max_pb']));
                                    $err_pt = ($pt !== null && ($pt < $std['min_pt'] || $pt > $std['max_pt']));
                                    $err_kab = ($kab !== null && ($kab < $std['min_kab'] || $kab > $std['max_kab']));
                                    $err_kaa = ($kaa !== null && ($kaa < $std['min_kaa'] || $kaa > $std['max_kaa']));
                                    $err_db = ($db !== null && ($db < $std['min_db'] || $db > $std['max_db']));
                                    $err_dt = ($dt !== null && ($dt < $std['min_dt'] || $dt > $std['max_dt']));
                                    $err_dtm = ($dtm !== null && ($dtm < $std['min_dtm'] || $dtm > $std['max_dtm']));
                                    
                                    $row_ng = ($err_pb || $err_pt || $err_kab || $err_kaa || $err_db || $err_dt || $err_dtm);
                                ?>
                                    <tr>
                                        <td><button class="btn btn-xs btn-info" data-toggle="modal" data-target="#modalDetail<?= $r['id'] ?>"><i class="fas fa-search-plus"></i> Detail</button></td>
                                        <td class="font-weight-bold text-nowrap"><?= date('d-m-Y H:i', strtotime($r['tanggal'])) ?></td>
                                        <td><span class="badge badge-secondary"><?= e($r['mesin']) ?></span></td>
                                        <td class="text-left"><?= e($r['produk']) ?></td>
                                        <td><?= $row_ng ? '<span class="badge badge-danger badge-status">NG</span>' : '<span class="badge badge-success badge-status">OK</span>' ?></td>
                                        
                                        <td class="<?= $err_pb ? 'text-danger font-weight-bold bg-light-danger':'' ?>"><?= formatAngka($pb, 2) ?></td>
                                        <td class="<?= $err_pt ? 'text-danger font-weight-bold bg-light-danger':'' ?>"><?= formatAngka($pt, 2) ?></td>
                                        <td class="<?= $err_kab ? 'text-danger font-weight-bold bg-light-danger':'' ?>"><?= formatAngka($kab, 2) ?></td>
                                        <td class="<?= $err_kaa ? 'text-danger font-weight-bold bg-light-danger':'' ?>"><?= formatAngka($kaa, 2) ?></td>
                                        <td class="<?= $err_db ? 'text-danger font-weight-bold bg-light-danger':'' ?>"><?= formatAngka($db, 3) ?></td>
                                        <td class="<?= $err_dt ? 'text-danger font-weight-bold bg-light-danger':'' ?>"><?= formatAngka($dt, 3) ?></td>
                                        <td class="<?= $err_dtm ? 'text-danger font-weight-bold bg-light-danger':'' ?>"><?= formatAngka($dtm, 3) ?></td>
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

<?php foreach ($table_data as $r): 
    $std = getStandardFC($r['mesin'], $r['produk'], $r);
    
    $pb = $r['press_barat']; $pt = $r['press_timur'];
    $kab = $r['kadar_air_before']; $kaa = $r['kadar_air_after'];
    $db = $r['densitas_barat']; $dt = $r['densitas_tengah']; $dtm = $r['densitas_timur'];

    $err_pb = ($pb !== null && ($pb < $std['min_pb'] || $pb > $std['max_pb']));
    $err_pt = ($pt !== null && ($pt < $std['min_pt'] || $pt > $std['max_pt']));
    $err_kab = ($kab !== null && ($kab < $std['min_kab'] || $kab > $std['max_kab']));
    $err_kaa = ($kaa !== null && ($kaa < $std['min_kaa'] || $kaa > $std['max_kaa']));
    $err_db = ($db !== null && ($db < $std['min_db'] || $db > $std['max_db']));
    $err_dt = ($dt !== null && ($dt < $std['min_dt'] || $dt > $std['max_dt']));
    $err_dtm = ($dtm !== null && ($dtm < $std['min_dtm'] || $dtm > $std['max_dtm']));
?>
    <div class="modal fade" id="modalDetail<?= $r['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-search-plus"></i> Detail QC FC - <?= date('d M Y, H:i', strtotime($r['tanggal'])) ?></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-0">
                    <div class="p-3 bg-light border-bottom">
                        <strong>Mesin:</strong> <?= e($r['mesin']) ?> | <strong>Produk:</strong> <?= e($r['produk']) ?> | <strong>Shift:</strong> <?= e($r['shift']) ?>
                    </div>
                    <table class="table table-bordered table-striped m-0 text-center">
                        <thead class="bg-light">
                            <tr><th>Parameter QC</th><th>Standar (Min-Max)</th><th>Hasil Uji</th></tr>
                        </thead>
                        <tbody>
                            <tr><td class="text-left font-weight-bold">Press Barat (Bar)</td><td><?= $std['min_pb'] . ' - ' . $std['max_pb'] ?></td><td class="<?= $err_pb ? 'text-danger font-weight-bold' : '' ?>"><?= formatAngka($pb, 2) ?></td></tr>
                            <tr><td class="text-left font-weight-bold">Press Timur (Bar)</td><td><?= $std['min_pt'] . ' - ' . $std['max_pt'] ?></td><td class="<?= $err_pt ? 'text-danger font-weight-bold' : '' ?>"><?= formatAngka($pt, 2) ?></td></tr>
                            <tr><td class="text-left font-weight-bold">Kadar Air Before (%)</td><td><?= $std['min_kab'] . ' - ' . $std['max_kab'] ?></td><td class="<?= $err_kab ? 'text-danger font-weight-bold' : '' ?>"><?= formatAngka($kab, 2) ?></td></tr>
                            <tr><td class="text-left font-weight-bold">Kadar Air After (%)</td><td><?= $std['min_kaa'] . ' - ' . $std['max_kaa'] ?></td><td class="<?= $err_kaa ? 'text-danger font-weight-bold' : '' ?>"><?= formatAngka($kaa, 2) ?></td></tr>
                            <tr><td class="text-left font-weight-bold">Densitas Barat (gr/cm³)</td><td><?= number_format($std['min_db'], 2) . ' - ' . number_format($std['max_db'], 2) ?></td><td class="<?= $err_db ? 'text-danger font-weight-bold' : '' ?>"><?= formatAngka($db, 3) ?></td></tr>
                            <tr><td class="text-left font-weight-bold">Densitas Tengah (gr/cm³)</td><td><?= number_format($std['min_dt'], 2) . ' - ' . number_format($std['max_dt'], 2) ?></td><td class="<?= $err_dt ? 'text-danger font-weight-bold' : '' ?>"><?= formatAngka($dt, 3) ?></td></tr>
                            <tr><td class="text-left font-weight-bold">Densitas Timur (gr/cm³)</td><td><?= number_format($std['min_dtm'], 2) . ' - ' . number_format($std['max_dtm'], 2) ?></td><td class="<?= $err_dtm ? 'text-danger font-weight-bold' : '' ?>"><?= formatAngka($dtm, 3) ?></td></tr>
                        </tbody>
                    </table>
                    <?php if(!empty($r['catatan'])): ?>
                    <div class="p-3 bg-light border-top">
                        <strong>Catatan Pemeriksa:</strong><br><span class="text-muted"><?= htmlspecialchars($r['catatan']) ?></span>
                    </div>
                    <?php endif; ?>
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
    $('#tableQCMortar').DataTable({ "scrollX": true, "paging": true, "searching": true, "ordering": false, "info": true, "autoWidth": false, "pageLength": 10 });

    var ctx = document.getElementById('chartQCTrend').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($arr_labels) ?>,
            datasets: [
                // GRUP 1: PRESS (Sumbu Y Kiri)
                { label: 'Press Barat', data: <?= json_encode($arr_press_barat) ?>, yAxisID: 'y_press', borderColor: '#2563eb', backgroundColor: 'transparent', borderWidth: 2, pointRadius: 3, tension: 0.1 },
                { label: 'Press Timur', data: <?= json_encode($arr_press_timur) ?>, yAxisID: 'y_press', borderColor: '#3b82f6', borderDash: [5, 5], backgroundColor: 'transparent', borderWidth: 2, pointRadius: 3, tension: 0.1 },
                
                // GRUP 2: KADAR AIR (Sumbu Y Kanan - Luar)
                { label: 'K.Air Before', data: <?= json_encode($arr_kab) ?>, yAxisID: 'y_kadar', borderColor: '#dc3545', backgroundColor: 'transparent', borderWidth: 2, pointRadius: 3, tension: 0.1 },
                { label: 'K.Air After', data: <?= json_encode($arr_kaa) ?>, yAxisID: 'y_kadar', borderColor: '#e83e8c', borderDash: [5, 5], backgroundColor: 'transparent', borderWidth: 2, pointRadius: 3, tension: 0.1 },
                
                // GRUP 3: DENSITAS (Sumbu Y Kanan - Dalam)
                { label: 'Densitas Barat', data: <?= json_encode($arr_dens_b) ?>, yAxisID: 'y_densitas', borderColor: '#28a745', backgroundColor: 'transparent', borderWidth: 2, pointRadius: 3, tension: 0.1 },
                { label: 'Densitas Tgh', data: <?= json_encode($arr_dens_t) ?>, yAxisID: 'y_densitas', borderColor: '#20c997', borderDash: [5, 5], backgroundColor: 'transparent', borderWidth: 2, pointRadius: 3, tension: 0.1 },
                { label: 'Densitas Timur', data: <?= json_encode($arr_dens_tm) ?>, yAxisID: 'y_densitas', borderColor: '#17a2b8', borderDash: [2, 2], backgroundColor: 'transparent', borderWidth: 2, pointRadius: 3, tension: 0.1 }
            ]
        },
        options: {
            responsive: true, 
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: { 
                x: { display: true },
                // Skala Khusus Press (1 - 5 Bar)
                y_press: { 
                    type: 'linear', display: true, position: 'left',
                    title: { display: true, text: 'Press (Bar)', color: '#2563eb', font: {weight: 'bold'} },
                    suggestedMin: 1.0, suggestedMax: 5.0 
                },
                // Skala Khusus Kadar Air (20 - 50 %)
                y_kadar: { 
                    type: 'linear', display: true, position: 'right',
                    title: { display: true, text: 'Kadar Air (%)', color: '#dc3545', font: {weight: 'bold'} },
                    grid: { drawOnChartArea: false }, // Jangan gambar garis grid agar tidak semrawut
                    suggestedMin: 20, suggestedMax: 50 
                },
                // Skala Khusus Densitas (1.5 - 2.0)
                y_densitas: { 
                    type: 'linear', display: true, position: 'right',
                    title: { display: true, text: 'Densitas (gr/cm³)', color: '#28a745', font: {weight: 'bold'} },
                    grid: { drawOnChartArea: false },
                    suggestedMin: 1.5, suggestedMax: 2.0 
                }
            },
            plugins: { 
                legend: { position: 'top', labels: { boxWidth: 12 } } 
            }
        }
    });
});
</script>
