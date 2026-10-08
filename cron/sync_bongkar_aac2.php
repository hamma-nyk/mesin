<?php
// cron/sync_bongkar_aac2.php
// Script otomatisasi sinkronisasi Laporan Bongkar Autoclave Lini AAC 2 - FULL DINAMIS MASE

ini_set('memory_limit', '-1'); // RAM Tanpa Batas
set_time_limit(600);           // Timeout 10 Menit

require_once __DIR__ . '/SimpleXLSX.php';
require_once __DIR__ . '/../config/database.php';

use Shuchkin\SimpleXLSX;

date_default_timezone_get() ?: date_default_timezone_set('Asia/Jakarta');

// =========================================================================
// SENSOR FILTER BULAN & TAHUN DINAMIS DARI DASHBOARD
// =========================================================================
$bulan_pilihan = isset($_GET['bulan']) && (int)$_GET['bulan'] >= 1 && (int)$_GET['bulan'] <= 12 ? (int)$_GET['bulan'] : (int)date('m');
$tahun_pilihan = isset($_GET['tahun']) && ctype_digit($_GET['tahun']) ? $_GET['tahun'] : date('Y');

$nama_bulan_indo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli',    8 => 'Agustus',  9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$folder_bulan_teks = $nama_bulan_indo[$bulan_pilihan] ?? 'Juni';
$prefix_bulan_uppercase = strtoupper($folder_bulan_teks);

// =========================================================================
// JALUR JARINGAN SHARE STORAGE LOG PABRIK DINAMIS BERDASARKAN BULAN/TAHUN
// =========================================================================
$baseFolderDinamis = '//192.168.10.14/Backup Produksi-AAC/ALICHA MANDY ALMIRA/1. LAPORAN PRODUKSI/AAC/' . $tahun_pilihan . '/' . $bulan_pilihan . '. ' . $folder_bulan_teks . '/';

// Menggunakan glob pattern yang cerdas untuk mencari berkas Laporan Bongkar Lini 2
$searchPattern = $baseFolderDinamis . '*Laporan*Bongkar*Autoclave*AAC*2*.xlsx';
$scanFiles = glob($searchPattern);

$excelFilePath = !empty($scanFiles) ? $scanFiles[0] : $baseFolderDinamis . '2. ' . $prefix_bulan_uppercase . $tahun_pilihan . '- Laporan Bongkar Autoclave Produksi AAC 2.xlsx';

if (!file_exists($excelFilePath)) {
    die("Gagal Sinkronisasi: Berkas Excel Laporan Bongkar untuk periode " . $folder_bulan_teks . " " . $tahun_pilihan . " tidak ditemukan di rute: " . $excelFilePath);
}

try {
    if ($xlsx = SimpleXLSX::parse($excelFilePath)) {
        
        // Cari index tab sheet "BRC" secara dinamis otomatis mase
        $sheetIndex = null;
        foreach ($xlsx->sheetNames() as $index => $name) {
            if (strtolower(trim($name)) === 'brc') {
                $sheetIndex = $index;
                break;
            }
        }
        if ($sheetIndex === null) { $sheetIndex = 4; } // Fallback ke index ke-4 (sheet ke-5 BRC sesuai struktur)

        $namaSheetDitemukan = $xlsx->sheetName($sheetIndex);
        $rows = $xlsx->rows($sheetIndex);
        $inserted = 0;

        if (empty($rows)) {
            throw new Exception("Berkas Excel berhasil dibuka, tetapi lembar \"{$namaSheetDitemukan}\" kosong.");
        }

        // QUERY SQL UPSERT TARGET KE POSTGRESQL (tb_qc_defect_detail)
        $sqlDefect = "INSERT INTO db_hasil_produksi_aac2.tb_qc_defect_detail (
                        tanggal, shift, regu, ukuran, hp_m3, hb_m3, sampel_m3, total_reject_m3, obs_m3,
                        gompal, pecah, lengket, pendek, dimensi, ccr, tidak_terseset, cacat_permukaan,
                        tidak_terseparating, not_cut, gelombang, wtm, linemark, updated_at
                      ) VALUES (
                        :tanggal, :shift, :regu, :ukuran, :hp_m3, :hb_m3, :sampel_m3, :total_reject_m3, :obs_m3,
                        :gompal, :pecah, :lengket, :pendek, :dimensi, :ccr, :tidak_terseset, :cacat_permukaan,
                        :tidak_terseparating, :not_cut, :gelombang, :wtm, :linemark, CURRENT_TIMESTAMP
                      ) ON CONFLICT (tanggal, shift, regu, ukuran) DO UPDATE SET
                        hp_m3 = EXCLUDED.hp_m3, hb_m3 = EXCLUDED.hb_m3, sampel_m3 = EXCLUDED.sampel_m3,
                        total_reject_m3 = EXCLUDED.total_reject_m3, obs_m3 = EXCLUDED.obs_m3,
                        gompal = EXCLUDED.gompal, pecah = EXCLUDED.pecah, lengket = EXCLUDED.lengket,
                        pendek = EXCLUDED.pendek, dimensi = EXCLUDED.dimensi, ccr = EXCLUDED.ccr,
                        tidak_terseset = EXCLUDED.tidak_terseset, cacat_permukaan = EXCLUDED.cacat_permukaan,
                        tidak_terseparating = EXCLUDED.tidak_terseparating, not_cut = EXCLUDED.not_cut,
                        gelombang = EXCLUDED.gelombang, wtm = EXCLUDED.wtm, linemark = EXCLUDED.linemark,
                        updated_at = CURRENT_TIMESTAMP";

        $stmtDefect = $pdo->prepare($sqlDefect);

        $parseNumber = function($val, $isInt = false) {
            if ($val === null || $val === '') return 0;
            if (is_int($val) || is_float($val)) return $isInt ? (int)$val : (float)$val;

            $val = trim($val);
            if (strpos($val, '=') === 0 || strtolower($val) === 'null' || $val === '-' || strpos($val, '#') !== false) return 0; 

            if (strpos($val, ',') !== false && strpos($val, '.') !== false) {
                $val = str_replace('.', '', $val); $val = str_replace(',', '.', $val);
            } elseif (strpos($val, ',') !== false) {
                $val = str_replace(',', '.', $val);
            }
            return $isInt ? (int)$val : (float)$val;
        };

        foreach ($rows as $index => $row) {
            if ($index < 7) continue; // START BARIS KE-8 DATA FIX (Index 7 mase!)

            $rawTanggal = trim($row[1] ?? ''); // Tanggal Produksi ada di Kolom B (Index 1)
            if (empty($rawTanggal) || strpos($rawTanggal, 'Tanggal') !== false || strpos($rawTanggal, 'Selisih') !== false || strpos($rawTanggal, 'TOTAL') !== false) continue;

            // Konversi format tanggal excel
            if (is_numeric($rawTanggal) && (int)$rawTanggal > 40000) {
                $tanggal = date('Y-m-d', (($rawTanggal - 25569) * 86400));
            } else {
                $tanggal = date('Y-m-d', strtotime(str_replace('/', '-', $rawTanggal)));
            }
            
            if (!$tanggal || $tanggal == '1970-01-01') continue;

            $shift = (int)trim($row[2] ?? 0); // Shift di Kolom C (Index 2)
            if ($shift !== 1 && $shift !== 2 && $shift !== 3) continue;

            $regu        = trim($row[3] ?? '-'); // Regu di Kolom D (Index 3)
            $nama_produk = trim($row[4] ?? '-'); // Ukuran di Kolom E (Index 4)
            if ($regu === '-' || empty($nama_produk) || $regu === 'TOTAL') continue;

            // =========================================================================
            // KOORDINAT SECTOR RINCIAN CACAT PCS (INDEX FIX BERDASARKAN FILE ORIGINAL)
            // =========================================================================
            $gompal              = $parseNumber($row[10] ?? 0, true);  // Kolom K (Index 10)
            $pecah               = $parseNumber($row[11] ?? 0, true);  // Kolom L (Index 11)
            $lengket             = $parseNumber($row[12] ?? 0, true);  // Kolom M (Index 12)
            $pendek              = $parseNumber($row[13] ?? 0, true);  // Kolom N (Index 13)
            $dimensi             = $parseNumber($row[14] ?? 0, true);  // Kolom O (Index 14)
            $ccr                 = $parseNumber($row[15] ?? 0, true);  // Kolom P (Index 15)
            $tidak_terseset      = $parseNumber($row[16] ?? 0, true);  // Kolom Q (Index 16)
            $cacat_permukaan     = $parseNumber($row[17] ?? 0, true);  // Kolom R (Index 17)
            $tidak_terseparating = $parseNumber($row[18] ?? 0, true);  // Kolom S (Index 18)
            $not_cut             = $parseNumber($row[19] ?? 0, true);  // Kolom T (Index 19)
            $gelombang           = $parseNumber($row[20] ?? 0, true);  // Kolom U (Index 20)
            $wtm                 = $parseNumber($row[21] ?? 0, true);  // Kolom V (Index 21)
            $linemark            = $parseNumber($row[22] ?? 0, true);  // Kolom W (Index 22)

            // =========================================================================
            // KOORDINAT SECTOR KUBIKASI M3 HARIAN (INDEX FIX BERDASARKAN FILE ORIGINAL)
            // =========================================================================
            $hp_m3           = $parseNumber($row[28] ?? 0, false); // Kolom AC (Index 28)
            $hb_m3           = $parseNumber($row[29] ?? 0, false); // Kolom AD (Index 29)
            $sampel_m3       = $parseNumber($row[30] ?? 0, false); // Kolom AE (Index 30)
            $total_reject_m3 = $parseNumber($row[31] ?? 0, false); // Kolom AF (Index 31)
            $obs_m3          = $parseNumber($row[32] ?? 0, false); // Kolom AG (Index 32)

            $stmtDefect->execute([
                ':tanggal'             => $tanggal,
                ':shift'               => $shift,
                ':regu'                => $regu,
                ':ukuran'              => $nama_produk, 
                ':hp_m3'               => $hp_m3,
                ':hb_m3'               => $hb_m3,
                ':sampel_m3'           => $sampel_m3,
                ':total_reject_m3'     => $total_reject_m3,
                ':obs_m3'              => $obs_m3,
                ':gompal'              => $gompal,  
                ':pecah'               => $pecah,  
                ':lengket'             => $lengket, 
                ':pendek'              => $pendek, 
                ':dimensi'             => $dimensi, 
                ':ccr'                 => $ccr, 
                ':tidak_terseset'      => $tidak_terseset, 
                ':cacat_permukaan'     => $cacat_permukaan, 
                ':tidak_terseparating' => $tidak_terseparating, 
                ':not_cut'             => $not_cut, 
                ':gelombang'           => $gelombang, 
                ':wtm'                 => $wtm, 
                ':linemark'            => $linemark  
            ]);

            $inserted++;
        }

        echo "Sukses Terbuka mase! Berhasil mengidentifikasi Sheet [{$namaSheetDitemukan}] dan melakukan sinkronisasi {$inserted} baris data Laporan Bongkar Lini AAC 2 Periode {$folder_bulan_teks} {$tahun_pilihan} ke PostgreSQL!";
    } else {
        throw new Exception(SimpleXLSX::parseError());
    }
} catch (Exception $e) {
    echo "Gagal Sinkronisasi File Bongkar Lini 2.";
}
