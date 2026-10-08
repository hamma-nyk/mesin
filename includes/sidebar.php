<?php
// Ambil role dari session (Jika tidak ada, set kosong agar aman)
$role_aktif = $_SESSION['role'] ?? '';

// Definisikan array halaman untuk masing-masing grup agar otomatis mengatur status 'menu-open' dan 'active'
$pages_aac1       = ['p1_karyawan_keluar.php'];
$pages_aac2       = ['data_mesin_mixing_aac2.php'];
$pages_mortar     = ['laporan_produksi_mortar.php'];

// Menggabungkan form input lama/baru dan laporan log ke dalam satu grup kontrol QC Mortar (FC)
$pages_qc         = ['qc_fc_input.php', 'laporan_qc_fc.php', 'qc_input_mortar.php', 'laporan_qc_mortar.php']; 
$pages_qc_aac1    = ['qc_aac1_input.php', 'laporan_qc_aac1.php'];
$pages_qc_aac2    = ['qc_aac2_input.php', 'laporan_qc_aac2.php'];
?>

<aside class="main-sidebar sidebar-dark-success elevation-0">
    
    <a href="index.php" class="brand-link text-center" style="background-color: #008d4c; border-bottom: none; height: 50px; padding: 10px 0;">
        <span class="logo-mini">
            <img src="assets/img/nbi.png" style="width: 0px; height: auto;" alt="Mini Logo">
        </span>
        <span class="logo-lg">
            <img src="assets/img/nbi.png" style="width: 52.5%; height: auto; margin-bottom: 1px;" alt="Logo Large">
        </span>
    </a>

    <div class="sidebar">
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column nav-child-indent" data-widget="treeview" role="menu" data-accordion="false">
                
                <li class="nav-item">
                    <a href="index.php" class="nav-link <?= $currentPage == 'index.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                
                <?php if($role_aktif === 'Admin IT'): ?>
                <li class="nav-header">MASTER DATA</li>
                <li class="nav-item">
                    <a href="master_user.php" class="nav-link <?= $currentPage == 'master_user.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-users-cog"></i>
                        <p>Data Pengguna</p>
                    </a>
                </li>
                <?php endif; ?>

                <?php if(in_array($role_aktif, ['Admin IT', 'Kabag QCL', 'Supervisor QCL AAC', 'Supervisor QCL FC'])): ?>
                <li class="nav-item">
                    <a href="master_standar_qc.php" class="nav-link <?= $currentPage == 'master_standar_qc.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-sliders-h text-info"></i>
                        <p>Standar Parameter QC</p>
                    </a>
                </li>
                <?php endif; ?>

                <?php if(in_array($role_aktif, ['Admin IT', 'Admin Produksi AAC1', 'Admin Produksi AAC2'])): ?>
                <li class="nav-header">DATA MESIN</li>
                
                    <?php if(in_array($role_aktif, ['Admin IT', 'Admin Produksi AAC1'])): ?>
                    <li class="nav-item <?= in_array($currentPage, $pages_aac1) ? 'menu-open' : '' ?>">
                        <a href="#" class="nav-link <?= in_array($currentPage, $pages_aac1) ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-cubes"></i>
                            <p>
                                AAC 1
                                <i class="right fas fa-angle-left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="p1_karyawan_keluar.php" class="nav-link <?= $currentPage == 'p1_karyawan_keluar.php' ? 'active' : '' ?>">
                                    <i class="nav-icon far fa-circle"></i>
                                    <p>Karyawan Keluar AAC1</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endif; ?>

                    <?php if(in_array($role_aktif, ['Admin IT', 'Admin Produksi AAC2'])): ?>
                    <li class="nav-item <?= in_array($currentPage, $pages_aac2) ? 'menu-open' : '' ?>">
                        <a href="#" class="nav-link <?= in_array($currentPage, $pages_aac2) ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-cubes"></i>
                            <p>
                                AAC 2
                                <i class="right fas fa-angle-left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="data_mesin_mixing_aac2.php" class="nav-link <?= $currentPage == 'data_mesin_mixing_aac2.php' ? 'active' : '' ?>">
                                    <i class="nav-icon fas fa-industry"></i>
                                    <p>Mesin Mixing AAC2</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if(in_array($role_aktif, ['Admin IT', 'Admin Produksi Mortar', 'Admin Produksi AAC2', 'Admin QMS'])): ?>
                <li class="nav-header">LAPORAN PRODUKSI</li>
                
                    <?php if(in_array($role_aktif, ['Admin IT', 'Kabag Produksi', 'Admin Produksi Mortar', 'Admin QMS'])): ?>
                    <li class="nav-item">
                        <a href="laporan_produksi_mortar.php" class="nav-link <?= $currentPage == 'laporan_produksi_mortar.php' ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-file-invoice text-info"></i>
                            <p>Produksi Mortar</p>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if(in_array($role_aktif, ['Admin IT', 'Kabag Produksi', 'Admin Produksi AAC1', 'Admin Produksi AAC2', 'Admin QMS'])): ?>
                    <li class="nav-item">
                        <a href="laporan_produksi_plc.php" class="nav-link <?= $currentPage == 'laporan_produksi_aac1.php' ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-file-invoice text-info"></i>
                            <p>Produksi AAC 1</p>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if(in_array($role_aktif, ['Admin IT', 'Kabag Produksi', 'Admin Produksi AAC2', 'Admin QMS'])): ?>
                    <li class="nav-item">
                        <a href="laporan_produksi_aac2.php" class="nav-link <?= $currentPage == 'laporan_produksi_aac2.php' ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-file-invoice text-info"></i>
                            <p>Produksi AAC 2</p>
                        </a>
                    </li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if(in_array($role_aktif, ['Admin IT', 'Admin QCL FC','Admin QCL AAC1','Admin QCL AAC2', 'Supervisor QCL AAC', 'Kabag QCL', 'Supervisor QCL FC'])): ?>
                <li class="nav-header">QUALITY CONTROL & LAB</li>
                
                    <?php if(in_array($role_aktif, ['Admin IT', 'Admin QCL FC', 'Kabag QCL', 'Supervisor QCL FC'])): ?>
                    <li class="nav-item <?= in_array($currentPage, $pages_qc) ? 'menu-open' : '' ?>">
                        <a href="#" class="nav-link <?= in_array($currentPage, $pages_qc) ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-vials text-warning"></i>
                            <p>
                                QC FC
                                <i class="right fas fa-angle-left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="qc_fc_input.php" class="nav-link <?= $currentPage == 'qc_fc_input.php' ? 'active' : '' ?>">
                                    <i class="nav-icon far fa-edit text-warning"></i>
                                    <p>Input Data</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="laporan_qc_fc.php" class="nav-link <?= $currentPage == 'laporan_qc_fc.php' ? 'active' : '' ?>">
                                    <i class="nav-icon far fa-chart-bar text-warning"></i>
                                    <p>Laporan</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    <?php if(in_array($role_aktif, ['Admin IT', 'Admin QCL AAC1', 'Supervisor QCL AAC','Kabag QCL'])): ?>
                    <li class="nav-item <?= in_array($currentPage, $pages_qc_aac1) ? 'menu-open' : '' ?>">
                        <a href="#" class="nav-link <?= in_array($currentPage, $pages_qc_aac1) ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-flask text-success"></i>
                            <p>
                                QC AAC 1
                                <i class="right fas fa-angle-left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="qc_aac1_input.php" class="nav-link <?= $currentPage == 'qc_aac1_input.php' ? 'active' : '' ?>">
                                    <i class="nav-icon far fa-edit text-success"></i>
                                    <p>Input Data</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="laporan_qc_aac1.php" class="nav-link <?= $currentPage == 'laporan_qc_aac1.php' ? 'active' : '' ?>">
                                    <i class="nav-icon far fa-chart-bar text-success"></i>
                                    <p>Laporan</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    <?php if(in_array($role_aktif, ['Admin IT', 'Admin QCL AAC2', 'Supervisor QCL AAC', 'Kabag QCL'])): ?>
                    <li class="nav-item <?= in_array($currentPage, $pages_qc_aac2) ? 'menu-open' : '' ?>">
                        <a href="#" class="nav-link <?= in_array($currentPage, $pages_qc_aac2) ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-flask text-success"></i>
                            <p>
                                QC AAC 2
                                <i class="right fas fa-angle-left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="qc_aac2_input.php" class="nav-link <?= $currentPage == 'qc_aac2_input.php' ? 'active' : '' ?>">
                                    <i class="nav-icon far fa-edit text-success"></i>
                                    <p>Input Data</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="laporan_qc_aac2.php" class="nav-link <?= $currentPage == 'laporan_qc_aac2.php' ? 'active' : '' ?>">
                                    <i class="nav-icon far fa-chart-bar text-success"></i>
                                    <p>Laporan</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if(in_array($role_aktif, ['Admin IT', 'Supervisor QCL FC', 'Supervisor QCL AAC', 'Kabag QCL'])): ?>
                <li class="nav-header">INTEGRASI NOTIFIKASI</li>
                <li class="nav-item">
                    <a href="qc_wa_connect.php" class="nav-link <?= $currentPage == 'qc_wa_connect.php' ? 'active' : '' ?>">
                        <i class="nav-icon fab fa-whatsapp text-success"></i>
                        <p class="text-bold">Koneksi WhatsApp</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="qc_wa_config.php" class="nav-link <?= $currentPage == 'qc_wa_config.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-cogs text-success"></i>
                        <p class="text-bold">Config WhatsApp</p>
                    </a>
                </li>
                <?php endif; ?>
                
                <li class="nav-header">AUTENTIKASI</li>
                <li class="nav-item">
                    <a href="logout.php" class="nav-link">
                        <i class="nav-icon fas fa-power-off text-danger"></i>
                        <p class="text-bold text-danger">Keluar Sistem</p>
                    </a>
                </li>

            </ul>
        </nav>
    </div>
</aside>
