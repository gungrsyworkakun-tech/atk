<?php
// Daftar menu/module beserta kuncinya. Dipakai untuk hak akses admin biasa.
const MODULES = [
    'barang_masuk_keluar' => 'Barang Masuk & Keluar',
    'barcode'             => 'Barcode Barang',
    'data_barang'         => 'Data Barang',
    'bast'                => 'Berkas BAST',
    'realisasi'           => 'Realisasi & Limit Stok',
    'harga'               => 'Harga Barang',
    'bidang'              => 'Bidang Pengambilan',
    'kelola_permintaan'   => 'Permintaan ATK Bidang',
    'asisten_ai'          => 'Asisten AI',
    'penitipan'           => 'Penitipan ATK',
];

// Ikon + warna badge untuk tiap menu (dipakai di sidebar & kartu shortcut dashboard).
const MODULE_META = [
    'barang_masuk_keluar' => ['icon' => 'arrows',    'color' => 'blue',   'sub' => 'Catat mutasi stok masuk & keluar'],
    'barcode'             => ['icon' => 'qrcode',    'color' => 'teal',   'sub' => 'QR untuk tiap kode barang'],
    'data_barang'          => ['icon' => 'box',       'color' => 'cyan',   'sub' => 'Jenis barang dan jumlah stok'],
    'bast'                 => ['icon' => 'file',      'color' => 'amber',  'sub' => 'Berkas serah terima barang'],
    'realisasi'            => ['icon' => 'gauge',     'color' => 'purple', 'sub' => 'Pemakaian dan batas stok minimum'],
    'harga'                => ['icon' => 'tag',       'color' => 'green',  'sub' => 'Harga satuan dan nilai stok'],
    'bidang'               => ['icon' => 'building',  'color' => 'pink',   'sub' => 'Rekap pengambilan per bidang'],
    'kelola_permintaan'    => ['icon' => 'clipboard',  'color' => 'indigo', 'sub' => 'Proses & update status permintaan ATK dari bidang'],
    'asisten_ai'           => ['icon' => 'chat',       'color' => 'purple', 'sub' => 'Tanya AI seputar stok & laporan'],
    'penitipan'            => ['icon' => 'archive',    'color' => 'amber',  'sub' => 'Barang pribadi yang dititipkan sementara ke gudang'],
];
const ADMIN_META = [
    'kelola_admin'  => ['icon' => 'shield',    'color' => 'indigo', 'label' => 'Kelola Admin',  'sub' => 'Akun & hak akses admin biasa'],
    'kelola_bidang' => ['icon' => 'building2', 'color' => 'blue',   'label' => 'Kelola Bidang', 'sub' => 'Daftar bidang/tujuan barang keluar'],
];

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function format_rupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

function redirect($path) {
    header('Location: ' . $path);
    exit;
}

function flash_set($msg, $type = 'ok') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function flash_get() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function decode_permissions($json) {
    $perms = json_decode((string)$json, true);
    return is_array($perms) ? $perms : [];
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check() {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(400);
        die('Sesi tidak valid, silakan muat ulang halaman dan coba lagi.');
    }
}

function permintaan_status_label($status) {
    $map = ['menunggu' => 'Menunggu', 'diproses' => 'Diproses', 'selesai' => 'Selesai', 'ditolak' => 'Ditolak'];
    return $map[$status] ?? $status;
}

function permintaan_status_class($status) {
    $map = ['menunggu' => 'st-menunggu', 'diproses' => 'st-diproses', 'selesai' => 'st-selesai', 'ditolak' => 'st-ditolak'];
    return $map[$status] ?? '';
}

function penitipan_status_label($status) {
    $map = ['dititip' => 'Masih Dititip', 'diambil' => 'Sudah Diambil'];
    return $map[$status] ?? $status;
}

function penitipan_status_class($status) {
    $map = ['dititip' => 'st-menunggu', 'diambil' => 'st-selesai'];
    return $map[$status] ?? '';
}

/**
 * Ikon garis (gaya Feather/Lucide) sebagai inline SVG, tanpa file/library eksternal.
 * Dipanggil langsung: <?= icon('box') ?> — sudah aman, jangan dibungkus e().
 */
function icon($name, $size = 18) {
    $paths = [
        'grid'          => '<rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect>',
        'arrows'        => '<path d="M17 3l4 4-4 4"></path><path d="M21 7H7a4 4 0 0 0-4 4"></path><path d="M7 21l-4-4 4-4"></path><path d="M3 17h14a4 4 0 0 0 4-4"></path>',
        'qrcode'        => '<rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><path d="M14 14h3v3h-3z"></path><path d="M19 14v2M14 19h2M19 19v2h2"></path>',
        'box'           => '<path d="M21 8l-9-5-9 5 9 5 9-5z"></path><path d="M3 8v8l9 5 9-5V8"></path><path d="M12 13v8"></path>',
        'file'          => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M9 13h6M9 17h6"></path>',
        'gauge'         => '<path d="M12 15l3-3"></path><circle cx="12" cy="12" r="9"></circle><path d="M8 12a4 4 0 0 1 8 0"></path>',
        'tag'           => '<path d="M20.6 12.6l-8.2 8.2a2 2 0 0 1-2.8 0l-6.6-6.6a2 2 0 0 1 0-2.8l8.2-8.2a2 2 0 0 1 1.4-.6H19a2 2 0 0 1 2 2v6.4a2 2 0 0 1-.6 1.4z"></path><circle cx="15" cy="9" r="1"></circle>',
        'building'      => '<rect x="4" y="2" width="16" height="20" rx="1"></rect><path d="M9 22v-4h6v4"></path><path d="M9 9h1M9 13h1M14 9h1M14 13h1"></path>',
        'shield'        => '<path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"></path>',
        'building2'     => '<path d="M6 22V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v18"></path><path d="M14 9h6a1 1 0 0 1 1 1v12"></path><path d="M9 8h.01M9 12h.01M9 16h.01M18 14h.01M18 18h.01M2 22h20"></path>',
        'search'        => '<circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path>',
        'bell'          => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.7 21a2 2 0 0 1-3.4 0"></path>',
        'chevron-right' => '<path d="M9 18l6-6-6-6"></path>',
        'chevron-down'  => '<path d="M6 9l6 6 6-6"></path>',
        'sidebar'       => '<rect x="3" y="3" width="18" height="18" rx="2"></rect><path d="M9 3v18"></path>',
        'logout'        => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path>',
        'plus'          => '<path d="M12 5v14M5 12h14"></path>',
        'sun'           => '<circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>',
        'clock'         => '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 3"></path>',
        'clipboard'     => '<rect x="8" y="2" width="8" height="4" rx="1"></rect><path d="M9 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3"></path><path d="M9 12h6M9 16h6"></path>',
        'chat'          => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><path d="M8 10h.01M12 10h.01M16 10h.01"></path>',
        'archive'       => '<rect x="2" y="3" width="20" height="5" rx="1"></rect><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"></path><path d="M10 13h4"></path>',
        'user-check'    => '<circle cx="9" cy="8" r="4"></circle><path d="M2 21v-2a5 5 0 0 1 5-5h4a5 5 0 0 1 1 .1"></path><path d="M16 13l2 2 4-4"></path>',
    ];
    $p = $paths[$name] ?? $paths['grid'];
    return '<svg width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}