<?php
// 1. Inisialisasi Session di baris paling pertama script
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

date_default_timezone_set('Asia/Jakarta');

require_once 'includes/auth.php';

// =========================================================================
// 🛡️ PROTEKSI HALAMAN (ROLE-BASED ACCESS CONTROL)
// =========================================================================
requireAccess(['Admin IT', 'Admin QCL FC', 'Kabag QCL', 'Supervisor QCL FC']);

require_once 'config/database.php'; // Menyediakan koneksi $pdo_ms2
include_once __DIR__ . '/config/wa_gateway.php'; 

$status_message = "";
$user_aktif = $_SESSION['username'] ?? 'Sistem';
$nama_aktif = $_SESSION['nama_lengkap'] ?? 'Sistem';

// =========================================================================
// LOGIKA POST SIMPAN DATA
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_simpan_qc'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    try {
        $tanggal_raw = $_POST['tanggal'];
        $tanggal     = str_replace('T', ' ', $tanggal_raw) . ':00'; 

        $mesin   = $_POST['mesin'];
        $produk  = $_POST['produk'];
        $shift   = $_POST['shift'];
        
        $catatan_raw = trim($_POST['catatan']);
        $catatan     = sanitize_input($catatan_raw);

        // Ambil & Sanitasi Angka Desimal
        $p_b = $_POST['press_barat'] !== '' ? (float)$_POST['press_barat'] : 'NULL';
        $p_t = $_POST['press_timur'] !== '' ? (float)$_POST['press_timur'] : 'NULL';
        $ka_b = $_POST['kadar_air_before'] !== '' ? (float)$_POST['kadar_air_before'] : 'NULL';
        $ka_a = $_POST['kadar_air_after'] !== '' ? (float)$_POST['kadar_air_after'] : 'NULL';
        $d_b = $_POST['densitas_barat'] !== '' ? (float)$_POST['densitas_barat'] : 'NULL';
        $d_t = $_POST['densitas_tengah'] !== '' ? (float)$_POST['densitas_tengah'] : 'NULL';
        $d_tm = $_POST['densitas_timur'] !== '' ? (float)$_POST['densitas_timur'] : 'NULL';

        // Ambil standar dari master berdasarkan mesin (FC standar per mesin)
        $stmt_std = $pdo->prepare("SELECT kode_param, nama_param, nilai_min, nilai_max FROM db_master.tb_standar_qc WHERE lini = 'FC' AND mesin = ? AND (produk IS NULL OR produk = ?)");
        $stmt_std->execute([$mesin, $produk]);
        $std_rows = $stmt_std->fetchAll(PDO::FETCH_ASSOC);
        
        // Fallback: jika tidak ada standar per produk, cari hanya per mesin
        if (empty($std_rows)) {
            $stmt_std2 = $pdo->prepare("SELECT kode_param, nama_param, nilai_min, nilai_max FROM db_master.tb_standar_qc WHERE lini = 'FC' AND mesin = ? AND produk IS NULL");
            $stmt_std2->execute([$mesin]);
            $std_rows = $stmt_std2->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $std_map = [];
        foreach ($std_rows as $sr) {
            $std_map[$sr['kode_param']] = $sr;
        }

        // Gunakan standar dari master atau fallback ke hidden input JS
        $std_min_pb = isset($std_map['press_barat']) && $std_map['press_barat']['nilai_min'] !== null ? (float)$std_map['press_barat']['nilai_min'] : (float)$_POST['std_min_press'];
        $std_max_pb = isset($std_map['press_barat']) && $std_map['press_barat']['nilai_max'] !== null ? (float)$std_map['press_barat']['nilai_max'] : (float)$_POST['std_max_press'];
        
        $std_min_pt = isset($std_map['press_timur']) && $std_map['press_timur']['nilai_min'] !== null ? (float)$std_map['press_timur']['nilai_min'] : (float)$_POST['std_min_press'];
        $std_max_pt = isset($std_map['press_timur']) && $std_map['press_timur']['nilai_max'] !== null ? (float)$std_map['press_timur']['nilai_max'] : (float)$_POST['std_max_press'];
        
        $std_min_kab = isset($std_map['kadar_air_before']) && $std_map['kadar_air_before']['nilai_min'] !== null ? (float)$std_map['kadar_air_before']['nilai_min'] : (float)$_POST['std_min_kab'];
        $std_max_kab = isset($std_map['kadar_air_before']) && $std_map['kadar_air_before']['nilai_max'] !== null ? (float)$std_map['kadar_air_before']['nilai_max'] : (float)$_POST['std_max_kab'];
        
        $std_min_kaa = isset($std_map['kadar_air_after']) && $std_map['kadar_air_after']['nilai_min'] !== null ? (float)$std_map['kadar_air_after']['nilai_min'] : (float)$_POST['std_min_kaa'];
        $std_max_kaa = isset($std_map['kadar_air_after']) && $std_map['kadar_air_after']['nilai_max'] !== null ? (float)$std_map['kadar_air_after']['nilai_max'] : (float)$_POST['std_max_kaa'];
        
        $std_min_db = isset($std_map['densitas_barat']) && $std_map['densitas_barat']['nilai_min'] !== null ? (float)$std_map['densitas_barat']['nilai_min'] : (float)$_POST['std_min_dens'];
        $std_max_db = isset($std_map['densitas_barat']) && $std_map['densitas_barat']['nilai_max'] !== null ? (float)$std_map['densitas_barat']['nilai_max'] : (float)$_POST['std_max_dens'];
        
        $std_min_dt = isset($std_map['densitas_tengah']) && $std_map['densitas_tengah']['nilai_min'] !== null ? (float)$std_map['densitas_tengah']['nilai_min'] : (float)$_POST['std_min_dens'];
        $std_max_dt = isset($std_map['densitas_tengah']) && $std_map['densitas_tengah']['nilai_max'] !== null ? (float)$std_map['densitas_tengah']['nilai_max'] : (float)$_POST['std_max_dens'];
        
        $std_min_dtm = isset($std_map['densitas_timur']) && $std_map['densitas_timur']['nilai_min'] !== null ? (float)$std_map['densitas_timur']['nilai_min'] : (float)$_POST['std_min_dens'];
        $std_max_dtm = isset($std_map['densitas_timur']) && $std_map['densitas_timur']['nilai_max'] !== null ? (float)$std_map['densitas_timur']['nilai_max'] : (float)$_POST['std_max_dens'];

        $list_ng = [];
        $list_ok = [];
        if ($p_b !== 'NULL') {
            if ($p_b < $std_min_pb || $p_b > $std_max_pb) { $list_ng[] = "❌ PRESS BARAT: *$p_b* (Std: $std_min_pb-$std_max_pb)"; }
            else { $list_ok[] = "✅ PRESS BARAT: *$p_b* (Std: $std_min_pb-$std_max_pb)"; }
        }
        if ($p_t !== 'NULL') {
            if ($p_t < $std_min_pt || $p_t > $std_max_pt) { $list_ng[] = "❌ PRESS TIMUR: *$p_t* (Std: $std_min_pt-$std_max_pt)"; }
            else { $list_ok[] = "✅ PRESS TIMUR: *$p_t* (Std: $std_min_pt-$std_max_pt)"; }
        }
        if ($ka_b !== 'NULL') {
            if ($ka_b < $std_min_kab || $ka_b > $std_max_kab) { $list_ng[] = "❌ K.AIR BEF: *$ka_b* (Std: $std_min_kab-$std_max_kab)"; }
            else { $list_ok[] = "✅ K.AIR BEF: *$ka_b* (Std: $std_min_kab-$std_max_kab)"; }
        }
        if ($ka_a !== 'NULL') {
            if ($ka_a < $std_min_kaa || $ka_a > $std_max_kaa) { $list_ng[] = "❌ K.AIR AFT: *$ka_a* (Std: $std_min_kaa-$std_max_kaa)"; }
            else { $list_ok[] = "✅ K.AIR AFT: *$ka_a* (Std: $std_min_kaa-$std_max_kaa)"; }
        }
        if ($d_b !== 'NULL') {
            if ($d_b < $std_min_db || $d_b > $std_max_db) { $list_ng[] = "❌ DENS BARAT: *$d_b* (Std: $std_min_db-$std_max_db)"; }
            else { $list_ok[] = "✅ DENS BARAT: *$d_b* (Std: $std_min_db-$std_max_db)"; }
        }
        if ($d_t !== 'NULL') {
            if ($d_t < $std_min_dt || $d_t > $std_max_dt) { $list_ng[] = "❌ DENS TENGAH: *$d_t* (Std: $std_min_dt-$std_max_dt)"; }
            else { $list_ok[] = "✅ DENS TENGAH: *$d_t* (Std: $std_min_dt-$std_max_dt)"; }
        }
        if ($d_tm !== 'NULL') {
            if ($d_tm < $std_min_dtm || $d_tm > $std_max_dtm) { $list_ng[] = "❌ DENS TIMUR: *$d_tm* (Std: $std_min_dtm-$std_max_dtm)"; }
            else { $list_ok[] = "✅ DENS TIMUR: *$d_tm* (Std: $std_min_dtm-$std_max_dtm)"; }
        }

        $std_snapshot = json_encode($std_map, JSON_UNESCAPED_UNICODE);

        // EKSEKUSI INSERT
        $sqlDirect = "INSERT INTO parameter_fc (
            tanggal, mesin, produk, shift, 
            press_barat, press_timur, kadar_air_before, kadar_air_after, 
            densitas_barat, densitas_tengah, densitas_timur, catatan, created_by, std_snapshot
        ) VALUES (
            ?, ?, ?, ?, 
            ?, ?, ?, ?, 
            ?, ?, ?, ?, ?, ?
        )";

        $stmt = $pdo_ms2->prepare($sqlDirect);
        $stmt->execute([
            $tanggal, $mesin, $produk, $shift,
            $p_b, $p_t, $ka_b, $ka_a,
            $d_b, $d_t, $d_tm, $catatan, $user_aktif, $std_snapshot
        ]);
        $_SESSION['qc_save_status'] = "success";

        // =========================================================================
        // WA GATEWAY NOTIFIKASI (OK & NG)
        // =========================================================================
        if (!empty($wa_recipients_qcl_fc) && (!empty($list_ok) || !empty($list_ng))) {
            if (!empty($list_ng)) {
                $message_text = "⚠️ *ALERT DEVIASI MUTU QC (FC)*\n\n";
                $message_text .= "🚨 Ditemukan parameter di luar standar.\n\n";
            } else {
                $message_text = "✅ *LAPORAN QC (FC) - SEMUA OK*\n\n";
                $message_text .= "Seluruh parameter memenuhi standar.\n\n";
            }

            $message_text .= "📋 *Info Cek:* \n";
            $message_text .= "⏱️ Waktu: " . date('d-m-Y H:i', strtotime($tanggal)) . " WIB\n";
            $message_text .= "⚙️ Mesin: *$mesin*\n";
            $message_text .= "📦 Produk: *$produk*\n";
            $message_text .= "👥 Regu: *$shift*\n";
			$message_text .= "👤 Input Oleh: *$nama_aktif ($user_aktif)*\n\n";

            if (!empty($list_ng)) {
                $message_text .= "❌ *Deviasi (NG):* \n";
                foreach ($list_ng as $item_ng) { $message_text .= $item_ng . "\n"; }
                $message_text .= "\n";
            }

            if (!empty($list_ok)) {
                $message_text .= "✅ *Parameter OK:* \n";
                foreach ($list_ok as $item_ok) { $message_text .= $item_ok . "\n"; }
                $message_text .= "\n";
            }

            $message_text .= "📝 *Catatan Proses Kontrol:* \n_" . (!empty($catatan_raw) ? $catatan_raw : "N/A") . "_\n\n";
            if (!empty($list_ng)) {
                $message_text .= "🔧 _Mohon untuk segera ditindaklanjuti._";
            }

            foreach ($wa_recipients_qcl_fc as $target_wa_number) {
                $payload = json_encode(["number" => $target_wa_number, "type" => "text", "text" => $message_text]);
                $ch = curl_init($wa_server_url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 4);
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($err || $code >= 400) {
                    write_security_log('security', "Notif WA FC GAGAL ke $target_wa_number: err=$err code=$code url=$wa_server_url");
                }
            }
        }
        
        // Post-Redirect-Get pattern to prevent double submit on refresh
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

// Ambil standar FC dari master untuk render JS
$stmt_fc_std = $pdo->prepare("SELECT mesin, produk, kode_param, nilai_min, nilai_max FROM db_master.tb_standar_qc WHERE lini = 'FC' ORDER BY mesin, kode_param");
$stmt_fc_std->execute();
$fc_std_raw = $stmt_fc_std->fetchAll(PDO::FETCH_ASSOC);
$fc_std_js = [];
foreach ($fc_std_raw as $r) {
    $key = $r['mesin'];
    $fc_std_js[$key][$r['kode_param']] = ['min' => $r['nilai_min'] !== null ? (float)$r['nilai_min'] : null, 'max' => $r['nilai_max'] !== null ? (float)$r['nilai_max'] : null];
}

require_once 'includes/header.php';
?>

<style>
    .status-box { font-weight: bold; text-align: center; border-radius: 4px; padding: 5px; }
    .status-ok { background-color: #dcfce7; color: #166534; }
    .status-ng { background-color: #fee2e2; color: #991b1b; }
    .status-default { background-color: #f3f4f6; color: #6c757d; }
    .table td, .table th { vertical-align: middle; }
</style>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <h1 class="m-0"><i class="fas fa-check-double text-primary mr-2"></i>QC Input System - Mesin FC</h1>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">
            
            <form id="qcForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="std_min_press" id="val_min_pb" value="">
                <input type="hidden" name="std_max_press" id="val_max_pb" value="">
                <input type="hidden" name="std_min_press_t" id="val_min_pt" value="">
                <input type="hidden" name="std_max_press_t" id="val_max_pt" value="">
                <input type="hidden" name="std_min_kab" id="val_min_kab" value="">
                <input type="hidden" name="std_max_kab" id="val_max_kab" value="">
                <input type="hidden" name="std_min_kaa" id="val_min_kaa" value="">
                <input type="hidden" name="std_max_kaa" id="val_max_kaa" value="">
                <input type="hidden" name="std_min_dens" id="val_min_db" value="">
                <input type="hidden" name="std_max_dens" id="val_max_db" value="">
                <input type="hidden" name="std_min_dens_t" id="val_min_dt" value="">
                <input type="hidden" name="std_max_dens_t" id="val_max_dt" value="">
                <input type="hidden" name="std_min_dens_tm" id="val_min_dtm" value="">
                <input type="hidden" name="std_max_dens_tm" id="val_max_dtm" value="">

                <div class="card card-default shadow-sm">
                    <div class="card-header bg-light"><h3 class="card-title">Administrasi Pemeriksaan</h3></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 form-group">
                                <label>Tanggal & Waktu</label>
                                <input type="datetime-local" name="tanggal" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                            </div>
                            <div class="col-md-3 form-group">
                                <label>Mesin Lini</label>
                                <select name="mesin" id="opt_mesin" class="form-control" required>
                                    <option value="FS-01">FS-01</option>
                                    <option value="FS-02">FS-02</option>
                                    <option value="FS-03">FS-03</option>
                                    <option value="FS-05">FS-05</option>
                                    <option value="FS-06">FS-06</option>
                                    <option value="FS-07">FS-07</option>
                                    <option value="FS-08">FS-08</option>
                                </select>
                            </div>
                            <div class="col-md-3 form-group">
                                <label>Varian Produk</label>
                                <select name="produk" id="opt_produk" class="form-control" required></select>
                            </div>
                            <div class="col-md-3 form-group">
                                <label>Grup Shift</label>
                                <select name="shift" class="form-control" required>
                                    <option value="Shift 1">Shift 1</option>
                                    <option value="Shift 2">Shift 2</option>
                                    <option value="Shift 3">Shift 3</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info py-2 text-sm shadow-sm" id="std_alert_info">
                    <i class="fas fa-info-circle"></i> Standar parameter otomatis menyesuaikan dengan kombinasi Mesin & Produk yang dipilih.
                </div>

                <div class="card card-primary card-outline shadow-sm">
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-bordered table-striped m-0 text-center">
                            <thead class="bg-primary text-white">
                                <tr>
                                    <th style="width: 25%;">Parameter QC</th>
                                    <th>Standard Dinamis</th>
                                    <th style="width: 25%;">Input Laboratorium</th>
                                    <th style="width: 15%;">Validasi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-left font-weight-bold">PRESS BARAT (Bar)</td>
                                    <td><span id="lbl_std_pb">-</span></td>
                                    <td><input type="number" step="0.01" name="press_barat" class="form-control text-center text-bold inp-val" oninput="evalField(this, 'pb')"></td>
                                    <td class="status-box status-default">-</td>
                                </tr>
                                <tr>
                                    <td class="text-left font-weight-bold">PRESS TIMUR (Bar)</td>
                                    <td><span id="lbl_std_pt">-</span></td>
                                    <td><input type="number" step="0.01" name="press_timur" class="form-control text-center text-bold inp-val" oninput="evalField(this, 'pt')"></td>
                                    <td class="status-box status-default">-</td>
                                </tr>
                                <tr>
                                    <td class="text-left font-weight-bold">KADAR AIR BEFORE (%)</td>
                                    <td><span id="lbl_std_kab">-</span></td>
                                    <td><input type="number" step="0.01" name="kadar_air_before" class="form-control text-center text-bold inp-val" oninput="evalField(this, 'kab')"></td>
                                    <td class="status-box status-default">-</td>
                                </tr>
                                <tr>
                                    <td class="text-left font-weight-bold">KADAR AIR AFTER (%)</td>
                                    <td><span id="lbl_std_kaa">-</span></td>
                                    <td><input type="number" step="0.01" name="kadar_air_after" class="form-control text-center text-bold inp-val" oninput="evalField(this, 'kaa')"></td>
                                    <td class="status-box status-default">-</td>
                                </tr>
                                <tr>
                                    <td class="text-left font-weight-bold">DENSITAS BARAT (gr/cm³)</td>
                                    <td><span id="lbl_std_db">-</span></td>
                                    <td><input type="number" step="0.01" name="densitas_barat" class="form-control text-center text-bold inp-val" oninput="evalField(this, 'db')"></td>
                                    <td class="status-box status-default">-</td>
                                </tr>
                                <tr>
                                    <td class="text-left font-weight-bold">DENSITAS TENGAH (gr/cm³)</td>
                                    <td><span id="lbl_std_dt">-</span></td>
                                    <td><input type="number" step="0.01" name="densitas_tengah" class="form-control text-center text-bold inp-val" oninput="evalField(this, 'dt')"></td>
                                    <td class="status-box status-default">-</td>
                                </tr>
                                <tr>
                                    <td class="text-left font-weight-bold">DENSITAS TIMUR (gr/cm³)</td>
                                    <td><span id="lbl_std_dtm">-</span></td>
                                    <td><input type="number" step="0.01" name="densitas_timur" class="form-control text-center text-bold inp-val" oninput="evalField(this, 'dtm')"></td>
                                    <td class="status-box status-default">-</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card card-default shadow-sm">
                    <div class="card-body">
                        <label><i class="fas fa-comment-dots text-secondary mr-1"></i> Catatan Proses Kontrol:</label>
                        <textarea name="catatan" class="form-control" rows="2" placeholder="Detail temuan / Tindakan koreksi..."></textarea>
                        
                        <div class="mt-3 text-right">
                            <button type="submit" name="btn_simpan_qc" class="btn btn-success btn-lg font-weight-bold">
                                <i class="fas fa-save mr-1"></i> SIMPAN HASIL QC FC
                            </button>
                        </div>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// =========================================================
// MAPPING PRODUK BERDASARKAN MESIN (CASCADING DROPDOWN)
// =========================================================
const mapProduk = {
    'FS-01': ['NUSABESS 11', 'NUSABESS 14'],
    'FS-02': ['NUSABESS 6 SINGLE', 'NUSABESS 6 DOUBLE'],
    'FS-03': ['NUSABOARD/NUSAWOOD'],
    'FS-05': ['NUSABESS 11'],
    'FS-06': ['NUSABOARD/NUSAWOOD'],
    'FS-07': ['NUSABOARD/NUSAWOOD'],
    'FS-08': ['NUSABESS 11', 'NUSABESS 14']
};

function renderDropdownProduk() {
    let mesin = $('#opt_mesin').val();
    let optHtml = '';
    
    if (mapProduk[mesin]) {
        mapProduk[mesin].forEach(function(item) {
            optHtml += `<option value="${item}">${item}</option>`;
        });
    }
    $('#opt_produk').html(optHtml);
    updateStandards(); // Setelah produk dirender, update standar tabelnya
}

// =========================================================
// STANDAR DARI MASTER DATABASE (DIINJECT DARI PHP)
// =========================================================
const masterStd = <?= json_encode($fc_std_js) ?>;

function updateStandards() {
    let mesin = $('#opt_mesin').val();
    let produk = $('#opt_produk').val();
    
    let min_pb=0, max_pb=0, min_pt=0, max_pt=0, min_kab=0, max_kab=0, min_kaa=0, max_kaa=0, min_db=0, max_db=0, min_dt=0, max_dt=0, min_dtm=0, max_dtm=0;

    if (masterStd[mesin]) {
        let s = masterStd[mesin];
        if (s.press_barat) { min_pb = s.press_barat.min || 0; max_pb = s.press_barat.max || 0; }
        if (s.press_timur) { min_pt = s.press_timur.min || 0; max_pt = s.press_timur.max || 0; }
        if (s.kadar_air_before) { min_kab = s.kadar_air_before.min || 0; max_kab = s.kadar_air_before.max || 0; }
        if (s.kadar_air_after) { min_kaa = s.kadar_air_after.min || 0; max_kaa = s.kadar_air_after.max || 0; }
        if (s.densitas_barat) { min_db = s.densitas_barat.min || 0; max_db = s.densitas_barat.max || 0; }
        if (s.densitas_tengah) { min_dt = s.densitas_tengah.min || 0; max_dt = s.densitas_tengah.max || 0; }
        if (s.densitas_timur) { min_dtm = s.densitas_timur.min || 0; max_dtm = s.densitas_timur.max || 0; }
    }

    // Tulis ke Hidden Input (Untuk Backend PHP Validation & Alert)
    $('#val_min_pb').val(min_pb); $('#val_max_pb').val(max_pb);
    $('#val_min_pt').val(min_pt); $('#val_max_pt').val(max_pt);
    $('#val_min_kab').val(min_kab); $('#val_max_kab').val(max_kab);
    $('#val_min_kaa').val(min_kaa); $('#val_max_kaa').val(max_kaa);
    $('#val_min_db').val(min_db);  $('#val_max_db').val(max_db);
    $('#val_min_dt').val(min_dt);  $('#val_max_dt').val(max_dt);
    $('#val_min_dtm').val(min_dtm);  $('#val_max_dtm').val(max_dtm);

    // Render Text di Tabel HTML
    $('#lbl_std_pb').text(min_pb.toString().replace('.',',') + ' - ' + max_pb.toString().replace('.',','));
    $('#lbl_std_pt').text(min_pt.toString().replace('.',',') + ' - ' + max_pt.toString().replace('.',','));
    $('#lbl_std_kab').text(min_kab + ' - ' + max_kab);
    $('#lbl_std_kaa').text(min_kaa + ' - ' + max_kaa);
    $('#lbl_std_db').text(min_db.toFixed(2).replace('.',',') + ' - ' + max_db.toFixed(2).replace('.',','));
    $('#lbl_std_dt').text(min_dt.toFixed(2).replace('.',',') + ' - ' + max_dt.toFixed(2).replace('.',','));
    $('#lbl_std_dtm').text(min_dtm.toFixed(2).replace('.',',') + ' - ' + max_dtm.toFixed(2).replace('.',','));

    // Triger validasi ulang semua input yang sudah terisi saat standar berganti
    $('.inp-val').each(function() {
        if($(this).val() !== '') {
            let type = '';
            if(this.name === 'press_barat') type = 'pb';
            if(this.name === 'press_timur') type = 'pt';
            if(this.name === 'kadar_air_before') type = 'kab';
            if(this.name === 'kadar_air_after') type = 'kaa';
            if(this.name === 'densitas_barat') type = 'db';
            if(this.name === 'densitas_tengah') type = 'dt';
            if(this.name === 'densitas_timur') type = 'dtm';
            evalField(this, type);
        }
    });
}

function evalField(input, type) {
    let val = parseFloat(input.value);
    let cell = input.parentElement.nextElementSibling;
    if (isNaN(val)) {
        cell.innerHTML = "-"; cell.className = "status-box status-default"; return;
    }

    let min = parseFloat($('#val_min_' + type).val()) || 0;
    let max = parseFloat($('#val_max_' + type).val()) || 0;

    if (val >= min && val <= max) {
        cell.innerHTML = "OK"; cell.className = "status-box status-ok";
    } else {
        cell.innerHTML = "NG"; cell.className = "status-box status-ng";
    }
}

$(document).ready(function() {
    // Render awal saat halaman dimuat
    renderDropdownProduk();

    // Event saat dropdown Mesin diganti
    $('#opt_mesin').on('change', function() {
        renderDropdownProduk();
        
        // Animasi notifikasi pergantian standar
        $('#std_alert_info').removeClass('alert-info').addClass('alert-warning').html('<i class="fas fa-sync fa-spin"></i> Memuat varian produk dan memperbarui standar...').fadeIn();
        setTimeout(() => { 
            $('#std_alert_info').removeClass('alert-warning').addClass('alert-info').html('<i class="fas fa-info-circle"></i> Standar parameter otomatis menyesuaikan dengan kombinasi Mesin & Produk yang dipilih.'); 
        }, 1500);
    });

    // Event saat dropdown Produk diganti (berjaga-jaga jika ada 1 mesin beda standar antar produk)
    $('#opt_produk').on('change', function() {
        updateStandards();
    });

    // Notifikasi Flash Message
    var status = "<?= $status_message ?>";
    if (status === "success") {
        Swal.fire({ icon: 'success', title: 'Berhasil Disimpan!', confirmButtonColor: '#28a745' });
    } else if (status.startsWith("error")) {
        Swal.fire({ icon: 'error', title: 'Gagal!', text: status, confirmButtonColor: '#dc3545' });
    }

    // Mencegah double submit / spam submit
    // PENTING: jangan disable tombol submit (name="btn_simpan_qc") karena tombol disabled TIDAK terkirim
    // ke $_POST -> isset($_POST['btn_simpan_qc']) false -> data tidak masuk. Salin dulu ke hidden field.
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
