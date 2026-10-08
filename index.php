<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';

// 🛡️ RULE AKSES BARU
//requireAccess(['Admin IT', 'Admin Produksi Mortar']);


// =========================================================================
// FILTER RENTANG TANGGAL (DEFAULT: 7 HARI TERAKHIR)
// =========================================================================
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-d', strtotime('-6 days'));
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');
$nama_user = $_SESSION['nama_lengkap'] ?? 'Pengguna';
$role_aktif = $_SESSION['role'] ?? 'Guest';

$error_db = null;

// ======================================================
// HAK AKSES TAMPILAN
// ======================================================
$isAdminIT      = ($role_aktif === 'Admin IT');
$isAdminMortar  = ($role_aktif === 'Admin Produksi Mortar');
$isAdminAAC1    = ($role_aktif === 'Admin Produksi AAC1');
$isAdminAAC2    = ($role_aktif === 'Admin Produksi AAC2');
$isDireksi      = ($role_aktif === 'Direksi');

// boleh melihat semua
$canSeeAll = $isAdminIT || $isDireksi;

// Hak tiap modul
$showMortar = $canSeeAll || $isAdminMortar;
$showAAC1   = $canSeeAll || $isAdminAAC1;
$showAAC2   = $canSeeAll || $isAdminAAC2;

// AI Insight hanya Direksi/Admin IT
$showAI = $canSeeAll;

// Grafik Executive hanya Direksi/Admin IT
$showExecutiveChart = $canSeeAll;

// SCADA
$showSCADA = $canSeeAll;

// Fungsi pencari kendala terbanyak
function getTopKendala($arr_kendala) {
    if (empty($arr_kendala)) return null;
    $counts = array_count_values($arr_kendala);
    arsort($counts);
    return htmlspecialchars(array_key_first($counts));
}

// =========================================================================
// 1. DATA AGREGASI KINERJA & KENDALA (BERDASARKAN RENTANG TANGGAL)
// =========================================================================

// --- MORTAR ---[cite: 2]
if ($showMortar) {
$target_mortar = 0; $realisasi_mortar = 0; $persen_mortar = 0; $total_sak_rusak = 0; $list_kendala_mortar = [];
try {
    $stmtM = $pdo->prepare("SELECT * FROM db_hasil_produksi_mortar.tb_harian WHERE tanggal BETWEEN :t1 AND :t2");
    $stmtM->execute([':t1' => $tgl_awal, ':t2' => $tgl_akhir]);
    while ($r = $stmtM->fetch(PDO::FETCH_ASSOC)) {
        $target_mortar    += (float)$r['target_per_tanggal_ton'];
        $realisasi_mortar += (float)($r['s1_hasil_packing_ton'] + $r['s2_hasil_packing_ton'] + $r['s3_hasil_packing_ton']);
        $total_sak_rusak  += (int)($r['s1_hold_sak_rusak'] + $r['s2_hold_sak_rusak'] + $r['s3_hold_sak_rusak']);
        
        if (!empty(trim($r['s1_kendala'])) && $r['s1_kendala'] != '-') $list_kendala_mortar[] = trim($r['s1_kendala']);
        if (!empty(trim($r['s2_kendala'])) && $r['s2_kendala'] != '-') $list_kendala_mortar[] = trim($r['s2_kendala']);
        if (!empty(trim($r['s3_kendala'])) && $r['s3_kendala'] != '-') $list_kendala_mortar[] = trim($r['s3_kendala']);
    }
    if ($target_mortar > 0) $persen_mortar = ($realisasi_mortar / $target_mortar) * 100;
} catch (Exception $e) { $error_db = "Kesalahan dalam mengambil data."; }
}

// --- LINI 1 (BRC & PLC) ---[cite: 4]
if ($showAAC1) {
$target_l1 = 0; $hasil_l1 = 0; $a1_l1 = 0; $persen_l1 = 0; $list_kendala_l1 = [];
try {
    $stmtL1 = $pdo->prepare("SELECT * FROM db_hasil_produksi_plc.tb_laporan_produksi_harian WHERE tanggal BETWEEN :t1 AND :t2");
    $stmtL1->execute([':t1' => $tgl_awal, ':t2' => $tgl_akhir]);
    while ($r = $stmtL1->fetch(PDO::FETCH_ASSOC)) {
        $target_l1 += (float)($r['brc_target_casting_m3'] + $r['plc_target_casting_m3']);
        $hasil_l1  += (float)($r['brc_hasil_casting_m3'] + $r['plc_hasil_casting_m3']);
        $a1_l1     += (int)$r['produk_baik_a1_pallet'];
        
        $parts = explode('|', $r['kendala_produksi'] ?? '-');
        $k = isset($parts[2]) ? trim($parts[2]) : (isset($parts[0]) ? trim($parts[0]) : '-');
        if ($k != '-' && !empty($k)) $list_kendala_l1[] = $k;
    }
    if ($target_l1 > 0) $persen_l1 = ($hasil_l1 / $target_l1) * 100;
} catch (Exception $e) { $error_db = "Kesalahan dalam mengambil data."; }
}
// --- LINI 2 (AAC2) ---[cite: 3]
if ($showAAC2) {
$target_l2 = 0; $hasil_l2 = 0; $reject_l2 = 0; $persen_l2 = 0; $list_kendala_l2 = [];
try {
    $stmtL2 = $pdo->prepare("SELECT SUM(target_casting_m3) as target, SUM(hasil_produk_jadi_m3) as hasil, SUM(reject_cake_m3) as reject FROM db_hasil_produksi_aac2.tb_harian WHERE tanggal BETWEEN :t1 AND :t2");
    $stmtL2->execute([':t1' => $tgl_awal, ':t2' => $tgl_akhir]);
    if ($r = $stmtL2->fetch(PDO::FETCH_ASSOC)) {
        $target_l2 = (float)$r['target'];
        $hasil_l2  = (float)$r['hasil'];
        $reject_l2 = (float)$r['reject'];
        if ($target_l2 > 0) $persen_l2 = ($hasil_l2 / $target_l2) * 100;
    }
    
    // Ambil log kendala Lini 2
    $stmtK2 = $pdo->prepare("SELECT kejadian FROM db_hasil_produksi_aac2.tb_kendala_harian WHERE tanggal BETWEEN :t1 AND :t2");
    $stmtK2->execute([':t1' => $tgl_awal, ':t2' => $tgl_akhir]);
    while($k = $stmtK2->fetch(PDO::FETCH_ASSOC)) {
        if (!empty(trim($k['kejadian'])) && $k['kejadian'] != '-') $list_kendala_l2[] = trim($k['kejadian']);
    }
} catch (Exception $e) { $error_db = "Kesalahan dalam mengambil data."; }
}

// =========================================================================
// 2. DATA TREN GRAFIK DIREKSI
// =========================================================================
$labels_date = []; $trend_pct_mortar = []; $trend_pct_l1 = []; $trend_pct_l2 = [];
$current_date = strtotime($tgl_awal);
$end_date = strtotime($tgl_akhir);

// Inisialisasi sumbu X (Tanggal) agar grafik konsisten meski data kosong[cite: 1]
while ($current_date <= $end_date) {
    $dt_str = date('Y-m-d', $current_date);
    $labels_date[] = date('d/m', $current_date);
    $trend_pct_mortar[$dt_str] = 0; $trend_pct_l1[$dt_str] = 0; $trend_pct_l2[$dt_str] = 0;
    $current_date = strtotime('+1 day', $current_date);
}

try {
    // Tarik Tren Mortar[cite: 2]
    $stmtTrMortar = $pdo->prepare("SELECT tanggal, target_per_tanggal_ton as target, (s1_hasil_packing_ton+s2_hasil_packing_ton+s3_hasil_packing_ton) as hasil FROM db_hasil_produksi_mortar.tb_harian WHERE tanggal BETWEEN :t1 AND :t2");
    $stmtTrMortar->execute([':t1' => $tgl_awal, ':t2' => $tgl_akhir]);
    while($r = $stmtTrMortar->fetch()) { if($r['target'] > 0) $trend_pct_mortar[$r['tanggal']] = round(($r['hasil']/$r['target'])*100, 1); }

    // Tarik Tren L1[cite: 4]
    $stmtTrL1 = $pdo->prepare("SELECT tanggal, SUM(brc_target_casting_m3 + plc_target_casting_m3) as target, SUM(brc_hasil_casting_m3 + plc_hasil_casting_m3) as hasil FROM db_hasil_produksi_plc.tb_laporan_produksi_harian WHERE tanggal BETWEEN :t1 AND :t2 GROUP BY tanggal");
    $stmtTrL1->execute([':t1' => $tgl_awal, ':t2' => $tgl_akhir]);
    while($r = $stmtTrL1->fetch()) { if($r['target'] > 0) $trend_pct_l1[$r['tanggal']] = round(($r['hasil']/$r['target'])*100, 1); }

    // Tarik Tren L2[cite: 3]
    $stmtTrL2 = $pdo->prepare("SELECT tanggal, SUM(target_casting_m3) as target, SUM(hasil_produk_jadi_m3) as hasil FROM db_hasil_produksi_aac2.tb_harian WHERE tanggal BETWEEN :t1 AND :t2 GROUP BY tanggal");
    $stmtTrL2->execute([':t1' => $tgl_awal, ':t2' => $tgl_akhir]);
    while($r = $stmtTrL2->fetch()) { if($r['target'] > 0) $trend_pct_l2[$r['tanggal']] = round(($r['hasil']/$r['target'])*100, 1); }
} catch (Exception $e) {}

$chart_mortar = array_values($trend_pct_mortar);
$chart_l1 = array_values($trend_pct_l1);
$chart_l2 = array_values($trend_pct_l2);

// =========================================================================
// 3. KECERDASAN BUATAN (AI INSIGHT) - KENDALA & SOLUSI
// =========================================================================
if ($showAI) {
$arr_kinerja = ['Divisi Mortar' => $persen_mortar, 'Divisi AAC 1' => $persen_l1, 'Divisi AAC 2' => $persen_l2];
arsort($arr_kinerja);
$best_line = array_key_first($arr_kinerja);
$best_pct  = current($arr_kinerja);

$ai_narasi = "Berdasarkan rentang data <b>" . date('d M Y', strtotime($tgl_awal)) . " s/d " . date('d M Y', strtotime($tgl_akhir)) . "</b>, kinerja produktivitas terbaik dipimpin oleh <b>{$best_line}</b> dengan efisiensi pencapaian <b class='text-success'>" . number_format($best_pct, 1, ',', '.') . "%</b>.<br><br>";

$warnings = []; $solusi = []; $kendala_ditemukan = [];

// Analisis Lini di bawah target (< 90%)
if ($persen_mortar > 0 && $persen_mortar < 90) {
    $warnings[] = "<b>Lini Mortar</b> (" . number_format($persen_mortar,1) . "%)";
    $top_k_m = getTopKendala($list_kendala_mortar);
    if ($top_k_m) $kendala_ditemukan[] = "Kendala Mortar: <i>$top_k_m</i>";
    $solusi[] = "Lini Mortar memerlukan inspeksi mesin packing, percepatan pasokan palet, serta meminimalkan sak reject.";
}
if ($persen_l1 > 0 && $persen_l1 < 90) {
    $warnings[] = "<b>AAC 1</b> (" . number_format($persen_l1,1) . "%)";
    $top_k_l1 = getTopKendala($list_kendala_l1);
    if ($top_k_l1) $kendala_ditemukan[] = "Kendala AAC 1: <i>$top_k_l1</i>";
    $solusi[] = "AAC 1 memerlukan pemantauan ketat pada sinkronisasi mesin BRC/PLC serta optimasi waktu tunggu mixing.";
}
if ($persen_l2 > 0 && $persen_l2 < 90) {
    $warnings[] = "<b>AAC 2</b> (" . number_format($persen_l2,1) . "%)";
    $top_k_l2 = getTopKendala($list_kendala_l2);
    if ($top_k_l2) $kendala_ditemukan[] = "Kendala AAC 2: <i>$top_k_l2</i>";
    $solusi[] = "AAC 2 perlu menekan rasio reject cake cutting dan mengantisipasi *downtime* pada proses Autoclave.";
}

if (count($warnings) > 0) {
    $ai_narasi .= "<span class='text-warning'><i class='fas fa-exclamation-triangle'></i> <b>Peringatan:</b></span> Atensi manajerial diperlukan pada " . implode(", ", $warnings) . " karena rasio efisiensi berada di bawah batas optimal 90%.<br>";
    if (count($kendala_ditemukan) > 0) {
        $ai_narasi .= "<br><b>Hambatan Dominan Tercatat:</b><br> - " . implode("<br> - ", $kendala_ditemukan) . "<br>";
    }
    $ai_narasi .= "<br><b>Rekomendasi Tindakan:</b><br> &bull; " . implode("<br> &bull; ", $solusi);
} else {
    if ($best_pct > 0) {
        $ai_narasi .= "<span class='text-success'><i class='fas fa-check-circle'></i> <b>Status Aman:</b></span> Seluruh lini produksi beroperasi sangat baik dengan efisiensi di atas 90%.<br><br><b>Rekomendasi Tindakan:</b><br>&bull; Pertahankan ritme <i>supply chain</i> saat ini.<br>&bull; Lakukan *Preventive Maintenance* rutin untuk mencegah <i>breakdown</i> mendadak.";
    } else {
        $ai_narasi = "Sistem belum menerima data laporan produksi untuk rentang tanggal ini. Pabrik dalam status <i>Idle / Standby</i>.";
    }
}
}
// =========================================================================
// 4. AMBIL STATUS MESIN LIVE SCADA[cite: 1]
// =========================================================================
$total_batch_mixing = 0; $last_mould = '-'; $last_time = '-'; $error_aac = null;
try {
    if (isset($pdo_ms)) {
        $stmtMix = $pdo_ms->prepare("SELECT COUNT(*) FROM OEE_AAC..db_log_mx WHERE CAST(waktu AS DATE) = :tgl");
        $stmtMix->execute([':tgl' => date('Y-m-d')]); // SCADA selalu hari ini
        $total_batch_mixing = (int) $stmtMix->fetchColumn();

        $stmtLast = $pdo_ms->query("SELECT TOP 1 no_mould, waktu FROM OEE_AAC..db_log_mx ORDER BY waktu DESC");
        if ($last_data = $stmtLast->fetch(PDO::FETCH_ASSOC)) {
            $last_mould = $last_data['no_mould'];
            $last_time  = date('H:i', strtotime($last_data['waktu']));
        }
    } else { $error_aac = "Koneksi SCADA terputus."; }
} catch(PDOException $e) { $error_aac = "Koneksi SCADA Offline."; }


require_once 'includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts/dist/apexcharts.css">
<style>
    .info-box-icon { transition: transform .3s; }
    .info-box:hover .info-box-icon { transform: scale(1.1); }
    .card-title { font-weight: 700; font-size: 15px; }
    .metric-title { font-size: 0.85rem; font-weight: bold; text-transform: uppercase; color: #6c757d; }
    .metric-val { font-size: 1.5rem; font-weight: bold; }
    .ai-box { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border-left: 5px solid #00c6ff; }
	.user-info-bar { background: #f8f9fa; border-bottom: 1px solid #e9ecef; padding: 10px 20px; font-size: 14px; }
</style>

<div class="content-wrapper">
	<!-- BARIS INFO USER -->
    <div class="user-info-bar">
        <i class="fas fa-user-circle mr-1"></i> Login sebagai: <b><?= htmlspecialchars($nama_user) ?></b> | 
        <i class="fas fa-id-badge ml-2 mr-1"></i> Role: <b><?= htmlspecialchars($role_aktif) ?></b>
    </div>
    <div class="content-header pb-1">
        <div class="container-fluid">
            <div class="row align-items-center">
	<?php if($showMortar || $showAAC1 || $showAAC2 || $showExecutiveChart): ?>
    <div class="col-sm-5">
        <h1 class="m-0 text-dark font-weight-bold">
            <i class="fas fa-chart-pie text-primary mr-2"></i>
            Executive Summary
        </h1>
    </div>
	 <?php endif; ?>


    <div class="col-sm-7">

        <?php if($showMortar || $showAAC1 || $showAAC2 || $showExecutiveChart): ?>

            <form method="GET" class="float-right form-inline">
                <div class="input-group input-group-sm shadow-sm mr-2">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-primary text-white border-primary">
                            Mulai
                        </span>
                    </div>
                    <input
                        type="date"
                        name="tgl_awal"
                        value="<?= e($tgl_awal) ?>"
                        class="form-control border-primary text-center font-weight-bold">
                </div>

                <div class="input-group input-group-sm shadow-sm mr-2">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-primary text-white border-primary">
                            Sampai
                        </span>
                    </div>
                    <input
                        type="date"
                        name="tgl_akhir"
                        value="<?= e($tgl_akhir) ?>"
                        class="form-control border-primary text-center font-weight-bold">
                </div>

                <button type="submit" class="btn btn-sm btn-primary font-weight-bold shadow-sm">
                    <i class="fas fa-filter"></i> Analisis
                </button>
            </form>

        <?php endif; ?>

    </div>

</div>

<?php if(!$showMortar && !$showAAC1 && !$showAAC2 && !$showExecutiveChart): ?>

<div class="alert alert-info mt-3 shadow-sm">
    <h5 class="mb-2">
        <i class="fas fa-info-circle mr-1"></i>
        Dashboard Belum Tersedia
    </h5>

    Anda berhasil login sebagai
    <strong><?= htmlspecialchars($role_aktif) ?></strong>,
    namun role tersebut belum memiliki akses untuk melihat Executive Summary.
    Silakan hubungi Administrator apabila diperlukan akses ke dashboard ini.
</div>

<?php endif; ?>
        </div>
    </div>

    <div class="content mt-3 pb-3">
        <div class="container-fluid">
            
            <!-- AI INSIGHT BOX (UNTUK DIREKSI) -->
			<?php if($showAI): ?>
            <div class="ai-box p-4 mb-4">
                <div class="row">
                    <div class="col-md-1 col-2 text-center">
                        <i class="fas fa-robot fa-3x text-light mt-2" style="opacity: 0.8;"></i>
                    </div>
                    <div class="col-md-11 col-10">
                        <h5 class="font-weight-bold text-warning mb-2"><i class="fas fa-bolt mr-1"></i> AI Performance & Issue Insight</h5>
                        <p class="mb-0 text-light" style="font-size: 14.5px; line-height: 1.6;"><?= $ai_narasi ?></p>
                    </div>
                </div>
            </div>
			<?php endif; ?>

            <div class="row">
                <!-- CHART TREN RENTANG TANGGAL -->
				<?php if($showExecutiveChart): ?>
                <div class="col-md-8">
                    <div class="card card-outline card-primary shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h3 class="card-title text-dark"><i class="fas fa-chart-line text-primary mr-1"></i> Tren Efisiensi Pabrik (<?= date('d/m/Y', strtotime($tgl_awal)) ?> - <?= date('d/m/Y', strtotime($tgl_akhir)) ?>)</h3>
                        </div>
                        <div class="card-body p-2">
                            <div id="executiveChart" style="min-height: 300px;"></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
				
                <!-- SUMMARY LINI AGREGASI -->

                <div class="col-md-4">
				<?php if($showMortar || $showAAC1 || $showAAC2 || $showExecutiveChart): ?>
                    <div class="card card-outline card-info shadow-sm h-100">
						
                        <div class="card-header bg-white">
                            <h3 class="card-title text-dark"><i class="fas fa-bullseye text-info mr-1"></i> Metrik Agregasi Produksi</h3>
                        </div>
						
                        <div class="card-body p-3">
                            <!-- Mortar -->
							<?php if($showMortar): ?>
                            <div class="mb-3 border-bottom pb-2">
                                <span class="font-weight-bold text-dark"><i class="fas fa-boxes text-warning mr-1"></i> Divisi Mortar</span>
                                <a href="laporan_produksi_mortar.php" class="float-right text-xs mt-1">Laporan Detail <i class="fas fa-chevron-right"></i></a>
                                <div class="progress mt-2" style="height: 12px;">
                                    <div class="progress-bar <?= $persen_mortar >= 90 ? 'bg-success' : 'bg-danger' ?>" style="width: <?= $persen_mortar > 100 ? 100 : $persen_mortar ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-1 text-sm">
                                    <span class="text-muted">Hasil: <?= number_format($realisasi_mortar, 1, ',', '.') ?> / <?= number_format($target_mortar, 1, ',', '.') ?> Ton</span>
                                    <span class="font-weight-bold"><?= number_format($persen_mortar, 1, ',', '.') ?>%</span>
                                </div>
                            </div>
							<?php endif; ?>
							
                            <!-- Lini 1 -->
							<?php if($showAAC1): ?>
                            <div class="mb-3 border-bottom pb-2">
                                <span class="font-weight-bold text-dark"><i class="fas fa-layer-group text-primary mr-1"></i> Divisi AAC 1</span>
                                <a href="laporan_produksi_plc.php" class="float-right text-xs mt-1">Laporan Detail <i class="fas fa-chevron-right"></i></a>
                                <div class="progress mt-2" style="height: 12px;">
                                    <div class="progress-bar <?= $persen_l1 >= 90 ? 'bg-success' : 'bg-danger' ?>" style="width: <?= $persen_l1 > 100 ? 100 : $persen_l1 ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-1 text-sm">
                                    <span class="text-muted">Hasil: <?= number_format($hasil_l1, 1, ',', '.') ?> / <?= number_format($target_l1, 1, ',', '.') ?> m³</span>
                                    <span class="font-weight-bold"><?= number_format($persen_l1, 1, ',', '.') ?>%</span>
                                </div>
                            </div>
							<?php endif; ?>
							
                            <!-- Lini 2 -->
							<?php if($showAAC2): ?>
                            <div class="mb-1">
                                <span class="font-weight-bold text-dark"><i class="fas fa-industry text-success mr-1"></i> Divisi AAC 2</span>
                                <a href="laporan_produksi_aac2.php" class="float-right text-xs mt-1">Laporan Detail <i class="fas fa-chevron-right"></i></a>
                                <div class="progress mt-2" style="height: 12px;">
                                    <div class="progress-bar <?= $persen_l2 >= 90 ? 'bg-success' : 'bg-danger' ?>" style="width: <?= $persen_l2 > 100 ? 100 : $persen_l2 ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-1 text-sm">
                                    <span class="text-muted">Hasil: <?= number_format($hasil_l2, 1, ',', '.') ?> / <?= number_format($target_l2, 1, ',', '.') ?> m³</span>
                                    <span class="font-weight-bold"><?= number_format($persen_l2, 1, ',', '.') ?>%</span>
                                </div>
                            </div>
							<?php endif; ?>
                        </div>
                    </div>
					<?php endif; ?>
                </div>
            </div>

            <!-- ========================================================= -->
            <!-- LIVE MACHINE MONITORING (SQL SERVER)                      -->
            <!-- ========================================================= -->
			<?php if($showSCADA): ?>
            <!-- <h5 class="mb-3 mt-4 font-weight-bold text-secondary border-bottom pb-2"><i class="fas fa-satellite-dish mr-1"></i> Live SCADA Sensor Integration (Hari Ini)</h5>
            
            <?php if (isset($error_aac)): ?>
                <div class="alert alert-danger text-sm shadow-sm"><i class="fas fa-exclamation-triangle"></i> <b>Status SCADA:</b> <?= $error_aac ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-dark shadow-sm">
                        <div class="inner">
                            <h3><?= number_format($total_batch_mixing); ?> <sup style="font-size: 15px">Batch</sup></h3>
                            <p class="mb-1 font-weight-bold text-success">Mesin Mixing AAC 2</p>
                            <span class="badge badge-light px-2 py-1 text-dark"><i class="far fa-clock text-success"></i> Last: Mould <?= $last_mould ?> (<?= $last_time ?>)</span>
                        </div>
                        <div class="icon"><i class="fas fa-blender text-white-50"></i></div>
                        <a href="data_mesin_mixing_aac2.php" class="small-box-footer font-weight-bold">Lihat Log SCADA <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>

                <div class="col-lg-3 col-6">
                    <div class="small-box bg-light shadow-sm border">
                        <div class="inner text-muted">
                            <h3>-</h3>
                            <p class="mb-1 font-weight-bold">Mesin Cutting AAC 2</p>
                            <small class="badge badge-secondary"><i class="fas fa-plug"></i> Offline</small>
                        </div>
                        <div class="icon"><i class="fas fa-cut text-black-50"></i></div>
                        <a href="#" class="small-box-footer text-muted">Coming Soon <i class="fas fa-lock"></i></a>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-light shadow-sm border">
                        <div class="inner text-muted">
                            <h3>-</h3>
                            <p class="mb-1 font-weight-bold">Mesin Mixing AAC 1</p>
                            <small class="badge badge-secondary"><i class="fas fa-tools"></i> Maintenance</small>
                        </div>
                        <div class="icon"><i class="fas fa-faucet text-black-50"></i></div>
                        <a href="#" class="small-box-footer text-muted">Coming Soon <i class="fas fa-lock"></i></a>
                    </div>
                </div>
            </div>
			-->
			<?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<?php if($showExecutiveChart): ?>
<script>
document.addEventListener("DOMContentLoaded", function () {
    var options = {
        series: [
            { name: 'Efisiensi Mortar (%)', data: <?= json_encode($chart_mortar) ?> },
            { name: 'Efisiensi AAC 1 (%)', data: <?= json_encode($chart_l1) ?> },
            { name: 'Efisiensi AAC 2 (%)', data: <?= json_encode($chart_l2) ?> }
        ],
        chart: { type: 'area', height: 320, toolbar: { show: false } },
        colors: ['#ffc107', '#007bff', '#28a745'], 
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 90, 100] } },
        xaxis: { categories: <?= json_encode($labels_date) ?>, tooltip: { enabled: false } },
        yaxis: { 
            title: { text: 'Persentase Efisiensi Target' }, 
            min: 0, max: 120, 
            labels: { formatter: function (value) { return value + "%"; } }
        },
        legend: { position: 'top', horizontalAlign: 'right' },
        tooltip: { y: { formatter: function (val) { return val + "%" } } }
    };

    var chart = new ApexCharts(document.querySelector("#executiveChart"), options);
    chart.render();
});
</script>
<?php endif; ?>
