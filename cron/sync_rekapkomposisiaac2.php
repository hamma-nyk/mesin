<?php
// cron/sync_rekapkomposisiaac2.php
// Script otomatisasi ekstraksi komposisi material Lini AAC 2 - Aktual 100% Sesuai Timbangan Excel

ini_set('memory_limit', '-1'); 
set_time_limit(600);           

require_once __DIR__ . '/SimpleXLSX.php';
require_once __DIR__ . '/../config/database.php';

use Shuchkin\SimpleXLSX;

date_default_timezone_get() ?: date_default_timezone_set('Asia/Jakarta');

// =========================================================================
// JALUR JARINGAN & PENENTUAN TAB SHEET BULANAN DINAMIS
// =========================================================================
$baseFolder = '//192.168.10.14/Backup Produksi-AAC/ALICHA MANDY ALMIRA/2. MASTER KOMPOSISI SISTEM/';
$excelFilePath = $baseFolder . 'Komposisi -- 2026.xlsx';

// Detektor Nama Tab Sheet berdasarkan singkatan nama bulan Indonesia berjalan
$bulan_ini = (int)date('m');
$nama_sheet_indo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$target_sheet_name = $nama_sheet_indo[$bulan_ini] ?? 'Juni';

if (!file_exists($excelFilePath)) {
    throw new Exception("Berkas Excel Master Komposisi tidak ditemukan pada jalur: " . $excelFilePath);
}

try {
    if ($xlsx = SimpleXLSX::parse($excelFilePath)) {
        
        $sheet_names = $xlsx->sheetNames();
        $sheetIndex = null;
        
        // Cari index tab berdasarkan nama bulan berjalan
        foreach ($sheet_names as $index => $name) {
            if (strtolower(trim($name)) === strtolower($target_sheet_name)) {
                $sheetIndex = $index;
                break;
            }
        }

        if ($sheetIndex === null) {
            throw new Exception("Tab Sheet untuk bulan '" . $target_sheet_name . "' tidak ditemukan di file Excel.");
        }

        $rows = $xlsx->rows($sheetIndex);
        $inserted = 0;

        if (empty($rows)) {
            throw new Exception("Data pada sheet '" . $target_sheet_name . "' kosong.");
        }

        // =========================================================================
        // QUERY UPSERT MASTER AKURASI TIMBANGAN MATERIAL
        // =========================================================================
        $sqlUpsert = "INSERT INTO db_hasil_produksi_aac2.tb_komposisi (
            tanggal, shift, regu, nama_produk, total_casting,
            alu_focoun, alu_platinum, alu_zouping,
            kapur_silo17, kapur_silo18, kapur_total,
            semen_silo15, semen_silo16, semen_total,
            pasir_kg, gypsum_kg, fresh_slurry, return_slurry, air_kg, updated_at
        ) VALUES (
            :tanggal, :shift, :regu, :nama_produk, :total_casting,
            :alu_focoun, :alu_platinum, :alu_zouping,
            :kapur_silo17, :kapur_silo18, :kapur_total,
            :semen_silo15, :semen_silo16, :semen_total,
            :pasir_kg, :gypsum_kg, :fresh_slurry, :return_slurry, :air_kg, CURRENT_TIMESTAMP
        ) ON CONFLICT (tanggal, shift, regu) DO UPDATE SET
            nama_produk   = EXCLUDED.nama_produk,
            total_casting = EXCLUDED.total_casting,
            alu_focoun    = EXCLUDED.alu_focoun,
            alu_platinum  = EXCLUDED.alu_platinum,
            alu_zouping   = EXCLUDED.alu_zouping,
            kapur_silo17  = EXCLUDED.kapur_silo17,
            kapur_silo18  = EXCLUDED.kapur_silo18,
            kapur_total   = EXCLUDED.kapur_total,
            semen_silo15  = EXCLUDED.semen_silo15,
            semen_silo16  = EXCLUDED.semen_silo16,
            semen_total   = EXCLUDED.semen_total,
            pasir_kg      = EXCLUDED.pasir_kg,
            gypsum_kg     = EXCLUDED.gypsum_kg,
            fresh_slurry  = EXCLUDED.fresh_slurry,
            return_slurry = EXCLUDED.return_slurry,
            air_kg        = EXCLUDED.air_kg,
            updated_at    = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sqlUpsert);

        // Helper pembersih format ribuan titik/koma Excel Indo
        $cleanNum = function($val) {
            if ($val === null || $val === '' || trim($val) == '-') return 0.00;
            if (is_int($val) || is_float($val)) return (float)$val;
            
            $val = trim($val);
            if (strpos($val, '=') === 0 || strpos($val, '#') !== false) return 0.00; 

            // Handle format amfibi Indonesia string (e.g., 10.120 or 1.352,46)
            if (strpos($val, ',') !== false && strpos($val, '.') !== false) {
                $val = str_replace('.', '', $val);
                $val = str_replace(',', '.', $val);
            } elseif (strpos($val, ',') !== false) {
                $val = str_replace(',', '.', $val);
            }
            return (float)$val;
        };

        $isHeaderFound = false;

        foreach ($rows as $index => $row) {
            // 1. SKIP BARIS ATAS SAMPAI KETEMU HEADER UTAMA DI BARIS 4 (INDEX EXCEL 3)
            if ($index < 3) continue;

            // 2. AMBIL DAN VALIDASI TANGGAL
            $rawTanggal = trim($row[0] ?? '');
            if (empty($rawTanggal) || strpos($rawTanggal, 'Rekap') !== false || strpos($rawTanggal, 'Tanggal') !== false) {
                continue; // Abaikan baris kosong atau banner judul
            }

            // Normalisasi Tanggal Excel format date murni / teks harian
            if (is_numeric($rawTanggal) && (int)$rawTanggal > 40000) {
                $tanggal = date('Y-m-d', ($rawTanggal - 25569) * 86400);
            } else {
                $tanggal = date('Y-m-d', strtotime(str_replace('/', '-', $rawTanggal)));
            }

            // 3. VALIDASI SHIFT VALID
            $shift = (int)trim($row[1] ?? 0);
            if ($shift !== 1 && $shift !== 2 && $shift !== 3) {
                continue; // Skip baris subtotal rumus harian
            }

            $regu        = trim($row[2] ?? '-');
            $nama_produk = trim($row[3] ?? '-');
            $casting_mld = (int)$cleanNum($row[4] ?? 0);

            // =========================================================================
            // MAPPING SNAIPER: MENEMBAK INDEX KOLOM AKURAT SESUAI GAMBAR 76D46A.PNG
            // =========================================================================
            $alu_focoun   = $cleanNum($row[5] ?? 0);   // Kolom F (Index 5)
            $alu_platinum = $cleanNum($row[6] ?? 0);   // Kolom G (Index 6)
            $alu_zouping  = $cleanNum($row[7] ?? 0);   // Kolom H (Index 7)
            
            $kapur_silo17 = $cleanNum($row[8] ?? 0);   // Kolom I (Index 8)
            $kapur_silo18 = $cleanNum($row[9] ?? 0);   // Kolom J (Index 9)
            $kapur_total  = $cleanNum($row[10] ?? 0);  // Kolom K (Index 10)
            
            $semen_silo15 = $cleanNum($row[11] ?? 0);  // Kolom L (Index 11)
            $semen_silo16 = $cleanNum($row[12] ?? 0);  // Kolom M (Index 12)
            $semen_total  = $cleanNum($row[13] ?? 0);  // Kolom N (Index 13)
            
            $pasir_kg     = $cleanNum($row[14] ?? 0);  // Kolom O (Index 14)
            $gypsum_kg    = $cleanNum($row[15] ?? 0);  // Kolom P (Index 15)
            
            $fresh_slurry = $cleanNum($row[21] ?? 0);  // Kolom V (Index 21)
            $return_slurry= $cleanNum($row[22] ?? 0);  // Kolom W (Index 22)
            $air_kg       = $cleanNum($row[23] ?? 0);  // Kolom X (Index 23)
            // =========================================================================

            // Eksekusi dorong data masuk PostgreSQL
            $stmt->execute([
                ':tanggal'        => $tanggal,
                ':shift'          => $shift,
                ':regu'           => $regu,
                ':nama_produk'    => $nama_produk,
                ':total_casting'  => $casting_mld,
                ':alu_focoun'     => $alu_focoun,
                ':alu_platinum'   => $alu_platinum,
                ':alu_zouping'    => $alu_zouping,
                ':kapur_silo17'   => $kapur_silo17,
                ':kapur_silo18'   => $kapur_silo18,
                ':kapur_total'    => $kapur_total,
                ':semen_silo15'   => $semen_silo15,
                ':semen_silo16'   => $semen_silo16,
                ':semen_total'    => $semen_total,
                ':pasir_kg'       => $pasir_kg,
                ':gypsum_kg'      => $gypsum_kg,
                ':fresh_slurry'   => $fresh_slurry,
                ':return_slurry'  => $return_slurry,
                ':air_kg'         => $air_kg
            ]);
            
            $inserted++;
        }

        echo "Sukses! Berhasil menarik data aktual timbangan Lini AAC 2 sebanyak {$inserted} baris shift dari Tab Sheet '{$target_sheet_name}'.";
    } else {
        throw new Exception(SimpleXLSX::parseError());
    }
} catch (Exception $e) {
    echo "Gagal Sinkronisasi Komposisi Aktual.";
}
