<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';

requireAccess(['Admin IT', 'Admin Produksi Mortar', 'Kabag Produksi', 'Admin QMS']);

$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');

// =========================================================================
// LOGIKA EXPORT EXCEL GABUNGAN
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Rekap_Produksi_Mortar.xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    $stmtExp = $pdo->prepare("SELECT * FROM db_hasil_produksi_mortar.tb_harian WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir ORDER BY tanggal DESC");
    $stmtExp->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);

    echo '<table border="1">
            <tr style="background-color:#00a65a; color:white; font-weight:bold;">
                <th>Tanggal</th><th>Target Harian (Ton)</th><th>Total Packing (Ton)</th><th>Pencapaian %</th><th>Shift 1 Varian</th><th>Shift 2 Varian</th>
            </tr>';
    while($r = $stmtExp->fetch()) {
        $total_pack_hari = $r['s1_hasil_packing_ton'] + $r['s2_hasil_packing_ton'] + $r['s3_hasil_packing_ton'];
        $persen = $r['target_per_tanggal_ton'] > 0 ? ($total_pack_hari / $r['target_per_tanggal_ton']) * 100 : 0;
        echo '<tr>
                <td>'.$r['tanggal'].'</td>
                <td style="mso-number-format:\'\#\,\#\#0\.00\';">'.$r['target_per_tanggal_ton'].'</td>
                <td style="mso-number-format:\'\#\,\#\#0\.00\';">'.$total_pack_hari.'</td>
                <td style="mso-number-format:\'\#\,\#\#0\.00\';">'.$persen.'%</td>
                <td>'.htmlspecialchars($r['s1_varian'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($r['s2_varian'], ENT_QUOTES, 'UTF-8').'</td>
              </tr>';
    }
    echo '</table>';
    exit;
}

require_once 'includes/header.php';

// Ambil data historis berdasarkan rentang tanggal
$stmtData = $pdo->prepare("SELECT * FROM db_hasil_produksi_mortar.tb_harian WHERE tanggal BETWEEN :tgl_awal AND :tgl_akhir ORDER BY tanggal ASC");
$stmtData->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
$raw_data = $stmtData->fetchAll();

// --- LOGIKA ALGORITMA STATISTIK DINAMIS ---
$arr_labels = []; $arr_target = []; $arr_realisasi = [];

$total_hari_kerja = count($raw_data);
$accum_target = 0; $accum_realisasi = 0;
$total_sak_bagus = 0; $total_sak_rusak = 0;
$hari_achieved = 0;

$varian_counter = [];
$daftar_kendala = [];

foreach($raw_data as $row) {
    $arr_labels[] = date('d/m', strtotime($row['tanggal']));
    $arr_target[] = (float)$row['target_per_tanggal_ton'];
    
    $realisasi_hari = (float)($row['s1_hasil_packing_ton'] + $row['s2_hasil_packing_ton'] + $row['s3_hasil_packing_ton']);
    $arr_realisasi[] = $realisasi_hari;

    $accum_target += (float)$row['target_per_tanggal_ton'];
    $accum_realisasi += $realisasi_hari;
    
    $total_sak_bagus += ($row['s1_detail_a1_sak'] + $row['s2_detail_a1_sak'] + $row['s3_detail_a1_sak']);
    $total_sak_rusak += ($row['s1_hold_sak_rusak'] + $row['s2_hold_sak_rusak'] + $row['s3_hold_sak_rusak']);
    
    if((float)$row['target_per_tanggal_ton'] > 0 && ($realisasi_hari / (float)$row['target_per_tanggal_ton'] * 100) >= 100) {
        $hari_achieved++;
    }

    if(!empty($row['s1_varian']) && $row['s1_varian'] != '-') $varian_counter[$row['s1_varian']] = ($varian_counter[$row['s1_varian']] ?? 0) + 1;
    if(!empty($row['s2_varian']) && $row['s2_varian'] != '-') $varian_counter[$row['s2_varian']] = ($varian_counter[$row['s2_varian']] ?? 0) + 1;
    if(!empty($row['s3_varian']) && $row['s3_varian'] != 'OFF') $varian_counter[$row['s3_varian']] = ($varian_counter[$row['s3_varian']] ?? 0) + 1;

    if(!empty(trim($row['s1_kendala'])) && $row['s1_kendala'] != '-') $daftar_kendala[] = date('d/m', strtotime($row['tanggal'])).": ".$row['s1_kendala'];
    if(!empty(trim($row['s2_kendala'])) && $row['s2_kendala'] != '-') $daftar_kendala[] = date('d/m', strtotime($row['tanggal'])).": ".$row['s2_kendala'];
    if(!empty(trim($row['s3_kendala'])) && $row['s3_kendala'] != '-') $daftar_kendala[] = date('d/m', strtotime($row['tanggal'])).": ".$row['s3_kendala'];
}

$varian_dominan = "Belum Ada Data";
if(!empty($varian_counter)) {
    arsort($varian_counter);
    $varian_dominan = array_key_first($varian_counter);
}

$table_data = array_reverse($raw_data);

$global_efficiency = $accum_target > 0 ? ($accum_realisasi / $accum_target) * 100 : 0;
$rate_reject_sak = ($total_sak_bagus + $total_sak_rusak) > 0 ? ($total_sak_rusak / ($total_sak_bagus + $total_sak_rusak)) * 100 : 0;
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
    .table-responsive { overflow-x: auto; }
    .card-title { font-weight: 600; }
    .metric-value { font-size: 1.4rem; font-weight: bold; margin-bottom: 0; }
    .metric-label { font-size: 0.85rem; color: #6c757d; text-transform: uppercase; }
</style>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid"><h1>Laporan Hasil Produksi Mortar</h1></div>
    </div>

    <div class="content">
        <div class="container-fluid">
            
            <div class="card card-default mb-3">
                <div class="card-body">
                    <div class="row align-items-end">
                        
                        <div class="col-md-5 border-right pr-3">
                            <form method="GET" class="row align-items-end">
                                <div class="col-sm-5 form-group mb-0">
                                    <label class="text-sm">Mulai Tanggal</label>
                                    <input type="date" name="tgl_awal" class="form-control form-control-sm" value="<?= e($tgl_awal) ?>">
                                </div>
                                <div class="col-sm-5 form-group mb-0">
                                    <label class="text-sm">Sampai Tanggal</label>
                                    <input type="date" name="tgl_akhir" class="form-control form-control-sm" value="<?= e($tgl_akhir) ?>">
                                </div>
                                <div class="col-sm-2 p-0 form-group mb-0">
                                    <button type="submit" class="btn btn-primary btn-sm btn-block"><i class="fas fa-filter"></i> Saring</button>
                                </div>
                            </form>
                        </div>

                        <div class="col-md-7 pl-4">
                            <div class="row align-items-end justify-content-end">
                                <div class="col-sm-7 form-group mb-0">
                                    <label class="text-warning font-weight-bold text-sm mb-1"><i class="fas fa-cloud-download-alt"></i> Tarik Data Excel (Rentang Tanggal):</label>
                                    <div class="input-group input-group-sm">
                                        <input type="date" id="tglSyncAwal" class="form-control text-center" value="<?= date('Y-m-d') ?>">
                                        <div class="input-group-prepend input-group-append">
                                            <span class="input-group-text bg-light">s/d</span>
                                        </div>
                                        <input type="date" id="tglSyncAkhir" class="form-control text-center" value="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                <div class="col-sm-5 text-right form-group mb-0">
                                    <a href="?tgl_awal=<?= $tgl_awal ?>&tgl_akhir=<?= $tgl_akhir ?>&export=excel" class="btn btn-success btn-sm mr-1" title="Export tabel di bawah ke Excel">
                                        <i class="fas fa-file-excel"></i> Export Rekap
                                    </a>
                                    <button type="button" id="btnSyncManual" class="btn btn-warning btn-sm font-weight-bold text-dark">
                                        <i class="fas fa-sync-alt" id="iconSync"></i> Sinkronisasi
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-info">
                        <p class="metric-value text-info"><?= number_format($accum_realisasi, 2, ',', '.') ?> <span style="font-size:12px;">Ton</span></p>
                        <p class="metric-label">Total Volume Packing</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-success">
                        <p class="metric-value text-success"><?= number_format($global_efficiency, 2, ',', '.') ?>%</p>
                        <p class="metric-label">Efisiensi Capaian Target</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-warning">
                        <p class="metric-value text-warning"><?= $hari_achieved ?> / <?= $total_hari_kerja ?> <span style="font-size:11px;">Hari</span></p>
                        <p class="metric-label">Target Terpenuhi 100%</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card p-3 mb-3 bg-white shadow-sm border-left border-danger">
                        <p class="metric-value text-danger"><?= number_format($rate_reject_sak, 2, ',', '.') ?>%</p>
                        <p class="metric-label">Rasio Sak Rusak (Reject)</p>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-7">
                    <div class="card card-info card-outline">
                        <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-bar mr-1"></i> Pencapaian Target Produksi Packing (Ton)</h3></div>
                        <div class="card-body">
                            <div style="position: relative; height: 300px; width: 100%;"><canvas id="mortarChart"></canvas></div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-5">
                    <div class="card card-warning card-outline">
                        <div class="card-header"><h3 class="card-title"><i class="fas fa-brain mr-1"></i> Hasil Analisis Sistem & Rekomendasi</h3></div>
                        <div class="card-body" style="height: 340px; overflow-y: auto;">
                            <?php if (!empty($raw_data)): ?>
                                <h5><b>Executive Review - PT. NBI</b></h5>
                                <p class="text-justify text-sm">
                                    Berdasarkan filter periode dari tanggal <b><?= date('d/m/Y', strtotime($tgl_awal)) ?></b> s/d <b><?= date('d/m/Y', strtotime($tgl_akhir)) ?></b>, total volume packing mortar yang berhasil diproduksi adalah <b><?= number_format($accum_realisasi, 2, ',', '.') ?> Ton</b> dari total target yang ditetapkan sebesar <b><?= number_format($accum_target, 2, ',', '.') ?> Ton</b>. Tingkat efisiensi pemenuhan target berada di angka 
                                    <b class="<?= $global_efficiency >= 100 ? 'text-success' : 'text-danger' ?>"><?= number_format($global_efficiency, 2, ',', '.') ?>%</b>.
                                </p>

                                <p class="text-sm">
                                    Fokus lini mesin dominan memproduksi varian produk <span class="badge badge-primary" style="font-size:12px;"><?= e($varian_dominan) ?></span>. Total kemasan utuh yang berhasil masuk ke gudang barangan jadi berjumlah <b><?= number_format($total_sak_bagus, 0, ',', '.') ?> Sak</b>, dengan jumlah insiden kerusakan fisik kemasan (*Sak Rusak*) sebanyak <b><?= number_format($total_sak_rusak, 0, ',', '.') ?> Sak</b> (Rasio kegagalan: <b><?= number_format($rate_reject_sak, 2, ',', '.') ?>%</b>).
                                </p>
                                
                                <h6 class="font-weight-bold text-sm mb-1 text-danger"><i class="fas fa-exclamation-circle"></i> Rekaman Kompilasi Kendala Lapangan (Database):</h6>
                                <div class="bg-light p-2 rounded mb-2" style="max-height: 120px; overflow-y: auto; font-size: 11px;">
                                    <?php if(!empty($daftar_kendala)): ?>
                                        <ul class="pl-3 mb-0">
                                            <?php foreach($daftar_kendala as $knd): ?>
                                                <li class="mb-1"><?= htmlspecialchars($knd) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="text-muted">Tidak tercatat kendala kritis di database untuk periode ini.</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="callout callout-warning p-2 mt-2 mb-0 text-xs text-justify">
                                    <h6 class="font-weight-bold mb-1">Rekomendasi Tindakan Strategis:</h6>
                                    <?php if ($global_efficiency < 100): ?>
                                        <p class="mb-0 text-danger font-weight-bold">⚠️ Perhatian: Produksi di bawah target!</p>
                                        <p class="mb-1">1. Evaluasi penumpukan durasi maintenance mesin pencampur (mixer) dan bersihkan elevator di sela-sela pergantian shift kerja.</p>
                                        <p class="mb-0">2. Pastikan pasokan palet kayu dan waktu tunggu armada *Forklift* dipercepat guna menghindari *idle time* pada operator packing line.</p>
                                    <?php else: ?>
                                        <p class="mb-0 text-success font-weight-bold">✅ Kinerja Baik!</p>
                                        <p class="mb-0">Pertahankan ritme kerja *mixing* dan kontrol supply raw material agar kontinuitas *throughput* tonase pabrik tetap stabil di atas 100%.</p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted pt-5"><i class="fas fa-folder-open fa-2x"></i><br><p class="mt-2">Tidak ada data analisis yang dapat dikalkulasi.</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-primary card-outline">
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tbMortar" class="table table-hover table-bordered w-100 text-sm">
                            <thead class="bg-light text-nowrap">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Target Hari (Ton)</th>
                                    <th>Total Mixing (Ton)</th>
                                    <th>Total Packing (Ton)</th>
                                    <th>Pencapaian %</th>
                                    <th>S1 Varian</th>
                                    <th>S2 Varian</th>
                                    <th>Total Produksi Sak</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($table_data as $r): 
                                    $tot_mix = $r['s1_hasil_mixing_ton'] + $r['s2_hasil_mixing_ton'] + $r['s3_hasil_mixing_ton'];
                                    $tot_pack = $r['s1_hasil_packing_ton'] + $r['s2_hasil_packing_ton'] + $r['s3_hasil_packing_ton'];
                                    $pencapaian = $r['target_per_tanggal_ton'] > 0 ? ($tot_pack / $r['target_per_tanggal_ton']) * 100 : 0;
                                    $total_sak = $r['s1_detail_a1_sak'] + $r['s2_detail_a1_sak'] + $r['s3_detail_a1_sak'];
                                    
                                    $json_data = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr>
                                        <td class="font-weight-bold"><?= date('d-m-Y', strtotime($r['tanggal'])) ?></td>
                                        <td class="text-right font-weight-bold"><?= number_format($r['target_per_tanggal_ton'], 2, ',', '.') ?></td>
                                        <td class="text-right"><?= number_format($tot_mix, 2, ',', '.') ?></td>
                                        <td class="text-right text-success font-weight-bold"><?= number_format($tot_pack, 2, ',', '.') ?></td>
                                        <td class="text-center font-weight-bold <?= $pencapaian >= 100 ? 'text-success':'text-danger'?>"><?= number_format($pencapaian, 2, ',', '.') ?>%</td>
                                <td><?= e($r['s1_varian']) ?></td>
                                <td><?= e($r['s2_varian']) ?></td>
                                        <td><?= number_format($total_sak, 0, ',', '.') ?> Sak</td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-xs btn-detail-mortar" data-row="<?= $json_data; ?>">
                                                <i class="fas fa-eye"></i> Detail
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

<div class="modal fade" id="modalDetailMortar" tabindex="-1" role="dialog" aria-labelledby="modalDetailMortarLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitleTanggal">Rincian Hasil Produksi Mortar Utama</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0">
                
                <div class="p-3 bg-light border-bottom row m-0">
                    <div class="col-md-6">
                        Target Bulan: <b id="mdTargetBulan">-</b> Ton<br>
                        Target Harian: <b id="mdTargetHari">-</b> Ton
                    </div>
                    <div class="col-md-6 text-right">
                        Akumulasi Capaian: <h4 class="text-success font-weight-bold mb-0" id="mdTotalCapaianHari">0.00 Ton</h4>
                    </div>
                </div>

                <table class="table table-bordered table-striped m-0 text-center text-sm">
                    <thead class="bg-secondary text-white">
                        <tr>
                            <th>Parameter Kerja</th>
                            <th>Shift 3 (Malam)</th>
                            <th>Shift 1 (Pagi)</th>
                            <th>Shift 2 (Siang)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="text-left font-weight-bold">Varian Produk</td>
                            <td><span class="badge badge-info" id="mdS3Varian">-</span></td>
                            <td><span class="badge badge-primary" id="mdS1Varian">-</span></td>
                            <td><span class="badge badge-dark" id="mdS2Varian">-</span></td>
                        </tr>
                        <tr>
                            <td class="text-left font-weight-bold">Hasil Mixing (Ton)</td>
                            <td id="mdS3Mixing">0.00</td>
                            <td id="mdS1Mixing">0.00</td>
                            <td id="mdS2Mixing">0.00</td>
                        </tr>
                        <tr>
                            <td class="text-left font-weight-bold text-success">Hasil Packing (Ton)</td>
                            <td class="text-success font-weight-bold" id="mdS3Packing">0.00</td>
                            <td class="text-success font-weight-bold" id="mdS1Packing">0.00</td>
                            <td class="text-success font-weight-bold" id="mdS2Packing">0.00</td>
                        </tr>
                        <tr class="table-info">
                            <td class="text-left font-weight-bold">A1 (Sak)</td>
                            <td id="mdS3A1">0</td>
                            <td id="mdS1A1">0</td>
                            <td id="mdS2A1">0</td>
                        </tr>
                        <tr class="table-info">
                            <td class="text-left font-weight-bold">Hold (Sak)</td>
                            <td id="mdS3Hold">0</td>
                            <td id="mdS1Hold">0</td>
                            <td id="mdS2Hold">0</td>
                        </tr>
                        <tr class="table-warning">
                            <td class="text-left font-weight-bold">Hold Timbang (Sak)</td>
                            <td id="mdS3HTimbang">0</td>
                            <td id="mdS1HTimbang">0</td>
                            <td id="mdS2HTimbang">0</td>
                        </tr>
                        <tr class="table-danger">
                            <td class="text-left font-weight-bold text-danger">Sak Rusak / Reject (Sak)</td>
                            <td class="text-danger font-weight-bold" id="mdS3HRusak">0</td>
                            <td class="text-danger font-weight-bold" id="mdS1HRusak">0</td>
                            <td class="text-danger font-weight-bold" id="mdS2HRusak">0</td>
                        </tr>
                    </tbody>
                </table>

                <div class="p-3">
                    <h6 class="font-weight-bold"><i class="fas fa-tools text-danger"></i> Catatan Kendala Kerja Tiap Shift:</h6>
                    <div class="p-2 border rounded bg-light text-xs">
                        <b>Shift 3:</b> <span id="mdS3Kendala">-</span><br>
                        <b>Shift 1:</b> <span id="mdS1Kendala">-</span><br>
                        <b>Shift 2:</b> <span id="mdS2Kendala">-</span>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Tutup Detail</button>
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
$(function() {
    
    // 1. Inisialisasi DataTables
    var table = $('#tbMortar').DataTable({
        "paging": true, 
        "searching": true, 
        "ordering": false, 
        "info": true, 
        "autoWidth": false, 
        "pageLength": 10
    });

    // 2. Inisialisasi ChartJS
    var ctx = document.getElementById('mortarChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($arr_labels) ?>,
            datasets: [
                { label: 'Target Ton', data: <?= json_encode($arr_target) ?>, borderColor: '#dc3545', backgroundColor: 'transparent', type: 'line', borderWidth: 2 },
                { label: 'Realisasi Packing', data: <?= json_encode($arr_realisasi) ?>, backgroundColor: '#28a745' }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // 3. FIX MODAL Detail
    $(document).on('click', '.btn-detail-mortar', function(e) {
        e.preventDefault();
        
        var data = $(this).data('row');
        if(!data) return;
        
        var dateObj = new Date(data.tanggal);
        var formattedDate = dateObj.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });

        var totalPackTon = parseFloat(data.s1_hasil_packing_ton) + parseFloat(data.s2_hasil_packing_ton) + parseFloat(data.s3_hasil_packing_ton);

        $('#modalTitleTanggal').text('Rincian Hasil Produksi Mortar (' + formattedDate + ')');
        $('#mdTargetBulan').text(parseFloat(data.target_bulan_ton).toLocaleString('id-ID'));
        $('#mdTargetHari').text(parseFloat(data.target_per_tanggal_ton).toLocaleString('id-ID'));
        $('#mdTotalCapaianHari').text(totalPackTon.toLocaleString('id-ID', { minimumFractionDigits: 2 }) + ' Ton');

        $('#mdS3Varian').text(data.s3_varian);
        $('#mdS1Varian').text(data.s1_varian);
        $('#mdS2Varian').text(data.s2_varian);

        $('#mdS3Mixing').text(parseFloat(data.s3_hasil_mixing_ton).toLocaleString('id-ID', { minimumFractionDigits: 2 }));
        $('#mdS1Mixing').text(parseFloat(data.s1_hasil_mixing_ton).toLocaleString('id-ID', { minimumFractionDigits: 2 }));
        $('#mdS2Mixing').text(parseFloat(data.s2_hasil_mixing_ton).toLocaleString('id-ID', { minimumFractionDigits: 2 }));

        $('#mdS3Packing').text(parseFloat(data.s3_hasil_packing_ton).toLocaleString('id-ID', { minimumFractionDigits: 2 }));
        $('#mdS1Packing').text(parseFloat(data.s1_hasil_packing_ton).toLocaleString('id-ID', { minimumFractionDigits: 2 }));
        $('#mdS2Packing').text(parseFloat(data.s2_hasil_packing_ton).toLocaleString('id-ID', { minimumFractionDigits: 2 }));

        $('#mdS3A1').text(parseInt(data.s3_detail_a1_sak).toLocaleString('id-ID'));
        $('#mdS1A1').text(parseInt(data.s1_detail_a1_sak).toLocaleString('id-ID'));
        $('#mdS2A1').text(parseInt(data.s2_detail_a1_sak).toLocaleString('id-ID'));

        $('#mdS3Hold').text(parseInt(data.s3_detail_hold_sak).toLocaleString('id-ID'));
        $('#mdS1Hold').text(parseInt(data.s1_detail_hold_sak).toLocaleString('id-ID'));
        $('#mdS2Hold').text(parseInt(data.s2_detail_hold_sak).toLocaleString('id-ID'));

        $('#mdS3HTimbang').text(parseInt(data.s3_hold_timbang_sak).toLocaleString('id-ID'));
        $('#mdS1HTimbang').text(parseInt(data.s1_hold_timbang_sak).toLocaleString('id-ID'));
        $('#mdS2HTimbang').text(parseInt(data.s2_hold_timbang_sak).toLocaleString('id-ID'));

        $('#mdS3HRusak').text(parseInt(data.s3_hold_sak_rusak).toLocaleString('id-ID'));
        $('#mdS1HRusak').text(parseInt(data.s1_hold_sak_rusak).toLocaleString('id-ID'));
        $('#mdS2HRusak').text(parseInt(data.s2_hold_sak_rusak).toLocaleString('id-ID'));

        $('#mdS3Kendala').text(data.s3_kendala ? data.s3_kendala : '-');
        $('#mdS1Kendala').text(data.s1_kendala ? data.s1_kendala : '-');
        $('#mdS2Kendala').text(data.s2_kendala ? data.s2_kendala : '-');

        $('#modalDetailMortar').modal('show');
    });

    // 4. Logika Sinkronisasi AJAX Manual (Range Tanggal)
    $('#btnSyncManual').on('click', function() {
        var tglAwal = $('#tglSyncAwal').val();
        var tglAkhir = $('#tglSyncAkhir').val();
        
        if(!tglAwal || !tglAkhir) {
            Swal.fire('Perhatian', 'Silakan tentukan rentang tanggal awal dan akhir!', 'warning');
            return;
        }

        if(tglAwal > tglAkhir) {
            Swal.fire('Perhatian', 'Tanggal mulai tidak boleh melebihi tanggal akhir!', 'warning');
            return;
        }

        $('#btnSyncManual').attr('disabled', true);
        $('#iconSync').addClass('fa-spin');

        Swal.fire({
            title: 'Sinkronisasi Manual...',
            text: 'Menarik data dari Excel untuk tanggal ' + tglAwal + ' s/d ' + tglAkhir,
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading() }
        });

        // PASTIKAN FIle cron/sync_mortar.php JUGA DIRUBAH AGAR MENERIMA tgl_awal & tgl_akhir
        $.ajax({
            url: 'cron/sync_mortar.php',
            type: 'GET',
            data: { tgl_awal: tglAwal, tgl_akhir: tglAkhir }, 
            success: function(response) {
                $('#btnSyncManual').attr('disabled', false);
                $('#iconSync').removeClass('fa-spin');

                if (response.includes("Gagal") || response.includes("Error")) {
                    Swal.fire({ icon: 'error', title: 'Sinkronisasi Gagal!', text: response });
                } else {
                    Swal.fire({ icon: 'success', title: 'Sukses!', text: response, confirmButtonText: 'Tampilkan' }).then((result) => {
                        if (result.isConfirmed) { 
                            // Refresh dan arahkan filter tabel ke tanggal yang baru disync
                            window.location.href = '?tgl_awal=' + tglAwal + '&tgl_akhir=' + tglAkhir;
                        }
                    });
                }
            },
            error: function(xhr, status, error) {
                $('#btnSyncManual').attr('disabled', false);
                $('#iconSync').removeClass('fa-spin');
                Swal.fire({ icon: 'error', title: 'System Error!', text: 'Koneksi gagal: ' + error });
            }
        });
    });
});
</script>
