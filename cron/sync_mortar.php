<?php
// cron/sync_mortar.php

// 1. Alokasi Memori Maksimal & Batas Waktu Eksekusi
ini_set('memory_limit', '-1');
set_time_limit(0); // 0 = Tanpa batas waktu

require_once __DIR__ . '/SimpleXLSX.php';
require_once __DIR__ . '/../config/database.php';

use Shuchkin\SimpleXLSX;

$log_messages = [];
$count_success = 0;
$count_error = 0;

try {
    // --- 1. TANGKAP RENTANG TANGGAL DARI GET AJAX ---
    $tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-d', strtotime('-1 day'));
    $tgl_akhir = $_GET['tgl_akhir'] ?? $tgl_awal;

    $start_date = new DateTime($tgl_awal);
    $end_date = new DateTime($tgl_akhir);
    $end_date->modify('+1 day'); 

    $period = new DatePeriod($start_date, new DateInterval('P1D'), $end_date);

    $sqlUpsert = "INSERT INTO db_hasil_produksi_mortar.tb_harian (
        tanggal, target_bulan_ton, target_per_tanggal_ton,
        s3_varian, s3_hasil_mixing_ton, s3_hasil_packing_ton, s3_detail_a1_sak, s3_detail_hold_sak, s3_hold_timbang_sak, s3_hold_sak_rusak, s3_kendala,
        s1_varian, s1_hasil_mixing_ton, s1_hasil_packing_ton, s1_detail_a1_sak, s1_detail_hold_sak, s1_hold_timbang_sak, s1_hold_sak_rusak, s1_kendala,
        s2_varian, s2_hasil_mixing_ton, s2_hasil_packing_ton, s2_detail_a1_sak, s2_detail_hold_sak, s2_hold_timbang_sak, s2_hold_sak_rusak, s2_kendala,
        cm100_akumulasi_ton, cm200_akumulasi_ton, cm300_akumulasi_ton, cm300_20kg_akumulasi_ton, cm310_akumulasi_ton, cm400_akumulasi_ton, cm600_akumulasi_ton, cm610_akumulasi_ton, mm100_akumulasi_ton, mm200_akumulasi_ton, mm300_akumulasi_ton, mm600_akumulasi_ton, updated_at
    ) VALUES (
        :tanggal, :target_bulan, :target_hari,
        :s3_varian, :s3_mixing, :s3_packing, :s3_a1, :s3_hold, :s3_h_timbang, :s3_h_rusak, :s3_kendala,
        :s1_varian, :s1_mixing, :s1_packing, :s1_a1, :s1_hold, :s1_h_timbang, :s1_h_rusak, :s1_kendala,
        :s2_varian, :s2_mixing, :s2_packing, :s2_a1, :s2_hold, :s2_h_timbang, :s2_h_rusak, :s2_kendala,
        :cm100, :cm200, :cm300, :cm300_20, :cm310, :cm400, :cm600, :cm610, :mm100, :mm200, :mm300, :mm600, CURRENT_TIMESTAMP
    ) ON CONFLICT (tanggal) DO UPDATE SET
        target_bulan_ton = EXCLUDED.target_bulan_ton, target_per_tanggal_ton = EXCLUDED.target_per_tanggal_ton,
        s3_varian = EXCLUDED.s3_varian, s3_hasil_mixing_ton = EXCLUDED.s3_hasil_mixing_ton, s3_hasil_packing_ton = EXCLUDED.s3_hasil_packing_ton, s3_detail_a1_sak = EXCLUDED.s3_detail_a1_sak, s3_detail_hold_sak = EXCLUDED.s3_detail_hold_sak, s3_hold_timbang_sak = EXCLUDED.s3_hold_timbang_sak, s3_hold_sak_rusak = EXCLUDED.s3_hold_sak_rusak, s3_kendala = EXCLUDED.s3_kendala,
        s1_varian = EXCLUDED.s1_varian, s1_hasil_mixing_ton = EXCLUDED.s1_hasil_mixing_ton, s1_hasil_packing_ton = EXCLUDED.s1_hasil_packing_ton, s1_detail_a1_sak = EXCLUDED.s1_detail_a1_sak, s1_detail_hold_sak = EXCLUDED.s1_detail_hold_sak, s1_hold_timbang_sak = EXCLUDED.s1_hold_timbang_sak, s1_hold_sak_rusak = EXCLUDED.s1_hold_sak_rusak, s1_kendala = EXCLUDED.s1_kendala,
        s2_varian = EXCLUDED.s2_varian, s2_hasil_mixing_ton = EXCLUDED.s2_hasil_mixing_ton, s2_hasil_packing_ton = EXCLUDED.s2_hasil_packing_ton, s2_detail_a1_sak = EXCLUDED.s2_detail_a1_sak, s2_detail_hold_sak = EXCLUDED.s2_detail_hold_sak, s2_hold_timbang_sak = EXCLUDED.s2_hold_timbang_sak, s2_hold_sak_rusak = EXCLUDED.s2_hold_sak_rusak, s2_kendala = EXCLUDED.s2_kendala,
        cm100_akumulasi_ton = EXCLUDED.cm100_akumulasi_ton, cm200_akumulasi_ton = EXCLUDED.cm200_akumulasi_ton, cm300_akumulasi_ton = EXCLUDED.cm300_akumulasi_ton, cm300_20kg_akumulasi_ton = EXCLUDED.cm300_20kg_akumulasi_ton, cm310_akumulasi_ton = EXCLUDED.cm310_akumulasi_ton, cm400_akumulasi_ton = EXCLUDED.cm400_akumulasi_ton, cm600_akumulasi_ton = EXCLUDED.cm600_akumulasi_ton, cm610_akumulasi_ton = EXCLUDED.cm610_akumulasi_ton, mm100_akumulasi_ton = EXCLUDED.mm100_akumulasi_ton, mm200_akumulasi_ton = EXCLUDED.mm200_akumulasi_ton, mm300_akumulasi_ton = EXCLUDED.mm300_akumulasi_ton, mm600_akumulasi_ton = EXCLUDED.mm600_akumulasi_ton, updated_at = CURRENT_TIMESTAMP;";

    $stmt = $pdo->prepare($sqlUpsert);

    // --- VARIABEL CACHE FILE EXCEL ---
    $current_loaded_file = '';
    $xlsx = null;

    // --- LOOPING SETIAP HARI ---
    foreach ($period as $dt) {
        $tanggal_target = $dt->format('Y-m-d');
        $tahun_target = $dt->format('Y');
        $bulan_target = (int)$dt->format('m');
        $nama_sheet_target = (string)(int)$dt->format('d');

        try {
            $basePath = '//192.168.30.38/Tempshare2/REZA/1. LAPORAN PRODUKSI MORTAR/'
                      . 'LAPORAN PRODUKSI MORTAR ' . $tahun_target
                      . '/HARIAN/' . $tahun_target . '/';

            $searchFilePattern = $basePath . $bulan_target . '. Produksi Update *.xlsx';
            $files = glob($searchFilePattern);

            if (empty($files)) {
                throw new Exception("File Excel bulan $bulan_target tahun $tahun_target tidak ditemukan di server.");
            }

            $excelFilePath = $files[0];

            // LOGIKA CACHING: Buka file HANYA jika file Excel-nya berbeda dari perulangan sebelumnya
            if ($current_loaded_file !== $excelFilePath) {
                if ($xlsx !== null) { unset($xlsx); } // Kosongkan memori file lama
                $xlsx = SimpleXLSX::parse($excelFilePath);
                $current_loaded_file = $excelFilePath;
                
                if (!$xlsx) {
                    throw new Exception(SimpleXLSX::parseError());
                }
            }

            // Mulai cari sheet berdasarkan tanggal
            $sheet_index_target = -1;
            foreach ($xlsx->sheetNames() as $index => $name) {
                if (trim($name) === $nama_sheet_target) {
                    $sheet_index_target = $index;
                    break;
                }
            }

            if ($sheet_index_target === -1) {
                throw new Exception("Sheet tgl '$nama_sheet_target' tidak ditemukan.");
            }

            $rows = $xlsx->rows($sheet_index_target);
            if (empty($rows)) {
                throw new Exception("Isi Sheet '$nama_sheet_target' kosong.");
            }

            // --- EKSTRAKSI DATA ---
            $target_bulan = (float)($rows[6][3] ?? 0); 
            $target_hari  = (float)($rows[7][3] ?? 0); 

            $s3_varian    = $rows[11][3] ?? 'OFF';     
            $s3_mixing    = (float)($rows[12][3] ?? 0); 
            $s3_packing   = (float)($rows[17][3] ?? 0); 
            $s3_a1        = (int)($rows[22][3] ?? 0);   
            $s3_hold      = (int)($rows[23][3] ?? 0);   
            $s3_h_timbang = (int)($rows[26][3] ?? 0);   
            $s3_h_rusak   = (int)($rows[27][3] ?? 0);   
            $s3_kendala   = $rows[32][2] ?? '';        

            $s1_varian    = $rows[11][4] ?? '-';        
            $s1_mixing    = (float)($rows[12][4] ?? 0); 
            $s1_packing   = (float)($rows[17][4] ?? 0); 
            $s1_a1        = (int)($rows[22][4] ?? 0);   
            $s1_hold      = (int)($rows[23][4] ?? 0);   
            $s1_h_timbang = (int)($rows[26][4] ?? 0);   
            $s1_h_rusak   = (int)($rows[27][4] ?? 0);   
            $s1_kendala   = $rows[33][2] ?? '';        

            $s2_varian    = $rows[11][5] ?? '-';        
            $s2_mixing    = (float)($rows[12][5] ?? 0); 
            $s2_packing   = (float)($rows[17][5] ?? 0); 
            $s2_a1        = (int)($rows[22][5] ?? 0);   
            $s2_hold      = (int)($rows[23][5] ?? 0);   
            $s2_h_timbang = (int)($rows[26][5] ?? 0);   
            $s2_h_rusak   = (int)($rows[27][5] ?? 0);   
            $s2_kendala   = $rows[34][2] ?? '';        

            $cm100    = (float)($rows[7][9] ?? 0);  
            $cm200    = (float)($rows[8][9] ?? 0);  
            $cm300    = (float)($rows[9][9] ?? 0);  
            $cm300_20 = (float)($rows[10][9] ?? 0); 
            $cm310    = (float)($rows[11][9] ?? 0); 
            $cm400    = (float)($rows[12][9] ?? 0); 
            $cm600    = (float)($rows[13][9] ?? 0); 
            $cm610    = (float)($rows[14][9] ?? 0); 
            $mm100    = (float)($rows[15][9] ?? 0); 
            $mm200    = (float)($rows[16][9] ?? 0); 
            $mm300    = (float)($rows[17][9] ?? 0); 
            $mm600    = (float)($rows[18][9] ?? 0); 

            unset($rows); // Hapus variabel row harian

            // --- EKSEKUSI INSERT/UPDATE KE POSTGRESQL ---
            $stmt->execute([
                ':tanggal' => $tanggal_target, ':target_bulan' => $target_bulan, ':target_hari' => $target_hari,
                ':s3_varian' => $s3_varian, ':s3_mixing' => $s3_mixing, ':s3_packing' => $s3_packing, ':s3_a1' => $s3_a1, ':s3_hold' => $s3_hold, ':s3_h_timbang' => $s3_h_timbang, ':s3_h_rusak' => $s3_h_rusak, ':s3_kendala' => $s3_kendala,
                ':s1_varian' => $s1_varian, ':s1_mixing' => $s1_mixing, ':s1_packing' => $s1_packing, ':s1_a1' => $s1_a1, ':s1_hold' => $s1_hold, ':s1_h_timbang' => $s1_h_timbang, ':s1_h_rusak' => $s1_h_rusak, ':s1_kendala' => $s1_kendala,
                ':s2_varian' => $s2_varian, ':s2_mixing' => $s2_mixing, ':s2_packing' => $s2_packing, ':s2_a1' => $s2_a1, ':s2_hold' => $s2_hold, ':s2_h_timbang' => $s2_h_timbang, ':s2_h_rusak' => $s2_h_rusak, ':s2_kendala' => $s2_kendala,
                ':cm100' => $cm100, ':cm200' => $cm200, ':cm300' => $cm300, ':cm300_20' => $cm300_20, ':cm310' => $cm310, ':cm400' => $cm400, ':cm600' => $cm600, ':cm610' => $cm610, ':mm100' => $mm100, ':mm200' => $mm200, ':mm300' => $mm300, ':mm600' => $mm600
            ]);

            $count_success++;

        } catch (Exception $e) {
            $log_messages[] = "[Tgl $tanggal_target] Gagal tersinkronisasi.";
            $count_error++;
        }
    } // Akhir dari foreach

    // Buang memori excel terakhir saat seluruh loop selesai
    if ($xlsx !== null) { unset($xlsx); }

    // --- 4. OUTPUT RESPONSE KE AJAX SWEETALERT ---
    if ($count_error == 0) {
        echo "Berhasil menyinkronkan data sebanyak $count_success hari dengan sempurna.";
    } else {
        echo "Selesai!\nBerhasil: $count_success hari.\nGagal/Kosong: $count_error hari.\n\nDetail:\n" . implode("\n", $log_messages);
    }

} catch (Exception $e) {
    echo "Error Sistem Master Sinkronisasi.";
}
?>
