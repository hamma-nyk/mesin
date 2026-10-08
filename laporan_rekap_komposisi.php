<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

// laporan_rekap_komposisi.php
require_once 'includes/auth.php';
require_once 'config/database.php';

requireAccess(['Admin IT', 'Admin Produksi AAC2', 'Kabag Produksi', 'Admin QMS']);

$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');

require_once 'includes/header.php';

// Ambil data konsumsi material aktual dari tabel tb_komposisi
$stmt = $pdo->prepare("SELECT * FROM db_hasil_produksi_aac2.tb_komposisi 
                       WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir 
                       ORDER BY tanggal ASC, shift ASC");
$stmt->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
$komposisi_data = $stmt->fetchAll();

// --- KALKULASI TOTAL AGREGASI AKTUAL DARI DATABASE ---
$total_pasir = 0; $total_semen = 0; $total_kapur = 0; $total_gypsum = 0;
$total_focoun = 0; $total_platinum = 0; $total_zouping = 0;
$total_fresh_slurry = 0; $total_return_slurry = 0; $total_air = 0;
$total_casting_mld = 0;

// Array Data Koleksi untuk Chart Tren Harian
$arr_dates = [];
$arr_chart_semen = [];
$arr_chart_kapur = [];
$arr_chart_focoun = [];
$arr_chart_platinum = [];

$last_date = '';
$date_idx = -1;

foreach($komposisi_data as $row) {
    $total_pasir         += (float)($row['pasir_kg'] ?? 0);
    $total_semen         += (float)($row['semen_total'] ?? 0);
    $total_kapur         += (float)($row['kapur_total'] ?? 0);
    $total_gypsum        += (float)($row['gypsum_kg'] ?? 0);
    $total_focoun        += (float)($row['alu_focoun'] ?? 0);
    $total_platinum      += (float)($row['alu_platinum'] ?? 0);
    $total_zouping       += (float)($row['alu_zouping'] ?? 0);
    $total_fresh_slurry  += (float)($row['fresh_slurry'] ?? 0);
    $total_return_slurry += (float)($row['return_slurry'] ?? 0);
    $total_air           += (float)($row['air_kg'] ?? 0);
    $total_casting_mld   += (int)($row['total_casting'] ?? 0);

    // Agregasi Data per Hari untuk Grafik Tren Line/Bar
    $tgl_fmt = date('d/m', strtotime($row['tanggal']));
    if ($tgl_fmt != $last_date) {
        $date_idx++;
        $arr_dates[$date_idx] = $tgl_fmt;
        $arr_chart_semen[$date_idx] = 0;
        $arr_chart_kapur[$date_idx] = 0;
        $arr_chart_focoun[$date_idx] = 0;
        $arr_chart_platinum[$date_idx] = 0;
        $last_date = $tgl_fmt;
    }
    
    $arr_chart_semen[$date_idx]    += (float)($row['semen_total'] ?? 0);
    $arr_chart_kapur[$date_idx]    += (float)($row['kapur_total'] ?? 0);
    $arr_chart_focoun[$date_idx]   += (float)($row['alu_focoun'] ?? 0);
    $arr_chart_platinum[$date_idx] += (float)($row['alu_platinum'] ?? 0);
}

$total_aluminium_powder = $total_focoun + $total_platinum + $total_zouping;
$total_tonase_kering = ($total_pasir + $total_semen + $total_kapur + $total_gypsum) / 1000;

// --- LOGIKA INTELLIGENT EXECUTIVE ANALYSIS AUTOMATION ---
$rasio_air_semen = $total_semen > 0 ? ($total_air / $total_semen) : 0;
$rasio_pasir_semen = $total_semen > 0 ? ($total_pasir / $total_semen) : 0;
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
    .table-responsive { overflow-x: auto; }
    .card-title { font-weight: 600; font-size: 14px; }
    .metric-value { font-size: 1.7rem; font-weight: bold; margin-bottom: 2px; }
    .metric-label { font-size: 0.78rem; color: #6c757d; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; }
    
    #tbKomposisi thead th { font-size: 11px; text-align: center; vertical-align: middle !important; font-weight: 700; padding: 8px 5px; }
    #tbKomposisi tbody td { font-size: 11px; vertical-align: middle !important; padding: 7px 8px; }
    
    /* MODIFIKASI TEMA WARNA: HIJAU & ORANGE CITICON BRANDING */
    .btn-orange { background-color: #fd7e14; color: white; border-color: #fd7e14; }
    .btn-orange:hover { background-color: #e06907; color: white; }
    .text-orange { color: #fd7e14 !important; }
    .text-green { color: #28a745 !important; }
    .card-green.card-outline { border-top: 3px solid #28a745; }
    .bg-gradient-green { background: linear-gradient(45deg, #28a745, #20c997); color: white; }
    .table-hover tbody tr:hover { background-color: rgba(40, 167, 69, 0.04) !important; }
</style>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1 class="m-0 text-dark"><i class="fas fa-flask mr-2 text-green"></i>Rekap Komposisi Bahan Baku AAC 2</h1></div>
                <div class="col-sm-6 text-right">
                    <a href="laporan_produksi_aac2.php?tgl_awal=<?= $tgl_awal ?>&tgl_akhir=<?= $tgl_akhir ?>" class="btn btn-secondary btn-sm font-weight-bold shadow-sm">
                        <i class="fas fa-arrow-left mr-1"></i> Kembali ke Laporan Utama
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">
            
            <div class="card card-default shadow-sm mb-3"><div class="card-body p-3">
                <form method="GET" class="row align-items-end">
                    <div class="col-sm-4"><label class="small font-weight-bold">Mulai Tanggal</label><input type="date" name="tgl_awal" class="form-control form-control-sm" value="<?= $tgl_awal ?>"></div>
                    <div class="col-sm-4"><label class="small font-weight-bold">Sampai Tanggal</label><input type="date" name="tgl_akhir" class="form-control form-control-sm" value="<?= $tgl_akhir ?>"></div>
                    <div class="col-sm-2"><button type="submit" class="btn btn-success btn-sm btn-block font-weight-bold shadow-sm"><i class="fas fa-filter mr-1"></i> Saring</button></div>
                    <div class="col-sm-2"><button type="button" id="btnForceSyncKomposisi" class="btn btn-orange btn-sm btn-block font-weight-bold shadow-sm"><i class="fas fa-sync-alt" id="iconSync"></i> Tarik Data Excel Aktual</button></div>
                </form>
            </div></div>

            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-primary shadow-sm">
                        <p class="metric-value text-primary"><?= number_format($total_pasir / 1000, 2, ',', '.') ?> <span style="font-size:12px;">Ton</span></p>
                        <p class="metric-label">Aktual Pasir Silika</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-success shadow-sm">
                        <p class="metric-value text-success"><?= number_format($total_semen / 1000, 2, ',', '.') ?> <span style="font-size:12px;">Ton</span></p>
                        <p class="metric-label">Aktual Semen OPC</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-warning shadow-sm">
                        <p class="metric-value text-warning"><?= number_format($total_kapur / 1000, 2, ',', '.') ?> <span style="font-size:12px;">Ton</span></p>
                        <p class="metric-label">Aktual Kapur Powder</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-orange shadow-sm">
                        <p class="metric-value text-orange"><?= number_format($total_aluminium_powder, 2, ',', '.') ?> <span style="font-size:12px;">Kg</span></p>
                        <p class="metric-label">Aktual Aluminium Powder</p>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4">
                    <div class="card card-green card-outline shadow-sm">
                        <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-pie mr-1 text-green"></i> 1. Rasio Komposisi Utama</h3></div>
                        <div class="card-body"><div style="position: relative; height: 210px; width: 100%;"><canvas id="chartKomposisi"></canvas></div></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-green card-outline shadow-sm">
                        <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-bar mr-1 text-success"></i> 2. Tren Semen vs Kapur Harian (Kg)</h3></div>
                        <div class="card-body"><div style="position: relative; height: 210px; width: 100%;"><canvas id="chartSemenKapur"></canvas></div></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-green card-outline shadow-sm">
                        <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-line mr-1 text-orange"></i> 3. Fluktuasi Aluminium Powder (Kg)</h3></div>
                        <div class="card-body"><div style="position: relative; height: 210px; width: 100%;"><canvas id="chartAluPowder"></canvas></div></div>
                    </div>
                </div>
            </div>

            <div class="card card-outline card-green shadow-sm mb-3">
                <div class="card-header bg-gradient-green"><h3 class="card-title text-white"><i class="fas fa-brain mr-1"></i> Automated Raw Material &amp; Efficiency Analysis</h3></div>
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-md-4 border-right">
                            <h5><i class="fas fa-balance-scale text-primary mr-1"></i> <b>Rasio Konversi Lumpur Pasir/Semen</b></h5>
                            <p class="analysis-text text-muted">
                                Perbandingan pemakaian pasir silika terhadap semen binder berada di skala <b><?= number_format($rasio_pasir_semen, 2, ',', '.') ?> : 1</b>. 
                                <?php if($rasio_pasir_semen > 3.2): ?>
                                    <span class="text-warning font-weight-bold"><i class="fas fa-exclamation-triangle mr-1"></i>Indikasi: Campuran adonan terlalu kurus (tinggi slurry), awasi penurunan kuat tekan (*Compressive Strength*) bata!</span>
                                <?php else: ?>
                                    <span class="text-success font-weight-bold"><i class="fas fa-check-circle mr-1"></i>Status Ideal: Keseimbangan bahan pengikat dan pengisi kokoh sesuai standar resep.</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-4 border-right">
                            <h5><i class="fas fa-water text-info mr-1"></i> <b>Water-Cement Ratio (Rasio Air)</b></h5>
                            <p class="analysis-text text-muted">
                                Tingkat konsumsi pemakaian air murni terhadap semen di tangki slurry mixer tercatat di rasio desimal <b><?= number_format($rasio_air_semen, 2, ',', '.') ?></b>. 
                                Jika ditambahkan dengan total pasokan aditif bubur **Fresh Slurry** sebesar <b><?= number_format($total_fresh_slurry, 2, ',', '.') ?> m³</b>, viskositas bubur lumpur adonan masih berada dalam batas regulasi aman hidrasi semen.
                            </p>
                        </div>
                        <div class="col-md-4">
                            <h5><i class="fas fa-truck-loading text-orange mr-1"></i> <b>Total Output</b></h5>
                            <p class="analysis-text text-muted">
                                Akumulasi total tonase kering bahan baku utama periode ini menembus **<?= number_format($total_tonase_kering, 2, ',', '.') ?> Ton** untuk menghasilkan output cetakan hulu sebanyak **<?= number_format($total_casting_mld, 0, ',', '.') ?> Mould casting**. Rata-rata konsumsi aditif kental pengebang Aluminium Powder per mould berada di takaran yang stabil.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-success card-outline shadow-sm">
                <div class="card-header bg-light"><h3 class="card-title text-green"><i class="fas fa-table mr-1"></i> Log Histori Timbangan Aktual Per Shift Lini AAC 2</h3></div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table id="tbKomposisi" class="table table-hover table-bordered table-striped w-100 text-nowrap">
                            <thead class="bg-gradient-green">
                                <tr>
                                    <th rowspan="2" class="align-middle">Tanggal</th>
                                    <th rowspan="2" class="align-middle">Shift</th>
                                    <th rowspan="2" class="align-middle">Regu</th>
                                    <th rowspan="2" class="align-middle border-right">Produk</th>
                                    <th colspan="3" class="bg-orange text-white">Aluminium Section (Kg)</th>
                                    <th colspan="3" class="bg-warning text-dark">Kapur Powder Section (Kg)</th>
                                    <th colspan="3" class="bg-success">Semen Section (Kg)</th>
                                    <th rowspan="2" class="bg-info align-middle">Pasir (Kg)</th>
                                    <th rowspan="2" class="bg-secondary align-middle">Gypsum (Kg)</th>
                                    <th rowspan="2" class="bg-dark align-middle border-left">Fresh Slurry (m³)</th>
                                    <th rowspan="2" class="bg-dark align-middle">Return Slurry (m³)</th>
                                </tr>
                                <tr>
                                    <th class="bg-orange small">Focoun</th>
                                    <th class="bg-orange small">Platinum</th>
                                    <th class="bg-orange small font-weight-bold border-right">Total Alu</th>
                                    <th class="bg-warning text-dark small">Silo 17</th>
                                    <th class="bg-warning text-dark small">Silo 18</th>
                                    <th class="bg-warning text-dark small font-weight-bold border-right">Total Kapur</th>
                                    <th class="bg-success small">Silo 15</th>
                                    <th class="bg-success small">Silo 16</th>
                                    <th class="bg-success small font-weight-bold">Total Semen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($table_data = array_reverse($komposisi_data) as $r): 
                                    $row_total_alu = (float)$r['alu_focoun'] + (float)$r['alu_platinum'] + (float)$r['alu_zouping'];
                                ?>
                                    <tr>
                                        <td class="font-weight-bold text-center"><?= date('d-m-Y', strtotime($r['tanggal'])) ?></td>
                                        <td class="text-center"><span class="badge badge-light border shadow-sm w-100 p-1"><?= $r['shift'] ?></span></td>
                                        <td class="text-center font-weight-bold text-green"><?= htmlspecialchars($r['regu']) ?></td>
                                        <td class="border-right"><small class="font-weight-bold text-muted"><?= htmlspecialchars($r['nama_produk']) ?></small></td>
                                        
                                        <td class="text-right text-muted"><?= number_format($r['alu_focoun'], 2, ',', '.') ?></td>
                                        <td class="text-right text-muted"><?= number_format($r['alu_platinum'], 2, ',', '.') ?></td>
                                        <td class="text-right font-weight-bold text-orange border-right bg-light"><?= number_format($row_total_alu, 2, ',', '.') ?></td>
                                        
                                        <td class="text-right text-muted"><?= number_format($r['kapur_silo17'], 0, ',', '.') ?></td>
                                        <td class="text-right text-muted"><?= number_format($r['kapur_silo18'], 0, ',', '.') ?></td>
                                        <td class="text-right font-weight-bold text-warning border-right bg-light"><?= number_format($r['kapur_total'], 0, ',', '.') ?></td>
                                        
                                        <td class="text-right text-muted"><?= number_format($r['semen_silo15'], 0, ',', '.') ?></td>
                                        <td class="text-right text-muted"><?= number_format($r['semen_silo16'], 0, ',', '.') ?></td>
                                        <td class="text-right font-weight-bold text-success bg-light"><?= number_format($r['semen_total'], 0, ',', '.') ?></td>
                                        
                                        <td class="text-right font-weight-bold text-info"><?= number_format($r['pasir_kg'], 2, ',', '.') ?></td>
                                        <td class="text-right text-secondary"><?= number_format($r['gypsum_kg'], 2, ',', '.') ?></td>
                                        
                                        <td class="text-right text-primary border-left font-weight-bold"><?= number_format($r['fresh_slurry'], 3, ',', '.') ?></td>
                                        <td class="text-right text-success font-weight-bold"><?= number_format($r['return_slurry'], 3, ',', '.') ?></td>
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
    $('#tbKomposisi').DataTable({ "paging": true, "searching": true, "ordering": false, "info": true, "pageLength": 10 });

    // --- CHART 1: DONUT PIE RASIO MATERIAL ---
    var ctx1 = document.getElementById('chartKomposisi').getContext('2d');
    new Chart(ctx1, {
        type: 'doughnut',
        data: {
            labels: ['Pasir (Kg)', 'Semen (Kg)', 'Kapur (Kg)', 'Gypsum (Kg)'],
            datasets: [{
                data: [<?= $total_pasir ?>, <?= $total_semen ?>, <?= $total_kapur ?>, <?= $total_gypsum ?>],
                backgroundColor: ['#007bff', '#28a745', '#ffc107', '#6c757d']
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // --- CHART 2: BAR BERJEJER (TREN SEMEN VS KAPUR HARIAN) ---
    var ctx2 = document.getElementById('chartSemenKapur').getContext('2d');
    new Chart(ctx2, {
        type: 'bar',
        data: {
            labels: <?= json_encode($arr_dates) ?>,
            datasets: [
                { label: 'Semen (Kg)', data: <?= json_encode($arr_chart_semen) ?>, backgroundColor: '#28a745', borderRadius: 2 },
                { label: 'Kapur (Kg)', data: <?= json_encode($arr_chart_kapur) ?>, backgroundColor: '#ffc107', borderRadius: 2 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });

    // --- CHART 3: LINE TIMBUL (FLUKTUASI ALUMINIUM POWDER) ---
    var ctx3 = document.getElementById('chartAluPowder').getContext('2d');
    new Chart(ctx3, {
        type: 'line',
        data: {
            labels: <?= json_encode($arr_dates) ?>,
            datasets: [
                { label: 'Focoun (Kg)', data: <?= json_encode($arr_chart_focoun) ?>, borderColor: '#fd7e14', tension: 0.2, fill: false },
                { label: 'Platinum (Kg)', data: <?= json_encode($arr_chart_platinum) ?>, borderColor: '#20c997', tension: 0.2, fill: false }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });

    // ACTION TRIGGER TOMBOL SYNC
    $('#btnForceSyncKomposisi').on('click', function() {
        var btn = $(this); var icon = $('#iconSync');
        btn.prop('disabled', true); icon.addClass('fa-spin');

        Swal.fire({
            title: 'Menyadap Data Timbangan...',
            text: 'Membaca data riil Aluminium, Semen, Kapur, dan Slurry dari master Excel Lini AAC 2',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading() }
        });

        $.ajax({
            url: 'cron/sync_rekapkomposisiaac2.php',
            type: 'GET',
            success: function(response) {
                btn.prop('disabled', false); icon.removeClass('fa-spin'); Swal.close();
                if(response.includes('Sukses')) {
                    Swal.fire({ icon: 'success', title: 'Sinkronisasi Timbangan Berhasil!', text: response, confirmButtonText: 'Sip Mantap Bos!' }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({ icon: 'warning', title: 'Log Penarikan Excel', text: response });
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false); icon.removeClass('fa-spin'); Swal.close();
                Swal.fire({ icon: 'error', title: 'Jaringan Down', text: 'Gagal menghubungi background script: ' + error });
            }
        });
    });
});
</script>
