# Pendataan ATK (PHP Native + MySQL)

## Kebutuhan server
- PHP 7.4+ (disarankan PHP 8.x) dengan ekstensi **PDO MySQL** dan **GD** aktif.
- MySQL / MariaDB.
- Bisa dijalankan di XAMPP/Laragon (lokal) maupun hosting biasa (cPanel dll).

## Instalasi
1. Salin seluruh folder ini ke `htdocs` (XAMPP) atau document root server Anda.
2. Buat database dengan mengimpor `schema.sql`:
   ```
   mysql -u root -p < schema.sql
   ```
   atau lewat phpMyAdmin: buat database `atk_db`, lalu impor `schema.sql`.
3. Buka `config.php`, sesuaikan `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` dengan pengaturan MySQL Anda.
4. Pastikan folder `uploads/bast/` bisa ditulis oleh PHP (`chmod 755` atau `775` di server Linux).
5. Akses aplikasi lewat browser, misalnya `http://localhost/atk-php/`.
6. Login pertama kali otomatis tersedia:
   - **Nama pengguna:** `superadmin`
   - **Kata sandi:** `admin123`
   
   Akun ini dibuat otomatis oleh aplikasi saat tabel `users` masih kosong. **Segera ganti kata sandi** setelah login (lewat menu Kelola Admin, atau update manual di database untuk sementara).

## Struktur menu
**Admin biasa** — menunya menyesuaikan hak akses yang diberikan super admin:
1. Barang Masuk & Keluar (`transaksi.php`) — termasuk tahun barang masuk
2. Barcode Barang (`barcode.php`) — barcode Code 39, bisa diunduh sebagai PNG
3. Data Barang (`data_barang.php`) — jenis dan jumlah/stok barang
4. Berkas BAST (`bast.php`) — unggah PDF/hasil pindai per transaksi keluar
5. Realisasi & Limit Stok (`realisasi.php`)
6. Harga Barang (`harga.php`)
7. Bidang Pengambilan (`bidang.php`)

**Super admin** — semua menu di atas + **Kelola Admin** (`kelola_admin.php`) untuk membuat akun admin biasa dan mengatur menu mana saja yang boleh mereka akses/edit.

## Catatan teknis
- Barcode dibuat murni dengan PHP + GD (format Code 39), tanpa library pihak ketiga.
- Berkas BAST disimpan di `uploads/bast/` dan hanya bisa diakses lewat `serve_bast.php` setelah login (folder diblokir langsung lewat `.htaccess`).
- Password disimpan dengan `password_hash()` (bcrypt), bukan teks biasa.
- Form-form penting sudah memakai token CSRF sederhana.
