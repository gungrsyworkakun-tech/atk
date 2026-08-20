<?php
/**
 * notifikasi.php
 *
 * Endpoint AJAX untuk bel notifikasi di topbar (lihat header.php). Sumber
 * data: tabel `notifikasi` (diisi oleh notifikasi_stok.php / kirim_notifikasi.php),
 * status dibaca dicatat PER ADMIN di tabel `notifikasi_dibaca` — jadi kalau
 * satu admin menandai dibaca, admin lain tetap melihatnya sebagai belum dibaca.
 *
 * Butuh tabel `notifikasi` dan `notifikasi_dibaca` — lihat migrasi terkait.
 */
require __DIR__ . '/includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

$do = $_GET['do'] ?? $_POST['do'] ?? '';
$u = current_user();
$userId = $u['id'];

try {
    switch ($do) {
        case 'list':
            $stmt = $pdo->prepare(
                'SELECT n.id, n.tipe, n.judul, n.pesan, n.created_at,
                        CASE WHEN nd.id IS NULL THEN 0 ELSE 1 END AS dibaca
                 FROM notifikasi n
                 LEFT JOIN notifikasi_dibaca nd ON nd.notifikasi_id = n.id AND nd.user_id = ?
                 ORDER BY n.created_at DESC LIMIT 20'
            );
            $stmt->execute([$userId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtUnread = $pdo->prepare(
                'SELECT COUNT(*) c FROM notifikasi n
                 LEFT JOIN notifikasi_dibaca nd ON nd.notifikasi_id = n.id AND nd.user_id = ?
                 WHERE nd.id IS NULL'
            );
            $stmtUnread->execute([$userId]);
            $unread = (int)$stmtUnread->fetch()['c'];

            echo json_encode(['items' => $items, 'unread' => $unread]);
            break;

        case 'count':
            $stmtUnread = $pdo->prepare(
                'SELECT COUNT(*) c FROM notifikasi n
                 LEFT JOIN notifikasi_dibaca nd ON nd.notifikasi_id = n.id AND nd.user_id = ?
                 WHERE nd.id IS NULL'
            );
            $stmtUnread->execute([$userId]);
            $unread = (int)$stmtUnread->fetch()['c'];
            echo json_encode(['unread' => $unread]);
            break;

        case 'mark_read':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method tidak diizinkan.']); break; }
            csrf_check();
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare('INSERT IGNORE INTO notifikasi_dibaca (notifikasi_id, user_id) VALUES (?, ?)');
                $stmt->execute([$id, $userId]);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'mark_all_read':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method tidak diizinkan.']); break; }
            csrf_check();
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO notifikasi_dibaca (notifikasi_id, user_id)
                 SELECT n.id, ? FROM notifikasi n
                 LEFT JOIN notifikasi_dibaca nd ON nd.notifikasi_id = n.id AND nd.user_id = ?
                 WHERE nd.id IS NULL'
            );
            $stmt->execute([$userId, $userId]);
            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Aksi tidak dikenali.']);
    }
} catch (Throwable $e) {
    // Kalau tabel notifikasi/notifikasi_dibaca belum ada (migrasi belum
    // dijalankan), jangan bikin seluruh topbar error — balas kosong saja.
    http_response_code(200);
    echo json_encode(['items' => [], 'unread' => 0, 'error' => 'Fitur notifikasi belum siap (cek migrasi database).']);
}