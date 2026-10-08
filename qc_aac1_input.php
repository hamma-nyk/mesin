<?php
// 1. Inisialisasi Session
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

date_default_timezone_set('Asia/Jakarta');

require_once 'includes/auth.php';

// =========================================================================
// 🛡️ PROTEKSI HALAMAN (ROLE-BASED ACCESS CONTROL)
// =========================================================================
requireAccess(['Admin IT', 'Admin QCL AAC1', 'Supervisor QCL AAC', 'Kabag QCL']);

require_once 'config/database.php'; // Menyediakan koneksi $pdo_ms3

// =========================================================================
// INCLUDE CONFIG WHATSAPP GATEWAY
// =========================================================================
include_once __DIR__ . '/config/wa_gateway.php'; 

$status_message = "";
$user_aktif = $_SESSION['username'] ?? 'Sistem';
$nama_aktif = $_SESSION['nama_lengkap'] ?? 'Sistem';
$role_aktif = $_SESSION['role'] ?? 'Guest';

// =========================================================================
// LOGIKA POST SIMPAN DATA
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_simpan_qc'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    try {
        $tanggal_raw = $_POST['tanggal'];
        $tanggal     = str_replace('T', ' ', $tanggal_raw) . ':00'; 
        $shift       = $_POST['shift'];
        $catatan     = sanitize_input($_POST['catatan']);

        // Penampung Nilai SQL & Validasi NG
        $sql_vals = [];
        $list_ng = [];
        $list_ok = [];

        // Ambil standar dari master PostgreSQL
        $stmt_std = $pdo->prepare("SELECT kode_param, nama_param, nilai_min, nilai_max FROM db_master.tb_standar_qc WHERE lini = 'AAC1'");
        $stmt_std->execute();
        $std_map = [];
        foreach ($stmt_std->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            $std_map[$sr['kode_param']] = $sr;
        }

        // Looping Tangki 1 sampai 4
        for ($i = 1; $i <= 4; $i++) {
            $d = $_POST["densitas_t$i"] !== '' ? (float)$_POST["densitas_t$i"] : 'NULL';
            $v = $_POST["viskositas_t$i"] !== '' ? (float)$_POST["viskositas_t$i"] : 'NULL';
            $s = $_POST["suhu_t$i"] !== '' ? (float)$_POST["suhu_t$i"] : 'NULL';
            $k = $_POST["stok_t$i"] !== '' ? (float)$_POST["stok_t$i"] : 'NULL';

            $sql_vals["d$i"] = $d; $sql_vals["v$i"] = $v; $sql_vals["s$i"] = $s; $sql_vals["k$i"] = $k;

            // VALIDASI DENSITAS
            if ($d !== 'NULL' && isset($std_map["densitas_t$i"])) {
                $st = $std_map["densitas_t$i"];
                $min_d = $st['nilai_min'] !== null ? (float)$st['nilai_min'] : null;
                $max_d = $st['nilai_max'] !== null ? (float)$st['nilai_max'] : null;
                $std_label = ($min_d !== null ? $min_d : '~') . '-' . ($max_d !== null ? $max_d : '~');
                $ng = ($min_d !== null && $d < $min_d) || ($max_d !== null && $d > $max_d);
                if ($ng) { $list_ng[] = "❌ TANGKI $i - Densitas: *$d* (Std: $std_label)"; }
                else     { $list_ok[] = "✅ TANGKI $i - Densitas: *$d* (Std: $std_label)"; }
            }
            // VALIDASI VISKOSITAS
            if ($v !== 'NULL' && isset($std_map["viskositas_t$i"])) {
                $st = $std_map["viskositas_t$i"];
                $min_v = $st['nilai_min'] !== null ? (float)$st['nilai_min'] : null;
                $max_v = $st['nilai_max'] !== null ? (float)$st['nilai_max'] : null;
                $std_label = ($min_v !== null ? $min_v : '~') . '-' . ($max_v !== null ? $max_v : '~');
                $ng = ($min_v !== null && $v < $min_v) || ($max_v !== null && $v > $max_v);
                if ($ng) { $list_ng[] = "❌ TANGKI $i - Viskositas: *$v* (Std: $std_label)"; }
                else     { $list_ok[] = "✅ TANGKI $i - Viskositas: *$v* (Std: $std_label)"; }
            }
            // VALIDASI SUHU
            if ($s !== 'NULL' && isset($std_map["suhu_t$i"])) {
                $st = $std_map["suhu_t$i"];
                $min_s = $st['nilai_min'] !== null ? (float)$st['nilai_min'] : null;
                $max_s = $st['nilai_max'] !== null ? (float)$st['nilai_max'] : null;
                $std_label = ($min_s !== null ? "Min: $min_s" : '') . ($max_s !== null ? " Maks: $max_s" : '');
                $ng = ($min_s !== null && $s < $min_s) || ($max_s !== null && $s > $max_s);
                if ($ng) { $list_ng[] = "❌ TANGKI $i - Suhu: *$s* ($std_label)"; }
                else     { $list_ok[] = "✅ TANGKI $i - Suhu: *$s* ($std_label)"; }
            }
        }
        
        $vp = $_POST["visco_pouring"] !== '' ? (float)$_POST["visco_pouring"] : 'NULL';
        // VALIDASI VISCO POURING
        if ($vp !== 'NULL' && isset($std_map["visco_pouring"])) {
            $st = $std_map["visco_pouring"];
            $min_vp = $st['nilai_min'] !== null ? (float)$st['nilai_min'] : null;
            $max_vp = $st['nilai_max'] !== null ? (float)$st['nilai_max'] : null;
            $std_label = ($min_vp !== null ? $min_vp : '~') . '-' . ($max_vp !== null ? $max_vp : '~');
            $ng = ($min_vp !== null && $vp < $min_vp) || ($max_vp !== null && $vp > $max_vp);
            if ($ng) { $list_ng[] = "❌ VISCO POURING: *$vp* (Std: $std_label)"; }
            else     { $list_ok[] = "✅ VISCO POURING: *$vp* (Std: $std_label)"; }
        }
        
        $std_snapshot = json_encode($std_map, JSON_UNESCAPED_UNICODE);

        $sqlDirect = "INSERT INTO parameter_aac1 (
            tanggal, shift, 
            densitas_t1, densitas_t2, densitas_t3, densitas_t4, densitas_t5, densitas_t6,
            viskositas_t1, viskositas_t2, viskositas_t3, viskositas_t4, viskositas_t5, viskositas_t6,
            suhu_t1, suhu_t2, suhu_t3, suhu_t4, suhu_t5, suhu_t6,
            stok_t1, stok_t2, stok_t3, stok_t4, stok_t5, stok_t6,
            visco_pouring,
            catatan, std_snapshot
        ) VALUES (
            ?, ?,
            ?, ?, ?, ?, NULL, NULL,
            ?, ?, ?, ?, NULL, NULL,
            ?, ?, ?, ?, NULL, NULL,
            ?, ?, ?, ?, NULL, NULL,
            ?,
            ?, ?
        )";

        $stmt = $pdo_ms3->prepare($sqlDirect);
        
        $params = [
            $tanggal, $shift,
            $sql_vals['d1'] !== 'NULL' ? (float)$sql_vals['d1'] : null, $sql_vals['d2'] !== 'NULL' ? (float)$sql_vals['d2'] : null, $sql_vals['d3'] !== 'NULL' ? (float)$sql_vals['d3'] : null, $sql_vals['d4'] !== 'NULL' ? (float)$sql_vals['d4'] : null,
            $sql_vals['v1'] !== 'NULL' ? (float)$sql_vals['v1'] : null, $sql_vals['v2'] !== 'NULL' ? (float)$sql_vals['v2'] : null, $sql_vals['v3'] !== 'NULL' ? (float)$sql_vals['v3'] : null, $sql_vals['v4'] !== 'NULL' ? (float)$sql_vals['v4'] : null,
            $sql_vals['s1'] !== 'NULL' ? (float)$sql_vals['s1'] : null, $sql_vals['s2'] !== 'NULL' ? (float)$sql_vals['s2'] : null, $sql_vals['s3'] !== 'NULL' ? (float)$sql_vals['s3'] : null, $sql_vals['s4'] !== 'NULL' ? (float)$sql_vals['s4'] : null,
            $sql_vals['k1'] !== 'NULL' ? (float)$sql_vals['k1'] : null, $sql_vals['k2'] !== 'NULL' ? (float)$sql_vals['k2'] : null, $sql_vals['k3'] !== 'NULL' ? (float)$sql_vals['k3'] : null, $sql_vals['k4'] !== 'NULL' ? (float)$sql_vals['k4'] : null,
            $vp !== 'NULL' ? (float)$vp : null,
            $catatan, $std_snapshot
        ];
        
        $stmt->execute($params);
        $_SESSION['qc_save_status'] = "success";

        // =========================================================================
        // LOGIKA AUTO-SEND WHATSAPP NOTIFIKASI (OK & NG)
        // =========================================================================
        if (!empty($wa_recipients_qcl_aac1) && (!empty($list_ok) || !empty($list_ng))) {
            if (!empty($list_ng)) {
                $message_text = "🚨 *ALERT DEVIASI MUTU QC (AAC 1)*\n\n";
                $message_text .= "⚠️ Ditemukan parameter tidak memenuhi standar laboratorium.\n\n";
            } else {
                $message_text = "✅ *LAPORAN QC (AAC 1) - SEMUA OK*\n\n";
                $message_text .= "Seluruh parameter memenuhi standar laboratorium.\n\n";
            }

            $message_text .= "📍 *Detail Cek:* \n";
            $message_text .= "▪️ Waktu: " . date('d-m-Y H:i', strtotime($tanggal)) . " WIB\n";
            $message_text .= "▪️ Regu Kerja: *" . $shift . "*\n";
            $message_text .= "▪️ Input Oleh: *$nama_aktif ($user_aktif)*\n\n";

            if (!empty($list_ng)) {
                $message_text .= "📊 *Daftar Deviasi (NG):* \n";
                foreach ($list_ng as $item_ng) { $message_text .= $item_ng . "\n"; }
                $message_text .= "\n";
            }

            if (!empty($list_ok)) {
                $message_text .= "📊 *Parameter OK:* \n";
                foreach ($list_ok as $item_ok) { $message_text .= $item_ok . "\n"; }
                $message_text .= "\n";
            }

            $message_text .= "📝 *Catatan Proses Kontrol:* \n_" . (!empty($catatan) ? $catatan : "Tidak ada catatan tambahan.") . "_\n\n";
            if (!empty($list_ng)) {
                $message_text .= "📢 _Mohon tim segera melakukan pengecekan pada area/tangki terkait._";
            }

            foreach ($wa_recipients_qcl_aac1 as $target_wa_number) {
                $payload = json_encode(["number" => $target_wa_number, "type" => "text", "text" => $message_text]);
                $ch = curl_init($wa_server_url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($payload)]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 4);
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($err || $code >= 400) {
                    write_security_log('security', "Notif WA AAC1 GAGAL ke $target_wa_number: err=$err code=$code url=$wa_server_url");
                }
            }
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['qc_save_status'] = "error: Gagal menyimpan data!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

if (isset($_SESSION['qc_save_status'])) {
    $status_message = $_SESSION['qc_save_status'];
    unset($_SESSION['qc_save_status']); 
}

// Ambil standar terkini untuk render form
$stmt_std_view = $pdo->prepare("SELECT kode_param, nama_param, nilai_min, nilai_max FROM db_master.tb_standar_qc WHERE lini = 'AAC1'");
$stmt_std_view->execute();
$std_view = [];
foreach ($stmt_std_view->fetchAll(PDO::FETCH_ASSOC) as $sr) {
    $std_view[$sr['kode_param']] = $sr;
}

require_once 'includes/header.php';
?>

<style>
    .val-box { display: block; font-size: 11px; font-weight: bold; margin-top: 3px; border-radius: 3px; }
    .status-ok { color: #166534; background-color: #dcfce7; padding: 2px; }
    .status-ng { color: #991b1b; background-color: #fee2e2; padding: 2px; }
    .status-default { color: #6c757d; }
    .table td, .table th { vertical-align: middle !important; }
    .user-info-bar { background: #f8f9fa; border-bottom: 1px solid #e9ecef; padding: 10px 20px; font-size: 14px; }
    .bg-light-warning { background-color: #fff3cd !important; }
</style>

<div class="content-wrapper">
    <!-- BARIS INFO USER -->
    <div class="user-info-bar">
        <i class="fas fa-user-circle mr-1"></i> Login sebagai: <b><?= htmlspecialchars($nama_aktif) ?></b> | 
        <i class="fas fa-id-badge ml-2 mr-1"></i> Role: <b><?= htmlspecialchars($role_aktif) ?></b>
    </div>

    <div class="content-header">
        <div class="container-fluid">
            <h1 class="m-0 font-weight-bold text-dark"><i class="fas fa-edit text-success mr-2"></i>Form Input Parameter QC - AAC 1</h1>
        </div>
    </div>

    <div class="content mt-2">
        <div class="container-fluid">
            
            <form id="qcForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <!-- KARTU ADMINISTRASI -->
                <div class="card card-outline card-primary shadow-sm mb-4">
                    <div class="card-header bg-white"><h3 class="card-title font-weight-bold"><i class="fas fa-clock text-primary mr-1"></i> Administrasi Pemeriksaan</h3></div>
                    <div class="card-body pb-3">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>Tanggal & Waktu Aktual</label>
                                <input type="datetime-local" name="tanggal" class="form-control text-bold text-primary" value="<?= date('Y-m-d\TH:i') ?>" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Grup Shift Kerja</label>
                                <select name="shift" class="form-control text-bold" required>
                                    <option value="Shift 1">Shift 1</option>
                                    <option value="Shift 2">Shift 2</option>
                                    <option value="Shift 3">Shift 3</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KARTU TABEL INPUT (TANGKI 1-4 & VISCO POURING) -->
                <div class="card card-success card-outline shadow-sm mb-4">
                    <div class="card-header bg-white"><h3 class="card-title font-weight-bold"><i class="fas fa-flask text-success mr-1"></i> Pengukuran Aktual Laboratorium</h3></div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-bordered table-striped m-0 text-center">
                            <thead class="bg-success text-white">
                                <tr>
                                    <th style="width: 12%;">AREA UJI</th>
                                    <th style="width: 22%;">DENSITAS (gr/ltr)</th>
                                    <th style="width: 22%;">VISKOSITAS (cm)</th>
                                    <th style="width: 22%;">SUHU (°C)</th>
                                    <th style="width: 22%;">STOK (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for($i = 1; $i <= 4; $i++): 
                                    $sd = $std_view["densitas_t$i"] ?? null;
                                    $sv = $std_view["viskositas_t$i"] ?? null;
                                    $ss = $std_view["suhu_t$i"] ?? null;

                                    $min_d = $sd && $sd['nilai_min'] !== null ? (float)$sd['nilai_min'] : 0;
                                    $max_d = $sd && $sd['nilai_max'] !== null ? (float)$sd['nilai_max'] : 99999;
                                    $std_dens = $sd ? ($sd['nilai_min'] ?? '~') . ' - ' . ($sd['nilai_max'] ?? '~') : '-';

                                    $min_v = $sv && $sv['nilai_min'] !== null ? (float)$sv['nilai_min'] : 0;
                                    $max_v = $sv && $sv['nilai_max'] !== null ? (float)$sv['nilai_max'] : 99999;
                                    $std_visko = $sv ? ($sv['nilai_min'] ?? '~') . ' - ' . ($sv['nilai_max'] ?? '~') : '-';

                                    $min_s = $ss && $ss['nilai_min'] !== null ? (float)$ss['nilai_min'] : 0;
                                    $max_s = $ss && $ss['nilai_max'] !== null ? (float)$ss['nilai_max'] : 99999;
                                    $std_suhu = $ss ? ($ss['nilai_min'] !== null ? $ss['nilai_min'] . ' - ' : 'Maks. ') . ($ss['nilai_max'] ?? '~') : '-';
                                ?>
                                <tr>
                                    <td class="font-weight-bold" style="font-size: 1.1rem;">TANGKI <?= $i ?></td>
                                    
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.001" name="densitas_t<?= $i ?>" class="form-control text-center text-bold" oninput="validateInput(this, <?= $min_d ?>, <?= $max_d ?>)">
                                        </div>
                                        <span class="text-xs text-muted">Std: <?= $std_dens ?></span>
                                        <span class="val-box status-default">-</span>
                                    </td>
                                    
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.01" name="viskositas_t<?= $i ?>" class="form-control text-center text-bold" oninput="validateInput(this, <?= $min_v ?>, <?= $max_v ?>)">
                                        </div>
                                        <span class="text-xs text-muted">Std: <?= $std_visko ?></span>
                                        <span class="val-box status-default">-</span>
                                    </td>
                                    
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.1" name="suhu_t<?= $i ?>" class="form-control text-center text-bold" oninput="validateInput(this, 0, <?= $max_s ?>)">
                                        </div>
                                        <span class="text-xs text-muted">Std: <?= $std_suhu ?></span>
                                        <span class="val-box status-default">-</span>
                                    </td>
                                    
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.1" name="stok_t<?= $i ?>" class="form-control text-center text-bold">
                                        </div>
                                        <span class="text-xs text-muted">Stok Aktual</span>
                                        <span class="val-box status-default" style="visibility:hidden;">-</span>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                                
                                <!-- BARIS VISCO POURING DILEBARKAN (COLSPAN) AGAR RAPI -->
                                <tr>
                                    <td class="font-weight-bold bg-light-warning" style="font-size: 1rem; color:#856404;">VISCO POURING</td>
                                    <td class="bg-light-warning">
                                        <?php
                                            $svp = $std_view["visco_pouring"] ?? null;
                                            $min_vp = $svp && $svp['nilai_min'] !== null ? (float)$svp['nilai_min'] : 0;
                                            $max_vp = $svp && $svp['nilai_max'] !== null ? (float)$svp['nilai_max'] : 99999;
                                            $std_vp_label = $svp ? ($svp['nilai_min'] ?? '~') . ' - ' . ($svp['nilai_max'] ?? '~') : '-';
                                        ?>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.1" name="visco_pouring" class="form-control text-center text-bold" oninput="validateInput(this, <?= $min_vp ?>, <?= $max_vp ?>)">
                                        </div>
                                        <span class="text-xs text-muted">Std: <?= $std_vp_label ?></span>
                                        <span class="val-box status-default">-</span>
                                    </td>
                                    <td colspan="3" class="bg-light-warning text-left pl-3 text-muted text-sm">
                                        <i class="fas fa-info-circle mr-1"></i> Nilai viskositas yang diukur saat proses <i>pouring</i> ke dalam <i>mould</i>.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- KARTU CATATAN DAN SIMPAN -->
                <div class="card card-default shadow-sm mb-5">
                    <div class="card-body">
                        <label><i class="fas fa-comment-dots text-secondary mr-1"></i> Catatan Khusus Pemeriksa QC :</label>
                        <textarea name="catatan" class="form-control" rows="3" placeholder="Masukkan detail deviasi jika ditemukan status NG..."></textarea>
                        
                        <div class="mt-4 text-right">
                            <button type="submit" name="btn_simpan_qc" class="btn btn-success btn-lg font-weight-bold shadow-sm">
                                <i class="fas fa-save mr-1"></i> SIMPAN DATA INPUT AAC 1
                            </button>
                        </div>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Fungsi validasi cerdas (Min-Max)
function validateInput(input, min, max) {
    let value = parseFloat(input.value);
    let statusCell = input.parentElement.nextElementSibling.nextElementSibling;

    if (isNaN(value) || input.value === "") {
        statusCell.innerHTML = "-";
        statusCell.className = "val-box status-default";
        return;
    }

    let isOk = (value >= min && value <= max);

    if (isOk) {
        statusCell.innerHTML = "STATUS: OK";
        statusCell.className = "val-box status-ok";
    } else {
        statusCell.innerHTML = "STATUS: NG";
        statusCell.className = "val-box status-ng";
    }
}

document.addEventListener("DOMContentLoaded", function() {
    var status = "<?= $status_message ?>";
    if (status === "success") {
        Swal.fire({ icon: 'success', title: 'Berhasil!', text: 'Data QC AAC 1 Berhasil Disimpan.', confirmButtonColor: '#28a745' });
    } else if (status.startsWith("error")) {
        Swal.fire({ icon: 'error', title: 'Gagal Menyimpan!', text: status, confirmButtonColor: '#dc3545' });
    }
    // Mencegah double submit / spam submit
    // PENTING: salin dulu nama tombol (btn_simpan_qc) ke hidden field, karena tombol disabled TIDAK terkirim
    // ke $_POST -> isset($_POST['btn_simpan_qc']) false -> data tidak masuk.
    $('#qcForm').on('submit', function() {
        var $form = $(this);
        var btn = $form.find('button[type="submit"][name]');
        btn.each(function() {
            var b = $(this);
            var name = b.attr('name');
            var val = (b.attr('value') !== undefined) ? b.attr('value') : '1';
            if ($form.find('input[type="hidden"][data-submit-name="' + name + '"]').length === 0) {
                $form.append('<input type="hidden" name="' + name + '" value="' + $('<div>').text(val).html() + '" data-submit-name="' + name + '">');
            }
        });
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> Menyimpan...');
    });
});
</script>
