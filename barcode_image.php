<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('barcode');
require __DIR__ . '/includes/barcode.php';

$kode = $_GET['kode'] ?? '';
if ($kode === '') { http_response_code(400); exit('Kode barang wajib diisi.'); }

$stmt = $pdo->prepare('SELECT kode, nama, jenis, tahun_masuk, stok FROM items WHERE kode = ?');
$stmt->execute([$kode]);
$item = $stmt->fetch();
if (!$item) { http_response_code(404); exit('Barang tidak ditemukan.'); }

if (!function_exists('imagepng')) {
    http_response_code(500);
    exit('Ekstensi GD belum aktif di server PHP.');
}

ob_start();
$error = null;
$img = null;
try {
    $img = ItemQr::render_with_label($item['kode'], $item['nama'], $item['tahun_masuk'], $item['stok'], $item['jenis']);
    if (!$img) { $error = 'render_with_label() tidak mengembalikan resource gambar.'; }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
ob_end_clean();

if ($error !== null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Gagal membuat barcode untuk "' . $kode . '": ' . $error);
}

if (!empty($_GET['download'])) {
    $safeKode = preg_replace('/[^A-Za-z0-9\-_]/', '', $item['kode']);
    $safeNama = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $item['nama']), '-');
    $filename = sprintf('%s_%s_%d.png', $safeKode, $safeNama, (int)$item['tahun_masuk']);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}
header('Content-Type: image/png');
imagepng($img);
imagedestroy($img);