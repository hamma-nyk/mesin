<?php
// config/wa_gateway.php
// Cegah akses langsung dari browser
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'wa_gateway.php') {
    http_response_code(403);
    die("Forbidden");
}
// Pastikan file ini dipanggil SETELAH koneksi database $pdo (PostgreSQL) terbentuk

$wa_server_url = "http://localhost:3003/api/send-message"; // Default Fallback

// Siapkan Array Kosong untuk masing-masing segmen lini produksi
// Kategori penerima didistribusikan per lini:
//   ALL       -> FC + AAC1 + AAC2
//   QCL FC    -> FC
//   QCL AAC1  -> AAC1
//   QCL AAC2  -> AAC2
//   QCL AAC   -> AAC1 + AAC2
$wa_recipients_qcl_fc   = [];
$wa_recipients_qcl_aac  = []; // gabungan AAC1+AAC2 (kompatibilitas)
$wa_recipients_qcl_aac1 = [];
$wa_recipients_qcl_aac2 = [];

try {
    if (isset($pdo)) {
        // 1. Tarik Alamat Server Default yang Aktif
        $stmtServer = $pdo->query("SELECT ip_address, port, endpoint FROM db_master.tb_alamat_server_wa WHERE is_default = 1 LIMIT 1");
        $serverData = $stmtServer->fetch(PDO::FETCH_ASSOC);
        
        if ($serverData) {
            $ip       = trim($serverData['ip_address']);
            $port     = trim($serverData['port']);
            $endpoint = trim($serverData['endpoint']);
            
            // Pengamanan URL: Pastikan endpoint diawali dengan '/'
            if (strpos($endpoint, '/') !== 0 && !empty($endpoint)) {
                $endpoint = '/' . $endpoint;
            }
            
            $wa_server_url = "http://{$ip}:{$port}{$endpoint}";
        }

        // 2. Tarik Semua Nomor Penerima WA yang berstatus aktif beserta kategorinya
        $stmtNumbers = $pdo->query("SELECT nomor_wa, kategori_notif FROM db_master.tb_nomor_penerima WHERE status_aktif = 1");
        $all_contacts = $stmtNumbers->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_contacts as $c) {
            $nomor = trim($c['nomor_wa']);
            $kat   = trim($c['kategori_notif'] ?? 'ALL'); // Fallback jika kosong dianggap ALL

            if (empty($nomor)) continue; // Lewati jika nomor kosong

            $isFc   = in_array($kat, ['ALL', 'QCL FC'], true);
            $isAac1 = in_array($kat, ['ALL', 'QCL AAC', 'QCL AAC1'], true);
            $isAac2 = in_array($kat, ['ALL', 'QCL AAC', 'QCL AAC2'], true);
            $isAac  = in_array($kat, ['ALL', 'QCL AAC', 'QCL AAC1', 'QCL AAC2'], true);

            if ($isFc)   $wa_recipients_qcl_fc[]   = $nomor;
            if ($isAac)  $wa_recipients_qcl_aac[]  = $nomor;  // gabungan (kompatibilitas)
            if ($isAac1) $wa_recipients_qcl_aac1[] = $nomor;
            if ($isAac2) $wa_recipients_qcl_aac2[] = $nomor;
        }
        
        // 3. Pastikan tidak ada pengiriman ganda ke nomor yang sama (Filter Duplikat)
        $wa_recipients_qcl_fc   = array_values(array_unique($wa_recipients_qcl_fc));
        $wa_recipients_qcl_aac  = array_values(array_unique($wa_recipients_qcl_aac));
        $wa_recipients_qcl_aac1 = array_values(array_unique($wa_recipients_qcl_aac1));
        $wa_recipients_qcl_aac2 = array_values(array_unique($wa_recipients_qcl_aac2));
    }
} catch (Exception $e) {
    // Jika gagal terhubung ke database master, sistem tetap berjalan 
    // menggunakan default fallback array kosong agar aplikasi tidak crash
}
?>
