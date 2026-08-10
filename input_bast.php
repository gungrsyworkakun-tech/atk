<?php
require __DIR__ . '/includes/bootstrap.php';
require_bidang();

$u = current_user();
$uploadDir = __DIR__ . '/uploads/permintaan/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
$allowedBastTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'upload') {
    csrf_check();
    $pid = (int)($_POST['permintaan_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM permintaan WHERE id = ? AND user_id = ?');
    $stmt->execute([$pid, $u['id']]);
    $req = $stmt->fetch();

    if (!$req) {
        flash_set('Permintaan tidak ditemukan.', 'error');
        redirect('input_bast.php');
    }

    if (empty($_FILES['berkas']) || $_FILES['berkas']['error'] !== UPLOAD_ERR_OK) {
        flash_set('Gagal mengunggah berkas. Coba lagi.', 'error');
        redirect('input_bast.php');
    }

    $file = $_FILES['berkas'];
    if (!in_array($file['type'], $allowedBastTypes, true)) {
        flash_set('Format berkas harus PDF, JPG, PNG, atau WebP.', 'error');
        redirect('input_bast.php');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        flash_set('Ukuran berkas maksimal 5 MB.', 'error');
        redirect('input_bast.php');
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = 'permintaan-' . $pid . '-' . time() . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $oldFile = $req['bast_file'];

    if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
        $pdo->prepare('UPDATE permintaan SET bast_file = ? WHERE id = ?')->execute([$safeName, $pid]);
        if ($oldFile && is_file($uploadDir . $oldFile)) @unlink($uploadDir . $oldFile);
        flash_set('Berkas BAST berhasil diunggah.');
    } else {
        flash_set('Gagal menyimpan berkas di server.', 'error');
    }
    redirect('input_bast.php');
}

$stmt = $pdo->prepare('SELECT * FROM permintaan WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$u['id']]);
$myRequests = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<style>
  .ib-card{border:1px solid var(--line,#232a35);border-radius:12px;padding:14px 16px;background:var(--panel,#12161c);margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
  .ib-info{min-width:0;}
  .ib-date{font-weight:600;font-size:14px;}
  .ib-sub{font-size:12px;color:var(--text-dim,#8b95a5);margin-top:2px;}
  .ib-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .ib-actions input[type=file]{font-size:11.5px;max-width:160px;}
</style>

<div class="topline">
  <div><h1>Input BAST</h1><div class="sub">Unggah atau ganti berkas Berita Acara Serah Terima untuk permintaan yang sudah diajukan</div></div>
</div>

<?php if (!$myRequests): ?>
  <div class="card"><div class="empty"><b>Belum ada permintaan</b>Ajukan permintaan lewat menu Permintaan ATK terlebih dahulu.</div></div>
<?php else: foreach ($myRequests as $r):
  $hasFile = !empty($r['bast_file']);
  $isPdf = $hasFile && strtolower(pathinfo($r['bast_file'], PATHINFO_EXTENSION)) === 'pdf';
?>
  <div class="ib-card">
    <div class="ib-info">
      <div class="ib-date"><?= e(date('d M Y', strtotime($r['tanggal']))) ?></div>
      <div class="ib-sub">Status: <?= e(permintaan_status_label($r['status'])) ?> · <?= $hasFile ? e($r['bast_file']) : 'Belum ada berkas' ?></div>
    </div>
    <div class="ib-actions">
      <?php if ($hasFile): ?>
        <button type="button" class="btn btn-ghost js-view-doc" style="font-size:12px;padding:6px 10px;"
          data-url="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>"
          data-download="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>&download=1"
          data-filename="<?= e($r['bast_file']) ?>"
          data-image="<?= $isPdf ? '0' : '1' ?>">Lihat</button>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="upload">
        <input type="hidden" name="permintaan_id" value="<?= e($r['id']) ?>">
        <input type="file" name="berkas" accept="application/pdf,image/*" required>
        <button class="btn btn-primary" style="font-size:12px;padding:6px 10px;" type="submit"><?= $hasFile ? 'Ganti' : 'Unggah' ?></button>
      </form>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>