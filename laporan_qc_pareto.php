<?php
// laporan_qc_pareto.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';

requireAccess(['Admin IT', 'Admin Produksi AAC2', 'Kabag Produksi', 'Admin QMS']);

// Ambil bulan & tahun aktif untuk sync (default bulan & tahun ini mase)
$bulan_pilihan = isset($_GET['filter_bulan']) ? (int)$_GET['filter_bulan'] : (int)date('m');
$tahun_pilihan = isset($_GET['filter_tahun']) ? (int)$_GET['filter_tahun'] : (int)date('Y');

// Sinkronisasi rentang filter tanggal agar otomatis mengikuti bulan & tahun yang dipilih
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date("$tahun_pilihan-$bulan_pilihan-01");
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date("$tahun_pilihan-$bulan_pilihan-t");

// 1. DATA TABEL LOG HISTORI (Murni tanpa JOIN agar tidak ganda)
$stmtData = $pdo->prepare("SELECT * FROM db_hasil_produksi_aac2.tb_qc_defect_detail 
                           WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir 
                           ORDER BY tanggal DESC, shift ASC, ukuran ASC");
$stmtData->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
$raw_data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

// 2. DATA AGREGASI UTAMA & PARETO (Menggunakan COALESCE standar PostgreSQL mase!)
$stmtDefectSum = $pdo->prepare("SELECT 
        COALESCE(SUM(gompal), 0) as total_gompal, 
        COALESCE(SUM(pecah), 0) as total_pecah, 
        COALESCE(SUM(lengket), 0) as total_lengket,
        COALESCE(SUM(pendek), 0) as total_pendek, 
        COALESCE(SUM(dimensi), 0) as total_dimensi, 
        COALESCE(SUM(ccr), 0) as total_ccr,
        COALESCE(SUM(tidak_terseset), 0) as total_tidak_terseset, 
        COALESCE(SUM(cacat_permukaan), 0) as total_cacat_permukaan,
        COALESCE(SUM(tidak_terseparating), 0) as total_tidak_terseparating, 
        COALESCE(SUM(not_cut), 0) as total_not_cut,
        COALESCE(SUM(gelombang), 0) as total_gelombang, 
        COALESCE(SUM(wtm), 0) as total_wtm, 
        COALESCE(SUM(linemark), 0) as total_linemark,
        COALESCE(SUM(hp_m3), 0) as total_hp_vol, 
        COALESCE(SUM(hb_m3), 0) as total_hb_vol, 
        COALESCE(SUM(total_reject_m3), 0) as total_reject_vol, 
        COALESCE(SUM(sampel_m3), 0) as total_sampel_vol
    FROM db_hasil_produksi_aac2.tb_qc_defect_detail 
    WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir");
$stmtDefectSum->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
$defect_sum = $stmtDefectSum->fetch(PDO::FETCH_ASSOC);

// Mapping Array Defect dengan proteksi super ?? 0
$arr_defects = [
    'Gompal' => (int)($defect_sum['total_gompal'] ?? 0),
    'Pecah' => (int)($defect_sum['total_pecah'] ?? 0),
    'Lengket' => (int)($defect_sum['total_lengket'] ?? 0),
    'Pendek' => (int)($defect_sum['total_pendek'] ?? 0),
    'Dimensi' => (int)($defect_sum['total_dimensi'] ?? 0),
    'CCR' => (int)($defect_sum['total_ccr'] ?? 0),
    'Tidak Terseset' => (int)($defect_sum['total_tidak_terseset'] ?? 0),
    'Cacat Permukaan' => (int)($defect_sum['total_cacat_permukaan'] ?? 0),
    'Tidak Terseparating' => (int)($defect_sum['total_tidak_terseparating'] ?? 0),
    'Tidak Terpotong' => (int)($defect_sum['total_not_cut'] ?? 0),
    'Gelombang' => (int)($defect_sum['total_gelombang'] ?? 0),
    'WTM' => (int)($defect_sum['total_wtm'] ?? 0),
    'Linemark' => (int)($defect_sum['total_linemark'] ?? 0)
];

// Kalkulasi akumulasi untuk Box Card atas
$total_reject_pcs_accum      = array_sum($arr_defects);
$total_reject_m3_accum       = (float)($defect_sum['total_reject_vol'] ?? 0);
$total_bongkar_baik_m3_accum = (float)($defect_sum['total_hb_vol'] ?? 0);
$total_hp_m3_accum           = (float)($defect_sum['total_hp_vol'] ?? 0);
$total_sample_lab            = (float)($defect_sum['total_sampel_vol'] ?? 0);

// Cari Cacat Tertinggi untuk Analisis Otomatis
arsort($arr_defects);
$top_defect_name = key($arr_defects) ?: 'Tidak Ada';
$top_defect_value = current($arr_defects) ?: 0;

// 3. DATA CHART TREN HARIAN
$stmtChart = $pdo->prepare("SELECT 
        tanggal, 
        SUM(gompal+pecah+lengket+pendek+dimensi+ccr+tidak_terseset+cacat_permukaan+tidak_terseparating+not_cut+gelombang+wtm+linemark) as total_cacat_hari,
        SUM(hp_m3) as t_hp_m3,
        SUM(hb_m3) as t_hb_m3,
        SUM(total_reject_m3) as t_rej_m3,
        SUM(sampel_m3) as t_sampel_m3
       FROM db_hasil_produksi_aac2.tb_qc_defect_detail
       WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir 
       GROUP BY tanggal ORDER BY tanggal ASC");
$chart_rows = [];
if ($stmtChart->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir])) {
    $chart_rows = $stmtChart->fetchAll(PDO::FETCH_ASSOC);
}

$arr_labels  = []; 
$data_sortir = []; 
$data_obs_a1 = []; 
$tren_hp_m3  = [];
$tren_hb_m3  = [];
$tren_rej_m3 = [];

foreach($chart_rows as $cr) {
    $arr_labels[]  = date('d/m', strtotime($cr['tanggal']));
    $data_sortir[] = (float)($cr['total_cacat_hari'] ?? 0);
    $data_obs_a1[] = (float)($cr['t_sampel_m3'] ?? 0);
    
    $tren_hp_m3[]  = (float)($cr['t_hp_m3'] ?? 0);
    $tren_hb_m3[]  = (float)($cr['t_hb_m3'] ?? 0);
    $tren_rej_m3[] = (float)($cr['t_rej_m3'] ?? 0);
}

// Hitung sebaran defect per regu
$regu_data = ['N1' => 0, 'N2' => 0, 'N3' => 0, 'N4' => 0];
foreach($raw_data as $r) {
    $rg = strtoupper(trim($r['regu'] ?? ''));
    if(isset($regu_data[$rg])) {
        $regu_data[$rg] += (int)($r['gompal'] ?? 0) + (int)($r['pecah'] ?? 0) + (int)($r['lengket'] ?? 0) + 
                           (int)($r['pendek'] ?? 0) + (int)($r['dimensi'] ?? 0) + (int)($r['ccr'] ?? 0) + 
                           (int)($r['tidak_terseset'] ?? 0) + (int)($r['cacat_permukaan'] ?? 0) + 
                           (int)($r['tidak_terseparating'] ?? 0) + (int)($r['not_cut'] ?? 0) + 
                           (int)($r['gelombang'] ?? 0) + (int)($r['wtm'] ?? 0) + (int)($r['linemark'] ?? 0);
    }
}

arsort($regu_data);
$top_regu_name = key($regu_data) ?: 'Tidak Ada';
$top_regu_value = current($regu_data) ?: 0;

$nama_bulan_list = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli',    8 => 'Agustus',  9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>

<?php require_once 'includes/header.php'; ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
    .table-responsive { overflow-x: auto; }
    .card-title { font-weight: 600; font-size: 14px; }
    .metric-value { font-size: 1.6rem; font-weight: bold; margin-bottom: 0; }
    .metric-label { font-size: 0.8rem; color: #6c757d; text-transform: uppercase; font-weight: 700; }
    #tbQc thead th { font-size: 10px; text-align: center; vertical-align: middle !important; background-color: #f4f6f9; color: #333; padding: 6px 4px; border: 1px solid #dee2e6 !important; }
    #tbQc tbody td { font-size: 11px; vertical-align: middle !important; padding: 5px 6px; }
    .analysis-text { font-size: 13px; line-height: 1.6; color: #495057; }
</style>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1 class="m-0 text-dark"><i class="fas fa-chart-bar text-danger mr-2"></i>Dashboard Analisis Defect &amp; Pareto 2</h1></div>
                <div class="col-sm-6 text-right">
                    <button type="button" id="btnSyncBongkar" class="btn btn-danger btn-sm font-weight-bold shadow-sm mr-1">
                        <i class="fas fa-sync-alt" id="iconSyncBongkar"></i> Tarik Data Excel Bongkar &amp; QC
                    </button>
                    <a href="laporan_produksi_aac2.php" class="btn btn-secondary btn-sm font-weight-bold shadow-sm"><i class="fas fa-arrow-left"></i> Kembali</a>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">
            
            <!-- PANEL FILTER TIMELINE DENGAN DROPDOWN BULAN & TAHUN -->
            <div class="card card-default shadow-sm mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-2">
                            <label class="small font-weight-bold">Bulan Target Tarik</label>
                            <select name="filter_bulan" id="filter_bulan" class="form-control form-control-sm font-weight-bold text-danger">
                                <?php foreach($nama_bulan_list as $num => $nama): ?>
                                    <option value="<?= $num ?>" <?= $num == $bulan_pilihan ? 'selected' : '' ?>><?= $nama ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="small font-weight-bold">Tahun Target</label>
                            <select name="filter_tahun" id="filter_tahun" class="form-control form-control-sm font-weight-bold text-danger">
                                <?php for($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == $tahun_pilihan ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-sm-3"><label class="small font-weight-bold">Mulai Rentang Tanggal</label><input type="date" name="tgl_awal" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl_awal) ?>"></div>
                        <div class="col-sm-3"><label class="small font-weight-bold">Sampai Rentang Tanggal</label><input type="date" name="tgl_akhir" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl_akhir) ?>"></div>
                        <div class="col-sm-2"><button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold"><i class="fas fa-filter"></i> Saring Data</button></div>
                    
                        <!-- SHORTCUT PERIODE AKUMULASI MASE -->
                        <div class="col-md-12 mt-2">
                            <span class="small font-weight-bold mr-2 text-secondary">Shortcut Akumulasi:</span>
                            <a href="?filter_bulan=<?= $bulan_pilihan ?>&filter_tahun=<?= $tahun_pilihan ?>&tgl_awal=<?= date('Y-m-d') ?>&tgl_akhir=<?= date('Y-m-d') ?>" class="btn btn-xs btn-outline-secondary border shadow-sm px-2 font-weight-bold mr-1">Hari Ini</a>
                            <a href="?filter_bulan=<?= $bulan_pilihan ?>&filter_tahun=<?= $tahun_pilihan ?>&tgl_awal=<?= date('Y-m-d', strtotime('-7 days')) ?>&tgl_akhir=<?= date('Y-m-d') ?>" class="btn btn-xs btn-outline-secondary border shadow-sm px-2 font-weight-bold mr-1">7 Hari Terakhir</a>
                            <a href="?filter_bulan=<?= $bulan_pilihan ?>&filter_tahun=<?= $tahun_pilihan ?>&tgl_awal=<?= date("$tahun_pilihan-".sprintf('%02d',$bulan_pilihan)."-01") ?>&tgl_akhir=<?= date("$tahun_pilihan-".sprintf('%02d',$bulan_pilihan)."-t") ?>" class="btn btn-xs btn-outline-secondary border shadow-sm px-2 font-weight-bold">Bulan Terpilih</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- KOTAK WIDGET METRIK ATAS -->
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-danger">
                        <p class="metric-value text-danger"><?= number_format($total_reject_pcs_accum, 0, ',', '.') ?> <span style="font-size:12px;">Pcs</span></p>
                        <p class="metric-label">Total Afkir Batangan</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-warning">
                        <p class="metric-value text-warning"><?= number_format($total_reject_m3_accum, 3, ',', '.') ?> <span style="font-size:12px;">m³</span></p>
                        <p class="metric-label">Kubikasi Loss Reject</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-success">
                        <p class="metric-value text-success"><?= number_format($total_bongkar_baik_m3_accum, 3, ',', '.') ?> <span style="font-size:12px;">m³</span></p>
                        <p class="metric-label">Bongkar Hasil Baik</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-info">
                        <p class="metric-value text-info"><?= number_format($total_sample_lab, 3, ',', '.') ?> <span style="font-size:12px;">m³</span></p>
                        <p class="metric-label">Uji Sampel Lab Lapangan</p>
                    </div>
                </div>
            </div>

            <!-- SECTION VISUALISASI CHART UTAMA -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header"><h3 class="card-title text-primary"><i class="fas fa-chart-pie mr-1"></i> Urutan Hukum Pareto Jenis Kerusakan Produk (Pcs)</h3></div>
                        <div class="card-body"><div style="position: relative; height: 280px; width: 100%;"><canvas id="chartParetoDefect"></canvas></div></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-success card-outline shadow-sm">
                        <div class="card-header"><h3 class="card-title text-success"><i class="fas fa-users mr-1"></i> Rasio Total Cacat Per Regu Kerja (Pcs)</h3></div>
                        <div class="card-body"><div style="position: relative; height: 280px; width: 100%;"><canvas id="chartReguDonut"></canvas></div></div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="card card-danger card-outline shadow-sm">
                        <div class="card-header"><h3 class="card-title text-danger"><i class="fas fa-chart-bar mr-1"></i> Tren Fluktuasi Harian Jumlah Batangan Reject (Pcs)</h3></div>
                        <div class="card-body"><div style="position: relative; height: 220px; width: 100%;"><canvas id="chartRejectDetail"></canvas></div></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card card-warning card-outline shadow-sm">
                        <div class="card-header"><h3 class="card-title text-warning"><i class="fas fa-chart-line mr-1"></i> Tren Volume Pengujian Sampel Lab (m³)</h3></div>
                        <div class="card-body"><div style="position: relative; height: 220px; width: 100%;"><canvas id="chartObservasiDetail"></canvas></div></div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark card-outline shadow-sm">
                        <div class="card-header bg-dark"><h3 class="card-title text-white"><i class="fas fa-cube mr-1"></i> Grafik Keseimbangan Yield Output Massa Kubikasi (m³)</h3></div>
                        <div class="card-body"><div style="position: relative; height: 260px; width: 100%;"><canvas id="chartTrenVolumeM3"></canvas></div></div>
                    </div>
                </div>
            </div>

            <!-- AUTOMATED ANALYSIS SECTION -->
            <div class="card card-teal card-outline shadow-sm mb-3">
                <div class="card-header bg-light"><h3 class="card-title text-teal"><i class="fas fa-brain mr-1"></i> Automated Quality Analysis Review Lini 2</h3></div>
                <div class="card-body analysis-text">
                    <div class="row">
                        <div class="col-md-6 border-right">
                            <h5><i class="fas fa-exclamation-triangle text-warning mr-1"></i> <b>Fokus Utama Pengendalian Mutu (Pareto Law)</b></h5>
                            <p>Berdasarkan saringan data periode ini, jenis cacat tertinggi yang mendominasi lantai pabrik adalah <b><span class="text-danger"><?= htmlspecialchars($top_defect_name) ?></span></b> dengan akumulasi kerusakan mencapai <b><?= number_format($top_defect_value) ?> Pcs</b>.</p>
                            <p><i class="fas fa-lightbulb text-success mr-1"></i> <b>Rekomendasi Tindakan:</b> 
                            <?php if($top_defect_name === 'Lengket'): ?>
                                Pastikan oli *mold release agent* disemprot merata pada plat cetakan, serta evaluasi kestabilan suhu ruang pre-curing agar adonan tidak terlalu lembek saat dibelah.
                            <?php else: ?>
                                Lakukan kalibrasi berkala pada jepitan clamp crane pembongkar autoclave dan pastikan tim handling menyusun material blok di atas palet secara perlahan.
                            <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <h5><i class="fas fa-chart-line text-info mr-1"></i> <b>Performa Massa &amp; Kerja Regu</b></h5>
                            <p>From total volume adonan block casting terproses sebesar <b><?= number_format($total_hp_m3_accum, 3, ',', '.') ?> m³</b>, yield hasil baik mencapai <b><?= number_format($total_bongkar_baik_m3_accum, 3, ',', '.') ?> m³</b> dengan rasio *loss reject* kubikasi sebesar <b><?= $total_hp_m3_accum > 0 ? number_format(($total_reject_m3_accum / $total_hp_m3_accum) * 100, 2) : 0 ?>%</b>.</p>
                            <p>Anomali kegagalan batangan tertinggi disumbang oleh <b>Regu <?= htmlspecialchars($top_regu_name) ?></b> dengan total afkir sebanyak <b><?= number_format($top_regu_value) ?> Pcs</b>. Segera laksanakan rapat evaluasi teknis khusus untuk tim shift tersebut.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- LOG HISTORY TABLE -->
            <div class="card card-outline card-dark shadow-sm">
                <div class="card-header bg-light"><h3 class="card-title text-dark"><i class="fas fa-list mr-1"></i> Log Histori Pengawasan Mutu &amp; Hasil Bongkar (Detail Cacat Lini 2)</h3></div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table id="tbQc" class="table table-hover table-bordered table-striped w-100 text-nowrap">
                            <thead>
                                <tr>
                                    <th rowspan="2">Tanggal</th><th rowspan="2">Shift</th><th rowspan="2">Regu</th><th rowspan="2">Ukuran / Kategori</th>
                                    <th colspan="4" class="bg-primary text-white">Volume Kubikasi (m³)</th>
                                    <th colspan="13" class="bg-danger text-white">Detail Defect Batangan (Pcs)</th>
                                </tr>
                                <tr>
                                    <th>HP (m³)</th><th>HB (m³)</th><th>Sampel (m³)</th><th>Reject (m³)</th>
                                    <th>Gompal</th><th>Pecah</th><th>Lengket</th><th>Pendek</th><th>Dimensi</th><th>CCR</th>
                                    <th>T.Terseset</th><th>C.Permukaan</th><th>T.Separating</th><th>Not Cut</th><th>Gelombang</th><th>WTM</th><th>Linemark</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($raw_data as $r): ?>
                                    <tr>
                                        <td class="font-weight-bold text-center"><?= date('d-m-Y', strtotime($r['tanggal'])) ?></td>
                                        <td class="text-center"><?= $r['shift'] ?></td>
                                        <td class="text-center font-weight-bold text-primary"><?= htmlspecialchars($r['regu'] ?? '') ?></td>
                                        <td class="text-center"><span class="badge badge-secondary"><?= htmlspecialchars($r['ukuran'] ?? '-') ?></span></td>
                                        
                                        <td class="text-right"><?= number_format((float)($r['hp_m3'] ?? 0), 3, ',', '.') ?></td>
                                        <td class="text-right text-success font-weight-bold"><?= number_format((float)($r['hb_m3'] ?? 0), 3, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((float)($r['sampel_m3'] ?? 0), 3, ',', '.') ?></td>
                                        <td class="text-right text-danger font-weight-bold"><?= number_format((float)($r['total_reject_m3'] ?? 0), 3, ',', '.') ?></td>
                                        
                                        <td class="text-right"><?= number_format((int)($r['gompal'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((int)($r['pecah'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right font-weight-bold text-danger"><?= number_format((int)($r['lengket'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((int)($r['pendek'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((int)($r['dimensi'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((int)($r['ccr'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((int)($r['tidak_terseset'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((int)($r['cacat_permukaan'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((int)($r['tidak_terseparating'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right font-weight-bold text-warning"><?= number_format((int)($r['not_cut'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((int)($r['gelombang'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((int)($r['wtm'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format((int)($r['linemark'] ?? 0), 0, ',', '.') ?></td>
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

<?php require_once 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    $('#tbQc').DataTable({ "paging": true, "searching": true, "ordering": false, "info": true, "pageLength": 10 });

    // Koreksi rentang tanggal dinamis otomatis saat dropdown bulan / tahun diganti mase
    $('#filter_bulan, #filter_tahun').on('change', function() {
        var b = $('#filter_bulan').val();
        var t = $('#filter_tahun').val();
        var formatBulan = ("0" + b).slice(-2);
        $('input[name="tgl_awal"]').val(t + '-' + formatBulan + '-01');
        var lastDay = new Date(t, b, 0).getDate();
        $('input[name="tgl_akhir"]').val(t + '-' + formatBulan + '-' + lastDay);
    });

    var labels = <?= json_encode($arr_labels) ?>;
    
    // CHART 1: TREN FLUKTUASI HARIAN DEFECT
    new Chart(document.getElementById('chartRejectDetail').getContext('2d'), {
        type: 'bar',
        data: { labels: labels, datasets: [{ label: 'Total Cacat (Pcs)', data: <?= json_encode($data_sortir) ?>, backgroundColor: '#dc3545' }] },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // CHART 2: OBSERVASI SAMPEL LAB
    new Chart(document.getElementById('chartObservasiDetail').getContext('2d'), {
        type: 'line',
        data: { labels: labels, datasets: [{ label: 'Sampel Lab (m³)', data: <?= json_encode($data_obs_a1) ?>, borderColor: '#ffc107', backgroundColor: 'transparent', borderWidth: 2, tension: 0.1 }] },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // CHART 3: DIAGRAM PARETO UTAMA
    var rawDefects = <?= json_encode($arr_defects) ?>;
    var sortedKeys = Object.keys(rawDefects).sort((a,b) => rawDefects[b] - rawDefects[a]);
    var sortedValues = sortedKeys.map(k => rawDefects[k]);

    var totalSum = sortedValues.reduce((a, b) => a + b, 0);
    var cumulativeSum = 0;
    var cumulativePercentages = sortedValues.map(v => {
        cumulativeSum += v;
        return totalSum > 0 ? ((cumulativeSum / totalSum) * 100).toFixed(2) : 0;
    });

    new Chart(document.getElementById('chartParetoDefect').getContext('2d'), {
        type: 'bar',
        data: {
            labels: sortedKeys,
            datasets: [
                { type: 'bar', label: 'Jumlah Defect (Pcs)', data: sortedValues, backgroundColor: '#007bff', yAxisID: 'y' },
                { type: 'line', label: 'Persentase Akumulasi (%)', data: cumulativePercentages, borderColor: '#ffc107', backgroundColor: 'transparent', borderWidth: 2, tension: 0.1, yAxisID: 'y1' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { type: 'linear', position: 'left', beginAtZero: true },
                y1: { type: 'linear', position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false } }
            }
        }
    });

    // CHART 4: REGU DONUT
    new Chart(document.getElementById('chartReguDonut').getContext('2d'), {
        type: 'doughnut',
        data: { labels: ['Regu N1', 'Regu N2', 'Regu N3', 'Regu N4'], datasets: [{ data: [<?= (int)$regu_data['N1'] ?>, <?= (int)$regu_data['N2'] ?>, <?= (int)$regu_data['N3'] ?>, <?= (int)$regu_data['N4'] ?>], backgroundColor: ['#007bff', '#dc3545', '#ffc107', '#28a745'] }] },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // CHART 5: YIELD KUBIKASI M3
    new Chart(document.getElementById('chartTrenVolumeM3').getContext('2d'), {
        type: 'line',
        data: { labels: labels, datasets: [
            { label: 'Hasil Produksi / HP (m³)', data: <?= json_encode($tren_hp_m3) ?>, borderColor: '#007bff', fill: false, tension: 0.1 },
            { label: 'Bongkar Baik / HB (m³)', data: <?= json_encode($tren_hb_m3) ?>, borderColor: '#28a745', fill: false, tension: 0.1 },
            { label: 'Total Loss Reject (m³)', data: <?= json_encode($tren_rej_m3) ?>, borderColor: '#dc3545', fill: false, tension: 0.1 }
        ]},
        options: { responsive: true, maintainAspectRatio: false }
    });

    // AJAX SYNC BUTTON - DINAMIS MENGIKUTI DROPDOWN BULAN & TAHUN
    $('#btnSyncBongkar').on('click', function() {
        var btn = $(this); var icon = $('#iconSyncBongkar');
        btn.prop('disabled', true).addClass('disabled'); icon.addClass('fa-spin');
        
        var targetBulan = $('#filter_bulan').val();
        var targetTahun = $('#filter_tahun').val();

        Swal.fire({ 
            title: 'Memproses Sinkronisasi', 
            text: 'Menghubungkan ke lokal server 192.168.10.14 untuk menarik data Laporan Bongkar bulan ke-' + targetBulan + ' tahun ' + targetTahun, 
            allowOutsideClick: false, 
            didOpen: () => { Swal.showLoading(); } 
        });
        
        $.ajax({
            url: 'cron/sync_bongkar_aac2.php?bulan=' + targetBulan + '&tahun=' + targetTahun, // Tembak dinamis mase!
            type: 'GET',
            success: function(response) {
                btn.prop('disabled', false).removeClass('disabled'); icon.removeClass('fa-spin'); Swal.close();
                if(response.includes('Sukses')) {
                    Swal.fire({ icon: 'success', title: 'Sinkronisasi Berhasil!', text: response }).then(() => { window.location.reload(); });
                } else {
                    Swal.fire({ icon: 'warning', title: 'Hasil Log Sinkronisasi', text: response });
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).removeClass('disabled'); icon.removeClass('fa-spin'); Swal.close();
                Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Terjadi kegagalan membaca response: ' + error });
            }
        });
    });
});
</script>
