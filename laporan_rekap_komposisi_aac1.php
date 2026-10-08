<?php
// laporan_rekap_komposisi_aac1.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';

requireAccess(['Admin IT', 'Admin Produksi AAC1', 'Kabag Produksi', 'Admin QMS']);

// Ambil bulan & tahun aktif untuk sync (default bulan & tahun ini mase)
$bulan_pilihan = isset($_GET['filter_bulan']) ? (int)$_GET['filter_bulan'] : (int)date('m');
$tahun_pilihan = isset($_GET['filter_tahun']) ? (int)$_GET['filter_tahun'] : (int)date('Y');

// Sinkronisasi rentang filter tanggal agar otomatis mengikuti bulan & tahun yang dipilih
$tgl_awal  = isset($_GET['tgl_awal'])  ? $_GET['tgl_awal']  : date("$tahun_pilihan-$bulan_pilihan-01");
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date("$tahun_pilihan-$bulan_pilihan-t");

require_once 'includes/header.php';

// Ambil SEMUA kolom yang dibutuhkan dari database
$stmt = $pdo->prepare("
    SELECT tanggal, shift, regu,
           plc_target_casting_m3, brc_hasil_casting_m3,
           brc_alu_mepco, brc_alu_platinum, brc_alu_zouping, brc_alu_focoun,
           brc_kapur_granul, brc_kapur_silo10, brc_kapur_silo14, brc_kapur_kg,
           brc_semen_kg, brc_sprint_pro, brc_pasir_silica, brc_gypsum_kg,
           total_pasir_kg, total_gypsum_kg, total_slurry_rekap,
           total_alu_mepco, total_alu_platinum, total_alu_zouping, total_aluminium_kg,
           total_kapur_granul, total_kapur_kg, total_semen_kg,
           slurry_logistik, return_logistik, air_liter, waste_mortar
    FROM db_hasil_produksi_plc.tb_produksi_plc_citicon
    WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir
    ORDER BY tanggal DESC, shift ASC
");
$stmt->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- KALKULASI AGREGASI UNTUK METRIK & CHART ---
$t_pasir = 0; $t_semen = 0; $t_kapur = 0; $t_gypsum = 0; $t_alu = 0;
$arr_dates = []; $arr_chart_semen = []; $arr_chart_kapur = []; $arr_chart_alu = [];
$last_date = ''; $date_idx = -1;

foreach ($raw_data as $row) {
    $row_semen  = (float)$row['total_semen_kg'];
    $row_kapur  = (float)$row['total_kapur_kg'];
    $row_gypsum = (float)$row['total_gypsum_kg'];
    $row_alu    = (float)$row['total_aluminium_kg'];
    $row_pasir  = (float)$row['total_pasir_kg'];

    $t_pasir  += $row_pasir;
    $t_semen  += $row_semen;
    $t_kapur  += $row_kapur;
    $t_gypsum += $row_gypsum;
    $t_alu    += $row_alu;

    $tgl_fmt = date('d/m', strtotime($row['tanggal']));
    if ($tgl_fmt != $last_date) {
        $date_idx++;
        $arr_dates[$date_idx]       = $tgl_fmt;
        $arr_chart_semen[$date_idx] = 0;
        $arr_chart_kapur[$date_idx] = 0;
        $arr_chart_alu[$date_idx]   = 0;
        $last_date = $tgl_fmt;
    }
    $arr_chart_semen[$date_idx] += $row_semen;
    $arr_chart_kapur[$date_idx] += $row_kapur;
    $arr_chart_alu[$date_idx]   += $row_alu;
}

$nama_bulan_list = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli',    8 => 'Agustus',  9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
    .table-responsive { overflow-x: auto; }
    .card-title { font-weight: 600; font-size: 14px; }
    .metric-value { font-size: 1.6rem; font-weight: bold; margin-bottom: 0; }
    .metric-label { font-size: 0.8rem; color: #6c757d; text-transform: uppercase; font-weight: 700; }

    #tbKomposisi1 thead th { font-size: 10px; text-align: center; vertical-align: middle !important; font-weight: 700; padding: 5px 3px; border: 1px solid #c0c0c0 !important; }
    #tbKomposisi1 tbody td { font-size: 11px; vertical-align: middle !important; padding: 5px 6px; border: 1px solid #dcdcdc !important; }

    .bg-excel-top      { background-color: #d9e1f2 !important; color: black !important; font-size: 12px !important; letter-spacing: 1px; }
    .bg-excel-header   { background-color: #e2efda !important; color: black !important; }
    .bg-excel-kuning   { background-color: #fff2cc !important; color: black !important; }
    .bg-excel-logistik { background-color: #f2f2f2 !important; color: black !important; }
    .btn-orange { background-color: #fd7e14; color: white; border-color: #fd7e14; }
    .btn-orange:hover { background-color: #e06907; color: white; }
    .val-zero { color: #bbb !important; }
</style>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">
                        <i class="fas fa-flask mr-2 text-success"></i>Rekap Komposisi Bahan Baku Master Lini 1
                    </h1>
                </div>
                <div class="col-sm-6 text-right">
                    <a href="laporan_produksi_plc.php" class="btn btn-secondary btn-sm font-weight-bold shadow-sm">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
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
                        <div class="col-sm-3"><label class="small font-weight-bold">Mulai Rentang Tanggal</label><input type="date" name="tgl_awal" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl_awal) ?>"></div>
                        <div class="col-sm-3"><label class="small font-weight-bold">Sampai Rentang Tanggal</label><input type="date" name="tgl_akhir" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl_akhir) ?>"></div>
                        <div class="col-sm-1">
                            <button type="submit" class="btn btn-success btn-sm btn-block font-weight-bold shadow-sm">
                                <i class="fas fa-filter"></i> Saring
                            </button>
                        </div>
                        <div class="col-sm-1">
                            <button type="button" id="btnSyncBahanBaku1" class="btn btn-orange btn-sm btn-block font-weight-bold shadow-sm">
                                <i class="fas fa-sync-alt" id="iconSync"></i> Sync
                            </button>
                        </div>

                        <!-- SHORTCUT PERIODE AKUMULASI MASE -->
                        <div class="col-md-12 mt-2">
                            <span class="small font-weight-bold mr-2 text-secondary">Shortcut Periode:</span>
                            <a href="?filter_bulan=<?= $bulan_pilihan ?>&filter_tahun=<?= $tahun_pilihan ?>&tgl_awal=<?= date('Y-m-d') ?>&tgl_akhir=<?= date('Y-m-d') ?>" class="btn btn-xs btn-outline-secondary border shadow-sm px-2 font-weight-bold mr-1">Hari Ini</a>
                            <a href="?filter_bulan=<?= $bulan_pilihan ?>&filter_tahun=<?= $tahun_pilihan ?>&tgl_awal=<?= date('Y-m-d', strtotime('-7 days')) ?>&tgl_akhir=<?= date('Y-m-d') ?>" class="btn btn-xs btn-outline-secondary border shadow-sm px-2 font-weight-bold mr-1">7 Hari Terakhir</a>
                            <a href="?filter_bulan=<?= $bulan_pilihan ?>&filter_tahun=<?= $tahun_pilihan ?>&tgl_awal=<?= date("$tahun_pilihan-".sprintf('%02d',$bulan_pilihan)."-01") ?>&tgl_akhir=<?= date("$tahun_pilihan-".sprintf('%02d',$bulan_pilihan)."-t") ?>" class="btn btn-xs btn-outline-secondary border shadow-sm px-2 font-weight-bold">Bulan Terpilih</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- METRIK KARTU -->
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-primary">
                        <p class="metric-value text-primary"><?= number_format($t_pasir / 1000, 2, ',', '.') ?> <span style="font-size:12px;">Ton</span></p>
                        <p class="metric-label">Total Pasir Silika</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-success">
                        <p class="metric-value text-success"><?= number_format($t_semen / 1000, 2, ',', '.') ?> <span style="font-size:12px;">Ton</span></p>
                        <p class="metric-label">Total Semen OPC</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-warning">
                        <p class="metric-value text-warning"><?= number_format($t_kapur / 1000, 2, ',', '.') ?> <span style="font-size:12px;">Ton</span></p>
                        <p class="metric-label">Total Kapur Powder</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left" style="border-color:#fd7e14!important">
                        <p class="metric-value" style="color:#fd7e14"><?= number_format($t_alu, 2, ',', '.') ?> <span style="font-size:12px;">Kg</span></p>
                        <p class="metric-label">Total Aluminium</p>
                    </div>
                </div>
            </div>

            <!-- CHART TRIO -->
            <div class="row">
                <div class="col-md-4">
                    <div class="card card-outline card-success shadow-sm mb-3">
                        <div class="card-body"><div style="position:relative;height:160px;"><canvas id="cPie1"></canvas></div></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-outline card-success shadow-sm mb-3">
                        <div class="card-body"><div style="position:relative;height:160px;"><canvas id="cBar1"></canvas></div></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-outline card-success shadow-sm mb-3">
                        <div class="card-body"><div style="position:relative;height:160px;"><canvas id="cLine1"></canvas></div></div>
                    </div>
                </div>
            </div>

            <!-- TABEL MATRIKS -->
            <div class="card card-success card-outline shadow-sm">
                <div class="card-header bg-light">
                    <h3 class="card-title text-success">
                        <i class="fas fa-table mr-1"></i> Log Histori Matriks Komposisi Horizontal Sesuai Sheet Excel Lini 1
                    </h3>
                </div>
                <div class="card-body p-1">
                    <div class="table-responsive">
                        <table id="tbKomposisi1" class="table table-hover table-bordered w-100 text-nowrap">
                            <thead>
                                <tr>
                                    <th colspan="17" class="bg-excel-top text-center font-weight-bold">TIMBANGAN SEKTOR AWAL (KIRI)</th>
                                    <th colspan="10" class="bg-excel-kuning text-center font-weight-bold text-dark">REKAP KOMPOSISI (TENGAH)</th>
                                    <th colspan="4"  class="bg-excel-logistik text-center font-weight-bold text-dark">TIMBANGAN LOGISTIK LOG (KANAN)</th>
                                </tr>
                                <tr>
                                    <th rowspan="2" class="bg-excel-header align-middle">TANGGAL</th>
                                    <th rowspan="2" class="bg-excel-header align-middle">SHIFT</th>
                                    <th rowspan="2" class="bg-excel-header align-middle">REGU</th>
                                    <th rowspan="2" class="bg-excel-header align-middle">PRODUK</th>
                                    <th rowspan="2" class="bg-excel-header align-middle border-right">CASTING</th>
                                    <th colspan="4" class="bg-primary text-white text-center">ALUMINIUM</th>
                                    <th rowspan="2" class="bg-excel-header align-middle">GRANUL</th>
                                    <th colspan="3" class="bg-primary text-white text-center">POWDER</th>
                                    <th rowspan="2" class="bg-excel-header align-middle">SEMEN</th>
                                    <th rowspan="2" class="bg-excel-header align-middle">SPRINT PRO</th>
                                    <th rowspan="2" class="bg-excel-header align-middle">SLURRY</th>
                                    <th rowspan="2" class="bg-excel-header align-middle border-right">GYPSUM</th>

                                    <th class="bg-excel-kuning text-dark">PASIR</th>
                                    <th class="bg-excel-kuning text-dark">GYPSUM</th>
                                    <th class="bg-excel-kuning text-dark">SLURRY</th>
                                    <th colspan="4" class="bg-excel-kuning text-center text-dark">ALUMINIUM</th>
                                    <th colspan="2" class="bg-excel-kuning text-center text-dark">KAPUR</th>
                                    <th class="bg-excel-kuning text-dark border-right">SEMEN</th>

                                    <th class="bg-excel-logistik text-dark">SLURRY</th>
                                    <th class="bg-excel-logistik text-dark">RETURN</th>
                                    <th class="bg-excel-logistik text-dark">AIR</th>
                                    <th class="bg-excel-logistik text-dark">WASTE MORTAR</th>
                                </tr>
                                <tr>
                                    <th class="bg-excel-header">MEPCO</th>
                                    <th class="bg-excel-header">PLATINUM</th>
                                    <th class="bg-excel-header">ZOUPING</th>
                                    <th class="bg-excel-header text-danger">FOCOUN</th>
                                    <th class="bg-excel-header">SILO 10</th>
                                    <th class="bg-excel-header">SILO 14</th>
                                    <th class="bg-excel-header">TOTAL</th>

                                    <th class="bg-excel-kuning text-muted">REKAP</th>
                                    <th class="bg-excel-kuning text-muted">REKAP</th>
                                    <th class="bg-excel-kuning text-muted">REKAP</th>
                                    <th class="bg-excel-kuning text-muted">MEPCO</th>
                                    <th class="bg-excel-kuning text-muted">PLATINUM</th>
                                    <th class="bg-excel-kuning text-muted">ZOUPING</th>
                                    <th class="bg-excel-kuning text-danger font-weight-bold">FOCOUN</th>
                                    <th class="bg-excel-kuning text-muted">GRANUL</th>
                                    <th class="bg-excel-kuning text-muted">POWDER</th>
                                    <th class="bg-excel-kuning text-success font-weight-bold border-right">SEMEN</th>

                                    <th class="bg-excel-logistik text-muted">LOG</th>
                                    <th class="bg-excel-logistik text-muted">LOG</th>
                                    <th class="bg-excel-logistik text-info font-weight-bold">LITER</th>
                                    <th class="bg-excel-logistik text-muted">LOG</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($raw_data as $r):
                                    $fmt = function($val, $dec = 2, $showZeroGray = true) {
                                        $v = (float)$val;
                                        $str = number_format($v, $dec, ',', '.');
                                        if ($showZeroGray && $v == 0) {
                                            $zeros = str_repeat('0', $dec);
                                            return '<span class="val-zero">0,' . $zeros . '</span>';
                                        }
                                        return $str;
                                    };
                                    $fmt0 = function($val, $showZeroGray = true) use ($fmt) {
                                        return $fmt($val, 0, $showZeroGray);
                                    };

                                    $produk = (float)$r['brc_semen_kg'] > 0 ? 'BRC' : ((float)$r['total_pasir_kg'] > 0 ? 'PLC' : '-');
                                ?>
                                <tr>
                                    <td class="font-weight-bold text-center"><?= date('d-m-Y', strtotime($r['tanggal'])) ?></td>
                                    <td class="text-center"><span class="badge badge-light border shadow-sm px-2"><?= htmlspecialchars($r['shift']) ?></span></td>
                                    <td class="text-center font-weight-bold text-success"><?= htmlspecialchars($r['regu'] ?? '-') ?></td>
                                    <td class="text-center"><?= $produk ?></td>
                                    <td class="text-center font-weight-bold text-primary border-right"><?= $fmt0($r['brc_hasil_casting_m3']) ?></td>

                                    <td class="text-right"><?= $fmt($r['brc_alu_mepco'],   2) ?></td>
                                    <td class="text-right"><?= $fmt($r['brc_alu_platinum'], 2) ?></td>
                                    <td class="text-right"><?= $fmt($r['brc_alu_zouping'],  2) ?></td>
                                    <td class="text-right text-danger font-weight-bold"><?= $fmt($r['brc_alu_focoun'], 2) ?></td>

                                    <td class="text-right"><?= $fmt0($r['brc_kapur_granul']) ?></td>

                                    <td class="text-right"><?= $fmt0($r['brc_kapur_silo10']) ?></td>
                                    <td class="text-right"><?= $fmt0($r['brc_kapur_silo14']) ?></td>
                                    <td class="text-right font-weight-bold"><?= $fmt0($r['brc_kapur_kg']) ?></td>

                                    <td class="text-right"><?= $fmt0($r['brc_semen_kg']) ?></td>
                                    <td class="text-right"><?= $fmt($r['brc_sprint_pro'], 2) ?></td>
                                    <td class="text-right font-weight-bold text-dark"><?= $fmt0($r['brc_pasir_silica']) ?></td>
                                    <td class="text-right text-secondary border-right"><?= $fmt0($r['brc_gypsum_kg']) ?></td>

                                    <td class="text-right bg-excel-kuning font-weight-bold text-primary"><?= $fmt($r['total_pasir_kg'],     2) ?></td>
                                    <td class="text-right bg-excel-kuning font-weight-bold"><?=      $fmt($r['total_gypsum_kg'],    2) ?></td>
                                    <td class="text-right bg-excel-kuning"><?=                               $fmt($r['total_slurry_rekap'], 2) ?></td>
                                    <td class="text-right bg-excel-kuning text-muted"><?=                    $fmt($r['total_alu_mepco'],    3) ?></td>
                                    <td class="text-right bg-excel-kuning text-muted"><?=                    $fmt($r['total_alu_platinum'], 3) ?></td>
                                    <td class="text-right bg-excel-kuning text-muted"><?=                    $fmt($r['total_alu_zouping'],  3) ?></td>
                                    <td class="text-right bg-excel-kuning text-danger font-weight-bold"><?=  $fmt($r['total_aluminium_kg'], 3) ?></td>
                                    <td class="text-right bg-excel-kuning text-muted"><?=                    $fmt($r['total_kapur_granul'], 3) ?></td>
                                    <td class="text-right bg-excel-kuning font-weight-bold"><?=              $fmt($r['total_kapur_kg'],     3) ?></td>
                                    <td class="text-right bg-excel-kuning font-weight-bold text-success border-right"><?= $fmt($r['total_semen_kg'], 3) ?></td>

                                    <td class="text-right bg-light"><?= $fmt0($r['slurry_logistik']) ?></td>
                                    <td class="text-right bg-light"><?= $fmt0($r['return_logistik']) ?></td>
                                    <td class="text-right text-info font-weight-bold bg-light"><?= $fmt0($r['air_liter']) ?></td>
                                    <td class="text-right text-muted bg-light"><?= $fmt0($r['waste_mortar']) ?></td>
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
$(document).ready(function () {

    $('#tbKomposisi1').DataTable({
        paging: true, searching: true, ordering: false, info: true, pageLength: 10,
        language: { search: "Cari:", lengthMenu: "Tampilkan _MENU_ baris", info: "Menampilkan _START_–_END_ dari _TOTAL_ baris", paginate: { previous: "‹", next: "›" } }
    });

    // Otomatisasi tanggal filter mengikuti pilihan dropdown mase
    $('#filter_bulan, #filter_tahun').on('change', function() {
        var b = $('#filter_bulan').val();
        var t = $('#filter_tahun').val();
        var formatBulan = ("0" + b).slice(-2);
        $('input[name="tgl_awal"]').val(t + '-' + formatBulan + '-01');
        var lastDay = new Date(t, b, 0).getDate();
        $('input[name="tgl_akhir"]').val(t + '-' + formatBulan + '-' + lastDay);
    });

    // Pie: proporsi bahan kering
    new Chart(document.getElementById('cPie1').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Pasir', 'Semen', 'Kapur', 'Gypsum'],
            datasets: [{ data: [<?= $t_pasir ?>, <?= $t_semen ?>, <?= $t_kapur ?>, <?= $t_gypsum ?>], backgroundColor: ['#007bff', '#28a745', '#ffc107', '#6c757d'] }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } } } }
    });

    // Bar: semen vs kapur per hari
    new Chart(document.getElementById('cBar1').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_values($arr_dates)) ?>,
            datasets: [
                { label: 'Semen (kg)', data: <?= json_encode(array_values($arr_chart_semen)) ?>, backgroundColor: '#28a745' },
                { label: 'Kapur (kg)', data: <?= json_encode(array_values($arr_chart_kapur)) ?>, backgroundColor: '#ffc107' }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { font: { size: 10 } } } } }
    });

    // Line: aluminium per hari
    new Chart(document.getElementById('cLine1').getContext('2d'), {
        type: 'line',
        data: {
            labels: <?= json_encode(array_values($arr_dates)) ?>,
            datasets: [{ label: 'Alu (Kg)', data: <?= json_encode(array_values($arr_chart_alu)) ?>, borderColor: '#fd7e14', tension: 0.2, fill: false }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { font: { size: 10 } } } } }
    });

    // Tombol sync dinamis mengikuti pilihan dropdown mase
    $('#btnSyncBahanBaku1').on('click', function () {
        var btn = $(this), icon = $('#iconSync');
        btn.prop('disabled', true);
        icon.addClass('fa-spin');

        var targetBulan = $('#filter_bulan').val();
        var targetTahun = $('#filter_tahun').val();

        Swal.fire({
            title: 'Menyadap Timbangan Lini 1...',
            text: 'Menghubungkan ke server jaringan 192.168.30.38 untuk menarik rekap Komposisi bulan ke-' + targetBulan,
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        $.ajax({
            url: 'cron/sync_rekapkomposisiaac1.php?bulan=' + targetBulan + '&tahun=' + targetTahun, // Tembak dinamis!
            type: 'GET',
            success: function (response) {
                btn.prop('disabled', false); icon.removeClass('fa-spin'); Swal.close();
                if (response.includes('Sukses')) {
                    Swal.fire({ icon: 'success', title: 'Sukses!', text: response })
                        .then(() => { window.location.reload(); });
                } else {
                    Swal.fire({ icon: 'warning', title: 'Gagal', text: response });
                }
            },
            error: function () {
                btn.prop('disabled', false); icon.removeClass('fa-spin'); Swal.close();
                Swal.fire({ icon: 'error', title: 'Error!', text: 'Tidak bisa menghubungi server sync.' });
            }
        });
    });
});
</script>
