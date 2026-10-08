<?php
// cron/sync_plc.php
// Script otomatisasi sinkronisasi Laporan Produksi Lini 1 (BRC & PLC) - FULL DINAMIS PER PERIODE

ini_set('memory_limit', '-1');
set_time_limit(600); // Batas eksekusi aman untuk membaca banyak sheet sekaligus

require_once __DIR__ . '/SimpleXLSX.php';
require_once __DIR__ . '/../config/database.php';

use Shuchkin\SimpleXLSX;

date_default_timezone_get() ?: date_default_timezone_set('Asia/Jakarta');

$jam_sekarang = (int)date('H');

// PERBAIKAN 1: Deteksi Ajax manual yang lebih aman (bisa lewat header atau parameter GET)
$isAjaxManual = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_GET['ajax']);

if ($jam_sekarang < 15 && !$isAjaxManual) {
    // Pakai return jika file ini di-include (bukan dieksekusi langsung),
    // agar tidak membunuh halaman induk (misal laporan_produksi_plc.php yang me-include file ini).
    if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
        exit("Sistem Standby: Penarikan otomatis terjadwal aman setiap hari pada pukul 15:00 WIB.");
    } else {
        return;
    }
}

// =========================================================================
// INTERSEPTOR FILTER TIMELINE DINAMIS DARI DASHBOARD
// =========================================================================
$bulan_target = isset($_GET['bulan']) && (int)$_GET['bulan'] >= 1 && (int)$_GET['bulan'] <= 12 ? (int)$_GET['bulan'] : (int)date('m');
$tahun_target = isset($_GET['tahun']) && ctype_digit($_GET['tahun']) ? $_GET['tahun'] : date('Y');

$nama_bulan_indo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli',    8 => 'Agustus',  9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$bulan_teks = $nama_bulan_indo[$bulan_target] ?? 'Juli';

// --- BASE PATH UTAMA & DETEKSI FILE DINAMIS BULANAN ---
$basePath = '\\\\192.168.30.38\\1. laporan produksi\\Harian\\' . $tahun_target . '\\';

// PERBAIKAN 2: Pola pencarian glob dibuat lebih fleksibel agar mendukung format '7.' maupun '07.'
$bulan_padded = sprintf('%02d', $bulan_target);
$searchFilePattern = $basePath . '{' . $bulan_target . ',' . $bulan_padded . '}. Produksi Update *.xlsx';
$files = glob($searchFilePattern, GLOB_BRACE);

if (!empty($files)) {
    $excelFilePath = $files[0];
} else {
    // Jalur alternatif jika glob gagal
    $excelFilePath = $basePath . $bulan_target . '. Produksi Update ' . $bulan_teks . '.xlsx';
    if (!file_exists($excelFilePath)) {
        $excelFilePath = $basePath . $bulan_padded . '. Produksi Update ' . $bulan_teks . '.xlsx';
    }
}

if (!file_exists($excelFilePath)) {
    if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
        die("Gagal Sinkronisasi: Berkas Excel Lini 1 untuk periode " . $bulan_teks . " " . $tahun_target . " tidak ditemukan pada rute: " . $excelFilePath);
    } else {
        return; // sedang di-include dari halaman web, jangan bunuh halaman
    }
}

try {
    // --- BACA EXCEL ONCE ---
    if ($xlsx = SimpleXLSX::parse($excelFilePath)) {
        
        $total_inserted_rows = 0;
        $sheet_names = $xlsx->sheetNames();

        // Tentukan batas iterasi tanggal berdasarkan bulan yang dipilih mase
        $tanggal_awal_iterasi = date($tahun_target . '-' . sprintf('%02d', $bulan_target) . '-01');
        $tanggal_akhir_iterasi = ($bulan_target == (int)date('m') && $tahun_target == date('Y')) ? date('Y-m-d') : date($tahun_target . '-' . sprintf('%02d', $bulan_target) . '-t');

        $sqlUpsert = "INSERT INTO db_hasil_produksi_plc.tb_laporan_produksi_harian (
                            tanggal, shift, regu, brc_target_casting_m3, brc_total_casting_m3, brc_hasil_casting_m3, brc_pencapaian_persen,
                            plc_target_casting_m3, plc_total_casting_m3, plc_hasil_casting_m3, plc_pencapaian_persen,
                            reject_cake_m3, hasil_produk_jadi_m3, total_packing_brc_mould, produk_baik_a1_pallet,
                            produk_baik_a2_pallet, jumlah_forklift, kendala_produksi, updated_at
                        ) VALUES (
                            :tanggal, :shift, :regu, :brc_target, :brc_total, :brc_hasil, :brc_pct,
                            :plc_target, :plc_total, :plc_hasil, :plc_pct,
                            :reject_cake, :produk_jadi, :packing_mould, :baik_a1,
                            :baik_a2, :forklift, :kendala, CURRENT_TIMESTAMP
                        ) ON CONFLICT (tanggal, shift) DO UPDATE SET
                            regu                    = EXCLUDED.regu,
                            brc_target_casting_m3   = EXCLUDED.brc_target_casting_m3,
                            brc_total_casting_m3    = EXCLUDED.brc_total_casting_m3,
                            brc_hasil_casting_m3    = EXCLUDED.brc_hasil_casting_m3,
                            brc_pencapaian_persen   = EXCLUDED.brc_pencapaian_persen,
                            plc_target_casting_m3   = EXCLUDED.plc_target_casting_m3,
                            plc_total_casting_m3    = EXCLUDED.plc_total_casting_m3,
                            plc_hasil_casting_m3    = EXCLUDED.plc_hasil_casting_m3,
                            plc_pencapaian_persen   = EXCLUDED.plc_pencapaian_persen,
                            reject_cake_m3          = EXCLUDED.reject_cake_m3,
                            hasil_produk_jadi_m3    = EXCLUDED.hasil_produk_jadi_m3,
                            total_packing_brc_mould = EXCLUDED.total_packing_brc_mould,
                            produk_baik_a1_pallet   = EXCLUDED.produk_baik_a1_pallet,
                            produk_baik_a2_pallet   = EXCLUDED.produk_baik_a2_pallet,
                            jumlah_forklift         = EXCLUDED.jumlah_forklift,
                            kendala_produksi        = EXCLUDED.kendala_produksi,
                            updated_at              = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sqlUpsert);
        $current_date = $tanggal_awal_iterasi;

        while (strtotime($current_date) <= strtotime($tanggal_akhir_iterasi)) {
            
            $nama_sheet_target = (string)(int)date('d', strtotime($current_date));
            $sheet_index_target = -1;

            foreach ($sheet_names as $index => $name) {
                if (trim($name) === $nama_sheet_target) {
                    $sheet_index_target = $index;
                    break;
                }
            }

            if ($sheet_index_target !== -1) {
                $rows = $xlsx->rows($sheet_index_target);
                
                if (!empty($rows) && count($rows) > 15) {
                    $brc_target_m3  = (float)($rows[9][3] ?? 0);
                    $brc_total_m3   = (float)($rows[11][3] ?? 0);
                    
                    $brc_pct_raw    = $rows[14][3] ?? 0;
                    $brc_pct        = is_numeric($brc_pct_raw) ? (float)$brc_pct_raw * 100 : (float)str_replace(['%', ' '], '', $brc_pct_raw);

                    $plc_target_m3  = (float)($rows[9][6] ?? 0);
                    $plc_total_m3   = (float)($rows[11][6] ?? 0);
                    
                    $plc_pct_raw    = $rows[14][6] ?? 0;
                    $plc_pct        = is_numeric($plc_pct_raw) ? (float)$plc_pct_raw * 100 : (float)str_replace(['%', ' '], '', $plc_pct_raw);

                    $shifts_mapping = [
                        'Shift 3' => ['col' => 3, 'regu' => 'Regu C', 'kendala_row' => 25],
                        'Shift 1' => ['col' => 4, 'regu' => 'Regu A', 'kendala_row' => 26],
                        'Shift 2' => ['col' => 5, 'regu' => 'Regu B', 'kendala_row' => 29]
                    ];

                    foreach ($shifts_mapping as $s_name => $s_info) {
                        $c = $s_info['col'];
                        
                        $brc_hasil_m3   = (float)($rows[10][$c] ?? 0);
                        $reject_cake    = (float)($rows[16][$c] ?? 0);
                        $produk_jadi    = (float)($rows[17][$c] ?? 0);
                        
                        $packing_mould  = (int)($rows[19][$c] ?? 0);
                        $baik_a1        = (int)($rows[20][$c] ?? 0);
                        $baik_a2        = (int)($rows[21][$c] ?? 0);
                        $forklift       = (int)($rows[23][$c] ?? 0);
                        
                        $kendala        = trim($rows[$s_info['kendala_row']][2] ?? '');

                        $brc_target_shift = $brc_target_m3 / 3;
                        $plc_target_shift = $plc_target_m3 / 3;

                        $plc_hasil_jadi = ($s_name === 'Shift 1') ? (float)($rows[10][6] ?? 0) : 0;
                        $keterangan_sistem = "PLC_PERFORMANCE:" . number_format($plc_pct, 2, '.', '') . "% | Accum:" . $plc_total_m3 . " | " . $kendala;

                        $stmt->execute([
                            ':tanggal'       => $current_date,
                            ':shift'         => $s_name,
                            ':regu'          => $s_info['regu'],
                            ':brc_target'    => $brc_target_shift,
                            ':brc_total'     => $brc_total_m3,
                            ':brc_hasil'     => $brc_hasil_m3,
                            ':brc_pct'       => $brc_pct,
                            ':plc_target'    => $plc_target_shift,
                            ':plc_total'     => $plc_total_m3,
                            ':plc_hasil'     => $plc_hasil_jadi,
                            ':plc_pct'       => $plc_pct,
                            ':reject_cake'   => $reject_cake,
                            ':produk_jadi'   => $produk_jadi,
                            ':packing_mould' => $packing_mould,
                            ':baik_a1'       => $baik_a1,
                            ':baik_a2'       => $baik_a2,
                            ':forklift'      => $forklift,
                            ':kendala'       => $keterangan_sistem
                        ]);

                        $total_inserted_rows++;
                    }
                }
                unset($rows); 
            }

            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
        }

        unset($xlsx); 

        if ($total_inserted_rows > 0) {
            echo "Sukses Total mase! Berhasil menyinkronkan data Lini 1 untuk periode {$bulan_teks} {$tahun_target} (Total: " . ($total_inserted_rows / 3) . " Hari / " . $total_inserted_rows . " Record Shift Ter-update).";
        } else {
            echo "Gagal: Tidak ada sheet tanggal aktif pada periode terpilih yang berhasil diproses.";
        }

    } else {
        throw new Exception(SimpleXLSX::parseError());
    }

} catch (Exception $e) {
    echo "Gagal Sinkronisasi Rekursif PLC mase.";
}
