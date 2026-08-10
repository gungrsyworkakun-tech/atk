<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('bast');

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT bast_file FROM transactions WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row || !$row['bast_file']) { http_response_code(404); exit('Berkas tidak ditemukan.'); }

$path = __DIR__ . '/uploads/bast/' . $row['bast_file'];
if (!is_file($path)) { http_response_code(404); exit('Berkas tidak ditemukan di server.'); }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';

$disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . basename($path) . '"');
readfile($path);