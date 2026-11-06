-- =====================================================
-- Skema Database v2: Peminjaman Alat & Barang Laboratorium
-- Pelacakan unit fisik (aset unik): 1 baris barang = 1 unit fisik
-- MySQL 5.7+ / MariaDB 10.3+
-- =====================================================

CREATE DATABASE IF NOT EXISTS peminjaman_lab
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE peminjaman_lab;

-- -----------------------------------------------------
-- BARANG: setiap unit fisik punya kode aset unik
-- (mis. LAPTOP-001, LAPTOP-002 adalah dua unit berbeda)
-- -----------------------------------------------------
CREATE TABLE barang (
  id_barang    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kode_aset    VARCHAR(30)  NOT NULL,            -- label/inventaris yang ditempel pada unit
  nama_barang  VARCHAR(120) NOT NULL,
  kategori     VARCHAR(60)  DEFAULT NULL,
  foto_barang  VARCHAR(255) DEFAULT NULL,        -- nama file di folder uploads/
  kondisi      ENUM('baik','rusak ringan','rusak berat') NOT NULL DEFAULT 'baik',
  status_aktif TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 = disembunyikan dari katalog
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_barang),
  UNIQUE KEY uq_kode_aset (kode_aset)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- PEMINJAM: NIM unik, dipakai ulang antar transaksi
-- -----------------------------------------------------
CREATE TABLE peminjam (
  id_peminjam INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama        VARCHAR(120) NOT NULL,
  nim         VARCHAR(20)  NOT NULL,
  jurusan     VARCHAR(100) NOT NULL,
  no_hp       VARCHAR(20)  NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_peminjam),
  UNIQUE KEY uq_nim (nim)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- TRANSAKSI: peminjam <-> unit barang + rentang waktu
-- Status 'menunggu','disetujui','dipinjam' MEMBLOKIR jadwal unit.
-- -----------------------------------------------------
CREATE TABLE transaksi (
  id_transaksi  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_peminjam   INT UNSIGNED NOT NULL,
  id_barang     INT UNSIGNED NOT NULL,
  waktu_ambil   DATETIME     NOT NULL,
  waktu_kembali DATETIME     NOT NULL,
  status        ENUM('menunggu','disetujui','dipinjam','selesai','ditolak','dibatalkan')
                NOT NULL DEFAULT 'menunggu',
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_transaksi),
  KEY idx_barang_waktu (id_barang, waktu_ambil, waktu_kembali),
  KEY idx_status (status),
  CONSTRAINT fk_transaksi_peminjam
    FOREIGN KEY (id_peminjam) REFERENCES peminjam (id_peminjam)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transaksi_barang
    FOREIGN KEY (id_barang) REFERENCES barang (id_barang)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_waktu CHECK (waktu_kembali > waktu_ambil)  -- ditegakkan MySQL 8.0.16+
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Data contoh (opsional). Foto diunggah lewat admin.php.
-- -----------------------------------------------------
INSERT INTO barang (kode_aset, nama_barang, kategori) VALUES
  ('LAPTOP-001', 'Laptop Asus A', 'Komputer'),
  ('LAPTOP-002', 'Laptop Asus A', 'Komputer'),
  ('PROYEKTOR-001', 'Proyektor Epson', 'Multimedia'),
  ('ROUTER-001', 'Router Cisco 2901', 'Jaringan'),
  ('SWITCH-001', 'Switch Cisco 2960', 'Jaringan'),
  ('MULTIMETER-001', 'Multimeter Digital', 'Elektronika');
