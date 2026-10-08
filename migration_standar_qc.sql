-- ============================================================================
-- MIGRATION: Master Standar QC + Snapshot Column
-- Jalankan di PostgreSQL (ikoit_mesin) dan MSSQL masing-masing
-- ============================================================================

-- ============================================================================
-- BAGIAN 1: PostgreSQL (ikoit_mesin) - Tabel Master Standar
-- ============================================================================

CREATE TABLE IF NOT EXISTS db_master.tb_standar_qc (
    id SERIAL PRIMARY KEY,
    lini VARCHAR(20) NOT NULL,            -- 'AAC1', 'AAC2', 'FC'
    kode_param VARCHAR(50) NOT NULL,      -- 'densitas_t1', 'viskositas_t2', 'press_barat', dll
    nama_param VARCHAR(100) NOT NULL,     -- 'Densitas Tangki 1', 'Press Barat', dll
    satuan VARCHAR(20) DEFAULT '',        -- 'gr/ltr', 'cm', '°C', 'Bar', '%'
    nilai_min NUMERIC(10,4),              -- NULL = tidak ada batas bawah (misal suhu)
    nilai_max NUMERIC(10,4),              -- NULL = tidak ada batas atas
    mesin VARCHAR(20) DEFAULT NULL,       -- NULL untuk AAC1/AAC2 (tidak per mesin), isi untuk FC
    produk VARCHAR(50) DEFAULT NULL,      -- NULL untuk AAC1/AAC2, isi untuk FC
    keterangan TEXT DEFAULT '',
    updated_by VARCHAR(50) DEFAULT 'Sistem',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_standar_qc_lini ON db_master.tb_standar_qc (lini);
CREATE INDEX IF NOT EXISTS idx_standar_qc_mesin ON db_master.tb_standar_qc (lini, mesin, produk);

-- ============================================================================
-- SEED DATA AAC1 (Tangki 1-3: Densitas 1600-1620, Tangki 4: 1330-1360)
-- ============================================================================
INSERT INTO db_master.tb_standar_qc (lini, kode_param, nama_param, satuan, nilai_min, nilai_max, updated_by) VALUES
('AAC1', 'densitas_t1', 'Densitas Tangki 1', 'gr/ltr', 1600, 1620, 'Sistem'),
('AAC1', 'densitas_t2', 'Densitas Tangki 2', 'gr/ltr', 1600, 1620, 'Sistem'),
('AAC1', 'densitas_t3', 'Densitas Tangki 3', 'gr/ltr', 1600, 1620, 'Sistem'),
('AAC1', 'densitas_t4', 'Densitas Tangki 4', 'gr/ltr', 1330, 1360, 'Sistem'),
('AAC1', 'viskositas_t1', 'Viskositas Tangki 1', 'cm', 33, 40, 'Sistem'),
('AAC1', 'viskositas_t2', 'Viskositas Tangki 2', 'cm', 33, 40, 'Sistem'),
('AAC1', 'viskositas_t3', 'Viskositas Tangki 3', 'cm', 33, 40, 'Sistem'),
('AAC1', 'viskositas_t4', 'Viskositas Tangki 4', 'cm', 33, 40, 'Sistem'),
('AAC1', 'suhu_t1', 'Suhu Tangki 1', '°C', NULL, 43, 'Sistem'),
('AAC1', 'suhu_t2', 'Suhu Tangki 2', '°C', NULL, 43, 'Sistem'),
('AAC1', 'suhu_t3', 'Suhu Tangki 3', '°C', NULL, 43, 'Sistem'),
('AAC1', 'suhu_t4', 'Suhu Tangki 4', '°C', NULL, 40, 'Sistem'),
('AAC1', 'visco_pouring', 'Visco Pouring', 'cm', 22, 24, 'Sistem');

-- ============================================================================
-- SEED DATA AAC2 (Tangki 1-4: Densitas 1600-1620, Tangki 5-6: 1330-1360)
-- ============================================================================
INSERT INTO db_master.tb_standar_qc (lini, kode_param, nama_param, satuan, nilai_min, nilai_max, updated_by) VALUES
('AAC2', 'densitas_t1', 'Densitas Tangki 1', 'gr/ltr', 1600, 1620, 'Sistem'),
('AAC2', 'densitas_t2', 'Densitas Tangki 2', 'gr/ltr', 1600, 1620, 'Sistem'),
('AAC2', 'densitas_t3', 'Densitas Tangki 3', 'gr/ltr', 1600, 1620, 'Sistem'),
('AAC2', 'densitas_t4', 'Densitas Tangki 4', 'gr/ltr', 1600, 1620, 'Sistem'),
('AAC2', 'densitas_t5', 'Densitas Tangki 5', 'gr/ltr', 1330, 1360, 'Sistem'),
('AAC2', 'densitas_t6', 'Densitas Tangki 6', 'gr/ltr', 1330, 1360, 'Sistem'),
('AAC2', 'viskositas_t1', 'Viskositas Tangki 1', 'cm', 33, 40, 'Sistem'),
('AAC2', 'viskositas_t2', 'Viskositas Tangki 2', 'cm', 33, 40, 'Sistem'),
('AAC2', 'viskositas_t3', 'Viskositas Tangki 3', 'cm', 33, 40, 'Sistem'),
('AAC2', 'viskositas_t4', 'Viskositas Tangki 4', 'cm', 33, 40, 'Sistem'),
('AAC2', 'viskositas_t5', 'Viskositas Tangki 5', 'cm', 33, 40, 'Sistem'),
('AAC2', 'viskositas_t6', 'Viskositas Tangki 6', 'cm', 33, 40, 'Sistem'),
('AAC2', 'suhu_t1', 'Suhu Tangki 1', '°C', NULL, 45, 'Sistem'),
('AAC2', 'suhu_t2', 'Suhu Tangki 2', '°C', NULL, 45, 'Sistem'),
('AAC2', 'suhu_t3', 'Suhu Tangki 3', '°C', NULL, 45, 'Sistem'),
('AAC2', 'suhu_t4', 'Suhu Tangki 4', '°C', NULL, 45, 'Sistem'),
('AAC2', 'suhu_t5', 'Suhu Tangki 5', '°C', NULL, 40, 'Sistem'),
('AAC2', 'suhu_t6', 'Suhu Tangki 6', '°C', NULL, 40, 'Sistem'),
('AAC2', 'visco_pouring', 'Visco Pouring', 'cm', 22, 24, 'Sistem');

-- ============================================================================
-- SEED DATA FC (Per Mesin + Produk)
-- ============================================================================

-- FS-01 (NUSABESS 11, NUSABESS 14)
INSERT INTO db_master.tb_standar_qc (lini, kode_param, nama_param, satuan, nilai_min, nilai_max, mesin, produk, updated_by) VALUES
('FC', 'press_barat', 'Press Barat', 'Bar', 1.6, 2.4, 'FS-01', NULL, 'Sistem'),
('FC', 'press_timur', 'Press Timur', 'Bar', 1.6, 2.4, 'FS-01', NULL, 'Sistem'),
('FC', 'kadar_air_before', 'Kadar Air Before', '%', 35, 38, 'FS-01', NULL, 'Sistem'),
('FC', 'kadar_air_after', 'Kadar Air After', '%', 26, 28, 'FS-01', NULL, 'Sistem'),
('FC', 'densitas_barat', 'Densitas Barat', 'gr/cm³', 1.82, 1.90, 'FS-01', NULL, 'Sistem'),
('FC', 'densitas_tengah', 'Densitas Tengah', 'gr/cm³', 1.82, 1.90, 'FS-01', NULL, 'Sistem'),
('FC', 'densitas_timur', 'Densitas Timur', 'gr/cm³', 1.82, 1.90, 'FS-01', NULL, 'Sistem');

-- FS-02
INSERT INTO db_master.tb_standar_qc (lini, kode_param, nama_param, satuan, nilai_min, nilai_max, mesin, produk, updated_by) VALUES
('FC', 'press_barat', 'Press Barat', 'Bar', 1.5, 4.5, 'FS-02', NULL, 'Sistem'),
('FC', 'press_timur', 'Press Timur', 'Bar', 1.5, 4.5, 'FS-02', NULL, 'Sistem'),
('FC', 'kadar_air_before', 'Kadar Air Before', '%', 35, 40, 'FS-02', NULL, 'Sistem'),
('FC', 'kadar_air_after', 'Kadar Air After', '%', 26, 30, 'FS-02', NULL, 'Sistem'),
('FC', 'densitas_barat', 'Densitas Barat', 'gr/cm³', 1.80, 1.90, 'FS-02', NULL, 'Sistem'),
('FC', 'densitas_tengah', 'Densitas Tengah', 'gr/cm³', 1.80, 1.90, 'FS-02', NULL, 'Sistem'),
('FC', 'densitas_timur', 'Densitas Timur', 'gr/cm³', 1.80, 1.90, 'FS-02', NULL, 'Sistem');

-- FS-03
INSERT INTO db_master.tb_standar_qc (lini, kode_param, nama_param, satuan, nilai_min, nilai_max, mesin, produk, updated_by) VALUES
('FC', 'press_barat', 'Press Barat', 'Bar', 1.5, 4.5, 'FS-03', NULL, 'Sistem'),
('FC', 'press_timur', 'Press Timur', 'Bar', 1.5, 4.5, 'FS-03', NULL, 'Sistem'),
('FC', 'kadar_air_before', 'Kadar Air Before', '%', 38, 45, 'FS-03', NULL, 'Sistem'),
('FC', 'kadar_air_after', 'Kadar Air After', '%', 28, 31, 'FS-03', NULL, 'Sistem'),
('FC', 'densitas_barat', 'Densitas Barat', 'gr/cm³', 1.65, 1.72, 'FS-03', NULL, 'Sistem'),
('FC', 'densitas_tengah', 'Densitas Tengah', 'gr/cm³', 1.65, 1.72, 'FS-03', NULL, 'Sistem'),
('FC', 'densitas_timur', 'Densitas Timur', 'gr/cm³', 1.65, 1.72, 'FS-03', NULL, 'Sistem');

-- FS-05
INSERT INTO db_master.tb_standar_qc (lini, kode_param, nama_param, satuan, nilai_min, nilai_max, mesin, produk, updated_by) VALUES
('FC', 'press_barat', 'Press Barat', 'Bar', 1.5, 4.5, 'FS-05', NULL, 'Sistem'),
('FC', 'press_timur', 'Press Timur', 'Bar', 1.5, 4.5, 'FS-05', NULL, 'Sistem'),
('FC', 'kadar_air_before', 'Kadar Air Before', '%', 35, 40, 'FS-05', NULL, 'Sistem'),
('FC', 'kadar_air_after', 'Kadar Air After', '%', 26, 30, 'FS-05', NULL, 'Sistem'),
('FC', 'densitas_barat', 'Densitas Barat', 'gr/cm³', 1.80, 1.90, 'FS-05', NULL, 'Sistem'),
('FC', 'densitas_tengah', 'Densitas Tengah', 'gr/cm³', 1.80, 1.90, 'FS-05', NULL, 'Sistem'),
('FC', 'densitas_timur', 'Densitas Timur', 'gr/cm³', 1.80, 1.90, 'FS-05', NULL, 'Sistem');

-- FS-06
INSERT INTO db_master.tb_standar_qc (lini, kode_param, nama_param, satuan, nilai_min, nilai_max, mesin, produk, updated_by) VALUES
('FC', 'press_barat', 'Press Barat', 'Bar', 3.7, 4.2, 'FS-06', NULL, 'Sistem'),
('FC', 'press_timur', 'Press Timur', 'Bar', 3.7, 4.2, 'FS-06', NULL, 'Sistem'),
('FC', 'kadar_air_before', 'Kadar Air Before', '%', 42, 45, 'FS-06', NULL, 'Sistem'),
('FC', 'kadar_air_after', 'Kadar Air After', '%', 27, 29, 'FS-06', NULL, 'Sistem'),
('FC', 'densitas_barat', 'Densitas Barat', 'gr/cm³', 1.68, 1.75, 'FS-06', NULL, 'Sistem'),
('FC', 'densitas_tengah', 'Densitas Tengah', 'gr/cm³', 1.68, 1.75, 'FS-06', NULL, 'Sistem'),
('FC', 'densitas_timur', 'Densitas Timur', 'gr/cm³', 1.68, 1.75, 'FS-06', NULL, 'Sistem');

-- FS-07
INSERT INTO db_master.tb_standar_qc (lini, kode_param, nama_param, satuan, nilai_min, nilai_max, mesin, produk, updated_by) VALUES
('FC', 'press_barat', 'Press Barat', 'Bar', 3.7, 4.2, 'FS-07', NULL, 'Sistem'),
('FC', 'press_timur', 'Press Timur', 'Bar', 3.7, 4.2, 'FS-07', NULL, 'Sistem'),
('FC', 'kadar_air_before', 'Kadar Air Before', '%', 43, 46, 'FS-07', NULL, 'Sistem'),
('FC', 'kadar_air_after', 'Kadar Air After', '%', 27, 28.5, 'FS-07', NULL, 'Sistem'),
('FC', 'densitas_barat', 'Densitas Barat', 'gr/cm³', 1.68, 1.75, 'FS-07', NULL, 'Sistem'),
('FC', 'densitas_tengah', 'Densitas Tengah', 'gr/cm³', 1.68, 1.75, 'FS-07', NULL, 'Sistem'),
('FC', 'densitas_timur', 'Densitas Timur', 'gr/cm³', 1.68, 1.75, 'FS-07', NULL, 'Sistem');

-- FS-08
INSERT INTO db_master.tb_standar_qc (lini, kode_param, nama_param, satuan, nilai_min, nilai_max, mesin, produk, updated_by) VALUES
('FC', 'press_barat', 'Press Barat', 'Bar', 1.5, 4.5, 'FS-08', NULL, 'Sistem'),
('FC', 'press_timur', 'Press Timur', 'Bar', 1.5, 4.5, 'FS-08', NULL, 'Sistem'),
('FC', 'kadar_air_before', 'Kadar Air Before', '%', 35, 40, 'FS-08', NULL, 'Sistem'),
('FC', 'kadar_air_after', 'Kadar Air After', '%', 26, 30, 'FS-08', NULL, 'Sistem'),
('FC', 'densitas_barat', 'Densitas Barat', 'gr/cm³', 1.80, 1.90, 'FS-08', NULL, 'Sistem'),
('FC', 'densitas_tengah', 'Densitas Tengah', 'gr/cm³', 1.80, 1.90, 'FS-08', NULL, 'Sistem'),
('FC', 'densitas_timur', 'Densitas Timur', 'gr/cm³', 1.80, 1.90, 'FS-08', NULL, 'Sistem');


-- ============================================================================
-- BAGIAN 2: SQL SERVER (MSSQL) - Tambah kolom snapshot standar
-- Jalankan di masing-masing database MSSQL
-- ============================================================================

-- ---- Di database: Quality_Product_AAC1_NBI ----
-- ALTER TABLE parameter_aac1 ADD std_snapshot NVARCHAR(MAX) NULL;

-- ---- Di database: Quality_Product_AAC2_NBI ----
-- ALTER TABLE parameter_aac2 ADD std_snapshot NVARCHAR(MAX) NULL;

-- ---- Di database: Quality_Product_FC_NBI ----
-- ALTER TABLE parameter_fc ADD std_snapshot NVARCHAR(MAX) NULL;
