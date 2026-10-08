<?php
// laporan_produksi_aac2.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';

requireAccess(['Admin IT', 'Admin Produksi AAC2', 'Kabag Produksi', 'Admin QMS']);

// Ambil bulan & tahun aktif untuk filter
$bulan_pilihan = isset($_GET['filter_bulan']) ? (int)$_GET['filter_bulan'] : (int)date('m');
$tahun_pilihan = isset($_GET['filter_tahun']) ? (int)$_GET['filter_tahun'] : (int)date('Y');

// Sinkronisasi rentang filter tanggal agar otomatis mengikuti bulan & tahun yang dipilih
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date("$tahun_pilihan-$bulan_pilihan-01");
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date("$tahun_pilihan-$bulan_pilihan-t");

// =========================================================================
// INTERSEPTOR AJAX: ENDPOINT REKAP PARAMETER + DAFTAR KENDALA HARIAN (MODAL ONLY)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_daily_detail_aac2' && isset($_GET['tanggal'])) {
    $tgl_target = $_GET['tanggal'];
    
    // 1. Ambil rincian data 3 shift untuk tanggal terpilih
    $stmtDetail = $pdo->prepare("SELECT * FROM db_hasil_produksi_aac2.tb_harian 
                                 WHERE tanggal = :tanggal ORDER BY CASE shift WHEN 'Shift 3' THEN 1 WHEN 'Shift 1' THEN 2 WHEN 'Shift 2' THEN 3 END ASC");
    $stmtDetail->execute([':tanggal' => $tgl_target]);
    $shifts_data = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

    // 2. Ambil data semua kendala yang terjadi pada tanggal tersebut mase
    $stmtKendalaTgl = $pdo->prepare("SELECT * FROM db_hasil_produksi_aac2.tb_kendala_harian WHERE tanggal = :tanggal ORDER BY id ASC");
    $stmtKendalaTgl->execute([':tanggal' => $tgl_target]);
    $kendala_tgl_rows = $stmtKendalaTgl->fetchAll(PDO::FETCH_ASSOC);

    $matrix = [
        'Shift 3' => ['regu'=>'-', 't_cast'=>0, 'h_cast'=>0, 'rej_cake'=>0, 'prod_jadi'=>0, 'pack_m3'=>0, 'a1'=>0, 'a2'=>0, 'kw'=>0, 'forklift'=>0, 'loader'=>0],
        'Shift 1' => ['regu'=>'-', 't_cast'=>0, 'h_cast'=>0, 'rej_cake'=>0, 'prod_jadi'=>0, 'pack_m3'=>0, 'a1'=>0, 'a2'=>0, 'kw'=>0, 'forklift'=>0, 'loader'=>0],
        'Shift 2' => ['regu'=>'-', 't_cast'=>0, 'h_cast'=>0, 'rej_cake'=>0, 'prod_jadi'=>0, 'pack_m3'=>0, 'a1'=>0, 'a2'=>0, 'kw'=>0, 'forklift'=>0, 'loader'=>0]
    ];

    $total_target_day = 0;
    $total_hasil_day = 0;

    foreach ($shifts_data as $sd) {
        $s_name = $sd['shift'];
        if (isset($matrix[$s_name])) {
            $matrix[$s_name]['regu']      = $sd['regu'] ?? '-';
            $matrix[$s_name]['t_cast']    = (float)($sd['target_casting_m3'] ?? 0);
            $matrix[$s_name]['h_cast']    = (float)($sd['hasil_casting_m3'] ?? 0);
            $matrix[$s_name]['rej_cake']  = (float)($sd['reject_cake_m3'] ?? 0);
            $matrix[$s_name]['prod_jadi'] = (float)($sd['hasil_produk_jadi_m3'] ?? 0);
            $matrix[$s_name]['pack_m3']   = (float)($sd['total_packing_m3'] ?? 0);
            $matrix[$s_name]['a1']        = (int)($sd['produk_baik_a1'] ?? 0);
            $matrix[$s_name]['a2']        = (int)($sd['produk_hold_a2'] ?? 0);
            $matrix[$s_name]['kw']        = (int)($sd['produk_kw'] ?? 0);
            $matrix[$s_name]['forklift']  = (int)($sd['forklift'] ?? 0);
            $matrix[$s_name]['loader']    = (int)($sd['wheel_loader'] ?? 0);

            $total_target_day += (float)($sd['target_casting_m3'] ?? 0);
            $total_hasil_day  += (float)($sd['hasil_produk_jadi_m3'] ?? 0);
        }
    }
    ?>
    <div class="row mb-3">
        <div class="col-6">
            <small class="font-weight-bold text-muted">Target Harian Global: <b><?= number_format($total_target_day, 2, ',', '.') ?> m³</b></small>
        </div>
        <div class="col-6 text-right">
            <small class="font-weight-bold text-muted">Akumulasi Capaian Jadi:</small>
            <h4 class="text-success font-weight-bold mb-0"><?= number_format($total_hasil_day, 2, ',', '.') ?> m³</h4>
        </div>
    </div>
    
    <table class="table table-bordered table-striped text-center small w-100 shadow-sm">
        <thead class="bg-secondary text-white">
            <tr>
                <th>Parameter Kerja Lini 2</th>
                <th>Shift 3 (Malam)</th>
                <th>Shift 1 (Pagi)</th>
                <th>Shift 2 (Siang)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="font-weight-bold text-left">Regu Kerja</td>
                <td><span class="badge badge-dark"><?= htmlspecialchars($matrix['Shift 3']['regu']) ?></span></td>
                <td><span class="badge badge-dark"><?= htmlspecialchars($matrix['Shift 1']['regu']) ?></span></td>
                <td><span class="badge badge-dark"><?= htmlspecialchars($matrix['Shift 2']['regu']) ?></span></td>
            </tr>
            <tr>
                <td class="font-weight-bold text-left">Hasil Casting Section (m³)</td>
                <td><?= number_format($matrix['Shift 3']['h_cast'], 2, ',', '.') ?></td>
                <td><?= number_format($matrix['Shift 1']['h_cast'], 2, ',', '.') ?></td>
                <td><?= number_format($matrix['Shift 2']['h_cast'], 2, ',', '.') ?></td>
            </tr>
            <tr class="text-success font-weight-bold">
                <td class="text-left">Hasil Autoclave Jadi (m³)</td>
                <td><?= number_format($matrix['Shift 3']['prod_jadi'], 2, ',', '.') ?></td>
                <td><?= number_format($matrix['Shift 1']['prod_jadi'], 2, ',', '.') ?></td>
                <td><?= number_format($matrix['Shift 2']['prod_jadi'], 2, ',', '.') ?></td>
            </tr>
            <tr>
                <td class="font-weight-bold text-left text-danger">Reject Cake Cutting (m³)</td>
                <td class="text-danger"><?= number_format($matrix['Shift 3']['rej_cake'], 2, ',', '.') ?></td>
                <td class="text-danger"><?= number_format($matrix['Shift 1']['rej_cake'], 2, ',', '.') ?></td>
                <td class="text-danger"><?= number_format($matrix['Shift 2']['rej_cake'], 2, ',', '.') ?></td>
            </tr>
            <tr class="bg-light">
                <td class="font-weight-bold text-left">Baik A1 (Pallet)</td>
                <td><?= number_format($matrix['Shift 3']['a1']) ?></td>
                <td><?= number_format($matrix['Shift 1']['a1']) ?></td>
                <td><?= number_format($matrix['Shift 2']['a1']) ?></td>
            </tr>
            <tr class="bg-light">
                <td class="font-weight-bold text-left">Hold A2 (Pallet)</td>
                <td><?= number_format($matrix['Shift 3']['a2']) ?></td>
                <td><?= number_format($matrix['Shift 1']['a2']) ?></td>
                <td><?= number_format($matrix['Shift 2']['a2']) ?></td>
            </tr>
            <tr class="table-warning">
                <td class="font-weight-bold text-left text-warning">Produk Rusak KW (Pallet)</td>
                <td class="text-warning font-weight-bold"><?= number_format($matrix['Shift 3']['kw']) ?></td>
                <td class="text-warning font-weight-bold"><?= number_format($matrix['Shift 1']['kw']) ?></td>
                <td class="text-warning font-weight-bold"><?= number_format($matrix['Shift 2']['kw']) ?></td>
            </tr>
            <tr class="table-info">
                <td class="font-weight-bold text-left">Forklift / Loader (Unit)</td>
                <td><?= $matrix['Shift 3']['forklift'] ?> / <?= $matrix['Shift 3']['loader'] ?></td>
                <td><?= $matrix['Shift 1']['forklift'] ?> / <?= $matrix['Shift 1']['loader'] ?></td>
                <td><?= $matrix['Shift 2']['forklift'] ?> / <?= $matrix['Shift 2']['loader'] ?></td>
            </tr>
        </tbody>
    </table>
    
    <!-- DAFTAR MASALAH / KENDALA DIKAWAL LANGSUNG DI MODAL MASE (FIXED FOOT TOTAL) -->
    <div class="card p-2 mt-2 border shadow-sm">
        <h6 class="small font-weight-bold text-danger mb-2"><i class="fas fa-exclamation-triangle mr-1"></i> Log Rincian Hambatan &amp; Downtime Kerja Hari Ini:</h6>
        <?php if(!empty($kendala_tgl_rows)): 
            $sub_total_menit = 0;
            $sub_total_m3 = 0;
        ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered small text-center mb-0 w-100">
                    <thead class="bg-light text-dark">
                        <tr>
                            <th>Shift / Regu</th>
                            <th class="text-left">Uraian Kejadian Kendala Lapangan</th>
                            <th>Downtime (Min)</th>
                            <th>Loss (m³)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($kendala_tgl_rows as $k): 
                            $sub_total_menit += (int)($k['waktu_hilang_menit'] ?? 0);
                            $sub_total_m3    += (float)($k['output_hilang_m3'] ?? 0);
                        ?>
                            <tr>
                                <td class="font-weight-bold text-primary"><?= htmlspecialchars(($k['shift'] ?? '-') . ' (' . ($k['regu'] ?? '-') . ')') ?></td>
                                <td class="text-left" style="white-space: normal !important;"><?= htmlspecialchars($k['kejadian'] ?? '-') ?></td>
                                <td class="font-weight-bold"><?= number_format((int)($k['waktu_hilang_menit'] ?? 0)) ?> Min</td>
                                <td class="text-danger font-weight-bold"><?= number_format((float)($k['output_hilang_m3'] ?? 0), 2, ',', '.') ?> m³</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="font-weight-bold bg-light text-dark">
                        <tr>
                            <td colspan="2" class="text-right font-weight-bold">TOTAL HARIAN:</td>
                            <td class="font-weight-bold text-dark"><?= number_format($sub_total_menit) ?> Min</td>
                            <td class="font-weight-bold text-danger"><?= number_format($sub_total_m3, 2, ',', '.') ?> m³</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <p class="small text-success font-weight-bold mb-0 pl-2"><i class="fas fa-check-circle mr-1"></i>Lantai pabrik Lini 2 terpantau aman terkendali, nihil kendala operasional.</p>
        <?php endif; ?>
    </div>
    <?php
    exit;
}

// --- AMBIL DATA AGREGASI FRONTEND UTAMA ---
$stmtData = $pdo->prepare("SELECT * FROM db_hasil_produksi_aac2.tb_harian 
                           WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir 
                           ORDER BY tanggal DESC, shift ASC");
$stmtData->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
$table_data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

$stmtKendala = $pdo->prepare("SELECT * FROM db_hasil_produksi_aac2.tb_kendala_harian 
                              WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir 
                              ORDER BY tanggal DESC, id ASC");
$stmtKendala->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
$kendala_data = $stmtKendala->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';

// Hitung rekap metrik atas mase
$total_target_casting = 0; $total_hasil_casting = 0; $total_reject_cake = 0;
$total_produk_jadi = 0; $total_pallet_box = 0; $total_vol_packing = 0;
$total_hari_kerja = 0; $total_forklift_unit = 0; $total_kw_pallet = 0;
$last_tanggal = '';

foreach ($table_data as $row) {
    if ($row['tanggal'] != $last_tanggal) { $total_hari_kerja++; $last_tanggal = $row['tanggal']; }
    $total_target_casting += (float)($row['target_casting_m3'] ?? 0);
    $total_hasil_casting  += (float)($row['hasil_casting_m3'] ?? 0);
    $total_reject_cake    += (float)($row['reject_cake_m3'] ?? 0);
    $total_produk_jadi    += (float)($row['hasil_produk_jadi_m3'] ?? 0);
    $total_pallet_box     += (int)($row['total_packing_pallet'] ?? 0);
    $total_vol_packing    += (float)($row['total_packing_m3'] ?? 0);
    $total_forklift_unit  += (int)($row['forklift'] ?? 0);
    $total_kw_pallet      += (int)($row['produk_kw'] ?? 0);
}
$global_efficiency = $total_target_casting > 0 ? ($total_produk_jadi / $total_target_casting) * 100 : 0;
$rate_reject_cake  = $total_target_casting > 0 ? ($total_reject_cake / $total_target_casting) * 100 : 0;

// Preparasi data tren chart harian global
$tren_tanggal = []; $tren_target = []; $tren_hasil = []; $temp_daily_group = [];
foreach (array_reverse($table_data) as $row) {
    $tgl = date('d-m-Y', strtotime($row['tanggal']));
    if (!isset($temp_daily_group[$tgl])) { $temp_daily_group[$tgl] = ['target'=>0, 'hasil'=>0]; }
    $temp_daily_group[$tgl]['target'] += (float)($row['target_casting_m3'] ?? 0);
    $temp_daily_group[$tgl]['hasil']  += (float)($row['hasil_produk_jadi_m3'] ?? 0);
}
foreach ($temp_daily_group as $date => $val) {
    $tren_tanggal[] = $date; $tren_target[] = round($val['target'], 2); $tren_hasil[] = round($val['hasil'], 2);
}

$loss_shift_data = ['Shift 1' => 0, 'Shift 2' => 0, 'Shift 3' => 0];
foreach ($kendala_data as $k) {
    $sf = trim($k['shift'] ?? '');
    $target_sf = 'Shift 1';
    if (stripos($sf, '3') !== false) { $target_sf = 'Shift 3'; }
    elseif (stripos($sf, '2') !== false) { $target_sf = 'Shift 2'; }
    $loss_shift_data[$target_sf] += (int)($k['waktu_hilang_menit'] ?? 0);
}
arsort($loss_shift_data);
$top_loss_shift_name = key($loss_shift_data) ?: 'Tidak Ada';
$top_loss_shift_val  = current($loss_shift_data) ?: 0;

// Ambil rekap global harian Lini 2 untuk tabel depan mase
$stmtMainTable = $pdo->prepare("
    SELECT tanggal,
           SUM(target_casting_m3) as daily_target,
           SUM(hasil_produk_jadi_m3) as daily_mixing_jadi,
           SUM(jumlah_packing_mould) as daily_mould,
           SUM(total_packing_pallet) as daily_pallet,
           SUM(produk_kw) as daily_kw_plt
    FROM db_hasil_produksi_aac2.tb_harian
    WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir
    GROUP BY tanggal ORDER BY tanggal DESC
");
$stmtMainTable->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
$rows_harian_aac2_global = $stmtMainTable->fetchAll(PDO::FETCH_ASSOC);

$nama_bulan_list = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli',    8 => 'Agustus',  9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
    .metric-value { font-size: 1.4rem; font-weight: bold; margin-bottom: 0; }
    .metric-label { font-size: 0.85rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }
    th { font-size: 11px; text-align: center; vertical-align: middle !important; }
    td { font-size: 11px; vertical-align: middle !important; }
    .text-citicon-green { color: #28a745 !important; }
    .analysis-text { font-size: 12.5px; line-height: 1.6; color: #495057; }
    .bg-gradient-citicon { background: linear-gradient(45deg, #28a745, #1e7e34); color: white; }
</style>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-6"><h1 class="m-0 text-dark"><i class="fas fa-chart-line text-citicon-green mr-2"></i>Laporan Hasil Produksi AAC Lini 2</h1></div>
                <div class="col-sm-6 text-right">
                    <a href="laporan_qc_pareto.php" class="btn btn-primary btn-sm font-weight-bold shadow-sm mr-2"><i class="fas fa-chart-bar mr-1"></i> QC Pareto Defect</a>
                    <button type="button" id="btnSyncAac2Fix" class="btn btn-success btn-sm font-weight-bold shadow-sm"><i class="fas fa-sync-alt" id="iconSync"></i> Tarik Excel Lini 2</button>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">
            
            <!-- PANEL FILTER -->
            <div class="card card-default mb-3 shadow-sm">
                <div class="card-body p-3">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-2">
                            <label class="small font-weight-bold">Bulan Target</label>
                            <select name="filter_bulan" id="filter_bulan" class="form-control form-control-sm font-weight-bold text-success">
                                <?php foreach($nama_bulan_list as $num => $nama): ?>
                                    <option value="<?= $num ?>" <?= $num == $bulan_pilihan ? 'selected' : '' ?>><?= $nama ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="small font-weight-bold">Tahun Target</label>
                            <select name="filter_tahun" id="filter_tahun" class="form-control form-control-sm font-weight-bold text-success">
                                <?php for($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == $tahun_pilihan ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3"><label class="small font-weight-bold">Mulai Tanggal</label><input type="date" name="tgl_awal" class="form-control form-control-sm" value="<?= e($tgl_awal) ?>"></div>
                        <div class="col-md-3"><label class="small font-weight-bold">Sampai Tanggal</label><input type="date" name="tgl_akhir" class="form-control form-control-sm" value="<?= e($tgl_akhir) ?>"></div>
                        <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold"><i class="fas fa-filter mr-1"></i> Saring Data</button></div>
                    </form>
                </div>
            </div>

            <!-- METRIK ATAS -->
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-info"><p class="metric-value text-info"><?= number_format($total_produk_jadi, 2, ',', '.') ?> <span style="font-size:12px;">m³</span></p><p class="metric-label">Total Produk Jadi</p></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-success"><p class="metric-value text-success"><?= number_format($global_efficiency, 2, ',', '.') ?>%</p><p class="metric-label">Efisiensi Capaian</p></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-primary"><p class="metric-value text-primary"><?= number_format($total_vol_packing, 2, ',', '.') ?> <span style="font-size:12px;">m³</span> (<?= number_format($total_pallet_box, 0, ',', '.') ?> Plt)</p><p class="metric-label">Total Volume Packing</p></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-danger"><p class="metric-value text-danger"><?= number_format($rate_reject_cake, 2, ',', '.') ?>%</p><p class="metric-label">Rasio Reject Cake</p></div>
                </div>
            </div>

            <!-- GRAFIK VISUAL -->
            <div class="row">
                <div class="col-md-7"><div class="card card-outline card-success shadow-sm mb-4"><div class="card-header"><h3 class="card-title font-weight-bold text-dark"><i class="fas fa-chart-area mr-1 text-success"></i> Tren Hasil Produksi vs Target (m³)</h3></div><div class="card-body p-2"><div id="chartTrenProduksi" style="min-height: 220px;"></div></div></div></div>
                <div class="col-md-5"><div class="card card-outline card-primary shadow-sm mb-4"><div class="card-header"><h3 class="card-title font-weight-bold text-dark"><i class="fas fa-chart-pie mr-1 text-primary"></i> Distribusi Waktu Hilang per Shift (Menit)</h3></div><div class="card-body p-2"><div id="chartLossTimeShift" style="min-height: 220px;"></div></div></div></div>
            </div>

            <!-- BLOK ANALISIS EXECUTIVE -->
            <div class="card card-outline card-success shadow-sm mb-4">
                <div class="card-header bg-gradient-citicon"><h3 class="card-title text-white"><i class="fas fa-brain mr-1"></i> Automated Lini 2 Production Executive Analysis</h3></div>
                <div class="card-body p-3 analysis-text">
                    <div class="row">
                        <div class="col-md-6 border-right">
                            <h5><i class="fas fa-industry text-success mr-1"></i> <b>Produktivitas Mixing Lini 2</b></h5>
                            <p>Selama adonan cor (*block casting*) terproses di Lini 2 mencapai <b><?= number_format($total_hasil_casting, 2, ',', '.') ?> m³</b> dari target <b><?= number_format($total_target_casting, 2, ',', '.') ?> m³</b>.</p>
                        </div>
                        <div class="col-md-6">
                            <h5><i class="fas fa-exclamation-triangle text-danger mr-1"></i> <b>Loss Time &amp; Waste Rate</b></h5>
                            <p>Reject Cake Cutting tercatat sebesar <b><?= number_format($total_reject_cake, 2, ',', '.') ?> m³</b> (<b><?= number_format($rate_reject_cake, 2, ',', '.') ?>%</b> adonan). Hambatan tertinggi terjadi pada <b><?= htmlspecialchars($top_loss_shift_name) ?></b> dengan total <b><?= number_format($top_loss_shift_val, 0, ',', '.') ?> Menit</b>.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABEL REKAP HARIAN KONSOLIDASI (image_c0d844.png) -->
            <div class="card card-outline card-success shadow-sm mb-4">
                <div class="card-header bg-light"><h3 class="card-title font-weight-bold text-success"><i class="fas fa-table mr-1"></i> Matriks Rekap Log Harian Hasil Produksi Lini 2</h3></div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table id="tbAac2MainGlobal" class="table table-hover table-bordered w-100 text-center text-nowrap small shadow-sm">
                            <thead class="bg-light">
                                <tr>
                                    <th>Tanggal Hari</th>
                                    <th>Target Hari (m³)</th>
                                    <th>Total Mixing / Casting (m³)</th>
                                    <th>Total Packing (Mould)</th>
                                    <th>Pencapaian %</th>
                                    <th>Baik A1 (Pallet)</th>
                                    <th>Hold A2 (Pallet)</th>
                                    <th>Produk KW (Pallet)</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($rows_harian_aac2_global as $rm): 
                                    $pct_day = $rm['daily_target'] > 0 ? ($rm['daily_mixing_jadi'] / $rm['daily_target']) * 100 : 0;
                                    $color_pct = $pct_day >= 95 ? 'text-success' : 'text-danger';
                                ?>
                                    <tr>
                                        <td class="font-weight-bold"><?= date('d-m-Y', strtotime($rm['tanggal'])) ?></td>
                                        <td class="text-right font-weight-bold"><?= number_format($rm['daily_target'], 2, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format($rm['daily_mixing_jadi'], 2, ',', '.') ?></td>
                                        <td class="text-right text-success font-weight-bold"><?= number_format($rm['daily_mould']) ?></td>
                                        <td class="font-weight-bold <?= $color_pct ?>"><?= number_format($pct_day, 2, ',', '.') ?>%</td>
                                        <td><?= number_format($rm['daily_pallet'] - $rm['daily_kw_plt']) ?> Plt</td>
                                        <td><?= number_format($rm['daily_pallet']) ?> Plt</td>
                                        <td class="text-warning font-weight-bold"><?= number_format($rm['daily_kw_plt']) ?> Plt</td>
                                        <td>
                                            <button type="button" class="btn btn-info btn-xs font-weight-bold px-2 btn-load-aac2-detail" data-tgl="<?= e($rm['tanggal']) ?>">
                                                <i class="fas fa-eye mr-1"></i> Detail
                                            </button>
                                        </td>
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

<!-- BOOTSTRAP MODAL DETAI PER SHIFT -->
<div class="modal fade" id="modalDetailAac2Harian" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white p-3">
                <h5 class="modal-title font-weight-bold" id="modalTitleHeaderAac2">Rincian Hasil Produksi Lini 2</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body p-3" id="modalBodyDynamicAac2">
                <!-- AJAX content mase -->
            </div>
            <div class="modal-footer p-2">
                <button type="button" class="btn btn-secondary btn-sm font-weight-bold" data-dismiss="modal">Tutup Detail</button>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<script>
$(document).ready(function() {
    $('#tbAac2MainGlobal').DataTable({ "paging": true, "searching": true, "ordering": false, "pageLength": 10 });

    $('.btn-load-aac2-detail').on('click', function() {
        var tgl = $(this).data('tgl');
        var tglFmt = new Date(tgl).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
        
        $('#modalTitleHeaderAac2').text('Rincian Hasil Produksi Lini 2 (' + tglFmt + ')');
        $('#modalBodyDynamicAac2').html('<div class="text-center my-4"><i class="fas fa-spinner fa-spin fa-2x text-success"></i><p class="small mt-2">Mengekstrak parameter shift & log kendala Lini 2...</p></div>');
        $('#modalDetailAac2Harian').modal('show');

        $.ajax({
            url: 'laporan_produksi_aac2.php',
            type: 'GET',
            data: { action: 'get_daily_detail_aac2', tanggal: tgl },
            success: function(response) {
                $('#modalBodyDynamicAac2').html(response);
            },
            error: function() {
                $('#modalBodyDynamicAac2').html('<div class="alert alert-danger small">Gagal memuat rincian shift kerja.</div>');
            }
        });
    });

    var chartTren = new ApexCharts(document.querySelector("#chartTrenProduksi"), {
        series: [{ name: 'Target Production (m³)', type: 'line', data: <?= json_encode($tren_target) ?> }, { name: 'Hasil Produk Jadi (m³)', type: 'area', data: <?= json_encode($tren_hasil) ?> }],
        chart: { height: 220, type: 'line', toolbar: { show: false } },
        colors: ['#6c757d', '#28a745'], stroke: { width: [2, 3], curve: 'smooth' },
        labels: <?= json_encode($tren_tanggal) ?>, xaxis: { type: 'category' }
    });
    chartTren.render();

    var chartPie = new ApexCharts(document.querySelector("#chartLossTimeShift"), {
        series: [<?= (int)$loss_shift_data['Shift 1'] ?>, <?= (int)$loss_shift_data['Shift 2'] ?>, <?= (int)$loss_shift_data['Shift 3'] ?>],
        chart: { height: 220, type: 'donut' }, labels: ['Shift 1', 'Shift 2', 'Shift 3'],
        colors: ['#007bff', '#ffc107', '#dc3545'], legend: { position: 'bottom' }
    });
    chartPie.render();

    $('#filter_bulan, #filter_tahun').on('change', function() {
        var b = $('#filter_bulan').val(), t = $('#filter_tahun').val();
        $('input[name="tgl_awal"]').val(t + '-' + ("0" + b).slice(-2) + '-01');
        $('input[name="tgl_akhir"]').val(t + '-' + ("0" + b).slice(-2) + '-' + new Date(t, b, 0).getDate());
    });

    $('#btnSyncAac2Fix').on('click', function() {
        var btn = $(this); var icon = $('#iconSync');
        btn.prop('disabled', true); icon.addClass('fa-spin');
        Swal.fire({ title: 'Mengekstraksi Berkas Lini 2...', text: 'Menghubungkan ke lokal server...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

        $.ajax({
            url: 'cron/sync_aac2.php?bulan=' + $('#filter_bulan').val() + '&tahun=' + $('#filter_tahun').val(),
            type: 'GET',
            success: function(response) {
                btn.prop('disabled', false); icon.removeClass('fa-spin'); Swal.close();
                Swal.fire({ icon: 'success', title: 'Sinkronisasi Sukses!', text: response }).then(() => { window.location.reload(); });
            }
        });
    });
});
</script>
