<?php
// Bersihkan buffer output apa pun yang mungkin sudah terlanjur keluar
// (mis. whitespace/BOM dari file yang di-require) sebelum kirim header gambar.
while (ob_get_level() > 0) { ob_end_clean(); }

require __DIR__ . '/includes/bootstrap.php';
require_login();

if (headers_sent($hsFile, $hsLine)) {
    http_response_code(500);
    header_remove();
    error_log("serve_foto_barang.php: headers already sent by $hsFile:$hsLine");
    // Jangan kirim teks apa pun agar tidak makin merusak stream gambar;
    // cukup catat ke error log server untuk didiagnosis.
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$path = null;

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT foto FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && !empty($row['foto'])) {
        $fotoDir = __DIR__ . '/uploads/barang/';
        // Cegah path traversal: hanya nama file polos yang diizinkan.
        $namaFile = basename($row['foto']);
        $candidate = $fotoDir . $namaFile;
        if (is_file($candidate)) {
            $path = $candidate;
        }
    }
}

if (!$path) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Foto tidak ditemukan.';
    exit;
}

$mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null;
if (!$mime) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $mime = $map[$ext] ?? 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;