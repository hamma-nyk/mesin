<?php
// Panggil koneksi database
require_once 'includes/auth.php';
require_once 'config/database.php'; 

requireAccess(['Admin IT', 'Admin Produksi AAC2', 'Kabag Produksi']);

// Filter Tanggal
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-d');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');

// =========================================================================
// 1. LOGIKA EXPORT KE EXCEL
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Mixing_AAC2_" . $tgl_awal . "_sd_" . $tgl_akhir . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    try {
        $sqlExport = "SELECT * FROM OEE_AAC..db_log_mx 
                      WHERE CAST(waktu AS DATE) BETWEEN :tgl_awal AND :tgl_akhir 
                      ORDER BY waktu DESC";
        
        $stmtExport = $pdo_ms->prepare($sqlExport);
        $stmtExport->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
        
        echo '<table border="1">';
        echo '<tr>
                <th style="background-color: #00a65a; color: white;">No</th>
                <th style="background-color: #00a65a; color: white;">Job ID</th>
                <th style="background-color: #00a65a; color: white;">Casting</th>
                <th style="background-color: #00a65a; color: white;">Waktu</th>
                <th style="background-color: #00a65a; color: white;">Kapur 1</th>
                <th style="background-color: #00a65a; color: white;">Kapur 2</th>
                <th style="background-color: #00a65a; color: white;">Semen</th>
                <th style="background-color: #00a65a; color: white;">Slurry</th>
                <th style="background-color: #00a65a; color: white;">Waste</th>
                <th style="background-color: #00a65a; color: white;">Air</th>
                <th style="background-color: #00a65a; color: white;">Alu</th>
                <th style="background-color: #00a65a; color: white;">Periksa Alu</th>
              </tr>';
        
        $no = 1;
        while ($row = $stmtExport->fetch(PDO::FETCH_ASSOC)) {
            echo '<tr>';
            echo '<td align="center">' . $no++ . '</td>';
            echo '<td>' . htmlspecialchars($row['id']) . '</td>';
            echo '<td>' . htmlspecialchars($row['no_mould']) . '</td>';
            echo '<td>' . date('d/m/Y H:i', strtotime($row['waktu'])) . '</td>';

            // Bold + Memaksa Excel merender 4 angka di belakang koma secara presisi
            $style_desimal = 'style="font-weight: bold; mso-number-format:\'\#\,\#\#0\.0000\';"';

            echo '<td ' . $style_desimal . '>' . htmlspecialchars($row['kapur1']) . '</td>';
            echo '<td ' . $style_desimal . '>' . htmlspecialchars($row['kapur2']) . '</td>';
            echo '<td ' . $style_desimal . '>' . htmlspecialchars($row['semen']) . '</td>';
            echo '<td ' . $style_desimal . '>' . htmlspecialchars($row['slurry']) . '</td>';
            echo '<td ' . $style_desimal . '>' . htmlspecialchars($row['waste']) . '</td>';
            echo '<td ' . $style_desimal . '>' . htmlspecialchars($row['air']) . '</td>';
            echo '<td ' . $style_desimal . '>' . htmlspecialchars($row['alu']) . '</td>';
            echo '<td ' . $style_desimal . '>' . htmlspecialchars($row['PERIKSA_ALU']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        exit; 

    } catch(PDOException $e) {
        die("Export Gagal. Terjadi Kesalahan Internal.");
    }
}

require_once 'includes/header.php';

// CSS Tambahan untuk DataTables dan Tampilan Halaman Rapi
echo '
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<style>
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    #tableMixing td {
        vertical-align: middle;
    }
    .text-number {
        text-align: right;
        font-weight: bold;
        color: #212529;
        font-family: "Courier New", Courier, monospace;
    }
    .chart-container {
        position: relative;
        height: 320px;
        width: 100%;
    }
</style>
';

$mixing_data = [];
$chart_labels = [];
$chart_kapur1 = [];
$chart_kapur2 = [];
$chart_semen  = [];

try {
    // Menggunakan ORDER BY waktu ASC khusus untuk susunan data di Chart agar runut dari awal ke akhir hari
    $sql = "SELECT * FROM OEE_AAC..db_log_mx 
            WHERE CAST(waktu AS DATE) BETWEEN :tgl_awal AND :tgl_akhir 
            ORDER BY waktu ASC";
    
    $stmt = $pdo_ms->prepare($sql);
    $stmt->execute([':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir]);
    $mixing_data = $stmt->fetchAll();

    // Mempersiapkan array terpisah untuk data Chart.js
    foreach ($mixing_data as $row) {
        $chart_labels[] = date('H:i', strtotime($row['waktu']));
        $chart_kapur1[] = (float)($row['kapur1'] ?? 0);
        $chart_kapur2[] = (float)($row['kapur2'] ?? 0);
        $chart_semen[]  = (float)($row['semen'] ?? 0);
    }

    // Balik urutan untuk tabel agar data terbaru tetap muncul di atas (DESC)
    $mixing_data_table = array_reverse($mixing_data);

} catch(PDOException $e) {
    $error_msg = $e->getMessage();
}
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid"><h1 class="m-0">Data Mesin Mixing AAC2</h1></div>
    </div>
    
    <div class="content">
        <div class="container-fluid">
            
            <div class="card card-default mb-3">
                <div class="card-body">
                    <form method="GET">
                        <div class="row align-items-end">
                            <div class="col-md-3">
                                <label>Tanggal Awal</label>
                                <input type="date" name="tgl_awal" class="form-control" value="<?= $tgl_awal; ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label>Tanggal Akhir</label>
                                <input type="date" name="tgl_akhir" class="form-control" value="<?= $tgl_akhir; ?>" required>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                                <a href="?tgl_awal=<?= $tgl_awal; ?>&tgl_akhir=<?= $tgl_akhir; ?>&export=excel" class="btn btn-success">
                                    <i class="fas fa-file-excel"></i> Excel
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row">
                <div class="col-md-8">
                    <div class="card card-info card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-line mr-1"></i> Grafik Tren Pemakaian Material Utama (Kapur & Semen)</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="mixingChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card card-warning card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-lightbulb mr-1"></i> Hasil Analisis & Kontrol Kualitas</h3>
                        </div>
                        <div class="card-body" style="height: 340px; overflow-y: auto;">
                            <?php if (!empty($mixing_data)): 
                                // Kalkulasi statistik ringkas otomatis menggunakan PHP
                                $avg_k1 = array_sum($chart_kapur1) / count($chart_kapur1);
                                $avg_k2 = array_sum($chart_kapur2) / count($chart_kapur2);
                                $avg_sm = array_sum($chart_semen) / count($chart_semen);
                            ?>
                                <h6><b>Ringkasan Batch Terbaca:</b> <?= count($mixing_data); ?> Batch</h6>
                                <hr class="my-2">
                                <p class="text-sm mb-1"><b>Rata-rata Input Timbangan Material:</b></p>
                                <ul class="text-sm pl-4 mb-3">
                                    <li>Kapur 1: <b><?= number_format($avg_k1, 4, ',', '.') ?></b></li>
                                    <li>Kapur 2: <b><?= number_format($avg_k2, 4, ',', '.') ?></b></li>
                                    <li>Semen  : <b><?= number_format($avg_sm, 4, ',', '.') ?></b></li>
                                </ul>
                                <div class="callout callout-info p-2 text-xs mb-0">
                                    <h5>Saran Pengawas:</h5>
                                    <p class="mb-1">1. Jika garis pada grafik mengalami lonjakan tajam fluktuatif (Spike), lakukan kalibrasi sensor *Load Cell* timbangan Mixing AAC2.</p>
                                    <p>2. Pastikan deviasi berat pengisian *Alu* dan *Periksa Alu* bernilai konstan mendekati batas toleransi standar *Recipe Block* PT. NBI.</p>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center pt-5">Pilih tanggal filter yang memiliki data transaksi untuk menampilkan kalkulasi tren pengawasan adonan.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-primary card-outline">
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tableMixing" class="table table-hover table-bordered w-100">
                            <thead>
                                <tr class="bg-light text-nowrap">
                                    <th>No</th>
                                    <th>Job ID</th>
                                    <th>Casting</th>
                                    <th>Waktu</th>
                                    <th>Kapur 1</th>
                                    <th>Kapur 2</th>
                                    <th>Semen</th>
                                    <th>Slurry</th>
                                    <th>Waste</th>
                                    <th>Air</th>
                                    <th>Alu</th>
                                    <th>Periksa Alu</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($mixing_data_table as $row): ?>
                                    <tr>
                                        <td class="text-center"><?= $no++; ?></td>
                                        <td><?= $row['id']; ?></td>
                                        <td class="font-weight-bold text-primary"><?= $row['no_mould'] ?></td>
                                        <td class="text-nowrap"><?= date('d M Y H:i', strtotime($row['waktu'])); ?></td>
                                        <td class="text-number"><?= number_format($row['kapur1'] ?? 0, 4, ',', '.') ?></td>
                                        <td class="text-number"><?= number_format($row['kapur2'] ?? 0, 4, ',', '.') ?></td>
                                        <td class="text-number"><?= number_format($row['semen'] ?? 0, 4, ',', '.') ?></td>
                                        <td class="text-number"><?= number_format($row['slurry'] ?? 0, 4, ',', '.') ?></td>
                                        <td class="text-number"><?= number_format($row['waste'] ?? 0, 4, ',', '.') ?></td>
                                        <td class="text-number"><?= number_format($row['air'] ?? 0, 4, ',', '.') ?></td>
                                        <td class="text-number"><?= number_format($row['alu'] ?? 0, 4, ',', '.') ?></td>
                                        <td class="text-number"><?= number_format($row['PERIKSA_ALU'] ?? 0, 4, ',', '.') ?></td>
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

<script>
$(document).ready(function() {
    // 1. Inisialisasi DataTables
    var table = $('#tableMixing').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": false, 
        "pageLength": 10,
        "language": {
            "search": "Cari (No. Mould):",
            "lengthMenu": "Tampilkan _MENU_ data",
            "zeroRecords": "Data tidak ditemukan",
            "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
            "paginate": {
                "next": "Lanjut",
                "previous": "Kembali"
            }
        },
        "columnDefs": [
            { "searchable": false, "targets": [0, 1, 3, 4, 5, 6, 7, 8, 9, 10, 11] }
        ]
    });

    // 2. Inisialisasi Chart.js Tren Material
    var ctx = document.getElementById('mixingChart').getContext('2d');
    var mixingChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_labels); ?>, // Mengambil array jam menit produksi
            datasets: [
                {
                    label: 'Kapur 1',
                    data: <?= json_encode($chart_kapur1); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.1
                },
                {
                    label: 'Kapur 2',
                    data: <?= json_encode($chart_kapur2); ?>,
                    borderColor: '#ffc107',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.1
                },
                {
                    label: 'Semen',
                    data: <?= json_encode($chart_semen); ?>,
                    borderColor: '#dc3545',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    grid: { display: false },
                    title: { display: true, text: 'Waktu Proses (Clock)' }
                },
                y: {
                    title: { display: true, text: 'Nilai Berat Timbangan' }
                }
            },
            plugins: {
                legend: { position: 'top' }
            }
        }
    });
});
</script>
