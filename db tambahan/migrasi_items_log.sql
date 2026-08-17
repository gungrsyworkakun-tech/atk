-- Migrasi: tabel riwayat perubahan data barang (siapa edit/tambah/hapus apa)
-- Jalankan di phpMyAdmin (tab SQL) pada database atk_db

CREATE TABLE `items_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) DEFAULT NULL COMMENT 'Boleh NULL/tidak valid lagi kalau barang sudah dihapus',
  `kode_barang` varchar(50) DEFAULT NULL,
  `nama_barang` varchar(150) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL COMMENT 'Disimpan redundan supaya riwayat tetap utuh walau akun dihapus',
  `aksi` enum('tambah','ubah','hapus') NOT NULL,
  `perubahan` text DEFAULT NULL COMMENT 'Ringkasan field yang berubah, mis. "Stok: 50 -> 80; Harga: 1000 -> 1200"',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `item_id` (`item_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
