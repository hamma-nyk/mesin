<?php
// laporan_produksi_plc.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';

requireAccess(['Admin IT', 'Admin Produksi AAC1', 'Kabag Produksi', 'Admin QMS']);

// Ambil bulan & tahun aktif untuk filter
$bulan_pilihan = isset($_GET['filter_bulan']) ? (int)$_GET['filter_bulan'] : (int)date('m');
$tahun_pilihan = isset($_GET['filter_tahun']) ? (int)$_GET['filter_tahun'] : (int)date('Y');

// Sinkronisasi rentang filter tanggal
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date("$tahun_pilihan-$bulan_pilihan-01");
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date("$tahun_pilihan-$bulan_pilihan-t");

// =========================================================================
// INTERSEPTOR AJAX MODAL POPUP DETAIL PER SHIFT (image_c0d867.png)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_daily_detail' && isset($_GET['tanggal'])) {
    $tgl_target = $_GET['tanggal'];
    
    $stmtDetail = $pdo->prepare("SELECT * FROM db_hasil_produksi_plc.tb_laporan_produksi_harian 
                                 WHERE tanggal = :tanggal ORDER BY CASE shift WHEN 'Shift 3' THEN 1 WHEN 'Shift 1' THEN 2 WHEN 'Shift 2' THEN 3 END ASC");
    $stmtDetail->execute([':tanggal' => $tgl_target]);
    $shifts_data = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

    $matrix = [
        'Shift 3' => ['regu'=>'-', 'brc_hasil'=>0, 'plc_hasil'=>0, 'packing'=>0, 'a1'=>0, 'a2'=>0, 'forklift'=>0, 'kendala'=>'-'],
        'Shift 1' => ['regu'=>'-', 'brc_hasil'=>0, 'plc_hasil'=>0, 'packing'=>0, 'a1'=>0, 'a2'=>0, 'forklift'=>0, 'kendala'=>'-'],
        'Shift 2' => ['regu'=>'-', 'brc_hasil'=>0, 'plc_hasil'=>0, 'packing'=>0, 'a1'=>0, 'a2'=>0, 'forklift'=>0, 'kendala'=>'-']
    ];

    $total_brc_target_day = 0;
    $total_plc_target_day = 0;
    $total_hasil_day = 0;

    foreach ($shifts_data as $sd) {
        $s_name = $sd['shift'];
        if (isset($matrix[$s_name])) {
            $matrix[$s_name]['regu']      = $sd['regu'] ?? '-';
            $matrix[$s_name]['brc_hasil'] = (float)($sd['brc_hasil_casting_m3'] ?? 0);
            $matrix[$s_name]['plc_hasil'] = (float)($sd['plc_hasil_casting_m3'] ?? 0);
            $matrix[$s_name]['packing']   = (int)($sd['total_packing_brc_mould'] ?? 0);
            $matrix[$s_name]['a1']        = (int)($sd['produk_baik_a1_pallet'] ?? 0);
            $matrix[$s_name]['a2']        = (int)($sd['produk_baik_a2_pallet'] ?? 0);
            $matrix[$s_name]['forklift']  = (int)($sd['jumlah_forklift'] ?? 0);
            
            $parts = explode('|', $sd['kendala_produksi'] ?? '-');
            $matrix[$s_name]['kendala']   = isset($parts[2]) ? trim($parts[2]) : (isset($parts[0]) ? trim($parts[0]) : '-');

            $total_brc_target_day += (float)($sd['brc_target_casting_m3'] ?? 0);
            $total_plc_target_day += (float)($sd['plc_target_casting_m3'] ?? 0);
            $total_hasil_day      += $matrix[$s_name]['brc_hasil'] + $matrix[$s_name]['plc_hasil'];
        }
    }
    ?>
    <div class="row mb-3">
        <div class="col-6">
            <small class="font-weight-bold text-muted">Target BRC Harian: <b><?= number_format($total_brc_target_day, 2, ',', '.') ?> m³</b></small><br>
            <small class="font-weight-bold text-muted">Target PLC Harian: <b><?= number_format($total_plc_target_day, 2, ',', '.') ?> m³</b></small>
        </div>
        <div class="col-6 text-right">
            <small class="font-weight-bold text-muted">Akumulasi Capaian Hari Ini:</small>
            <h4 class="text-success font-weight-bold mb-0"><?= number_format($total_hasil_day, 2, ',', '.') ?> m³</h4>
        </div>
    </div>
    
    <table class="table table-bordered table-striped text-center small w-100">
        <thead class="bg-secondary text-white">
            <tr>
                <th>Parameter Kerja Lini 1</th>
                <th>Shift 3 (Malam)</th>
                <th>Shift 1 (Pagi)</th>
                <th>Shift 2 (Siang)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="font-weight-bold text-left">Varian Regu Kerja</td>
                <td><span class="badge badge-dark"><?= htmlspecialchars($matrix['Shift 3']['regu']) ?></span></td>
                <td><span class="badge badge-dark"><?= htmlspecialchars($matrix['Shift 1']['regu']) ?></span></td>
                <td><span class="badge badge-dark"><?= htmlspecialchars($matrix['Shift 2']['regu']) ?></span></td>
            </tr>
            <tr>
                <td class="font-weight-bold text-left">Hasil Casting BRC (m³)</td>
                <td><?= number_format($matrix['Shift 3']['brc_hasil'], 2, ',', '.') ?></td>
                <td><?= number_format($matrix['Shift 1']['brc_hasil'], 2, ',', '.') ?></td>
                <td><?= number_format($matrix['Shift 2']['brc_hasil'], 2, ',', '.') ?></td>
            </tr>
            <tr class="text-success font-weight-bold">
                <td class="text-left">Hasil Casting PLC Harian (m³)</td>
                <td><?= number_format($matrix['Shift 3']['plc_hasil'], 2, ',', '.') ?></td>
                <td><?= number_format($matrix['Shift 1']['plc_hasil'], 2, ',', '.') ?></td>
                <td><?= number_format($matrix['Shift 2']['plc_hasil'], 2, ',', '.') ?></td>
            </tr>
            <tr class="bg-light">
                <td class="font-weight-bold text-left">Total Packing BRC (Mould)</td>
                <td><?= number_format($matrix['Shift 3']['packing']) ?></td>
                <td><?= number_format($matrix['Shift 1']['packing']) ?></td>
                <td><?= number_format($matrix['Shift 2']['packing']) ?></td>
            </tr>
            <tr class="bg-light">
                <td class="font-weight-bold text-left">Produk Baik A1 (Pallet)</td>
                <td><?= number_format($matrix['Shift 3']['a1']) ?></td>
                <td><?= number_format($matrix['Shift 1']['a1']) ?></td>
                <td><?= number_format($matrix['Shift 2']['a1']) ?></td>
            </tr>
            <tr class="bg-light">
                <td class="font-weight-bold text-left">Hold (Pallet)</td>
                <td><?= number_format($matrix['Shift 3']['a2']) ?></td>
                <td><?= number_format($matrix['Shift 1']['a2']) ?></td>
                <td><?= number_format($matrix['Shift 2']['a2']) ?></td>
            </tr>
        </tbody>
    </table>
    
    <div class="card p-2 mt-2 border shadow-sm">
        <h6 class="small font-weight-bold text-danger mb-1"><i class="fas fa-tools mr-1"></i> Catatan Kendala Kerja Tiap Shift:</h6>
        <ul class="list-unstyled pl-2 small mb-0">
            <li><b>Shift 3:</b> <span class="text-muted"><?= htmlspecialchars($matrix['Shift 3']['kendala']) ?></span></li>
            <li><b>Shift 1:</b> <span class="text-muted"><?= htmlspecialchars($matrix['Shift 1']['kendala']) ?></span></li>
            <li><b>Shift 2:</b> <span class="text-muted"><?= htmlspecialchars($matrix['Shift 2']['kendala']) ?></span></li>
        </ul>
    </div>
    <?php
    exit;
}

// =========================================================================
// CRON AUTO-SYNC HARIAN
// =========================================================================
$jam_sekarang = (int)date('H');
$hari_ini     = date('Y-m-d');

if ($jam_sekarang >= 10) {
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM db_hasil_produksi_plc.tb_laporan_produksi_harian WHERE tanggal = :hari_ini");
    $stmtCheck->execute([':hari_ini' => $hari_ini]);
    $sudah_sync = $stmtCheck->fetchColumn();

    if ($sudah_sync == 0) {
        ob_start();
        $_GET['bulan'] = date('m', strtotime($hari_ini));
        $_GET['tahun'] = date('Y', strtotime($hari_ini));
        include __DIR__ . '/cron/sync_plc.php'; 
        ob_end_clean();
    }
}

// 1. QUERY HARIAN GLOBAL (GROUP BY TANGGAL)
$stmtMainTable = $pdo->prepare("
    SELECT tanggal,
           SUM(brc_target_casting_m3 + plc_target_casting_m3) as total_target_hari,
           SUM(brc_hasil_casting_m3 + plc_hasil_casting_m3) as total_mixing_hari,
           SUM(total_packing_brc_mould) as total_packing_mould,
           SUM(produk_baik_a1_pallet) as total_a1_pallet,
           SUM(produk_baik_a2_pallet) as total_a2_pallet,
           SUM(jumlah_forklift) as total_forklift_unit
    FROM db_hasil_produksi_plc.tb_laporan_produksi_harian
    WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir
    GROUP BY tanggal ORDER BY tanggal DESC
");
$stmtMainTable->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
$rows_harian_global = $stmtMainTable->fetchAll(PDO::FETCH_ASSOC);

// Ambil rekap data mentah full shift murni untuk kalkulasi widget box atas mase
$stmtBox = $pdo->prepare("SELECT * FROM db_hasil_produksi_plc.tb_laporan_produksi_harian WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir");
$stmtBox->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
$all_shifts_raw = $stmtBox->fetchAll(PDO::FETCH_ASSOC);

$total_brc_m3 = 0; $total_plc_m3 = 0; $total_pallet_a1 = 0; $total_forklift = 0;
$total_reject_cake = 0; $total_target_all = 0;

foreach($all_shifts_raw as $row) {
    $total_brc_m3      += (float)($row['brc_hasil_casting_m3'] ?? 0);
    $total_plc_m3      += (float)($row['plc_hasil_casting_m3'] ?? 0);
    $total_pallet_a1   += (int)($row['produk_baik_a1_pallet'] ?? 0);
    $total_forklift    += (int)($row['jumlah_forklift'] ?? 0);
    $total_reject_cake += (float)($row['reject_cake_m3'] ?? 0);
    $total_target_all  += (float)($row['brc_target_casting_m3'] ?? 0) + (float)($row['plc_target_casting_m3'] ?? 0);
}

$grand_total_m3 = $total_brc_m3 + $total_plc_m3;
$rasio_brc = $grand_total_m3 > 0 ? ($total_brc_m3 / $grand_total_m3) * 100 : 0;
$rasio_plc = $grand_total_m3 > 0 ? ($total_plc_m3 / $grand_total_m3) * 100 : 0;
$reject_rate = $total_brc_m3 > 0 ? ($total_reject_cake / $total_brc_m3) * 100 : 0;

// 2. DATA CHART TREN HARIAN
$arr_labels = []; $data_trend_brc = []; $data_trend_plc = []; $brc_target_line = []; $plc_target_line = [];
$stmtChart = $pdo->prepare("SELECT tanggal, SUM(brc_hasil_casting_m3) as t_brc, SUM(plc_hasil_casting_m3) as t_plc, SUM(brc_target_casting_m3) as tg_brc, SUM(plc_target_casting_m3) as tg_plc FROM db_hasil_produksi_plc.tb_laporan_produksi_harian WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir GROUP BY tanggal ORDER BY tanggal ASC");
$stmtChart->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
foreach($stmtChart->fetchAll(PDO::FETCH_ASSOC) as $cr) {
    $arr_labels[] = date('d/m', strtotime($cr['tanggal']));
    $data_trend_brc[] = (float)$cr['t_brc']; $data_trend_plc[] = (float)$cr['t_plc'];
    $brc_target_line[] = (float)$cr['tg_brc']; $plc_target_line[] = (float)$cr['tg_plc'];
}

$nama_bulan_list = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli',    8 => 'Agustus',  9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>

<?php require_once 'includes/header.php'; ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
    .card-title { font-weight: 600; font-size: 14px; }
    .metric-value { font-size: 1.5rem; font-weight: bold; margin-bottom: 0; }
    .metric-label { font-size: 0.82rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }
    th { font-size: 11px; text-align: center; vertical-align: middle !important; }
    td { font-size: 11px; vertical-align: middle !important; }
    .text-green { color: #28a745 !important; }
    .card-green.card-outline { border-top: 3px solid #28a745; }
    .bg-gradient-green { background: linear-gradient(45deg, #28a745, #20c997); color: white; }
</style>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1 class="m-0 text-dark"><i class="fas fa-chart-pie mr-2 text-green"></i>Dashboard Konsolidasi Lini 1 (BRC &amp; PLC)</h1></div>
                <div class="col-sm-6 text-right">
                    <button type="button" id="btnSyncPlc" class="btn btn-success btn-sm font-weight-bold mr-1 shadow-sm"><i class="fas fa-sync-alt" id="iconSyncPlc"></i> Tarik Excel Lini 1</button>
                    <a href="laporan_rekap_komposisi_aac1.php" class="btn btn-orange btn-sm font-weight-bold shadow-sm"><i class="fas fa-flask"></i> Rekap Komposisi</a>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">
            
            <!-- PANEL FILTER TIMELINE -->
            <div class="card card-default shadow-sm mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-2">
                            <label class="small font-weight-bold">Bulan Target Tarik</label>
                            <select name="filter_bulan" id="filter_bulan" class="form-control form-control-sm font-weight-bold text-green">
                                <?php foreach($nama_bulan_list as $num => $nama): ?>
                                    <option value="<?= $num ?>" <?= $num == $bulan_pilihan ? 'selected' : '' ?>><?= $nama ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="small font-weight-bold">Tahun Target</label>
                            <select name="filter_tahun" id="filter_tahun" class="form-control form-control-sm font-weight-bold text-green">
                                <?php for($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == $tahun_pilihan ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-sm-3"><label class="small font-weight-bold">Mulai Tanggal</label><input type="date" name="tgl_awal" class="form-control form-control-sm" value="<?= e($tgl_awal) ?>"></div>
                        <div class="col-sm-3"><label class="small font-weight-bold">Sampai Tanggal</label><input type="date" name="tgl_akhir" class="form-control form-control-sm" value="<?= e($tgl_akhir) ?>"></div>
                        <div class="col-sm-2"><button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold shadow-sm"><i class="fas fa-filter mr-1"></i> Saring Data</button></div>
                    </form>
                </div>
            </div>

            <!-- CARD WIDGET STATISTIK UTAMA -->
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 border-left border-primary shadow-sm"><p class="metric-value text-primary"><?= number_format($total_brc_m3, 2, ',', '.') ?> <span style="font-size:11px;">m³</span></p><p class="metric-label">Hasil Casting BRC</p></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 border-left border-success shadow-sm"><p class="metric-value text-success"><?= number_format($total_plc_m3, 2, ',', '.') ?> <span style="font-size:11px;">m³</span></p><p class="metric-label">Hasil Casting PLC</p></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 border-left border-info shadow-sm"><p class="metric-value text-info"><?= number_format($total_pallet_a1, 0, ',', '.') ?> <span style="font-size:11px;">Plt</span></p><p class="metric-label">Produk Baik A1</p></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 border-left border-warning shadow-sm"><p class="metric-value text-warning"><?= number_format($total_forklift, 0, ',', '.') ?> <span style="font-size:11px;">Unit</span></p><p class="metric-label">Total Running Forklift</p></div>
                </div>
            </div>

            <!-- GRAFIK VISUAL CHART PANEL -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card card-green card-outline shadow-sm"><div class="card-header"><h3 class="card-title text-green"><i class="fas fa-chart-bar mr-1"></i> Grafik Realisasi Harian vs Batas Target Produksi (m³)</h3></div><div class="card-body"><div style="position: relative; height: 240px; width: 100%;"><canvas id="chartTrenPlc"></canvas></div></div></div>
                </div>
                <div class="col-md-4">
                    <div class="card card-green card-outline shadow-sm"><div class="card-header"><h3 class="card-title text-green"><i class="fas fa-chart-pie mr-1"></i> Komparasi Output Lini (BRC vs PLC)</h3></div><div class="card-body"><div style="position: relative; height: 240px; width: 100%;"><canvas id="chartPiePlc"></canvas></div></div></div>
                </div>
            </div>

            <!-- TABEL LOG DATA MATRIX HARIAN -->
            <div class="card card-success card-outline shadow-sm">
                <div class="card-header bg-light"><h3 class="card-title text-green"><i class="fas fa-table mr-1"></i> Matriks Rekap Log Harian Hasil Produksi Lini 1</h3></div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table id="tbPlcMain" class="table table-hover table-bordered w-100 text-center text-nowrap small">
                            <thead class="bg-light">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Target Hari (m³)</th>
                                    <th>Total Mixing / Casting (m³)</th>
                                    <th>Total Packing (Mould)</th>
                                    <th>Pencapaian %</th>
                                    <th>Baik A1 (Pallet)</th>
                                    <th>Hold A2 (Pallet)</th>
                                    <th>Total Running Forklift</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($rows_harian_global as $rm): 
                                    $pct_day = $rm['total_target_hari'] > 0 ? ($rm['total_mixing_hari'] / $rm['total_target_hari']) * 100 : 0;
                                    $color_pct = $pct_day >= 95 ? 'text-success' : 'text-danger';
                                ?>
                                    <tr>
                                        <td class="font-weight-bold"><?= date('d-m-Y', strtotime($rm['tanggal'])) ?></td>
                                        <td class="text-right font-weight-bold"><?= number_format($rm['total_target_hari'], 2, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format($rm['total_mixing_hari'], 2, ',', '.') ?></td>
                                        <td class="text-right text-success font-weight-bold"><?= number_format($rm['total_packing_mould']) ?></td>
                                        <td class="font-weight-bold <?= $color_pct ?>"><?= number_format($pct_day, 2, ',', '.') ?>%</td>
                                        <td><?= number_format($rm['total_a1_pallet']) ?> Plt</td>
                                        <td><?= number_format($rm['total_a2_pallet']) ?> Plt</td>
                                        <td><?= number_format($rm['total_forklift_unit']) ?> Unit</td>
                                        <td>
                                            <button type="button" class="btn btn-info btn-xs font-weight-bold px-2 btn-load-plc-detail" data-tgl="<?= e($rm['tanggal']) ?>">
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

<!-- BOOTSTRAP MODAL DETAIL PER SHIFT -->
<div class="modal fade" id="modalDetailPlcHarian" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white p-3">
                <h5 class="modal-title font-weight-bold" id="modalTitleHeaderPlc">Rincian Hasil Produksi Lini 1</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body p-3" id="modalBodyDynamicPlc">
                <!-- Data AJAX mase -->
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

<!-- PERBAIKAN: Memasukkan CDN Chart.js tepat sebelum eksekusi script inisialisasi -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
$(document).ready(function() {
    $('#tbPlcMain').DataTable({ "paging": true, "searching": true, "ordering": false, "pageLength": 10 });

    // AJAX EVENT TRIGGER UNTUK MODAL POPUP DETAIL 3 SHIFT
    $('.btn-load-plc-detail').on('click', function() {
        var tgl = $(this).data('tgl');
        var tglFmt = new Date(tgl).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
        
        $('#modalTitleHeaderPlc').text('Rincian Hasil Produksi Lini 1 (' + tglFmt + ')');
        $('#modalBodyDynamicPlc').html('<div class="text-center my-4"><i class="fas fa-spinner fa-spin fa-2x text-success"></i><p class="small mt-2">Mengekstrak matriks parameter shift kerja...</p></div>');
        $('#modalDetailPlcHarian').modal('show');

        $.ajax({
            url: 'laporan_produksi_plc.php',
            type: 'GET',
            data: { action: 'get_daily_detail', tanggal: tgl },
            success: function(response) {
                $('#modalBodyDynamicPlc').html(response);
            },
            error: function() {
                $('#modalBodyDynamicPlc').html('<div class="alert alert-danger small">Gagal memuat rincian shift kerja harian.</div>');
            }
        });
    });

    // CODE RENDER CHART TREN DAN PIE
    new Chart(document.getElementById('chartTrenPlc').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($arr_labels) ?>,
            datasets: [
                { label: 'Target BRC', data: <?= json_encode($brc_target_line) ?>, type: 'line', borderColor: '#ff4d4d', borderWidth: 1, borderDash: [3, 3], pointRadius: 0, fill: false },
                { label: 'Target PLC', data: <?= json_encode($plc_target_line) ?>, type: 'line', borderColor: '#fd7e14', borderWidth: 1, borderDash: [3, 3], pointRadius: 0, fill: false },
                { label: 'Bata BRC Real', data: <?= json_encode($data_trend_brc) ?>, backgroundColor: '#007bff' },
                { label: 'Panel PLC Real', data: <?= json_encode($data_trend_plc) ?>, backgroundColor: '#28a745' }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    new Chart(document.getElementById('chartPiePlc').getContext('2d'), {
        type: 'doughnut',
        data: { labels: ['Bata BRC', 'Panel PLC'], datasets: [{ data: [<?= $total_brc_m3 ?>, <?= $total_plc_m3 ?>], backgroundColor: ['#007bff', '#28a745'] }] },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // AJAX SYNC EXECUTION HANDLER
    $('#btnSyncPlc').on('click', function() {
        var btn = $(this); var icon = $('#iconSyncPlc');
        btn.prop('disabled', true); icon.addClass('fa-spin');
        Swal.fire({ title: 'Menyinkronkan Berkas...', text: 'Membaca data komprehensif dari rute jaringan.', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

        // PERBAIKAN: Menambahkan parameter '&ajax=1' agar bypass validasi jam rilis cron otomatis di backend
        $.ajax({
            url: 'cron/sync_plc.php?bulan=' + $('#filter_bulan').val() + '&tahun=' + $('#filter_tahun').val() + '&ajax=1',
            type: 'GET',
            success: function(response) {
                btn.prop('disabled', false); icon.removeClass('fa-spin'); Swal.close();
                Swal.fire({ icon: 'success', title: 'Sukses!', text: response }).then(() => { window.location.reload(); });
            },
            error: function() {
                btn.prop('disabled', false); icon.removeClass('fa-spin'); Swal.close();
                Swal.fire({ icon: 'error', title: 'Gagal!', text: 'Proses sinkronisasi terputus.' });
            }
        });
    });
});
</script>
