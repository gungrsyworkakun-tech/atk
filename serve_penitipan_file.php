<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('penitipan');

$type = $_GET['type'] ?? 'berkas';
if (!in_array($type, ['foto', 'berkas', 'foto_pengambilan'], true)) $type = 'berkas';
$forceDownload = isset($_GET['download']);
$uploadDir = __DIR__ . '/uploads/penitipan/';

if ($type === 'foto') {
    $fotoId = (int)($_GET['foto_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT `file` FROM penitipan_foto WHERE id = ?');
    $stmt->execute([$fotoId]);
    $row = $stmt->fetch();
} else {
    $id = (int)($_GET['id'] ?? 0);
    $col = $type === 'foto_pengambilan' ? 'foto_pengambilan' : 'berkas';
    $stmt = $pdo->prepare("SELECT `$col` AS `file` FROM penitipan WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
}

if (!$row || empty($row['file'])) {
    http_response_code(404);
    exit('Berkas tidak ditemukan.');
}

$path = $uploadDir . $row['file'];

if (!is_file($path)) {
    http_response_code(404);
    exit('Berkas tidak ditemukan.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'webp' => 'image/webp', 'pdf' => 'application/pdf',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . basename($row['file']) . '"');
readfile($path);
exit;