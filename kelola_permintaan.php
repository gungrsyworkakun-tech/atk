<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('kelola_permintaan');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'update_status') {
    csrf_check();
    $id = (int)$_POST['id'];
    $status = in_array($_POST['status'] ?? '', ['menunggu', 'diproses', 'selesai', 'ditolak'], true) ? $_POST['status'] : 'menunggu';
    $catatan = trim($_POST['catatan_admin'] ?? '');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM permintaan WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $req = $stmt->fetch();

        if (!$req) {
            $pdo->rollBack();
            flash_set('Permintaan tidak ditemukan.', 'error');
            redirect('kelola_permintaan.php');
        }

        $oldStatus = $req['status'];

        if ($oldStatus !== $status) {
            $stmtItems = $pdo->prepare('SELECT * FROM permintaan_items WHERE permintaan_id = ?');
            $stmtItems->execute([$id]);
            $reqItems = $stmtItems->fetchAll();

            if ($oldStatus === 'ditolak' && $status !== 'ditolak') {
                $stmtLock = $pdo->prepare('SELECT * FROM items WHERE id = ? FOR UPDATE');
                $insufficient = [];
                foreach ($reqItems as $ri) {
                    if (!$ri['item_id']) continue;
                    $stmtLock->execute([$ri['item_id']]);
                    $it = $stmtLock->fetch();
                    if (!$it || (int)$it['stok'] < (int)$ri['jumlah']) {
                        $insufficient[] = $ri['nama_barang'] . ' (butuh ' . $ri['jumlah'] . ', tersedia ' . ($it ? $it['stok'] : 0) . ')';
                    }
                }
                if ($insufficient) {
                    $pdo->rollBack();
                    flash_set('Tidak bisa mengubah status: stok tidak lagi mencukupi untuk ' . implode(', ', $insufficient) . '.', 'error');
                    redirect('kelola_permintaan.php');
                }
                foreach ($reqItems as $ri) {
                    if (!$ri['item_id']) continue;
                    $pdo->prepare('UPDATE items SET stok = stok - ? WHERE id = ?')->execute([$ri['jumlah'], $ri['item_id']]);
                }
            } elseif ($oldStatus !== 'ditolak' && $status === 'ditolak') {
                foreach ($reqItems as $ri) {
                    if (!$ri['item_id']) continue;
                    $pdo->prepare('UPDATE items SET stok = stok + ? WHERE id = ?')->execute([$ri['jumlah'], $ri['item_id']]);
                }
            }
        }

        $pdo->prepare('UPDATE permintaan SET status = ?, catatan_admin = ? WHERE id = ?')->execute([$status, $catatan, $id]);
        $pdo->commit();
        flash_set('Status permintaan diperbarui.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Gagal memperbarui status.', 'error');
    }
    redirect('kelola_permintaan.php');
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM permintaan WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $req = $stmt->fetch();

        if (!$req) {
            $pdo->rollBack();
            flash_set('Permintaan tidak ditemukan.', 'error');
            redirect('kelola_permintaan.php');
        }

        // Kalau status bukan "ditolak", stoknya masih tertahan (reserved) -> kembalikan dulu sebelum dihapus.
        if ($req['status'] !== 'ditolak') {
            $stmtItems = $pdo->prepare('SELECT * FROM permintaan_items WHERE permintaan_id = ?');
            $stmtItems->execute([$id]);
            foreach ($stmtItems->fetchAll() as $ri) {
                if (!$ri['item_id']) continue;
                $pdo->prepare('UPDATE items SET stok = stok + ? WHERE id = ?')->execute([$ri['jumlah'], $ri['item_id']]);
            }
        }

        // Hapus berkas BAST fisik kalau ada.
        if ($req['bast_file']) {
            $f = __DIR__ . '/uploads/permintaan/' . $req['bast_file'];
            if (is_file($f)) @unlink($f);
        }

        $pdo->prepare('DELETE FROM permintaan WHERE id = ?')->execute([$id]);
        $pdo->commit();
        flash_set('Permintaan dihapus' . ($req['status'] !== 'ditolak' ? ', stok dikembalikan.' : '.'));
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Gagal menghapus permintaan.', 'error');
    }
    redirect('kelola_permintaan.php');
}

$rows = $pdo->query("
    SELECT p.*, u.username
    FROM permintaan p
    LEFT JOIN users u ON u.id = p.user_id
    ORDER BY (p.status = 'menunggu') DESC, p.created_at DESC
")->fetchAll();

$itemsByReq = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM permintaan_items WHERE permintaan_id IN ($in) ORDER BY id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) $itemsByReq[$row['permintaan_id']][] = $row;
}

require __DIR__ . '/includes/header.php';
?>
<style>
  .pq-card{border:1px solid var(--line,#232a35);border-radius:12px;padding:16px;background:var(--panel,#12161c);margin-bottom:12px;}
  .pq-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;}
  .pq-date{font-weight:600;font-size:14px;}
  .pq-sub{font-size:12px;color:var(--text-dim,#8b95a5);margin-top:2px;}
  .pq-items{margin-top:10px;font-size:13px;}
  .pq-items li{margin-bottom:2px;}
  .pq-top-right{display:flex;align-items:center;gap:8px;}

  .st-badge{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
  .st-menunggu{background:rgba(224,178,53,.15);color:#e0b235;}
  .st-diproses{background:rgba(76,140,255,.15);color:#4c8cff;}
  .st-selesai{background:rgba(76,175,140,.15);color:#4caf8c;}
  .st-ditolak{background:rgba(224,82,82,.15);color:#e05252;}

  .pq-form{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:12px;border-top:1px solid var(--line,#232a35);padding-top:12px;}
  .pq-form .field{margin:0;}
  .pq-form select, .pq-form input{min-width:160px;}
</style>

<div class="topline">
  <div><h1>Permintaan ATK Bidang</h1><div class="sub">Proses dan perbarui status permintaan ATK yang diajukan tiap bidang. Menolak atau menghapus permintaan yang belum ditolak otomatis mengembalikan stok.</div></div>
</div>

<?php if (!$rows): ?>
  <div class="card"><div class="empty"><b>Belum ada permintaan</b>Permintaan yang diajukan Admin Bidang akan muncul di sini.</div></div>
<?php else: foreach ($rows as $r): ?>
  <div class="pq-card">
    <div class="pq-top">
      <div>
        <div class="pq-date"><?= e(date('d M Y', strtotime($r['tanggal']))) ?> — <?= e($r['bidang']) ?></div>
        <div class="pq-sub">Diajukan oleh: <?= e($r['username'] ?? '(dihapus)') ?> · Penanggung jawab: <?= e($r['penanggung_jawab']) ?></div>
      </div>
      <div class="pq-top-right">
        <span class="st-badge <?= permintaan_status_class($r['status']) ?>"><?= e(permintaan_status_label($r['status'])) ?></span>
        <a class="icon-btn danger" href="?action=delete&id=<?= e($r['id']) ?>" title="Hapus permintaan"
           onclick="return confirm('Hapus permintaan ini?<?= $r['status'] !== 'ditolak' ? ' Stok yang tertahan akan dikembalikan otomatis.' : '' ?>');">Hapus</a>
      </div>
    </div>

    <ul class="pq-items">
      <?php foreach ($itemsByReq[$r['id']] ?? [] as $it): ?>
       <li>
          <?= e($it['nama_barang']) ?> — <b><?= (int)$it['jumlah'] ?></b> pcs
          <?php if (!empty($it['keterangan_satuan'])): ?>
            <span class="unit-note">(diajukan: <?= e($it['keterangan_satuan']) ?>)</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($r['bast_file']): ?>
      <div style="margin-top:10px;">
        <button type="button" class="btn btn-ghost js-view-doc" style="font-size:12px;padding:6px 10px;"
          data-url="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>"
          data-download="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>&download=1"
          data-filename="<?= e($r['bast_file']) ?>"
          data-image="<?= strtolower(pathinfo($r['bast_file'], PATHINFO_EXTENSION)) === 'pdf' ? '0' : '1' ?>">Lihat BAST</button>
      </div>
    <?php endif; ?>

    <form method="post" class="pq-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="update_status">
      <input type="hidden" name="id" value="<?= e($r['id']) ?>">
      <div class="field">
        <label style="font-size:11px;">Status</label>
        <select name="status">
          <?php foreach (['menunggu', 'diproses', 'selesai', 'ditolak'] as $st): ?>
            <option value="<?= $st ?>" <?= $r['status'] === $st ? 'selected' : '' ?>><?= permintaan_status_label($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="flex:1;">
        <label style="font-size:11px;">Catatan (opsional)</label>
        <input type="text" name="catatan_admin" value="<?= e($r['catatan_admin'] ?? '') ?>" placeholder="mis. sudah diserahkan tanggal ..">
      </div>
      <button class="btn btn-primary" type="submit" style="padding:8px 14px;font-size:12.5px;">Simpan</button>
    </form>
  </div>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>