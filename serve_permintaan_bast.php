<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM permintaan WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row || !$row['bast_file']) { http_response_code(404); exit('Berkas tidak ditemukan.'); }

$u = current_user();
$allowed = is_super() || has_permission('kelola_permintaan') || (is_bidang() && (int)$row['user_id'] === (int)$u['id']);
if (!$allowed) { http_response_code(403); exit('Akses ditolak.'); }

$path = __DIR__ . '/uploads/permintaan/' . $row['bast_file'];
if (!is_file($path)) { http_response_code(404); exit('Berkas tidak ditemukan di server.'); }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
$disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . basename($path) . '"');
readfile($path);