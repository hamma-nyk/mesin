<?php
// cron/sync_rekapkomposisiaac1.php
// VERSI FIXED TOTAL: SEDOT MASSAL KOMPOSISI BAHAN BAKU LINI 1 (FULL DINAMIS PER PERIODE)

ini_set('memory_limit', '-1');
set_time_limit(600);

require_once __DIR__ . '/SimpleXLSX.php';
require_once __DIR__ . '/../config/database.php';

use Shuchkin\SimpleXLSX;

date_default_timezone_get() ?: date_default_timezone_set('Asia/Jakarta');

$jam_sekarang = (int)date('H');
$isAjaxManual = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($jam_sekarang < 15 && !$isAjaxManual) {
    exit("Sistem Standby: Penarikan otomatis terjadwal aman setiap hari pada pukul 15:00 WIB.");
}

// =========================================================================
// INTERSEPTOR FILTER TIMELINE DINAMIS DARI FRONTEND
// =========================================================================
$bulan_target = isset($_GET['bulan']) && (int)$_GET['bulan'] >= 1 && (int)$_GET['bulan'] <= 12 ? (int)$_GET['bulan'] : (int)date('m');
$tahun_target = isset($_GET['tahun']) && ctype_digit($_GET['tahun']) ? $_GET['tahun'] : date('Y');

$nama_bulan_indo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli',    8 => 'Agustus',  9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$folder_bulan = $nama_bulan_indo[$bulan_target] ?? 'Juni';

// PATH JARINGAN UTAMA LINI 1
$baseFolderDinamis = '//192.168.30.38/1. laporan produksi/AAC/' . $tahun_target . '/' . $bulan_target . '. ' . $folder_bulan . '/';
$excelFilePath     = $baseFolderDinamis . 'KOMPOSISI AAC 1 ' . $tahun_target . '.xlsx';

$scanFiles = glob($baseFolderDinamis . '*KOMPOSISI*AAC*1*.xlsx');
if (!empty($scanFiles)) { $excelFilePath = $scanFiles[0]; }

if (!file_exists($excelFilePath)) {
    die("Gagal Sinkronisasi: Berkas Excel tidak ditemukan di jalur jaringan: " . $excelFilePath);
}

try {
    if ($xlsx = SimpleXLSX::parse($excelFilePath)) {
        
        // AUTO-MAPPING INDEX SHEET: Mencari sheet yang namanya persis nama bulan (misal "Juni" atau "Juli")
        $sheet_index_target = 0; // Default fallback sheet pertama
        foreach ($xlsx->sheetNames() as $idx => $s_name) {
            if (strtolower(trim($s_name)) === strtolower($folder_bulan)) {
                $sheet_index_target = $idx;
                break;
            }
        }

        $rows = $xlsx->rows($sheet_index_target);
        if (empty($rows)) { throw new Exception("Gagal mengurai isi lembar rekap baris."); }

        // =====================================================================
        // FUNGSI PEMBERSIH ANGKA — VERSI DIPERKUAT
        // =====================================================================
        $cleanNum = function($val) {
            if ($val === null || $val === '') return 0.000;
            if (is_int($val) || is_float($val)) return (float)$val;

            $val = trim((string)$val);
            $errorTokens = ['#DIV/0!', '#VALUE!', '#REF!', '#N/A', '#NAME?', '#NULL!', '#NUM!'];
            foreach ($errorTokens as $err) {
                if (stripos($val, $err) !== false) return 0.000;
            }

            if ($val === '-' || $val === '–' || $val === '—') return 0.000;
            if (strpos($val, '=') === 0) return 0.000;

            if (strpos($val, ',') !== false) {
                if (strpos($val, '.') !== false) {
                    $val = str_replace('.', '', $val);
                }
                $val = str_replace(',', '.', $val);
            }

            if (!is_numeric($val)) return 0.000;
            return (float)$val;
        };

        // =====================================================================
        // UPSERT POSTGRESQL — 32 KOLOM LENGKAP KUNCI MATI
        // =====================================================================
        $sqlUpsert = "INSERT INTO db_hasil_produksi_plc.tb_produksi_plc_citicon
                        (tanggal, shift, regu, plc_target_casting_m3, brc_hasil_casting_m3,
                         brc_alu_mepco, brc_alu_platinum, brc_alu_zouping, brc_alu_focoun, brc_kapur_granul,
                         brc_kapur_silo10, brc_kapur_silo14, brc_kapur_kg, brc_semen_kg, brc_sprint_pro,
                         brc_pasir_silica, brc_gypsum_kg, total_pasir_kg, total_gypsum_kg, total_slurry_rekap,
                         total_alu_mepco, total_alu_platinum, total_alu_zouping, total_aluminium_kg, total_kapur_granul,
                         total_kapur_kg, total_semen_kg, slurry_logistik, return_logistik, air_liter,
                         waste_mortar, updated_at)
                      VALUES (:tanggal, :shift, :regu, :plc_target, :brc_casting,
                              :b_alu_mep, :b_alu_plat, :b_alu_zou, :b_alu_foc, :b_kap_gran,
                              :b_kap_s10, :b_kap_s14, :b_kap_kg, :b_sem_kg, :b_sprint,
                              :b_pasir, :b_gyp, :t_pasir, :t_gyp, :t_slurry_r,
                              :t_alu_mep, :t_alu_plat, :t_alu_zou, :t_alu_foc, :t_kap_gran,
                              :t_kap_pwd, :t_sem_kg, :s_log, :r_log, :air,
                              :waste, CURRENT_TIMESTAMP)
                      ON CONFLICT (tanggal, shift) DO UPDATE SET
                        regu                   = EXCLUDED.regu,
                        plc_target_casting_m3  = EXCLUDED.plc_target_casting_m3,
                        brc_hasil_casting_m3   = EXCLUDED.brc_hasil_casting_m3,
                        brc_alu_mepco          = EXCLUDED.brc_alu_mepco,
                        brc_alu_platinum       = EXCLUDED.brc_alu_platinum,
                        brc_alu_zouping        = EXCLUDED.brc_alu_zouping,
                        brc_alu_focoun         = EXCLUDED.brc_alu_focoun,
                        brc_kapur_granul       = EXCLUDED.brc_kapur_granul,
                        brc_kapur_silo10       = EXCLUDED.brc_kapur_silo10,
                        brc_kapur_silo14       = EXCLUDED.brc_kapur_silo14,
                        brc_kapur_kg           = EXCLUDED.brc_kapur_kg,
                        brc_semen_kg           = EXCLUDED.brc_semen_kg,
                        brc_sprint_pro         = EXCLUDED.brc_sprint_pro,
                        brc_pasir_silica       = EXCLUDED.brc_pasir_silica,
                        brc_gypsum_kg          = EXCLUDED.brc_gypsum_kg,
                        total_pasir_kg         = EXCLUDED.total_pasir_kg,
                        total_gypsum_kg        = EXCLUDED.total_gypsum_kg,
                        total_slurry_rekap     = EXCLUDED.total_slurry_rekap,
                        total_alu_mepco        = EXCLUDED.total_alu_mepco,
                        total_alu_platinum     = EXCLUDED.total_alu_platinum,
                        total_alu_zouping      = EXCLUDED.total_alu_zouping,
                        total_aluminium_kg     = EXCLUDED.total_aluminium_kg,
                        total_kapur_granul     = EXCLUDED.total_kapur_granul,
                        total_kapur_kg         = EXCLUDED.total_kapur_kg,
                        total_semen_kg         = EXCLUDED.total_semen_kg,
                        slurry_logistik        = EXCLUDED.slurry_logistik,
                        return_logistik        = EXCLUDED.return_logistik,
                        air_liter              = EXCLUDED.air_liter,
                        waste_mortar           = EXCLUDED.waste_mortar,
                        updated_at             = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sqlUpsert);
        $updated_rows       = 0;
        $skipped_rows       = 0;
        $last_valid_tanggal = null;

        foreach ($rows as $index => $row) {
            if ($index < 9) continue; // Mulai baris ke-10 Excel

            // --- TANGGAL ---
            $rawTanggal = trim($row[0] ?? '');

            if (empty($rawTanggal)) {
                if ($last_valid_tanggal === null) continue;
                $tanggal = $last_valid_tanggal;
            } else {
                if (stripos($rawTanggal, 'Rekap') !== false || stripos($rawTanggal, 'TOTAL') !== false) continue;

                if (is_numeric($rawTanggal) && (int)$rawTanggal > 40000) {
                    $tanggal = date('Y-m-d', ($rawTanggal - 25569) * 86400);
                } else {
                    $parsed = strtotime(str_replace('/', '-', $rawTanggal));
                    if ($parsed === false) { $skipped_rows++; continue; }
                    $tanggal = date('Y-m-d', $parsed);
                }
                $last_valid_tanggal = $tanggal;
            }

            // --- SHIFT ---
            $shift_num = (int)trim($row[1] ?? 0);
            if (!in_array($shift_num, [1, 2, 3])) { $skipped_rows++; continue; }
            $shift_string = "Shift " . $shift_num;

            // --- REGU ---
            $regu = trim($row[2] ?? '-');
            if ($regu === '') $regu = '-';

            // EKSEKUSI DATA
            $stmt->execute([
                ':tanggal'    => $tanggal,
                ':shift'      => $shift_string,
                ':regu'       => $regu,
                ':plc_target' => $cleanNum($row[3]  ?? 0),   
                ':brc_casting'=> $cleanNum($row[4]  ?? 0),   
                ':b_alu_mep'  => $cleanNum($row[5]  ?? 0),   
                ':b_alu_plat' => $cleanNum($row[6]  ?? 0),   
                ':b_alu_zou'  => $cleanNum($row[7]  ?? 0),   
                ':b_alu_foc'  => $cleanNum($row[8]  ?? 0),   
                ':b_kap_gran' => $cleanNum($row[9]  ?? 0),   
                ':b_kap_s10'  => $cleanNum($row[10] ?? 0),   
                ':b_kap_s14'  => $cleanNum($row[11] ?? 0),   
                ':b_kap_kg'   => $cleanNum($row[12] ?? 0),   
                ':b_sem_kg'   => $cleanNum($row[13] ?? 0),   
                ':b_sprint'   => $cleanNum($row[14] ?? 0),   
                ':b_pasir'    => $cleanNum($row[15] ?? 0),   
                ':b_gyp'      => $cleanNum($row[16] ?? 0),   
                ':t_pasir'    => $cleanNum($row[17] ?? 0),   
                ':t_gyp'      => $cleanNum($row[18] ?? 0),   
                ':t_slurry_r' => $cleanNum($row[19] ?? 0),   
                ':t_alu_mep'  => $cleanNum($row[20] ?? 0),   
                ':t_alu_plat' => $cleanNum($row[21] ?? 0),   
                ':t_alu_zou'  => $cleanNum($row[22] ?? 0),   
                ':t_alu_foc'  => $cleanNum($row[23] ?? 0),   
                ':t_kap_gran' => $cleanNum($row[24] ?? 0),   
                ':t_kap_pwd'  => $cleanNum($row[25] ?? 0),   
                ':t_sem_kg'   => $cleanNum($row[26] ?? 0),   
                ':s_log'      => $cleanNum($row[28] ?? 0),   
                ':r_log'      => $cleanNum($row[29] ?? 0),   
                ':air'        => $cleanNum($row[30] ?? 0),   
                ':waste'      => $cleanNum($row[31] ?? 0),   
            ]);

            $updated_rows++;
        }

        echo "Sukses! Berhasil memproses lembar [{$folder_bulan}] dari berkas Komposisi Lini 1. Sebanyak {$updated_rows} baris material sukses disedot masuk database mase! ({$skipped_rows} baris tidak valid dilewati)";
    }
} catch (Exception $e) {
    echo "Gagal Sinkronisasi Komposisi Lini 1.";
}
