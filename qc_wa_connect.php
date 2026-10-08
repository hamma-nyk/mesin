<?php
// 1. Inisialisasi Session di baris paling pertama script
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';

// =========================================================================
// 🛡️ PROTEKSI HALAMAN (SOFT WARNING / READ-ONLY)
// =========================================================================
$role_aktif = $_SESSION['role'] ?? 'Guest';
// Hanya Admin IT yang berhak melakukan pemutusan sesi (Logout Bot)
$has_full_access = in_array($role_aktif, ['Admin IT', 'Supervisor QCL FC', 'Supervisor QCL AAC', 'Kabag QCL']);

require_once 'config/database.php'; // Menyediakan koneksi PostgreSQL

// =========================================================================
// INCLUDE CONFIG WHATSAPP GATEWAY (DINAMIS DARI PostgreSQL db_master)
// =========================================================================
include_once __DIR__ . '/config/wa_gateway.php'; 
// File di atas otomatis menyediakan variabel $wa_server_url
// Kita bersihkan path '/send-message' di akhir URL untuk mendapatkan base API URL Node.js
$api_base_url = str_replace('/send-message', '', $wa_server_url); 

require_once 'includes/header.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <h1 class="m-0"><i class="fab fa-whatsapp text-success mr-2"></i>WhatsApp Gateway Integration</h1>
        </div>
    </div>

    <div class="content">
        <div class="container-fluid">

            <?php if(!$has_full_access): ?>
            <div class="alert alert-warning shadow-sm">
                <h5><i class="icon fas fa-exclamation-triangle"></i> Akses Pemantauan Saja</h5>
                Role Anda saat ini (<b><?= htmlspecialchars($role_aktif) ?></b>) hanya diizinkan untuk melihat status mesin Bot WhatsApp. Hak akses untuk memutus sesi (Logout) dilarang dan hanya dibolehkan untuk <b>Admin IT</b>.
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-4">
                    <div class="card card-default shadow-sm">
                        <div class="card-header bg-light">
                            <h3 class="card-title">Status Layanan WA Bot</h3>
                        </div>
                        <div class="card-body text-center">
                            
                            <div id="statusIndicator" class="alert alert-secondary py-3 mb-3">
                                <h5 class="mb-1 font-weight-bold" id="txtStatus">MENGECEK...</h5>
                                <span class="text-xs" id="txtService">Mesin BOT Internal</span>
                            </div>

                            <div class="bg-light p-3 rounded text-left mb-3 text-sm">
                                <b>Sistem Integrasi:</b> <span class="float-right text-bold text-primary">Baileys WA Socket</span><br>
                                <b>Terhubung Nomor:</b> <span class="float-right text-bold" id="txtNumber">-</span><br>
                                <hr class="my-2">
                                <b>Target Endpoint:</b> <br>
                                <small class="text-muted word-break-all" id="txtEndpointTarget"><?= htmlspecialchars($api_base_url, ENT_QUOTES, 'UTF-8') ?></small><br>
                                <small class="text-muted"><i class="fas fa-shield-alt text-success mt-2"></i> Dilindungi oleh PHP Internal Proxy</small>
                            </div>

                            <button type="button" id="btnRefresh" class="btn btn-primary btn-block mb-2">
                                <i class="fas fa-sync-alt mr-1"></i> Perbarui Status
                            </button>
                            
                            <button type="button" id="btnLogout" class="btn btn-danger btn-block" style="display: none;">
                                <i class="fas fa-power-off mr-1"></i> Putuskan Sesi (Logout)
                            </button>

                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header bg-white">
                            <h3 class="card-title text-primary"><i class="fas fa-qrcode mr-1"></i> QR Code Authentication</h3>
                        </div>
                        <div class="card-body text-center py-4">
                            
                            <div id="qrContainer" class="d-flex align-items-center justify-content-center border rounded mx-auto shadow-inner" style="width: 280px; height: 280px; background-color: #fafafa; position: relative;">
                                <div id="qrLoading">
                                    <i class="fas fa-circle-notch fa-spin fa-3x text-muted mb-2"></i>
                                    <p class="text-xs text-muted mb-0">Meminta QR Baru...</p>
                                </div>
                                <img id="imgQr" src="" alt="WhatsApp QR Code" class="img-fluid p-2" style="display: none; width: 100%; height: 100%;">
                            </div>

                            <div class="mt-3 max-width-500 mx-auto text-muted text-sm" id="qrInstruction">
                                <p class="mb-1 font-weight-bold text-dark"><i class="fas fa-info-circle text-info"></i> Petunjuk Koneksi:</p>
                                <ol class="text-left pl-4 text-xs">
                                    <li>Buka aplikasi <b>WhatsApp</b> di ponsel Anda.</li>
                                    <li>Ketuk <b>Menu</b> (titik tiga) atau <b>Pengaturan</b> lalu pilih <b>Perangkat Tertaut</b>.</li>
                                    <li>Ketuk <b>Tautkan Perangkat</b>, lalu arahkan kamera ponsel Anda ke kotak QR diatas.</li>
                                </ol>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    const hasFullAccess = <?= $has_full_access ? 'true' : 'false' ?>; // Status Role
    let qrInterval = null;

    // 1. FUNGSI UTAMA: CEK STATUS BOT (VIA PROXY)
    function checkBotStatus() {
        $.ajax({
            url: 'proxy_wa.php?path=/status', // <--- BYPASS CORS & MIXED CONTENT
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                $('#statusIndicator').removeClass('alert-secondary alert-success alert-warning alert-danger');
                
                if (data.status === 'connected') {
                    $('#txtStatus').text('TERHUBUNG');
                    $('#statusIndicator').addClass('alert-success');
                    $('#txtNumber').text('+' + data.user);
                    
                    if (hasFullAccess) { $('#btnLogout').show(); } else { $('#btnLogout').hide(); }
                    
                    $('#imgQr').hide();
                    $('#qrInstruction').hide();
                    $('#qrLoading').html('<i class="fas fa-check-circle text-success fa-3x mb-2"></i><p class="text-bold text-success mb-0">Bot Aktif & Siap Digunakan</p>');
                    $('#qrLoading').show();

                    clearInterval(qrInterval);
                    qrInterval = null;

                } else if (data.status === 'qr_ready') {
                    $('#txtStatus').text('MENUNGGU SCAN QR');
                    $('#statusIndicator').addClass('alert-warning');
                    $('#txtNumber').text('-');
                    $('#btnLogout').hide();
                    $('#qrInstruction').show();
                    
                    fetchQrCode();
                    
                    if (!qrInterval) {
                        qrInterval = setInterval(fetchQrCode, 15000);
                    }

                } else {
                    $('#txtStatus').text('TERPUTUS');
                    $('#statusIndicator').addClass('alert-danger');
                    $('#txtNumber').text('-');
                    $('#btnLogout').hide();
                    $('#imgQr').hide();
                    $('#qrInstruction').hide();
                    $('#qrLoading').html('<i class="fas fa-exclamation-triangle text-danger fa-3x mb-2"></i><p class="text-xs text-muted mb-0">Sedang menginisialisasi ulang mesin bot...</p>');
                    $('#qrLoading').show();
                    
                    if (!qrInterval) {
                        qrInterval = setInterval(checkBotStatus, 5000);
                    }
                }
            },
            error: function() {
                $('#statusIndicator').removeClass('alert-success alert-warning alert-danger').addClass('alert-danger');
                $('#txtStatus').text('OFFLINE');
                $('#txtNumber').text('-');
                $('#btnLogout').hide();
                $('#imgQr').hide();
                $('#qrLoading').html('<i class="fas fa-power-off text-danger fa-3x mb-2"></i><p class="text-bold text-danger mb-0">Engine API Node.js Offline</p>');
                $('#qrLoading').show();
            }
        });
    }

    // 2. FUNGSI AMBIL GAMBAR QR (VIA PROXY)
    function fetchQrCode() {
        $.ajax({
            url: 'proxy_wa.php?path=/qr', // <--- BYPASS CORS & MIXED CONTENT
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                if (data.success && data.qr) {
                    $('#qrLoading').hide();
                    $('#imgQr').attr('src', data.qr).show();
                }
            },
            error: function(xhr) {
                if(xhr.status === 400) {
                    checkBotStatus();
                }
            }
        });
    }

    // 3. FUNGSI LOGOUT / PUTUS SESI (VIA PROXY)
    $('#btnLogout').on('click', function() {
        Swal.fire({
            title: 'Putuskan Sesi WhatsApp?',
            text: "Anda harus melakukan scan ulang menggunakan QR jika memutus koneksi bot saat ini.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Putuskan Sesi',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Memproses Logout...', allowOutsideClick: false, didOpen: () => { Swal.showLoading() } });
                
                $.ajax({
                    url: 'proxy_wa.php?path=/logout', // <--- BYPASS CORS & MIXED CONTENT
                    type: 'POST',
                    data: {
                        csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        Swal.fire('Berhasil!', 'Sesi WhatsApp berhasil dihapus murni.', 'success').then(() => {
                            location.reload();
                        });
                    },
                    error: function() {
                        Swal.fire('Gagal!', 'Gagal mengirim instruksi pemutusan sesi.', 'error');
                    }
                });
            }
        });
    });

    // Event Klik Tombol Refresh Manual
    $('#btnRefresh').on('click', function() {
        checkBotStatus();
    });

    // Jalankan pengecekan otomatis pertama kali saat halaman dibuka
    checkBotStatus();
    // Berikan pooling status global berkala setiap 5 detik agar dashboard berubah otomatis jika HP sukses scan
    setInterval(checkBotStatus, 5000);
});
</script>
