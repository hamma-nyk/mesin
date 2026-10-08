<?php
// cron/sync_aac2.php
// FIX RENTANG BULAN DINAMIS DARI DASHBOARD (AGUSTUS / PERIODE PILIHAN)

ini_set('memory_limit', '-1'); 
set_time_limit(600);           

require_once __DIR__ . '/SimpleXLSX.php';
require_once __DIR__ . '/../config/database.php';

use Shuchkin\SimpleXLSX;

date_default_timezone_get() ?: date_default_timezone_set('Asia/Jakarta');

// =========================================================================
// 1. TANGKAP PERIODE RENTANG DARI FILTER DASHBOARD SECARA CERDAS
// =========================================================================
$nama_bulan_indo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli',    8 => 'Agustus',  9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Cek apakah ada kiriman rentang tanggal dari form filter
$tgl_awal_req  = $_GET['tgl_awal'] ?? '';
$tgl_akhir_req = $_GET['tgl_akhir'] ?? '';

if (!empty($tgl_awal_req)) {
    $bulan_ini = (int)date('m', strtotime($tgl_awal_req));
    $tahun_ini = date('Y', strtotime($tgl_awal_req));
    $tanggal_awal_iterasi  = $tgl_awal_req;
    $tanggal_akhir_iterasi = !empty($tgl_akhir_req) ? $tgl_akhir_req : date('Y-m-t', strtotime($tgl_awal_req));
} elseif (isset($_GET['bulan']) && (int)$_GET['bulan'] >= 1 && (int)$_GET['bulan'] <= 12) {
    $bulan_ini = (int)$_GET['bulan'];
    $tahun_ini = isset($_GET['tahun']) && ctype_digit($_GET['tahun']) ? $_GET['tahun'] : date('Y');
    $tanggal_awal_iterasi  = date($tahun_ini . '-' . sprintf('%02d', $bulan_ini) . '-01');
    $tanggal_akhir_iterasi = date($tahun_ini . '-' . sprintf('%02d', $bulan_ini) . '-t');
} else {
    // Default fallback ke AGUSTUS 2026 jika tidak ada kiriman parameter
    $bulan_ini = 8;
    $tahun_ini = '2026';
    $tanggal_awal_iterasi  = '2026-08-01';
    $tanggal_akhir_iterasi = '2026-08-31';
}

$folder_bulan_teks = $nama_bulan_indo[$bulan_ini] ?? 'Agustus';
$bulan_upper = strtoupper($folder_bulan_teks);

// =========================================================================
// 2. JALUR JARINGAN SERVER LOCAL 10.14
// =========================================================================
$baseFolderDinamis = "\\\\192.168.10.14\\Backup Produksi-AAC\\ALICHA MANDY ALMIRA\\1. LAPORAN PRODUKSI\\AAC\\{$tahun_ini}\\{$bulan_ini}. {$folder_bulan_teks}\\";

$searchPatterns = [
    $baseFolderDinamis . "2.*Bongkar*.xlsx",
    $baseFolderDinamis . "2. {$bulan_upper}{$tahun_ini}*Bongkar*.xlsx",
    $baseFolderDinamis . "*Laporan Bongkar Autoclave*.xlsx",
    $baseFolderDinamis . "1.*Hasil Produksi*.xlsx"
];

$excelFilePath = '';
foreach ($searchPatterns as $pattern) {
    $scan = glob($pattern);
    if (!empty($scan)) {
        $excelFilePath = $scan[0];
        break;
    }
}

if (empty($excelFilePath) || !file_exists($excelFilePath)) {
    die("Gagal: Berkas Excel {$folder_bulan_teks} {$tahun_ini} tidak ditemukan di rute: " . $baseFolderDinamis);
}

// =========================================================================
// 3. PARSING DATA EXCEL (SINKRON DENGAN RENTANG TANGGAL)
// =========================================================================
try {
    if ($xlsx = SimpleXLSX::parse($excelFilePath)) {
        
        $total_inserted_rows = 0;
        $total_kendala_saved = 0;
        $sheet_names = $xlsx->sheetNames();

        // Prepare Statement Data Utama & Kendala
        $sqlUpsertMain = "INSERT INTO db_hasil_produksi_aac2.tb_harian (
                            tanggal, shift, regu, target_casting_m3, hasil_casting_m3, total_casting_m3, pencapaian_produksi_persen,
                            reject_cake_m3, hasil_produk_jadi_m3, jumlah_packing_mould, produk_baik_a1, produk_hold_a2,
                            produk_kw, total_packing_pallet, total_packing_m3, forklift, wheel_loader, log_kendala_json, updated_at
                        ) VALUES (
                            :tanggal, :shift, :regu, :target_cast, :hasil_cast, :total_cast, :pct,
                            :reject, :hasil_jadi, :packing_mould, :baik_a1, :hold_a2,
                            :kw, :tot_pallet, :tot_pack_m3, :forklift, :loader, '[]', CURRENT_TIMESTAMP
                        ) ON CONFLICT (tanggal, shift) DO UPDATE SET
                            regu = EXCLUDED.regu,
                            target_casting_m3 = EXCLUDED.target_casting_m3,
                            hasil_casting_m3 = EXCLUDED.hasil_casting_m3,
                            total_casting_m3 = EXCLUDED.total_casting_m3,
                            pencapaian_produksi_persen = EXCLUDED.pencapaian_produksi_persen,
                            reject_cake_m3 = EXCLUDED.reject_cake_m3,
                            hasil_produk_jadi_m3 = EXCLUDED.hasil_produk_jadi_m3,
                            jumlah_packing_mould = EXCLUDED.jumlah_packing_mould,
                            produk_baik_a1 = EXCLUDED.produk_baik_a1,
                            produk_hold_a2 = EXCLUDED.produk_hold_a2,
                            produk_kw = EXCLUDED.produk_kw,
                            total_packing_pallet = EXCLUDED.total_packing_pallet,
                            total_packing_m3 = EXCLUDED.total_packing_m3,
                            forklift = EXCLUDED.forklift,
                            wheel_loader = EXCLUDED.wheel_loader,
                            updated_at = CURRENT_TIMESTAMP";
        $stmtMain = $pdo->prepare($sqlUpsertMain);

        $sqlInsertKendala = "INSERT INTO db_hasil_produksi_aac2.tb_kendala_harian (
                                tanggal, shift, regu, kejadian, waktu_hilang_menit, waktu_hilang_jam, output_hilang_m3
                             ) VALUES (
                                :tanggal, :shift, :regu, :kejadian, :menit, :jam, :m3
                             )";
        $stmtKendala = $pdo->prepare($sqlInsertKendala);
        $stmtDeleteKendala = $pdo->prepare("DELETE FROM db_hasil_produksi_aac2.tb_kendala_harian WHERE tanggal = :tanggal");

        $current_date = $tanggal_awal_iterasi;

        while (strtotime($current_date) <= strtotime($tanggal_akhir_iterasi)) {
            $day_int = (int)date('d', strtotime($current_date));
            $day_pad = sprintf('%02d', $day_int);
            $sheet_index_target = -1;

            // Pencocokan Nama Sheet Fleksibel
            foreach ($sheet_names as $index => $name) {
                $clean_name = trim($name);
                if (
                    $clean_name === (string)$day_int ||
                    $clean_name === $day_pad ||
                    preg_match("/(^|\D)0?{$day_int}(\D|$)/i", $clean_name)
                ) {
                    $sheet_index_target = $index;
                    break;
                }
            }

            if ($sheet_index_target !== -1) {
                $rows = $xlsx->rows($sheet_index_target);
                
                if (!empty($rows) && count($rows) >= 15) {
                    $stmtDeleteKendala->execute([':tanggal' => $current_date]);

                    $regu_shift3 = trim($rows[5][2] ?? '3N1');
                    $regu_shift1 = trim($rows[5][3] ?? '1N3');
                    $regu_shift2 = trim($rows[5][4] ?? '2N2');

                    $shifts_config = [
                        'Shift 3' => ['col' => 2, 'regu' => $regu_shift3],
                        'Shift 1' => ['col' => 3, 'regu' => $regu_shift1],
                        'Shift 2' => ['col' => 4, 'regu' => $regu_shift2]
                    ];

                    $target_m3_harian = (float)($rows[5][5] ?? 0) / 3;
                    $pct_raw = $rows[11][5] ?? ($rows[11][2] ?? 0);
                    $pct_clean = is_numeric($pct_raw) ? (float)$pct_raw * 100 : (float)str_replace(['%', ' '], '', $pct_raw);

                    foreach ($shifts_config as $s_name => $conf) {
                        $c = $conf['col'];
                        $r_code = (strlen($conf['regu']) > 5 || empty($conf['regu'])) ? 'REGU' : $conf['regu'];

                        $stmtMain->execute([
                            ':tanggal'       => $current_date,
                            ':shift'         => $s_name,
                            ':regu'          => $r_code,
                            ':target_cast'   => $target_m3_harian,
                            ':hasil_cast'    => (float)($rows[6][$c] ?? 0),
                            ':total_cast'    => (float)($rows[7][$c] ?? 0),
                            ':pct'           => $pct_clean,
                            ':reject'        => (float)($rows[10][$c] ?? 0),
                            ':hasil_jadi'    => (float)($rows[11][$c] ?? 0),
                            ':packing_mould' => (int)($rows[13][$c] ?? 0),
                            ':baik_a1'       => (int)($rows[15][$c] ?? 0),
                            ':hold_a2'       => (int)($rows[16][$c] ?? 0),
                            ':kw'            => (int)($rows[17][$c] ?? 0),
                            ':tot_pallet'    => (int)($rows[18][$c] ?? 0),
                            ':tot_pack_m3'   => (float)($rows[19][$c] ?? 0),
                            ':forklift'      => (int)($rows[21][$c] ?? 0),
                            ':loader'        => (int)($rows[22][$c] ?? 0)
                        ]);
                        $total_inserted_rows++;
                    }

                    // Ekstraksi Kendala Lapangan
                    for ($i = 25; $i < count($rows); $i++) {
                        $regu_val = trim($rows[$i][2] ?? ($rows[$i][0] ?? ''));
                        $uraian_kejadian = trim($rows[$i][3] ?? ($rows[$i][1] ?? ''));

                        if ($regu_val === 'TOTAL' || $uraian_kejadian === 'TOTAL') {
                            break;
                        }

                        if (empty($uraian_kejadian) || $uraian_kejadian === '-' || $uraian_kejadian === 'KEJADIAN' || $uraian_kejadian === 'Description') {
                            continue;
                        }

                        $v_menit = (int)($rows[$i][6] ?? ($rows[$i][2] ?? 0));
                        $v_jam   = (float)($rows[$i][8] ?? ($rows[$i][3] ?? 0));
                        $v_m3    = (float)($rows[$i][10] ?? ($rows[$i][4] ?? 0));

                        $target_shift_text = 'Shift 1';
                        if (stripos($regu_val, '3') === 0 || stripos($regu_val, '3N') !== false) {
                            $target_shift_text = 'Shift 3';
                        } elseif (stripos($regu_val, '2') === 0 || stripos($regu_val, '2N') !== false) {
                            $target_shift_text = 'Shift 2';
                        }

                        $stmtKendala->execute([
                            ':tanggal'  => $current_date,
                            ':shift'    => $target_shift_text,
                            ':regu'     => !empty($regu_val) ? $regu_val : 'REGU',
                            ':kejadian' => $uraian_kejadian,
                            ':menit'    => $v_menit,
                            ':jam'      => $v_jam,
                            ':m3'       => $v_m3
                        ]);
                        $total_kendala_saved++;
                    }
                }
                unset($rows);
            }
            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
        }

        unset($xlsx);
        echo "Sukses Total! Berhasil menarik {$total_inserted_rows} data harian & {$total_kendala_saved} baris kendala untuk rentang {$tanggal_awal_iterasi} s/d {$tanggal_akhir_iterasi}.";
    }
} catch (Exception $e) {
    echo "Gagal: " . $e->getMessage();
}