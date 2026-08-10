-- Skema database Pendataan ATK
-- Import file ini ke MySQL/MariaDB sebelum menjalankan aplikasi:
--   mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS atk_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE atk_db;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('super','admin') NOT NULL DEFAULT 'admin',
  permissions TEXT NULL COMMENT 'JSON: daftar menu yang boleh diakses admin biasa',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kode VARCHAR(50) NOT NULL UNIQUE,
  nama VARCHAR(150) NOT NULL,
  jenis VARCHAR(100) NOT NULL,
  stok INT NOT NULL DEFAULT 0,
  stok_minimum INT NOT NULL DEFAULT 0,
  harga DECIMAL(14,2) NOT NULL DEFAULT 0,
  tahun_masuk INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  tipe ENUM('masuk','keluar') NOT NULL,
  jumlah INT NOT NULL,
  tanggal DATE NOT NULL,
  bidang VARCHAR(150) NULL,
  keterangan VARCHAR(255) NULL,
  bast_file VARCHAR(255) NULL COMMENT 'nama file BAST tersimpan di /uploads/bast',
  created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Catatan: akun superadmin default (superadmin / admin123) dibuat otomatis
-- oleh aplikasi (includes/bootstrap.php) saat tabel users masih kosong,
-- supaya kata sandi ter-hash dengan benar oleh PHP.
