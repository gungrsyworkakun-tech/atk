<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

// Mode global search (dipakai kotak cari di topbar) -> cocok sebagian di kode/nama barang & nama bidang.
if (isset($_GET['q'])) {
    $q = trim($_GET['q']);
    if ($q === '' || mb_strlen($q) < 2) {
        echo json_encode(['items' => [], 'bidang' => []]);
        exit;
    }
    $like = '%' . $q . '%';

    $items = [];
    if (has_permission('data_barang') || has_permission('barang_masuk_keluar') || has_permission('barcode') || has_permission('harga') || has_permission('realisasi')) {
        $stmt = $pdo->prepare('SELECT id, kode, nama, stok FROM items WHERE kode LIKE ? OR nama LIKE ? ORDER BY nama LIMIT 6');
        $stmt->execute([$like, $like]);
        $items = $stmt->fetchAll();
    }

    $bidang = [];
    if (has_permission('bidang')) {
        $stmt = $pdo->prepare('SELECT id, nama FROM bidang_list WHERE nama LIKE ? ORDER BY nama LIMIT 6');
        $stmt->execute([$like]);
        $bidang = $stmt->fetchAll();
    }

    echo json_encode(['items' => $items, 'bidang' => $bidang]);
    exit;
}

// Mode exact-match by kode (dipakai fitur pindai barcode di halaman transaksi) -> perilaku lama, tetap dipertahankan.
require_permission('barang_masuk_keluar');
$kode = trim($_GET['kode'] ?? '');
if ($kode === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Kode kosong.']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, kode, nama, stok FROM items WHERE kode = ?');
$stmt->execute([$kode]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    echo json_encode(['error' => 'Barang dengan kode tersebut tidak ditemukan.']);
    exit;
}

echo json_encode($item);