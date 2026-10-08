<?php 
// Mendapatkan nama file saat ini untuk menentukan menu yang aktif
$currentPage = basename($_SERVER['PHP_SELF']); 

// Keamanan: jangan tampilkan error ke browser di production
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Header keamanan dasar (tanpa mengganggu session yang sudah dimulai)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PT. NBI - Factory Support</title>
	<link rel="icon" href="assets/icon/favicon.ico" type="image/png">

    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
	<style>
	/* --- LOGIKA GAMBAR LOGO MINI & LARGE --- */
        /* Kondisi Normal (Sidebar Terbuka) */
        .brand-link .logo-mini { display: none; } 
        .brand-link .logo-lg { display: inline-block; transition: width 0.3s; }

        /* Kondisi Dilipat (Sidebar Collapse) */
        body.sidebar-collapse .brand-link .logo-lg { 
            display: none !important; 
        }
        body.sidebar-collapse .brand-link .logo-mini { 
            display: inline-block !important; 
        }

        /* Kondisi Saat Dilipat tapi Di-Hover (Buka Sementara) */
        body.sidebar-collapse .main-sidebar:hover .brand-link .logo-lg { 
            display: inline-block !important; 
        }
        body.sidebar-collapse .main-sidebar:hover .brand-link .logo-mini { 
            display: none !important; 
        }
	</style>
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">

    <nav class="main-header navbar navbar-expand navbar-dark" style="background-color: #00a65a; border-bottom: none; height: 50px;">
        <ul class="navbar-nav">
            <li class="nav-item">
				<a class="nav-link" data-widget="pushmenu" href="#" role="button">
					<i class="fas fa-bars" style="font-size: 13px;"></i>
				</a>            
			</li>
        </ul>
    </nav>
    <?php require_once 'sidebar.php'; ?>
